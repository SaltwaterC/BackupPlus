#!/usr/bin/env python3
"""Hidden terminal restore checks; disposable identities remain in process memory."""
import errno
import json
import os
import pty
import secrets
import select
import signal
import subprocess
import time

runtime = os.environ.get('PODMAN', 'podman')
container = os.environ.get('WP_TEST_CONTAINER', 'backupplus-dev-wordpress')
directory = '/tmp/backupplus-cli-restore-' + secrets.token_hex(12)
base = [runtime, 'exec', container, 'wp', '--allow-root', '--skip-plugins=backupplus']
fixture = 'wp-content/plugins/backupplus/scripts/check-cli-restore.php'
secret_values = []


def expect(value, message):
    if not value:
        raise RuntimeError(message)


def helper(action):
    result = subprocess.run(base + ['eval-file', fixture, action, directory], capture_output=True)
    expect(result.returncode == 0, 'Encrypted restore fixture operation failed: ' + action)
    return result.stdout


def no_secrets(data):
    if isinstance(data, str):
        data = data.encode()
    expect(all(value not in data for value in secret_values), 'A restore secret appeared in output or persisted state')


def report(expected):
    raw = helper('report')
    state = json.loads(raw)
    no_secrets(state['state'])
    expect(state['file'] == expected and state['table'] == expected,
           'Restore must change both selected components only after all archives decrypt')
    expect(not state['staging'], 'Decrypted staging files must be removed on success and failure')


def command(timestamp):
    return ['wp', '--allow-root', '--skip-plugins=backupplus',
            '--exec=putenv(' + json.dumps('BACKUPPLUS_CLI_TEST_DIR=' + directory) + ');',
            '--require=/workspace/backupplus/scripts/fixtures/cli.php',
            'backupplus', 'restore', str(timestamp), '--yes']


def terminal_restore(timestamp, identity, password, interrupt=False):
    argv = [runtime, 'exec', '-it', container] + command(timestamp)
    pid, descriptor = pty.fork()
    if pid == 0:
        os.execvp(argv[0], argv)
    transcript = bytearray()
    sent_key = sent_password = False
    deadline = time.monotonic() + (15 if interrupt else 120)
    status = None
    try:
        while status is None:
            expect(time.monotonic() < deadline, 'Interactive restore did not finish')
            readable, _, _ = select.select([descriptor], [], [], 0.2)
            if readable:
                try:
                    chunk = os.read(descriptor, 65536)
                except OSError as error:
                    if error.errno != errno.EIO:
                        raise
                    chunk = b''
                transcript.extend(chunk)
                no_secrets(transcript)
            if not sent_key and b'Private key (hidden;' in transcript:
                os.write(descriptor, identity.encode() + (b'\x03' if interrupt else b'\n'))
                sent_key = True
            if sent_key and not sent_password and b'Private key passphrase (hidden;' in transcript:
                os.write(descriptor, password.encode() + b'\n')
                sent_password = True
            finished, child_status = os.waitpid(pid, os.WNOHANG)
            if finished:
                status = child_status
        # Drain output written immediately before exit.
        while select.select([descriptor], [], [], 0)[0]:
            try:
                chunk = os.read(descriptor, 65536)
            except OSError:
                break
            if not chunk:
                break
            transcript.extend(chunk)
        no_secrets(transcript)
        expect(sent_key and (sent_password or interrupt), 'Both hidden secret prompts must be shown')
        return os.waitstatus_to_exitcode(status) if hasattr(os, 'waitstatus_to_exitcode') else os.WEXITSTATUS(status)
    finally:
        if status is None:
            os.kill(pid, signal.SIGTERM)
            os.waitpid(pid, 0)
        os.close(descriptor)


try:
    # Setup communicates its generated identities solely through a captured pipe.
    print('Checking encrypted restore prompts and cancellation...', flush=True)
    setup = json.loads(helper('setup'))
    password = setup['passphrase']
    wrong_password = 'wrong-restore-secret-' + secrets.token_hex(8)
    secret_values = [password.encode(), wrong_password.encode()]
    for field in ('identity', 'unencrypted_identity'):
        secret_values.extend(line.encode() for line in setup[field].splitlines()
                             if line and not line.startswith('-----'))
    timestamp = setup['timestamp']
    # No command-line secret flags or piped-stdin fallback; --yes bypasses confirmation only.
    noninteractive = subprocess.run([runtime, 'exec', container] + command(timestamp), capture_output=True)
    expect(noninteractive.returncode != 0, 'Encrypted restores must require a terminal')
    no_secrets(noninteractive.stdout + noninteractive.stderr)
    report('LIVE')
    cancel_probe = 'cancelled-secret-' + secrets.token_hex(8)
    secret_values.append(cancel_probe.encode())
    expect(terminal_restore(timestamp, cancel_probe, '', interrupt=True) != 0,
           'Cancelling secret entry must abort the restore')
    report('LIVE')
    expect(terminal_restore(timestamp, setup['identity'], wrong_password) != 0,
           'A wrong private-key passphrase must fail')
    report('LIVE')
    expect(terminal_restore(timestamp, setup['identity'], password) == 0,
           'The encrypted private key and passphrase must restore the selected file and table')
    report('BACKUP')
    helper('reset')
    expect(terminal_restore(timestamp, '# identity comment\n' + setup['unencrypted_identity'], '') == 0,
           'An unencrypted multiline identity must accept an empty passphrase')
    report('BACKUP')
    helper('reset')
    helper('tamper')
    expect(terminal_restore(timestamp, setup['identity'], password) != 0,
           'A corrupted archive must fail authentication before any selected component is restored')
    report('LIVE')
    print('PASS: CLI encrypted file/database restores, hidden multiline prompts, wrong passwords, authentication failures and secret non-persistence')
finally:
    helper('cleanup')
    # Keep no secret files, environment variables or command-line arguments to clean up.
    secret_values.clear()
