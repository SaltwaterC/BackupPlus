#!/usr/bin/env python3
"""Exercise WebDAV over loopback HTTP (Digest auth) and HTTPS (Basic auth).

Requires Python's standard library, PHP with cURL/DOM, and openssl. The disposable
DAV fixture also returns malformed/mixed XML and incomplete HTTP responses to
exercise cases that are difficult to reproduce with a normal storage service.
"""
import base64
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
from urllib.parse import quote, unquote, urlsplit
from urllib.request import parse_http_list, parse_keqv_list
import xml.etree.ElementTree as ET

USER = "backup+user@example.test"
PASSWORD = " secret:@/?#% "
DIRECTORY = "/dav/my backups +#?%/"
ET.register_namespace("", "DAV:")


def md5(value):
    return hashlib.md5(value.encode()).hexdigest()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def reply(self, status, body=b"", headers=None, extra_length=0):
        self.send_response(status)
        self.send_header("Content-Length", str(len(body) + extra_length))
        for key, value in (headers or {}).items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(body)

    def authenticated(self):
        # Consume the body even when this is libcurl's initial auth probe.
        self.body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        auth = self.headers.get("Authorization", "")
        if self.server.digest:
            if auth.startswith("Digest "):
                parts = parse_keqv_list(parse_http_list(auth[7:]))
                ha1 = md5(f"{USER}:backupplus:{PASSWORD}")
                ha2 = md5(f"{self.command}:{self.path}")
                expected = md5(f"{ha1}:test-nonce:{parts.get('nc')}:{parts.get('cnonce')}:auth:{ha2}")
                if (parts.get("username") == USER and parts.get("uri") == self.path
                        and parts.get("response") == expected):
                    return True
            challenge = 'Digest realm="backupplus", nonce="test-nonce", algorithm=MD5, qop="auth"'
        else:
            expected = "Basic " + base64.b64encode(f"{USER}:{PASSWORD}".encode()).decode()
            if auth == expected:
                return True
            challenge = 'Basic realm="backupplus"'
        self.reply(401, b"authentication required", {"WWW-Authenticate": challenge})
        return False

    @property
    def remote_path(self):
        return unquote(urlsplit(self.path).path)

    def do_PUT(self):
        if not self.authenticated():
            return
        path = self.remote_path
        if path.rsplit("/", 1)[0] + "/" not in ("/", DIRECTORY):
            return self.reply(409)
        # Store precisely what arrived; short or repeated bodies will fail PHP's
        # subsequent size and binary-content assertions.
        status = 204 if path in self.server.files else 201
        self.server.files[path] = self.body
        self.reply(status)

    def do_GET(self):
        if not self.authenticated():
            return
        path = self.remote_path
        if path.endswith("backup_truncated.zip"):
            return self.reply(200, b"partial archive", extra_length=100)
        if path.endswith("backup_redirect.zip"):
            return self.reply(302, headers={"Location": "/redirect-target"})
        if path not in self.server.files:
            return self.reply(404, b"not found")
        self.reply(200, self.server.files[path])

    def do_DELETE(self):
        if not self.authenticated():
            return
        path = self.remote_path
        if path.endswith("backup_forbidden.zip"):
            return self.reply(403)
        if path not in self.server.files:
            return self.reply(404)
        del self.server.files[path]
        self.reply(204)

    def do_PROPFIND(self):
        if not self.authenticated():
            return
        path = self.remote_path
        if path == "/malformed/":
            return self.reply(207, b"<not-xml")
        if path == "/empty-xml/":
            return self.reply(207)
        if path == "/xxe/":
            xml = b'<!DOCTYPE multistatus [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><multistatus xmlns="DAV:">&xxe;</multistatus>'
            return self.reply(207, xml)
        if path == "/redirect/":
            return self.reply(301, headers={"Location": "/"})
        if path not in ("/", DIRECTORY):
            return self.reply(404)
        requested = ET.fromstring(self.body)
        assert self.headers.get("Depth") == "1"
        assert requested.find("{DAV:}prop/{DAV:}resourcetype") is not None
        assert requested.find("{DAV:}prop/{DAV:}getcontentlength") is not None
        root = ET.Element("{DAV:}multistatus")

        def entry(href, size=0, collection=False, status="200 OK"):
            node = ET.SubElement(root, "{DAV:}response")
            ET.SubElement(node, "{DAV:}href").text = href
            propstat = ET.SubElement(node, "{DAV:}propstat")
            prop = ET.SubElement(propstat, "{DAV:}prop")
            kind = ET.SubElement(prop, "{DAV:}resourcetype")
            if collection:
                ET.SubElement(kind, "{DAV:}collection")
            ET.SubElement(prop, "{DAV:}getcontentlength").text = str(size)
            ET.SubElement(propstat, "{DAV:}status").text = "HTTP/1.1 " + status

        entry(quote(path), collection=True)
        entry(quote(path + "backup_directory"), collection=True)
        entry(quote(path + "backup_denied.zip"), status="403 Forbidden")
        entry(quote(path + "nested/backup_nested.zip"))
        entry(quote(path + "../backup_escape.zip"))
        entry(quote(path) + "backup_encoded%2Fslash.zip")
        for name, data in self.server.files.items():
            if name.rsplit("/", 1)[0] + "/" == path:
                # Exercise both absolute URI and absolute path href formats.
                href = quote(name) if self.server.digest else "https://" + self.headers["Host"] + quote(name)
                entry(href, len(data))
        self.reply(207, ET.tostring(root, encoding="utf-8", xml_declaration=True), {"Content-Type": "application/xml"})


def main():
    servers = []
    with tempfile.TemporaryDirectory(prefix="backupplus-dav-") as fixture:
        cert = str(Path(fixture) / "localhost.pem")
        key = str(Path(fixture) / "localhost.key")
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                        "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost",
                        "-keyout", key, "-out", cert], check=True, capture_output=True)
        env = os.environ.copy()
        try:
            for secure in (False, True):
                server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
                server.files = {}
                server.digest = not secure
                if secure:
                    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                    context.load_cert_chain(cert, key)
                    server.socket = context.wrap_socket(server.socket, server_side=True)
                servers.append(server)
                threading.Thread(target=server.serve_forever, daemon=True).start()
                variable = "BACKUPPLUS_TEST_DAV_TLS_HOST" if secure else "BACKUPPLUS_TEST_DAV_HOST"
                env[variable] = "localhost:" + str(server.server_port)
            command = ["php", "-d", "curl.cainfo=" + cert, str(Path(__file__).with_name("test-webdav.php"))]
            subprocess.run(command, env=env, check=True, timeout=90)
            for server in servers:
                assert not server.files, "Tests must remove all remote test archives"
        finally:
            for server in servers:
                server.shutdown()
                server.server_close()


if __name__ == "__main__":
    main()
