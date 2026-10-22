.PHONY: build-assets check-assets test test-prerequisites test-unit test-integration test-wordpress test-client test-client-browser

PYTHON ?= python3
TEST_VENV ?= .cache/test-venv
PODMAN ?= podman
WP_TEST_CONTAINER ?= backupplus-dev-wordpress

PHP_TESTS := $(filter-out scripts/test-storage-providers.php,$(wildcard scripts/test-*.php))
INTEGRATION_TESTS := $(wildcard scripts/test-*-integration.py)

build-assets:
	php scripts/build-assets.php --build
	node scripts/build-decryptor.mjs

check-assets:
	php scripts/build-assets.php --check
	node scripts/build-decryptor.mjs --check

# Keep prerequisites ahead of every suite, including when invoked with make -j.
test: test-prerequisites
	$(MAKE) check-assets test-unit test-integration test-wordpress test-client test-client-browser
	@echo 'All tests passed.'

test-prerequisites:
	@set -e; for tool in php node "$(PYTHON)" openssl scp age age-keygen "$(PODMAN)"; do \
		command -v "$$tool" >/dev/null 2>&1 || { echo "Missing test prerequisite: $$tool" >&2; exit 1; }; \
	done
	@php -r '$$missing = array_filter(array("curl", "dom", "openssl", "ctype"), function($$name) { return !extension_loaded($$name); }); if ($$missing || !function_exists("curl_init") || !function_exists("fsockopen")) { fwrite(STDERR, "Tests require PHP cURL, DOM, OpenSSL, ctype, curl_init and fsockopen. Missing extensions: ".implode(", ", $$missing)."\n"); exit(1); } $$curl = curl_version(); if (!in_array("ftp", $$curl["protocols"], true) || !($$curl["features"] & CURL_VERSION_SSL)) { fwrite(STDERR, "Tests require libcurl with FTP and SSL/TLS support.\n"); exit(1); }'
	@"$(PYTHON)" -c 'import sys, venv; sys.exit("Tests require Python 3.8 or newer.") if sys.version_info < (3, 8) else None'
	@if ! "$(PODMAN)" exec "$(WP_TEST_CONTAINER)" wp --skip-plugins=backupplus core is-installed; then \
		echo 'WordPress test environment unavailable. Run ./dev/up.sh, then make test again.' >&2; \
		exit 1; \
	fi

$(TEST_VENV)/.requirements-installed: scripts/test-requirements.txt
	"$(PYTHON)" -m venv "$(TEST_VENV)"
	"$(TEST_VENV)/bin/python" -m pip --disable-pip-version-check install -r scripts/test-requirements.txt
	touch "$@"

test-unit:
	@set -e; for test in $(PHP_TESTS); do \
		echo "Running $$test"; \
		php "$$test"; \
	done
	php -d disable_functions=curl_init scripts/test-ftp.php
	php -d disable_functions=fsockopen scripts/test-sftp.php
	php -d disable_functions=curl_init scripts/test-webdav.php
	php -d disable_functions=openssl_encrypt,openssl_decrypt scripts/test-encryption.php --portable
	php -d disable_functions=openssl_encrypt,openssl_decrypt,sodium_crypto_aead_chacha20poly1305_ietf_encrypt,sodium_crypto_aead_chacha20poly1305_ietf_decrypt,sodium_crypto_scalarmult,sodium_crypto_box_publickey_from_secretkey scripts/test-age.php --portable

test-integration: $(TEST_VENV)/.requirements-installed
	@set -e; for test in $(INTEGRATION_TESTS); do \
		echo "Running $$test"; \
		"$(TEST_VENV)/bin/python" "$$test"; \
	done

test-wordpress:
	"$(PODMAN)" exec "$(WP_TEST_CONTAINER)" wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-credential-storage.php
	"$(PODMAN)" exec "$(WP_TEST_CONTAINER)" wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-dropbox-token-storage.php
	"$(PODMAN)" exec "$(WP_TEST_CONTAINER)" wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-age-encryption.php
	"$(PODMAN)" exec "$(WP_TEST_CONTAINER)" wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/test-storage-providers.php
	@set -e; for provider in $$(awk '/^[[:space:]]*\* Provider ID: / { print $$4 }' methods/*.php) all; do \
		"$(PODMAN)" exec "$(WP_TEST_CONTAINER)" wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/test-storage-providers.php "$$provider"; \
		if [ "$$provider" != all ]; then "$(PODMAN)" exec "$(WP_TEST_CONTAINER)" wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/test-storage-providers.php "only:$$provider"; fi; \
	done

test-client:
	node scripts/test-credential-export.mjs
	node scripts/test-client-decryption.mjs

test-client-browser:
	PODMAN="$(PODMAN)" WP_TEST_CONTAINER="$(WP_TEST_CONTAINER)" node scripts/test-client-decryption-browser.mjs
