#!/usr/bin/env bash
#
# One PDF from upload to tagged link, against the mock Iris, on the test site.
#
# WHAT IT CHECKS
#
#   - A PDF linked only from a draft page is refused.
#   - A PDF linked from a published page is queued, uploaded, tagged and saved
#     within a few runs of the job.
#   - Logged out, the page links to the tagged copy, and the tagged copy is a PDF.
#   - The plugin wrote nothing to debug.log while it did all that.
#
# WHAT IT LEAVES BEHIND
#
#   Nothing it made: its pages and PDFs are deleted, and the API address is put
#   back. It does run the whole network's job, so on a site with automatic tagging
#   on, other PDFs may be tagged by the mock while it runs.
#
#   It refuses to start while PDFs are waiting at a real Iris, because pointing at
#   the mock would lose them.
#
# USAGE
#
#   ./setup.sh && ./smoke.sh

set -euo pipefail

cd "$(dirname "$0")"

SITE_URL="https://equalify-iris-test.ddev.site"
MOCK_URL="http://127.0.0.1:8099/v1"
CONTAINER_SAMPLES="/var/www/html/samples"
RUNS=6

ok() {
	printf 'ok    %s\n' "$1"
}

# Any other command that fails ends the script under `set -e`. Say which, rather than stopping
# with no message.
trap 'printf "FAIL  line %s: %s\n" "$LINENO" "$BASH_COMMAND" >&2' ERR

fail() {
	printf 'FAIL  %s\n' "$1" >&2
	exit 1
}

wpq() {
	ddev wp "$@" 2>&1 | tr -d '\r'
}

# ---------------------------------------------------------------------------
# Where the network points now, so it can be put back.

before="$(ddev wp network meta get 1 equalify_iris_api_url 2>/dev/null | tr -d '\r' || true)"

if [ "$before" != "$MOCK_URL" ]; then
	at_iris="$(wpq eval 'echo Equalify_Iris_Runner::count_at_iris();' | tail -1)"

	if [ "${at_iris:-0}" != "0" ]; then
		fail "${at_iris} PDFs are waiting at ${before:-the default Iris}. Let them finish, or they would be lost to the mock."
	fi
fi

page=""
draft_page=""
pdf=""
draft_pdf=""

cleanup() {
	for id in $page $draft_page $pdf $draft_pdf; do
		ddev wp post delete "$id" --force --quiet >/dev/null 2>&1 || true
	done

	if [ -z "$before" ]; then
		ddev wp network meta delete 1 equalify_iris_api_url >/dev/null 2>&1 || true
	else
		ddev wp network meta update 1 equalify_iris_api_url "$before" >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

./bin/start-mock-iris.sh >/dev/null || fail "The mock Iris did not start. See /tmp/mock-iris.log in the web container."
ok "mock Iris answering at ${MOCK_URL}"

log_lines="$(ddev exec "{ wc -l < wp/wp-content/debug.log; } 2>/dev/null || echo 0" | tr -d '\r ')"

# ---------------------------------------------------------------------------
# A PDF on a draft page, and one on a published page.

ddev exec php /var/www/html/bin/make-sample-pdf.php "${CONTAINER_SAMPLES}/smoke-test.pdf" 2 "Smoke Test" >/dev/null
ddev exec php /var/www/html/bin/make-sample-pdf.php "${CONTAINER_SAMPLES}/smoke-draft.pdf" 1 "Smoke Test Draft" >/dev/null

pdf="$(ddev wp media import "${CONTAINER_SAMPLES}/smoke-test.pdf" --porcelain 2>/dev/null | tail -1 | tr -d '\r')"
draft_pdf="$(ddev wp media import "${CONTAINER_SAMPLES}/smoke-draft.pdf" --porcelain 2>/dev/null | tail -1 | tr -d '\r')"
pdf_url="$(wpq eval "echo wp_get_attachment_url( ${pdf} );" | tail -1)"
draft_url="$(wpq eval "echo wp_get_attachment_url( ${draft_pdf} );" | tail -1)"

slug="smoke-test-$(date +%s)"
page="$(ddev wp post create --post_type=page --post_status=publish --post_title="Smoke Test" --post_name="$slug" \
	--post_content="<p><a href=\"${pdf_url}\">The smoke test PDF</a></p>" --porcelain 2>/dev/null | tail -1 | tr -d '\r')"
draft_page="$(ddev wp post create --post_type=page --post_status=draft --post_title="Smoke Test Draft" \
	--post_content="<p><a href=\"${draft_url}\">Not public yet</a></p>" --porcelain 2>/dev/null | tail -1 | tr -d '\r')"

# ---------------------------------------------------------------------------
# Queue them. A site that has not read its content yet needs a run first.

# Captured, then searched: `grep -q` stops reading early, and under pipefail that can turn a match
# into a failure.
out="$(wpq equalify-iris tag "$pdf" || true)"
if ! grep -qF "Queued ${pdf}." <<<"$out"; then
	wpq equalify-iris run >/dev/null
	out="$(wpq equalify-iris tag "$pdf" || true)"
	grep -qF "Queued ${pdf}." <<<"$out" || fail "The PDF on a published page was not queued."
fi
ok "the PDF on a published page is queued"

out="$(wpq equalify-iris tag "$draft_pdf" || true)"
if grep -qF "Queued ${draft_pdf}." <<<"$out"; then
	fail "The PDF on a draft page was queued. Only PDFs visitors can reach may be sent."
fi
ok "the PDF on a draft page is refused"

# ---------------------------------------------------------------------------
# Run the job until it is tagged. A site with a PDF at Iris is next due in two
# minutes; waking it stands in for the wait.

status=""
for i in $(seq 1 "$RUNS"); do
	[ "$i" -gt 1 ] && wpq eval 'Equalify_Iris_Runner::wake();' >/dev/null
	wpq equalify-iris run | sed 's/^/      /'
	status="$(wpq eval "echo Equalify_Iris_Tagger::status( ${pdf} );" | tail -1)"
	[ "$status" = "tagged" ] && break
	[ "$status" = "failed" ] && break
done

if [ "$status" != "tagged" ]; then
	error="$(wpq eval "echo Equalify_Iris_Tagger::error( ${pdf} );" | tail -1)"
	fail "After ${RUNS} runs the PDF is '${status}'. ${error}"
fi
ok "tagged after ${i} runs"

# ---------------------------------------------------------------------------
# What a visitor sees.

tagged_url="$(wpq eval "echo Equalify_Iris_Tagger::tagged_url( ${pdf} );" | tail -1)" \
	|| fail "Could not read the tagged address: ${tagged_url:-no output}"
[ -n "$tagged_url" ] || fail "The PDF is tagged but has no tagged address."

html="$(curl -sk "${SITE_URL}/${slug}/")" || fail "Logged out, ${SITE_URL}/${slug}/ did not load (curl exit $?)."
tagged_path="${tagged_url#*://*/}"
grep -qF "${tagged_path%%\?*}" <<<"$html" || fail "Logged out, the page does not link to the tagged copy (${tagged_url})."
grep -qF "${pdf_url}\"" <<<"$html" && fail "Logged out, the page still links to the original PDF."
ok "logged out, the page links to the tagged copy"

# To a file, not into `head`: on a large PDF curl would die writing to a closed pipe.
curl -sk -o "${TMPDIR:-/tmp}/smoke-tagged.pdf" "${tagged_url//&#038;/&}" || true
head="$(head -c 5 "${TMPDIR:-/tmp}/smoke-tagged.pdf" 2>/dev/null || true)"
rm -f "${TMPDIR:-/tmp}/smoke-tagged.pdf"
[ "$head" = "%PDF-" ] || fail "The tagged copy is not served as a PDF (${tagged_url})."
ok "the tagged copy is a PDF"

# ---------------------------------------------------------------------------
# Nothing the plugin said in debug.log.

new_log="$(ddev exec "tail -n +$((log_lines + 1)) wp/wp-content/debug.log 2>/dev/null || true" | tr -d '\r')"
if grep -i 'equalify-iris' <<<"$new_log"; then
	fail "The plugin wrote the lines above to debug.log."
fi
ok "nothing from the plugin in debug.log"

echo
echo "Smoke test passed."
