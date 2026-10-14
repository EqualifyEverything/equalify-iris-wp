#!/usr/bin/env bash
# Usage: php-checks.sh [file ...]
#
# The PHP checks CI runs, in a pinned container, so a laptop with Docker gets the same answers:
#
#   lint      `php -l` under PHP 8.0, the plugin's `Requires PHP`, on every PHP file in the repo.
#             Blocking: a parse error in a network-activated plugin takes down every site on the
#             network at once, and 8.0 is what catches syntax from later versions (an enum, say).
#   compat    PHPCompatibility against PHP 8.0 and up. Blocking. It is the 9.x line, which knows
#             functions removed or added up to PHP 7.4 but not newer ones, so a function new in 8.1
#             (array_is_list, say) still needs a reviewer's eye.
#   security  WordPress's escaping, nonce, sanitising and SQL sniffs, on the files given (every
#             plugin file when none are). Advisory: the code has known false positives, such as an
#             `IN ()` built from placeholders, so a hit is a lead for a reviewer, not a failure.
#
# The sniffs come from .github/phpcs/composer.lock. Keep wpcs at 3.4.1 or later: earlier versions
# can run code from the files they scan (CVE-2026-45293), and CI scans pull requests.
#
# Exits 1 when lint or compat fails.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
php80='php:8.0-cli@sha256:0569e384b9064c04dec55dc6e41be41b494a878dfbb6577a7d76bd50cfd5bc00'
composer='composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c'
status=0

# Read-only mounts, so nothing the checks run can change the checkout.
docker run --rm -v "$root":/repo:ro -w /repo "$php80" sh -c '
  echo "### lint (php $(php -r "echo PHP_VERSION;"))"
  bad=$(find . -path ./test-site/wp -prune -o -name "*.php" -print | sort | while read -r f; do
    php -l "$f" >/tmp/lint.txt 2>&1 || cat /tmp/lint.txt
  done)
  if [ -n "$bad" ]; then echo "$bad"; echo "lint: FAIL"; exit 1; fi
  echo "lint: pass"
' || status=1

docker run --rm -v "$root":/repo:ro -w /repo "$composer" sh -c '
  set -u
  mkdir -p /tmp/phpcs
  cp .github/phpcs/composer.json .github/phpcs/composer.lock /tmp/phpcs/
  composer install --working-dir=/tmp/phpcs --no-interaction --no-progress --quiet
  phpcs=/tmp/phpcs/vendor/bin/phpcs
  status=0

  echo
  echo "### compat (PHP 8.0 and up)"
  if $phpcs -q --standard=PHPCompatibilityWP --runtime-set testVersion 8.0- plugin/equalify-iris; then
    echo "compat: pass"
  else
    echo "compat: FAIL"; status=1
  fi

  echo
  echo "### security sniffs (advisory)"
  files=""
  for f in "$@"; do
    case "$f" in plugin/*.php) [ -f "$f" ] && files="$files $f" ;; esac
  done
  [ $# -eq 0 ] && files=plugin/equalify-iris
  if [ -z "$files" ]; then
    echo "no plugin PHP files to check"
  elif $phpcs -q --standard=WordPress --report-width=100 \
      --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.SafeRedirect,WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders,WordPress.WP.GlobalVariablesOverride \
      $files; then
    echo "security sniffs: nothing found"
  else
    echo "security sniffs: see above (advisory)"
  fi

  exit $status
' sh "$@" || status=1

exit "$status"
