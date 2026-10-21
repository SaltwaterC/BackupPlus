# BackupPlus

BackupPlus is a WordPress backup and restore plugin. To move a site, create a backup and restore it on the destination. This repository includes a Podman-based local WordPress environment that installs WordPress automatically, activates the checked-out plugin, and provides a local administrator auto-login link.

Start with `cp .env.example .env && ./dev/up.sh`. See [the development environment guide](dev/README.md) for configuration, WP-CLI commands, and cleanup.

## Building assets

The plugin's versioned minified assets are generated from the stable inputs in `assets-src/`. After changing the `Version:` header in `backupplus.php`, run `make build-assets` to produce the filenames used by the plugin and update CSS source-map references. Run `make check-assets` to verify the checked-in assets match the current plugin version. This uses PHP CLI and Make; it does not require Node or npm.
