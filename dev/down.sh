#!/usr/bin/env bash
set -Eeuo pipefail

podman rm --force backupplus-dev-wordpress backupplus-dev-database >/dev/null 2>&1 || true

if [[ "${1:-}" == --volumes ]]; then
	podman volume rm --force backupplus-dev-wordpress backupplus-dev-mariadb >/dev/null 2>&1 || true
	podman network rm backupplus-dev >/dev/null 2>&1 || true
	printf 'Removed the BackupPlus development containers, volumes, and network.\n'
else
	printf 'Stopped the BackupPlus development containers; WordPress and database volumes are preserved.\n'
	printf 'Run %s --volumes to also remove their data.\n' "$0"
fi
