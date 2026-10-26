#!/usr/bin/env bash
set -Eeuo pipefail

DEV_NAME=${DEV_NAME:-backupplus-dev}

podman rm --force --ignore ${DEV_NAME}-wordpress ${DEV_NAME}-database >/dev/null

if [[ "${1:-}" == --volumes ]]; then
	podman volume rm --force ${DEV_NAME}-wordpress ${DEV_NAME}-mariadb ${DEV_NAME}-db-socket >/dev/null 2>&1 || true
	podman network rm ${DEV_NAME} >/dev/null 2>&1 || true
	printf 'Removed the BackupPlus development containers, volumes, and network.\n'
else
	printf 'Stopped the BackupPlus development containers; WordPress and database volumes are preserved.\n'
	printf 'Run %s --volumes to also remove their data.\n' "$0"
fi
