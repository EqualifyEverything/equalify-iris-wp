#!/usr/bin/env bash
#
# Start a fake Equalify Iris inside the web container and point the test site at it.
#
# WHY
#
#   Nothing you tag against it leaves your machine, and it answers in seconds rather
#   than minutes. It is bin/mock-iris.php: every upload converts, and the "tagged" PDF
#   is the original with a comment appended. A file named *fail* fails to convert and
#   one named *encrypted* is refused by the tagger, so both error paths can be tested.
#
#   It dies with the container. Run this again after `ddev restart`.
#
# USAGE
#
#   ./bin/start-mock-iris.sh
#   ./bin/point-at-local-iris.sh --production   # put it back

set -euo pipefail

cd "$(dirname "$0")/.."

ddev exec -d /var/www/html "pkill -f '^php -S 0.0.0.0:8099' || true; nohup php -S 0.0.0.0:8099 bin/mock-iris.php > /tmp/mock-iris.log 2>&1 &"
sleep 1

ddev wp network meta update 1 equalify_iris_api_url "http://127.0.0.1:8099/v1"
ddev wp equalify-iris check
