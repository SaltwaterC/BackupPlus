#!/usr/bin/env bash
# Stage a release in a WordPress.org plugin SVN working copy, from the package that `make dist` built.
#
#   scripts/stage-wporg-svn.sh SVN_WORKING_COPY
#
# Check out the plugin's repository once (svn checkout https://plugins.svn.wordpress.org/backupplus), then for each release:
#
#   make dist && make dist-check
#   scripts/stage-wporg-svn.sh ~/svn/backupplus
#
# The script updates the working copy, mirrors build/backupplus/ into trunk/ (adding new files and removing deleted ones),
# copies trunk/ to tags/VERSION, and, if the repository has a .wordpress-org/ folder (banners, icons and screenshots for the
# plugin directory page), mirrors it into assets/. It does not commit: review `svn status` and `svn diff`, then run the commit
# command it prints. readme.txt's Stable tag, which make dist checks against Version, tells WordPress.org which tag to offer.
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
package=$repo_root/build/backupplus
assets=$repo_root/.wordpress-org

if [[ $# -ne 1 ]]; then
	echo "Usage: $0 SVN_WORKING_COPY" >&2
	exit 2
fi
for tool in svn rsync; do
	command -v "$tool" >/dev/null 2>&1 || { echo "$tool is required." >&2; exit 1; }
done
[[ -f "$package/backupplus.php" ]] || { echo "No package in $package; run make dist first." >&2; exit 1; }

svn_dir=$(cd "$1" && pwd)
url=$(svn info --show-item url "$svn_dir")
[[ "$url" =~ /backupplus/?$ ]] || { echo "$svn_dir is a working copy of $url, not of the backupplus plugin." >&2; exit 1; }
if [[ -n "$(svn status --quiet "$svn_dir")" ]]; then
	echo "$svn_dir has uncommitted changes; commit or revert them first:" >&2
	svn status --quiet "$svn_dir" >&2
	exit 1
fi

version=$(sed -n 's/^Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$package/backupplus.php")
[[ -n "$version" ]] || { echo "Cannot read the Version of $package/backupplus.php." >&2; exit 1; }

svn update --quiet "$svn_dir"
mkdir -p "$svn_dir/trunk" "$svn_dir/tags"
if svn info "$url/tags/$version" >/dev/null 2>&1 || [[ -e "$svn_dir/tags/$version" ]]; then
	echo "tags/$version already exists. WordPress.org tags are releases: raise Version (and Stable tag) instead." >&2
	exit 1
fi

# Mirror a folder into the working copy and schedule the additions and deletions.
mirror() {
	local from=$1 to=$2
	rsync --recursive --checksum --delete --exclude=.svn "$from/" "$to/"
	svn add --force --quiet --depth infinity "$to"
	svn status "$to" | sed -n 's/^!       //p' | while IFS= read -r missing; do
		svn delete --force --quiet "$missing"
	done
}

mirror "$package" "$svn_dir/trunk"
if [[ -d "$assets" ]]; then
	mkdir -p "$svn_dir/assets"
	mirror "$assets" "$svn_dir/assets"
	# Without a MIME type, browsers download the images on the plugin page instead of showing them.
	while IFS= read -r -d '' image; do
		case "${image,,}" in
		*.png) type=image/png ;;
		*.jpg | *.jpeg) type=image/jpeg ;;
		*.gif) type=image/gif ;;
		*.svg) type=image/svg+xml ;;
		*) continue ;;
		esac
		svn propset --quiet svn:mime-type "$type" "$image"
	done < <(find "$svn_dir/assets" -path '*/.svn' -prune -o -type f -print0)
fi
svn copy --quiet "$svn_dir/trunk" "$svn_dir/tags/$version"

changes=$(svn status "$svn_dir" | grep -c '^[AMDR]' || true)
echo "Staged BackupPlus $version in $svn_dir ($changes changed paths, tags/$version added)."
echo "Review with: svn status $svn_dir; svn diff $svn_dir/trunk/readme.txt"
echo "Publish with: svn commit $svn_dir -m 'Release $version'"
