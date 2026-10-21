.PHONY: build-assets check-assets

build-assets:
	php scripts/build-assets.php --build

check-assets:
	php scripts/build-assets.php --check
