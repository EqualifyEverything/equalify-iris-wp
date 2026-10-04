#!/usr/bin/env bash
#
# Run the plugin's background job by hand, instead of waiting five minutes for
# WP-Cron. One run works through every site on the network that has something to
# do. A PDF takes a few runs: one to upload it, then one or more while Iris
# converts it, then one to fetch the tagged copy.
#
# It talks to whichever Iris the site points at. Use ./bin/start-mock-iris.sh unless
# you mean to send the sample PDFs somewhere real.
#
# USAGE
#
#   ./tick.sh                                   # one run, then the main site's status
#   ./tick.sh 5                                 # five in a row
#   ./tick.sh 5 https://equalify-iris-test.ddev.site/research   # then that site's status

set -euo pipefail

cd "$(dirname "$0")"

count="${1:-1}"
url="${2:-https://equalify-iris-test.ddev.site}"

ddev wp equalify-iris run --count="$count"

echo
ddev wp equalify-iris status --url="$url"
