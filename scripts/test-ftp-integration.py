#!/usr/bin/env python3
"""Run live FTP/FTPS checks with disposable servers and locally issued certificates.

Requires pyftpdlib and pyOpenSSL (which includes cryptography). All listeners bind
only to loopback. No external FTP service or WordPress installation is needed.
"""
import datetime
import multiprocessing
import os
from pathlib import Path
import subprocess
import tempfile

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID
from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import FTPHandler, TLS_FTPHandler
from pyftpdlib.servers import FTPServer

USER = "backup+user@example.test"
PASSWORD = "p@ss:/?#% word"


def issue_certificate(directory, name, issuer=None, expired=False):
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, name)])
    now = datetime.datetime.now(datetime.timezone.utc)
    is_ca = issuer is None
    builder = (x509.CertificateBuilder()
               .subject_name(subject)
               .issuer_name(issuer[0].subject if issuer else subject)
               .public_key(key.public_key())
               .serial_number(x509.random_serial_number())
               .not_valid_before(now - datetime.timedelta(days=10))
               .not_valid_after(now + datetime.timedelta(days=-1 if expired else 1))
               .add_extension(x509.BasicConstraints(ca=is_ca, path_length=None), critical=True))
    if not is_ca:
        builder = (builder.add_extension(x509.SubjectAlternativeName([x509.DNSName("localhost")]), critical=False)
                   .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.SERVER_AUTH]), critical=False))
    cert = builder.sign(issuer[1] if issuer else key, hashes.SHA256())
    cert_path = Path(directory) / (name + ".pem")
    key_path = Path(directory) / (name + ".key")
    cert_path.write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    key_path.write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.TraditionalOpenSSL, serialization.NoEncryption()))
    return cert, key, str(cert_path), str(key_path)


def serve(root, certificate, queue, log_path):
    with open(log_path, "a") as log:
        os.dup2(log.fileno(), 2)
        authorizer = DummyAuthorizer()
        authorizer.add_user(USER, PASSWORD, root, perm="elradfmwMT")
        base = TLS_FTPHandler if certificate else FTPHandler

        class Handler(base):
            def on_login(self, username):
                # Exercise a login directory different from the server's root.
                self.fs.chdir(self.fs.ftp2fs("/login"))

        Handler.authorizer = authorizer
        if certificate:
            Handler.certfile = certificate[2]
            Handler.keyfile = certificate[3]
            Handler.tls_control_required = True
            Handler.tls_data_required = True
        server = FTPServer(("127.0.0.1", 0), Handler)
        queue.put(server.socket.getsockname()[1])
        server.serve_forever()


def main():
    processes = []
    with tempfile.TemporaryDirectory(prefix="backupplus-ftps-") as directory:
        ca = issue_certificate(directory, "test-ca")
        other_ca = issue_certificate(directory, "untrusted-ca")
        certificates = {
            "FTP": None,
            "FTPS": issue_certificate(directory, "trusted-server", ca),
            "FTPS_UNTRUSTED": issue_certificate(directory, "untrusted-server", other_ca),
            "FTPS_EXPIRED": issue_certificate(directory, "expired-server", ca, expired=True),
        }
        env = os.environ.copy()
        env.update(BACKUPPLUS_TEST_FTP_USER=USER, BACKUPPLUS_TEST_FTP_PASS=PASSWORD,
                   BACKUPPLUS_TEST_FTP_CA=ca[2])
        try:
            for name, certificate in certificates.items():
                root = Path(directory) / name
                (root / "login").mkdir(parents=True)
                queue = multiprocessing.Queue()
                process = multiprocessing.Process(target=serve, args=(str(root), certificate, queue, directory + "/server.log"))
                processes.append(process)
                process.start()
                port = queue.get(timeout=10)
                env["BACKUPPLUS_TEST_" + name + "_HOST"] = "localhost:" + str(port)
            # Disable the native FTP API to guard against accidental transport fallbacks.
            native_functions = "ftp_connect,ftp_ssl_connect,ftp_login,ftp_nb_fput,ftp_nb_fget,ftp_nlist,ftp_size,ftp_delete"
            command = ["php", "-d", "curl.cainfo=" + ca[2], "-d", "disable_functions=" + native_functions,
                       str(Path(__file__).with_name("test-ftp.php"))]
            result = subprocess.run(command, env=env, check=False)
            if result.returncode:
                print(Path(directory, "server.log").read_text())
            return result.returncode
        finally:
            for process in processes:
                process.terminate()
                process.join()


if __name__ == "__main__":
    raise SystemExit(main())
