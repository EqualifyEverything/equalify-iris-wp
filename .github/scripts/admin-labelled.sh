#!/usr/bin/env bash
# Usage: admin-labelled.sh <issue number> <label>
#
# Prints "<login> <time>" and exits 0 when the last add or removal of <label> on the issue is an add
# by someone with admin permission on this repo now. Exits non-zero otherwise, including when the events or
# the permission cannot be read.
#
# GitHub lets anyone with triage access add a label, so the label alone proves nothing. The time is
# when the admin added it: the issue as it stood then is what they approved.
#
# Exits 2 when the title was changed after that time. The body's `lastEditedAt` does not count a
# new title, and the reporter can change their own title, so it is checked here.
set -euo pipefail

n="$1"
label="$2"
repo="${GITHUB_REPOSITORY:-$(gh repo view --json nameWithOwner --jq .nameWithOwner)}"

gh api --paginate "repos/$repo/issues/$n/events" > /tmp/admin-labelled-events.json
last=$(jq -r --arg l "$label" '.[] | select((.event == "labeled" or .event == "unlabeled") and .label.name == $l)
                              | "\(.event) \(.actor.login) \(.created_at)"' /tmp/admin-labelled-events.json \
  | tail -n 1)

read -r event login at <<<"$last" || true
if [ "${event:-}" != "labeled" ] || [ -z "${login:-}" ]; then
  exit 1
fi

permission=$(gh api "repos/$repo/collaborators/$login/permission" --jq .permission 2>/dev/null) || exit 1
if [ "$permission" != "admin" ]; then
  exit 1
fi

renamed=$(jq -r '.[] | select(.event == "renamed") | .created_at' /tmp/admin-labelled-events.json | sort | tail -n 1)
if [ -n "$renamed" ] && [[ "$renamed" > "$at" ]]; then
  echo "$login $at"
  exit 2
fi

echo "$login $at"
