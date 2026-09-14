#!/usr/bin/env bash
#
# Throw the test site away and build it again from nothing.
#
# WHEN YOU WANT THIS
#
#   After changing the database schema, after testing the uninstall path, or any time
#   the state has got confusing enough that you no longer trust what you are seeing.
#   Starting clean is cheap here, and a test rig you are suspicious of is worse than
#   no test rig.
#
# WHAT IT DELETES
#
#   The database, the WordPress files in wp/, the sample PDFs, and the DDEV project.
#   It does NOT touch the plugin source in ../plugin, which is only mounted in.
#
# USAGE
#
#   ./reset.sh            # asks first
#   ./reset.sh --yes      # does not ask

set -euo pipefail

cd "$(dirname "$0")"

if [ "${1:-}" != "--yes" ]; then
	echo "This deletes the test site's database and WordPress files."
	echo "The plugin source in ../plugin is not touched."
	echo
	read -r -p "Delete it? [y/N] " reply

	case "$reply" in
		[yY] | [yY][eE][sS] ) ;;
		* ) echo "Left alone."; exit 0 ;;
	esac
fi

echo
echo "==> Removing the DDEV project and its database"

# --omit-snapshot skips the automatic database backup, which is not worth keeping for
# a site that is designed to be rebuilt.
ddev delete --omit-snapshot --yes 2>/dev/null || true

echo "==> Deleting WordPress files"

# Everything inside wp/, including dotfiles, but not the folder itself — DDEV's config
# points at it and expects it to exist.
find wp -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true

echo "==> Deleting sample PDFs"

rm -rf samples

echo
echo "Gone. Run ./setup.sh to build it again."
