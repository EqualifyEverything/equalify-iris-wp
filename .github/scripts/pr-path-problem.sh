#!/usr/bin/env bash
# Usage: pr-path-problem.sh <pr number> <issue number>
#
# Prints why the maintainer may not keep working on a pull request, or nothing when it may. Both
# maintainer workflows' verify jobs run it, from `main`, after the model has finished.
#
# A problem is any of:
#   - the branch is not `iris-wp-auto/issue-<issue>` or `iris-wp-auto/issue-<issue>-<k>`;
#   - it changes `.github/`, LICENSE or a .gitignore: what defines this workflow's privilege;
#   - it adds what this public repository must never hold: WordPress, wp-config, database dumps,
#     PDFs, uploads, or the test site's files.
#
# Fails closed: a file list that cannot be read, or one over 300 files, is a problem too. Old names
# count, so a file moved out of `.github/` is caught.
set -euo pipefail

pr="$1"
issue="$2"
repo="${GH_REPO:-${GITHUB_REPOSITORY:?}}"

FORBIDDEN='^\.github/|^LICENSE$|(^|/)\.gitignore$'
PRIVATE='(^|/)wp-config[^/]*\.php$|\.(sql|sql\.gz|pdf|zip)$|(^|/)\.env$|^test-site/samples/|^test-site/wp/|(^|/)wp-(admin|includes)/|(^|/)wp-content/uploads/'
BRANCH_RE="^iris-wp-auto/issue-${issue}(-[0-9]+)?$"

if ! ref=$(gh api "repos/$repo/pulls/$pr" --jq .head.ref 2>/dev/null); then
  echo "its details could not be read"
  exit 0
fi
if ! [[ "$ref" =~ $BRANCH_RE ]]; then
  echo "it is on \`$ref\`, not an \`iris-wp-auto/issue-$issue\` branch"
  exit 0
fi

files="/tmp/files-$pr.txt"
if ! gh api --paginate "repos/$repo/pulls/$pr/files" \
       --jq '.[] | .filename, (.previous_filename // empty)' > "$files" 2>/dev/null; then
  echo "its files could not be read"
  exit 0
fi
if [ "$(wc -l < "$files")" -gt 300 ]; then
  echo "it changes more than 300 files"
  exit 0
fi

bad=$(grep -E "$FORBIDDEN" "$files" || true)
leak=$(grep -E "$PRIVATE" "$files" | grep -vxF 'test-site/wp/.gitkeep' || true)
if [ -n "$bad$leak" ]; then
  echo "it changes paths the maintainer may not: \`$(printf '%s %s' "$bad" "$leak" | tr '\n' ' ' | sed 's/ *$//')\`"
fi
