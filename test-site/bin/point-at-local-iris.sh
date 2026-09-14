#!/usr/bin/env bash
#
# Point the test site at an Equalify Iris running on your own machine.
#
# WHY
#
#   Testing against the production service is slow, uses real conversion capacity, and
#   — because Iris reports conversion problems as public GitHub issues — can put your
#   test documents somewhere public. A local Iris avoids all three.
#
# BEFORE YOU RUN THIS
#
#   Start Iris from its own repo:
#
#       cd ../../equalify-iris && npm run dev
#
#   It listens on port 8080 by default (see its config.yaml).
#
# THE ONE FIDDLY BIT
#
#   Inside the container, "localhost" means the container, not your Mac. So the URL
#   uses host.docker.internal, which DDEV wires up to point at the host machine.
#
# USAGE
#
#   ./bin/point-at-local-iris.sh              # http://host.docker.internal:8080/v1
#   ./bin/point-at-local-iris.sh 3000         # a different port
#   ./bin/point-at-local-iris.sh --production # put it back to the real service

set -euo pipefail

cd "$(dirname "$0")/.."

PRODUCTION_URL="https://iris.equalify.uic.edu/v1"

if [ "${1:-}" = "--production" ]; then
	url="$PRODUCTION_URL"
else
	port="${1:-8080}"
	url="http://host.docker.internal:${port}/v1"
fi

# The plugin stores its settings as network options, which live in the sitemeta table.
# Site 1 is the network itself.
ddev wp network meta update 1 equalify_iris_api_url "$url"

echo
echo "The test site now talks to: ${url}"
echo
echo "Check it worked:"
echo "    ddev wp equalify-iris status"
echo
echo "If calls fail with a connection error, Iris is probably not running, or is on a"
echo "different port. Confirm from inside the container:"
echo "    ddev exec curl -sS -o /dev/null -w '%{http_code}\\n' ${url%/v1}/"
