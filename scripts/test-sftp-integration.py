#!/usr/bin/env python3
"""Exercise SSH storage against disposable loopback SSH servers.

Requires Python paramiko and the local scp command. The SFTP server rejects exec;
the SCP server rejects SFTP, and runs commands in a temporary login directory.
All keys and credentials are disposable. Run only this test's generated client
against these fixtures: the command server is not a shell sandbox.
"""
import base64
import hashlib
import json
import os
from pathlib import Path
import socketserver
import subprocess
import tempfile
import threading

import paramiko
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import ed25519


class Files(paramiko.SFTPServerInterface):
    def __init__(self, server, *args, **kwargs):
        super().__init__(server, *args, **kwargs)
        self.root = server.root

    def path(self, path):
        result = os.path.abspath(os.path.join(self.root, path))
        if os.path.commonpath([self.root, result]) != self.root:
            raise OSError(13, "Outside test directory")
        return result

    def canonicalize(self, path):
        return self.path(path)

    def stat(self, path):
        try:
            return paramiko.SFTPAttributes.from_stat(os.stat(self.path(path)))
        except OSError as error:
            return paramiko.SFTPServer.convert_errno(error.errno)

    def lstat(self, path):
        try:
            return paramiko.SFTPAttributes.from_stat(os.lstat(self.path(path)))
        except OSError as error:
            return paramiko.SFTPServer.convert_errno(error.errno)

    def list_folder(self, path):
        try:
            result = []
            for name in os.listdir(self.path(path)):
                entry = paramiko.SFTPAttributes.from_stat(os.lstat(os.path.join(self.path(path), name)))
                entry.filename = name
                result.append(entry)
            return result
        except OSError as error:
            return paramiko.SFTPServer.convert_errno(error.errno)

    def open(self, path, flags, attr):
        try:
            fd = os.open(self.path(path), flags, 0o600)
            handle = paramiko.SFTPHandle(flags)
            stream = os.fdopen(fd, 'r+b' if flags & os.O_RDWR else ('wb' if flags & os.O_WRONLY else 'rb'))
            handle.readfile = stream
            handle.writefile = stream
            return handle
        except OSError as error:
            return paramiko.SFTPServer.convert_errno(error.errno)

    def remove(self, path):
        try:
            os.unlink(self.path(path))
            return paramiko.SFTP_OK
        except OSError as error:
            return paramiko.SFTPServer.convert_errno(error.errno)


class Auth(paramiko.ServerInterface):
    def __init__(self, root, public_keys, shell, attempts):
        self.root = root
        self.public_keys = public_keys
        self.shell = shell
        self.attempts = attempts

    def get_allowed_auths(self, username):
        return 'password,publickey'

    def check_auth_password(self, username, password):
        self.attempts.append(('password', password))
        return paramiko.AUTH_SUCCESSFUL if username == 'test' and password == ' test password ' else paramiko.AUTH_FAILED

    def check_auth_publickey(self, username, key):
        self.attempts.append(('key', key.get_base64()))
        return paramiko.AUTH_SUCCESSFUL if username == 'test' and key in self.public_keys else paramiko.AUTH_FAILED

    def check_channel_request(self, kind, channel_id):
        return paramiko.OPEN_SUCCEEDED if kind == 'session' else paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED

    def check_channel_subsystem_request(self, channel, name):
        return False if self.shell else super().check_channel_subsystem_request(channel, name)

    def check_channel_exec_request(self, channel, command):
        if not self.shell:
            return False
        threading.Thread(target=self.run_command, args=(channel, command), daemon=True).start()
        return True

    def run_command(self, channel, command):
        process = subprocess.Popen(command.decode(), shell=True, cwd=self.root,
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

        def receive():
            try:
                while data := channel.recv(32768):
                    process.stdin.write(data)
                    process.stdin.flush()
            except (OSError, EOFError):
                pass
            finally:
                process.stdin.close()

        def send(stream, write):
            try:
                while data := os.read(stream.fileno(), 32768):
                    write(data)
            except OSError:
                pass
            finally:
                stream.close()

        threading.Thread(target=receive, daemon=True).start()
        output = threading.Thread(target=send, args=(process.stdout, channel.sendall), daemon=True)
        errors = threading.Thread(target=send, args=(process.stderr, channel.sendall_stderr), daemon=True)
        output.start()
        errors.start()
        status = process.wait()
        output.join()
        errors.join()
        channel.send_exit_status(status)
        channel.close()


class Server(socketserver.ThreadingTCPServer):
    daemon_threads = True
    allow_reuse_address = True


def serve(root, host_key, client_keys, shell, attempts):
    class Handler(socketserver.BaseRequestHandler):
        def handle(self):
            transport = paramiko.Transport(self.request)
            transport.add_server_key(host_key)
            transport.set_subsystem_handler('sftp', paramiko.SFTPServer, Files)
            try:
                transport.start_server(server=Auth(root, client_keys, shell, attempts))
                while transport.is_active():
                    transport.join(0.1)
            except (EOFError, OSError, paramiko.SSHException):
                pass
            finally:
                transport.close()

    server = Server(('127.0.0.1', 0), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


def main():
    with tempfile.TemporaryDirectory(prefix='backupplus-ssh-') as directory:
        root = Path(directory)
        login = root / 'login'
        login.mkdir()
        (login / "archives ' #$").mkdir()
        (login / 'backup_directory').mkdir()
        (login / 'read-only').mkdir(mode=0o500)
        (login / 'outside').write_text('protected')
        (login / 'backup_link').symlink_to(login / 'outside')
        host_key = paramiko.RSAKey.generate(2048)
        client_key = paramiko.RSAKey.generate(2048)
        key_path = root / 'client.pem'
        client_key.write_private_key_file(str(key_path), password='key secret')
        rsa_plain_path = root / 'client-plain.pem'
        client_key.write_private_key_file(str(rsa_plain_path))
        ed_key = ed25519.Ed25519PrivateKey.generate()
        ed_plain_path = root / 'client-ed25519'
        ed_encrypted_path = root / 'client-ed25519-encrypted'
        for path, encryption in ((ed_plain_path, serialization.NoEncryption()),
                                 (ed_encrypted_path, serialization.BestAvailableEncryption(b'key secret'))):
            path.write_bytes(ed_key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.OpenSSH, encryption))
        ed_public_path = root / 'client-ed25519.pub'
        ed_public_path.write_bytes(ed_key.public_key().public_bytes(serialization.Encoding.OpenSSH, serialization.PublicFormat.OpenSSH))
        ed_client_key = paramiko.Ed25519Key.from_private_key_file(str(ed_plain_path))
        ed_host_path = root / 'host-ed25519'
        ed_host_path.write_bytes(ed25519.Ed25519PrivateKey.generate().private_bytes(
            serialization.Encoding.PEM, serialization.PrivateFormat.OpenSSH, serialization.NoEncryption()))
        ed_host_key = paramiko.Ed25519Key.from_private_key_file(str(ed_host_path))
        fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(host_key.asbytes()).digest()).decode().rstrip('=')
        ed_fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(ed_host_key.asbytes()).digest()).decode().rstrip('=')
        attempts = []
        clients = [client_key, ed_client_key]
        sftp = serve(str(login), host_key, clients, False, attempts)
        scp = serve(str(login), host_key, clients, True, attempts)
        ed_sftp = serve(str(login), ed_host_key, clients, False, attempts)
        ed_scp = serve(str(login), ed_host_key, clients, True, attempts)
        keys = [dict(name='RSA PEM', path=str(rsa_plain_path), passphrase=''),
                dict(name='encrypted RSA PEM', path=str(key_path), passphrase='key secret'),
                dict(name='Ed25519 OpenSSH', path=str(ed_plain_path), passphrase=''),
                dict(name='encrypted Ed25519 OpenSSH', path=str(ed_encrypted_path), passphrase='key secret')]
        environment = dict(os.environ, BACKUPPLUS_TEST_SFTP_PORT=str(sftp.server_address[1]),
                           BACKUPPLUS_TEST_SCP_PORT=str(scp.server_address[1]),
                           BACKUPPLUS_TEST_SSH_ROOT=str(root), BACKUPPLUS_TEST_SSH_KEYS=json.dumps(keys),
                           BACKUPPLUS_TEST_SSH_PUBLIC_KEY=str(ed_public_path),
                           BACKUPPLUS_TEST_SFTP_ED25519_PORT=str(ed_sftp.server_address[1]),
                           BACKUPPLUS_TEST_SCP_ED25519_PORT=str(ed_scp.server_address[1]),
                           BACKUPPLUS_TEST_SSH_ED25519_FINGERPRINT=ed_fingerprint,
                           BACKUPPLUS_TEST_SSH_FINGERPRINT=fingerprint)
        try:
            subprocess.run(['php', '-d', 'error_reporting=24575', str(Path(__file__).with_name('test-sftp.php'))],
                           env=environment, check=True, timeout=240)
            assert not list(login.rglob('INJECTED')), 'A filename executed a shell command'
            assert (login / 'outside').read_text() == 'protected', 'A transfer followed a symlink'
            assert not list(login.rglob('backupplus-test-*')), 'Credentials tests left remote files'
            assert not any(secret == 'UNVERIFIED' for kind, secret in attempts if kind == 'password'), 'Host verification sent credentials before verification'
            print('PASS: SCP without SFTP, SFTP without exec, shell escaping, symlink protection and pre-authentication host verification')
        finally:
            for server in (sftp, scp, ed_sftp, ed_scp):
                server.shutdown()
                server.server_close()


if __name__ == '__main__':
    main()
