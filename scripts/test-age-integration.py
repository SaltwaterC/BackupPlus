#!/usr/bin/env python3
"""Check the PHP implementation against the independent age executable."""
import json
import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FIXTURE = json.loads((ROOT / "scripts/fixtures/age-keys.json").read_text())


def php(request):
    command = ["php", "-d", "error_reporting=24575"]
    if request.get("portable"):
        command += ["-d", "disable_functions=openssl_encrypt,openssl_decrypt,sodium_crypto_aead_chacha20poly1305_ietf_encrypt,sodium_crypto_aead_chacha20poly1305_ietf_decrypt,sodium_crypto_scalarmult,sodium_crypto_box_publickey_from_secretkey"]
    result = subprocess.run(command + [str(ROOT / "scripts/fixtures/age-interop.php")],
                            input=json.dumps(request).encode(), capture_output=True, timeout=120)
    if result.returncode:
        raise RuntimeError("PHP interoperability check failed: " + result.stderr.decode(errors="replace"))
    return result.stdout


def main():
    if not shutil.which("age"):
        raise SystemExit("age is required for the interoperability tests.")
    with tempfile.TemporaryDirectory(prefix="backupplus-age-reference-") as directory:
        folder = Path(directory)
        identities = {}
        for name, key in FIXTURE["keys"].items():
            path = folder / (name + ".key")
            raw = key["identity"].encode() if name == "native" else php({
                "operation": "identity", "identity": key["identity"], "passphrase": FIXTURE["passphrase"]})
            path.write_bytes(raw)
            path.chmod(0o600)
            identities[name] = path
        source = folder / "source.sql"
        php_archive = folder / "php.age"
        cli_archive = folder / "cli.age"
        restored = folder / "restored.sql"
        for size in (0, 1, 65536, 65537, 3 * 65536 + 17):
            plain = (bytes(range(256)) * ((size + 255) // 256))[:size]
            source.write_bytes(plain)
            for name, key in FIXTURE["keys"].items():
                php({"operation": "encrypt", "input": str(source), "output": str(php_archive),
                     "recipients": [key["recipient"]]})
                result = subprocess.run(["age", "-d", "-i", str(identities[name]), str(php_archive)],
                                        capture_output=True, check=True, timeout=30)
                assert result.stdout == plain, (name, size, "PHP -> age")
                subprocess.run(["age", "-r", key["recipient"], "-o", str(cli_archive), str(source)],
                               capture_output=True, check=True, timeout=30)
                for identity in (key["identity"], key["encrypted_pem"]):
                    php({"operation": "decrypt", "input": str(cli_archive), "output": str(restored),
                         "identity": identity, "passphrase": FIXTURE["passphrase"]})
                    assert restored.read_bytes() == plain, (name, size, "age -> PHP")
            print("PASS: bidirectional age interoperability for all recipients at", size, "bytes")
        recipients = [key["recipient"] for key in FIXTURE["keys"].values()]
        php({"operation": "encrypt", "input": str(source), "output": str(php_archive),
             "recipients": recipients, "portable": True})
        for name, key in FIXTURE["keys"].items():
            result = subprocess.run(["age", "-d", "-i", str(identities[name]), str(php_archive)],
                                    capture_output=True, check=True, timeout=30)
            assert result.stdout == plain, (name, "portable multi-recipient")
            php({"operation": "decrypt", "input": str(php_archive), "output": str(restored),
                 "identity": key["encrypted_pem"], "passphrase": FIXTURE["passphrase"], "portable": True})
            assert restored.read_bytes() == plain
        print("PASS: portable PHP multi-recipient output and encrypted PEM restoration without OpenSSL/sodium encryption")


if __name__ == "__main__":
    main()
