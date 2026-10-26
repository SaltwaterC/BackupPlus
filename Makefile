.PHONY: test-sites test-wordpress-lane build-assets check-assets check-php install-tools format-tools format check-format test-tools test test-host test-site test-prerequisites test-unit test-integration test-wordpress test-client test-client-browser test-pitr-native

PODMAN ?= podman
COMPOSER ?= $(if $(wildcard .cache/composer.phar),php .cache/composer.phar,composer)
PHP_TOOLS_BIN := .cache/php-tools/vendor/bin
PHP_TOOLS_MEMORY_LIMIT ?= 512M
PHPUNIT ?= php -d memory_limit=1G $(PHP_TOOLS_BIN)/phpunit
# The host-only suites run at a lower priority so that they never starve the development site, whose PHP server answers the
# WordPress and browser suites with a handful of workers and which times out under CPU contention.
HOST_NICE ?= nice -n 10
PARATEST ?= php -d memory_limit=1G $(PHP_TOOLS_BIN)/paratest
# Worker processes of the unit suite, which is split into single tests; the default is half the CPUs, like TEST_JOBS.
TEST_PROCESSES ?= $(shell expr \( $$(nproc) + 1 \) / 2)
FORMAT_PATHS ?= .

# The PHPUnit suites read these. WP_TEST_CONTAINER (with BACKUPPLUS_TEST_URL) pins the WordPress suites to one site, for example
# backupplus-dev-wordpress; by default they lease the isolated test sites that dev/test-sites.sh keeps (TEST_SITES, default 8).
export PODMAN WP_TEST_CONTAINER BACKUPPLUS_TEST_URL TEST_SITES

build-assets:
	node scripts/build-admin-common.mjs
	node scripts/build-css.mjs
	php scripts/build-assets.php --build
	PODMAN="$(PODMAN)" node scripts/build-agessh-wasm.mjs
	node scripts/build-decryptor.mjs

check-assets:
	node scripts/build-admin-common.mjs --check
	node scripts/build-css.mjs --check
	php scripts/build-assets.php --check
	PODMAN="$(PODMAN)" node scripts/build-agessh-wasm.mjs --check
	node scripts/build-decryptor.mjs --check

check-php:
	@find . -type d \( -name vendor -o -name node_modules -o -name .git -o -name .cache \) -prune -o -type f -name '*.php' -print0 | xargs -0 -n 1 -P $$(nproc) php -l
	php scripts/check-sql.php

install-tools:
	COMPOSER_HOME="$(CURDIR)/.cache/composer-home" COMPOSER_CACHE_DIR="$(CURDIR)/.cache/composer-cache" $(COMPOSER) install --no-interaction --prefer-dist

format-tools:
	@test -f "$(PHP_TOOLS_BIN)/phpcs" -a -f "$(PHP_TOOLS_BIN)/phpcbf" || { echo 'PHP formatting tools are missing. Run make install-tools first.' >&2; exit 1; }

format: format-tools
	@php -d memory_limit="$(PHP_TOOLS_MEMORY_LIMIT)" "$(PHP_TOOLS_BIN)/phpcbf" --standard=.phpcs.xml.dist $(FORMAT_PATHS); \
	status=$$?; \
	case $$status in 0|1) exit 0 ;; *) exit $$status ;; esac

check-format: format-tools
	php -d memory_limit="$(PHP_TOOLS_MEMORY_LIMIT)" "$(PHP_TOOLS_BIN)/phpcs" --standard=.phpcs.xml.dist $(FORMAT_PATHS)

test-tools:
	@test -f "$(PHP_TOOLS_BIN)/phpunit" -a -f "$(PHP_TOOLS_BIN)/paratest" || { echo 'PHPUnit or ParaTest is missing. Run make install-tools first.' >&2; exit 1; }

# The test sites come first; everything else, the static checks included, then runs side by side. test-host needs nothing
# but PHP, Node and the OS; the WordPress suites run in isolated test sites, which each script leases while it runs.
# TEST_JOBS bounds how many scripts of a suite run at once (default: half the CPUs).
test: test-prerequisites
	$(MAKE) test-sites
	$(MAKE) --jobs --output-sync=target check-format check-php check-assets test-host test-wordpress-lane test-pitr-native
	@echo 'All tests passed.'

test-host:
	$(MAKE) --jobs --output-sync=target test-unit test-integration test-client

# One recipe line per suite, so that the browser tests start when the WordPress scripts are done.
test-wordpress-lane:
	$(MAKE) test-wordpress
	$(MAKE) test-client-browser

test-sites:
	./dev/test-sites.sh

test-prerequisites:
	@set -e; for tool in php node openssl scp ssh-keygen age age-keygen "$(PODMAN)"; do \
		command -v "$$tool" >/dev/null 2>&1 || { echo "Missing test prerequisite: $$tool" >&2; exit 1; }; \
	done
	@php -r '$$missing = array_filter(array("curl", "dom", "openssl", "ctype", "sodium"), function($$name) { return !extension_loaded($$name); }); if ($$missing || !function_exists("curl_init") || !function_exists("fsockopen")) { fwrite(STDERR, "Tests require PHP cURL, DOM, OpenSSL, ctype, sodium, curl_init and fsockopen. Missing extensions: ".implode(", ", $$missing)."\n"); exit(1); } $$curl = curl_version(); if (!in_array("ftp", $$curl["protocols"], true) || !($$curl["features"] & CURL_VERSION_SSL)) { fwrite(STDERR, "Tests require libcurl with FTP and SSL/TLS support.\n"); exit(1); }'

test-unit: test-tools
	$(HOST_NICE) $(PARATEST) --testsuite unit --functional --processes $(TEST_PROCESSES)

test-integration: test-tools
	$(HOST_NICE) $(PHPUNIT) --testsuite integration

test-wordpress: test-tools test-sites
	$(PHPUNIT) --testsuite wordpress

test-client: test-tools
	$(HOST_NICE) $(PHPUNIT) --testsuite client

test-pitr-native: test-tools test-sites
	$(PHPUNIT) --testsuite pitr

test-client-browser: test-tools test-sites
	$(PHPUNIT) --testsuite browser
