#!/usr/bin/env bash
#
# Run the plugin's background job by hand.
#
# WHY YOU NEED THIS
#
#   In production the job runs every five minutes on a schedule. Waiting five minutes
#   between each step of a test is intolerable, so this runs a tick immediately.
#
#   Each tick does a small, capped amount of work — at most one upload, five status
#   checks, two imports, twenty posts swept. So getting a document all the way from
#   "found" to "published" takes several ticks, which is why this takes a count.
#
# USAGE
#
#   ./tick.sh          # one tick
#   ./tick.sh 10       # ten ticks, one after another
#
# WHAT TO EXPECT
#
#   The first few ticks mostly sweep, because there is nothing queued yet. Then
#   uploads start. Then a wait, because Iris takes minutes — ticks during that wait
#   look like they are doing nothing, and they are: they are polling.

set -euo pipefail

cd "$(dirname "$0")"

count="${1:-1}"

ddev wp equalify-iris tick --count="$count"

echo
ddev wp equalify-iris status
