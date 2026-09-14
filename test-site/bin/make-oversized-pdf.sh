#!/usr/bin/env bash
#
# Make a PDF that is over the 50 MB limit, to test the "too big" path.
#
# WHY IT IS A SEPARATE SCRIPT
#
#   Because it writes 51 MB to disk and setup.sh should not do that every time. Most
#   testing does not need it. Reach for it when you are specifically checking that an
#   oversized file is refused politely, before any upload is attempted.
#
# HOW IT WORKS
#
#   It builds an ordinary 2-page PDF and then appends padding after the end-of-file
#   marker. PDF readers ignore anything after %%EOF, so the file stays valid and
#   readable — it is just enormous. That is exactly the case we want: something the
#   plugin should refuse on size alone, not because it is broken.
#
#   Two pages, not thirty, so the size rule is what trips and not the page-count rule.
#
# USAGE
#
#   ./bin/make-oversized-pdf.sh
#
# THEN
#
#   Upload samples/oversized.pdf to a site, link it from a published page, and run
#   ./tick.sh a few times. Expect status "too big" — with no Retry button, because
#   retrying cannot help.

set -euo pipefail

cd "$(dirname "$0")/.."

mkdir -p samples

echo "==> Building a 2-page PDF"
ddev exec php /var/www/html/bin/make-sample-pdf.php /var/www/html/samples/oversized.pdf 2 "Oversized Document"

echo "==> Padding it past 50 MB (this takes a moment)"

# 51 MB of padding, written inside the container so it lands on the same filesystem.
ddev exec bash -c "head -c 53477376 /dev/zero | tr '\\0' ' ' >> /var/www/html/samples/oversized.pdf"

echo
ls -lh samples/oversized.pdf
echo
echo "Upload it to a site and link it from a published page:"
echo "    ddev wp media import /var/www/html/samples/oversized.pdf --url=https://equalify-iris-test.ddev.site"
