#!/usr/bin/env bash
# Usage: install-ddev.sh
#
# Installs one version of DDEV on a Linux CI runner, checked against its SHA-256, rather than
# running the install script from ddev.com, which nothing pins.
#
# To move to a newer DDEV, take both values from the release's checksums.txt.
set -euo pipefail

version='1.24.10'
sha256='c04cb3eb36f4f36b6e18533570a66a60ba65bfde1dfdfc9e465816661da30494'

curl -fsSL --retry 3 -o /tmp/ddev.tgz \
  "https://github.com/ddev/ddev/releases/download/v${version}/ddev_linux-amd64.v${version}.tar.gz"
echo "${sha256}  /tmp/ddev.tgz" | sha256sum -c -
sudo tar -xzf /tmp/ddev.tgz -C /usr/local/bin ddev ddev-hostname mkcert

ddev config global --instrumentation-opt-in=false --omit-containers=ddev-ssh-agent
ddev version | head -3
