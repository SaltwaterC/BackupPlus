# age test vectors

The `testdata/` files are the C2SP age test kit snapshot from CCTV commit
`50a8ecf2a220f4c8bdc4f085789b8e85c26829e7`.

They are used by `tests/Unit/Age/AgeTestkitTest.php` and `scripts/test-client-decryption.mjs`.
The PHP suite runs its supported X25519 vectors. The JavaScript suite also checks
scrypt passphrase recipients and hybrid ML-KEM-768/X25519 identities in both
WebCrypto and portable X25519 modes. Armor vectors remain skipped because
armored input is not implemented by these readers.

The vector files are available under the Zero-Clause BSD (0BSD), CC0 1.0, or
Unlicense terms. Upstream source: <https://github.com/C2SP/CCTV/tree/50a8ecf2a220f4c8bdc4f085789b8e85c26829e7/age/testdata>.
