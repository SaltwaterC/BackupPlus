#!/usr/bin/env python3
"""Exercise the generic streaming HTTP transport against a disposable TLS server."""
import hashlib
import http.server
import json
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def do_PUT(self):
        length = int(self.headers["Content-Length"])
        digest = hashlib.sha256()
        remaining = length
        while remaining:
            data = self.rfile.read(min(65536, remaining))
            if not data:
                return
            digest.update(data)
            remaining -= len(data)
        if self.path == "/redirect":
            self.send_response(302)
            self.send_header("Location", "/must-not-follow")
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        if self.path == "/must-not-follow":
            raise AssertionError("Upload redirect was followed")
        body = (b"X" * 1048577 if self.path == "/oversized" else
                json.dumps({"size": length, "sha256": digest.hexdigest()}).encode())
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass


def main():
    with tempfile.TemporaryDirectory(prefix="backupplus-cloud-http-") as directory:
        certificate = str(Path(directory, "certificate.pem"))
        key = str(Path(directory, "key.pem"))
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
                        "-keyout", key, "-out", certificate, "-days", "1",
                        "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost"],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(certificate, key)
        server.socket = context.wrap_socket(server.socket, server_side=True)
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        try:
            env = os.environ.copy()
            env["BACKUPPLUS_TEST_CLOUD_HTTPS"] = "https://localhost:" + str(server.server_port)
            return subprocess.run(["php", "-d", "curl.cainfo=" + certificate,
                                   str(Path(__file__).with_name("test-cloud-http.php"))], env=env).returncode
        finally:
            server.shutdown()
            server.server_close()
            worker.join()


if __name__ == "__main__":
    raise SystemExit(main())
