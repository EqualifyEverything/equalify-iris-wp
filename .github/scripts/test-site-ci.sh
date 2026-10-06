#!/usr/bin/env bash
# Usage: test-site-ci.sh
#
# Builds the DDEV test site on a CI runner and points it at the mock Iris for good.
#
# The real Iris files conversion problems as public GitHub issues that can quote the document, so
# nothing in CI may reach it. Three layers, because a model with a shell runs on this site:
#
#   - a must-use plugin that answers the API address with the mock's, whatever the setting says;
#   - the production host resolving to nowhere on the runner and inside the web container (the
#     container's copy is lost on `ddev restart`; the must-use plugin is not);
#   - the prompts, which say so.
#
# Everything here is under test-site/wp, which git ignores, so none of it can reach a commit.
set -euo pipefail

cd "$(dirname "$0")/../../test-site"

echo '0.0.0.0 iris.equalify.uic.edu' | sudo tee -a /etc/hosts >/dev/null \
  || echo "::warning::Could not block iris.equalify.uic.edu on the runner."

./setup.sh

mkdir -p wp/wp-content/mu-plugins
cat > wp/wp-content/mu-plugins/ci-mock-iris-only.php <<'PHP'
<?php
// Written by .github/scripts/test-site-ci.sh. In CI this network talks to the mock Iris and nothing
// else: the real one files public GitHub issues.
add_filter( 'pre_site_option_equalify_iris_api_url', fn() => 'http://127.0.0.1:8099/v1' );
PHP

ddev exec "echo '0.0.0.0 iris.equalify.uic.edu' | sudo tee -a /etc/hosts >/dev/null" \
  || echo "::warning::Could not block iris.equalify.uic.edu in the web container; the must-use plugin still points at the mock."

./bin/start-mock-iris.sh
