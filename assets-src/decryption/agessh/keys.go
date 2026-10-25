//go:build js && wasm

package main

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/ecdh"
	"crypto/ed25519"
	"crypto/rsa"
	"crypto/sha1"
	"crypto/sha256"
	"crypto/sha512"
	"crypto/x509/pkix"
	"encoding/asn1"
	"encoding/pem"
	"errors"
	"hash"
	"strings"

	"filippo.io/age"
	"filippo.io/age/agessh"
	"github.com/dchest/bcrypt_pbkdf"
	"github.com/youmark/pkcs8"
	"golang.org/x/crypto/pbkdf2"
	"golang.org/x/crypto/ssh"
)

type importedIdentity struct {
	ssh    age.Identity
	native []byte
}

type openSSHEnvelope struct {
	Cipher, KDF, Options string
	Count                uint32
	Public, Private      []byte
	Tag                  []byte `ssh:"rest"`
}

// Register the library's KDF extension to preserve all existing PRFs, optional
// keyLength, and the application's work/salt limits. PBKDF2 itself is upstream.
type boundedPBKDF2 struct {
	Salt       []byte
	Iterations int
	KeyLength  int                      `asn1:"optional"`
	PRF        pkix.AlgorithmIdentifier `asn1:"optional"`
}

// The dependency uses an empty password to select unencrypted input. Our PEM
// envelope already establishes encrypted mode, including an empty passphrase.
// Bridge calls are synchronous, so this override is scoped to one import.
var emptyPKCS8Password bool

func (p boundedPBKDF2) DeriveKey(password []byte, size int) ([]byte, error) {
	if emptyPKCS8Password {
		password = nil
	}
	if len(p.Salt) < 8 || len(p.Salt) > 1024 || p.Iterations < 1 || p.Iterations > 10000000 || (p.KeyLength != 0 && p.KeyLength != size) {
		return nil, errors.New("invalid KDF limits")
	}
	if len(p.PRF.Parameters.FullBytes) != 0 && !bytes.Equal(p.PRF.Parameters.FullBytes, []byte{5, 0}) {
		return nil, errors.New("invalid KDF PRF parameters")
	}
	var h func() hash.Hash
	switch p.PRF.Algorithm.String() {
	case "", "1.2.840.113549.2.7":
		h = sha1.New
	case "1.2.840.113549.2.9":
		h = sha256.New
	case "1.2.840.113549.2.10":
		h = sha512.New384
	case "1.2.840.113549.2.11":
		h = sha512.New
	default:
		return nil, errors.New("unsupported KDF PRF")
	}
	return pbkdf2.Key(password, p.Salt, p.Iterations, size, h), nil
}

func init() {
	pkcs8.RegisterKDF(asn1.ObjectIdentifier{1, 2, 840, 113549, 1, 5, 12}, func() pkcs8.KDFParameters { return new(boundedPBKDF2) })
}

// Check envelope policy with Go's ASN.1 decoder before the library derives keys.
func allowedPKCS8(der []byte) bool {
	var envelope struct {
		Algorithm pkix.AlgorithmIdentifier
		Data      []byte
	}
	rest, err := asn1.Unmarshal(der, &envelope)
	if err != nil || len(rest) != 0 || envelope.Algorithm.Algorithm.String() != "1.2.840.113549.1.5.13" || len(envelope.Data) == 0 || len(envelope.Data)%16 != 0 {
		return false
	}
	var params struct{ KDF, Cipher pkix.AlgorithmIdentifier }
	rest, err = asn1.Unmarshal(envelope.Algorithm.Parameters.FullBytes, &params)
	if err != nil || len(rest) != 0 || params.KDF.Algorithm.String() != "1.2.840.113549.1.5.12" {
		return false
	}
	switch params.Cipher.Algorithm.String() {
	case "2.16.840.1.101.3.4.1.2", "2.16.840.1.101.3.4.1.22", "2.16.840.1.101.3.4.1.42":
	default:
		return false
	}
	var iv []byte
	rest, err = asn1.Unmarshal(params.Cipher.Parameters.FullBytes, &iv)
	return err == nil && len(rest) == 0 && len(iv) == 16
}

// ssh.Unmarshal supplies the wire decoding; these are compatibility/work budgets.
func allowedOpenSSH(der []byte) bool {
	magic := []byte("openssh-key-v1\x00")
	if !bytes.HasPrefix(der, magic) {
		return false
	}
	var envelope openSSHEnvelope
	if ssh.Unmarshal(der[len(magic):], &envelope) != nil || envelope.Count != 1 || len(envelope.Private) == 0 {
		return false
	}
	if envelope.Cipher == "none" {
		return envelope.KDF == "none" && envelope.Options == "" && len(envelope.Private)%8 == 0 && len(envelope.Tag) == 0
	}
	switch envelope.Cipher {
	case "aes256-ctr":
		if len(envelope.Tag) != 0 {
			return false
		}
	case "aes256-gcm@openssh.com":
		// OpenSSH stores the authentication tag after the encrypted SSH string.
		if len(envelope.Tag) != 16 {
			return false
		}
	default:
		return false
	}
	if envelope.KDF != "bcrypt" || len(envelope.Private)%16 != 0 {
		return false
	}
	var kdf struct {
		Salt   []byte
		Rounds uint32
	}
	return ssh.Unmarshal([]byte(envelope.Options), &kdf) == nil && len(kdf.Salt) >= 16 && len(kdf.Salt) <= 1024 && kdf.Rounds > 0 && kdf.Rounds <= 1024
}

// x/crypto/ssh does not unlock OpenSSH GCM envelopes. Authenticate that envelope
// with standard AES-GCM, then let its existing parser validate the private key.
// The caller has already checked allowedOpenSSH's cipher and KDF work budgets.
func parseOpenSSHGCM(envelope openSSHEnvelope, password []byte) (any, error) {
	var kdf struct {
		Salt   []byte
		Rounds uint32
	}
	if err := ssh.Unmarshal([]byte(envelope.Options), &kdf); err != nil {
		return nil, err
	}
	material, err := bcrypt_pbkdf.Key(password, kdf.Salt, int(kdf.Rounds), 32+12)
	if err != nil {
		return nil, err
	}
	defer clear(material[:cap(material)])
	block, err := aes.NewCipher(material[:32])
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	sealed := append(append([]byte(nil), envelope.Private...), envelope.Tag...)
	plain, err := gcm.Open(nil, material[32:], sealed, nil)
	if err != nil {
		return nil, err
	}
	defer clear(plain)
	envelope.Cipher, envelope.KDF, envelope.Options = "none", "none", ""
	envelope.Private, envelope.Tag = plain, nil
	body := ssh.Marshal(envelope)
	defer clear(body)
	wire := append([]byte("openssh-key-v1\x00"), body...)
	defer clear(wire)
	pemBytes := pem.EncodeToMemory(&pem.Block{Type: "OPENSSH PRIVATE KEY", Bytes: wire})
	defer clear(pemBytes)
	return ssh.ParseRawPrivateKey(pemBytes)
}

func parseIdentity(input, passphrase string) (importedIdentity, string) {
	fail := importedIdentity{}
	input = strings.TrimSpace(input)
	if len(input) == 0 || len(input) > maxKeyBytes {
		return fail, "UNSUPPORTED_KEY"
	}
	pemBytes, password := []byte(input), []byte(passphrase)
	defer clear(pemBytes)
	defer clear(password)
	block, rest := pem.Decode(pemBytes)
	if block == nil || len(bytes.TrimSpace(rest)) != 0 || !strings.HasPrefix(input, "-----BEGIN "+block.Type+"-----\n") && !strings.HasPrefix(input, "-----BEGIN "+block.Type+"-----\r\n") {
		return fail, "UNSUPPORTED_KEY"
	}
	defer clear(block.Bytes)
	var envelope openSSHEnvelope
	if block.Type == "OPENSSH PRIVATE KEY" {
		if !allowedOpenSSH(block.Bytes) {
			return fail, "UNSUPPORTED_KEY"
		}
		if ssh.Unmarshal(block.Bytes[len("openssh-key-v1\x00"):], &envelope) != nil {
			return fail, "UNSUPPORTED_KEY"
		}
	}
	if len(block.Headers) > 0 {
		cipher := strings.Split(block.Headers["DEK-Info"], ",")[0]
		if block.Type != "RSA PRIVATE KEY" || block.Headers["Proc-Type"] != "4,ENCRYPTED" || (cipher != "AES-128-CBC" && cipher != "AES-192-CBC" && cipher != "AES-256-CBC") {
			return fail, "UNSUPPORTED_KEY"
		}
	}
	var parsed any
	var err error
	if block.Type == "OPENSSH PRIVATE KEY" && envelope.Cipher == "aes256-gcm@openssh.com" {
		if len(password) == 0 {
			return fail, "KEY_PASSPHRASE_REQUIRED"
		}
		parsed, err = parseOpenSSHGCM(envelope, password)
		if err != nil {
			return fail, "KEY_UNLOCK_FAILED"
		}
	} else if block.Type == "ENCRYPTED PRIVATE KEY" {
		if !allowedPKCS8(block.Bytes) {
			return fail, "UNSUPPORTED_KEY"
		}
		if len(password) == 0 {
			emptyPKCS8Password = true
			defer func() { emptyPKCS8Password = false }()
			password = []byte{0} // Select encrypted mode; the KDF override uses nil.
		}
		parsed, err = pkcs8.ParsePKCS8PrivateKey(block.Bytes, password)
		if err != nil {
			return fail, "KEY_UNLOCK_FAILED"
		}
	} else {
		if block.Type != "OPENSSH PRIVATE KEY" && block.Type != "PRIVATE KEY" && block.Type != "RSA PRIVATE KEY" {
			return fail, "UNSUPPORTED_KEY"
		}
		parsed, err = ssh.ParseRawPrivateKey(pemBytes)
		if err != nil {
			var needsPassphrase *ssh.PassphraseMissingError
			if !errors.As(err, &needsPassphrase) {
				return fail, "UNSUPPORTED_KEY"
			}
			if len(password) == 0 && block.Type == "OPENSSH PRIVATE KEY" {
				return fail, "KEY_PASSPHRASE_REQUIRED"
			}
			parsed, err = ssh.ParseRawPrivateKeyWithPassphrase(pemBytes, password)
			if err != nil {
				return fail, "KEY_UNLOCK_FAILED"
			}
		}
	}
	// Go parses OpenSSH's public envelope separately from the private material.
	// Preserve the previous decoder's consistency check before using the key.
	if block.Type == "OPENSSH PRIVATE KEY" {
		signer, err := ssh.NewSignerFromKey(parsed)
		if err != nil || !bytes.Equal(signer.PublicKey().Marshal(), envelope.Public) {
			return fail, "UNSUPPORTED_KEY"
		}
	}
	if key, ok := parsed.(*ed25519.PrivateKey); ok {
		parsed = *key
	}
	if key, ok := parsed.(ed25519.PrivateKey); ok {
		if len(key) != ed25519.PrivateKeySize {
			return fail, "UNSUPPORTED_KEY"
		}
		derived := ed25519.NewKeyFromSeed(key.Seed())
		matches := bytes.Equal(derived, key)
		clear(derived)
		if !matches {
			return fail, "UNSUPPORTED_KEY"
		}
	}
	switch key := parsed.(type) {
	case *rsa.PrivateKey:
		if key.N.BitLen() < 2048 || key.N.BitLen() > 8192 || len(key.Primes) != 2 {
			return fail, "UNSUPPORTED_KEY"
		}
		identity, err := agessh.NewRSAIdentity(key)
		if err != nil {
			return fail, "UNSUPPORTED_KEY"
		}
		return importedIdentity{ssh: identity}, ""
	case ed25519.PrivateKey:
		identity, err := agessh.NewEd25519Identity(key)
		if err != nil {
			return fail, "UNSUPPORTED_KEY"
		}
		return importedIdentity{ssh: identity}, ""
	case *ed25519.PrivateKey:
		identity, err := agessh.NewEd25519Identity(*key)
		if err != nil {
			return fail, "UNSUPPORTED_KEY"
		}
		return importedIdentity{ssh: identity}, ""
	case *ecdh.PrivateKey:
		if key.Curve() != ecdh.X25519() {
			return fail, "UNSUPPORTED_KEY"
		}
		return importedIdentity{native: key.Bytes()}, ""
	default:
		return fail, "UNSUPPORTED_KEY"
	}
}
