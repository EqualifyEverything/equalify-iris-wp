#!/usr/bin/env bash
#
# Build a WordPress multisite for testing the Equalify Iris plugin.
#
# WHAT THIS GIVES YOU
#
#   - A three-site multisite network, running locally over HTTPS.
#   - The plugin mounted live from ../plugin/equalify-iris and network-activated.
#   - Sample PDFs on every site, linked from published pages — plus a draft, a private
#     page, and a 30-page PDF, so you can check that the things the plugin is supposed
#     to ignore are actually ignored.
#
# WHAT IT DOES NOT DO
#
#   Connect to Equalify Iris. That needs your GitHub account, so it is a step you do
#   yourself once the site is up. The script prints the command at the end.
#
# SAFE TO RUN TWICE
#
#   Yes. It skips anything already done. To start completely fresh, run ./reset.sh.
#
# USAGE
#
#   ./setup.sh

set -euo pipefail

cd "$(dirname "$0")"

SITE_URL="https://equalify-iris-test.ddev.site"
ADMIN_USER="admin"
ADMIN_PASS="admin"
ADMIN_EMAIL="admin@example.com"

# Where sample PDFs are written. Inside the project so the container can see them.
SAMPLES="samples"
CONTAINER_SAMPLES="/var/www/html/samples"

say() {
	printf '\n\033[1m==> %s\033[0m\n' "$1"
}

note() {
	printf '    %s\n' "$1"
}

# ---------------------------------------------------------------------------
say "Checking what we need"

if ! command -v ddev >/dev/null 2>&1; then
	echo "DDEV is not installed. On a Mac: brew install ddev/ddev/ddev" >&2
	echo "Then make sure Docker is running (Docker Desktop, colima, or OrbStack)." >&2
	exit 1
fi

if ! docker info >/dev/null 2>&1; then
	echo "Docker is not running. Start Docker Desktop, or: colima start" >&2
	exit 1
fi

note "DDEV $(ddev version | awk '/DDEV version/ {print $4}') and Docker are both available."

# ---------------------------------------------------------------------------
say "Starting the containers"

# -y so it never stops to ask. First run downloads a few hundred megabytes of
# images and can take several minutes; later runs take seconds.
ddev start -y

# ---------------------------------------------------------------------------
say "Installing WordPress"

if [ ! -f wp/wp-load.php ]; then
	note "Downloading WordPress core."
	ddev wp core download
else
	note "WordPress core is already here. Leaving it alone."
fi

if [ ! -f wp/wp-config.php ]; then
	# Rare: DDEV normally writes wp-config.php itself when it starts a WordPress
	# project, and puts the database settings in wp-config-ddev.php next to it. This
	# branch is only here for the case where it did not.
	note "Writing wp-config.php."

	ddev wp config create --dbname=db --dbuser=db --dbpass=db --dbhost=db --skip-check
else
	note "wp-config.php is already here — DDEV writes one when it starts."
fi

# Send PHP notices to a file instead of into the page.
#
# This matters more than it sounds. A notice printed mid-response corrupts JSON,
# breaks redirects, and buries the actual bug in the middle of a page of HTML. DDEV
# already turns WP_DEBUG on; these two are the ones it leaves to us.
#
# Read them with: ddev exec tail -f wp-content/debug.log
ddev wp config set WP_DEBUG_LOG true --raw --type=constant --quiet 2>/dev/null \
	|| note "Could not set WP_DEBUG_LOG automatically. Add it to wp/wp-config.php by hand."
ddev wp config set WP_DEBUG_DISPLAY false --raw --type=constant --quiet 2>/dev/null \
	|| note "Could not set WP_DEBUG_DISPLAY automatically. Add it to wp/wp-config.php by hand."

if ! ddev wp core is-installed --network 2>/dev/null; then
	note "Installing the network. This creates the database tables."

	# Subdirectory multisite (site.test/research) rather than subdomain
	# (research.site.test), because *.ddev.site only resolves one level deep — a
	# hostname like research.equalify-iris-test.ddev.site would not resolve without
	# editing /etc/hosts on every machine that runs these tests.
	ddev wp core multisite-install \
		--url="$SITE_URL" \
		--title="Equalify Iris Test Network" \
		--admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASS" \
		--admin_email="$ADMIN_EMAIL" \
		--skip-email

	note "Turning on pretty permalinks, which document pages need to get readable URLs."
	ddev wp rewrite structure '/%postname%/' --quiet
	ddev wp rewrite flush --quiet
else
	note "The network is already installed. Leaving it alone."
fi

# ---------------------------------------------------------------------------
say "Creating the other sites"

for site in "research:Research Office" "library:University Library"; do
	slug="${site%%:*}"
	title="${site#*:}"

	if ddev wp site list --field=url 2>/dev/null | grep -q "/${slug}/"; then
		note "Site '${slug}' already exists."
		continue
	fi

	note "Creating site '${slug}'."
	ddev wp site create --slug="$slug" --title="$title" --quiet
done

# ---------------------------------------------------------------------------
say "Turning on the plugin"

# The plugin is bind-mounted from ../plugin/equalify-iris — see
# .ddev/docker-compose.plugin.yaml. Editing the real source changes the test site
# immediately, with no copying and no chance of testing a stale copy.
if ddev wp plugin is-active equalify-iris --network 2>/dev/null; then
	note "Already network-activated."
else
	ddev wp plugin activate equalify-iris --network
fi

# ---------------------------------------------------------------------------
say "Making sample PDFs"

mkdir -p "$SAMPLES"

make_pdf() {
	local file="$1" pages="$2" title="$3"

	if [ -f "${SAMPLES}/${file}" ]; then
		note "${file} already exists."
		return
	fi

	ddev exec php /var/www/html/bin/make-sample-pdf.php "${CONTAINER_SAMPLES}/${file}" "$pages" "$title"
}

make_pdf "annual-report.pdf"      4  "Annual Accessibility Report"
make_pdf "meeting-minutes.pdf"    2  "Committee Meeting Minutes"
make_pdf "research-findings.pdf"  6  "Research Findings 2026"
make_pdf "reading-list.pdf"       3  "Reading List"
make_pdf "unpublished-notes.pdf"  2  "Notes On A Draft Page"
make_pdf "private-memo.pdf"       2  "A Private Memo"
make_pdf "very-long-manual.pdf"   30 "A Manual Too Long To Convert"

# ---------------------------------------------------------------------------
say "Adding content"

# Upload one PDF to a site and return the URL WordPress gives it.
import_pdf() {
	local url="$1" file="$2"

	local id
	id="$(ddev wp media import "${CONTAINER_SAMPLES}/${file}" --url="$url" --porcelain 2>/dev/null | tail -1 | tr -d '\r')"

	ddev wp eval "echo wp_get_attachment_url( ${id} );" --url="$url" 2>/dev/null | tail -1 | tr -d '\r'
}

# Create a page on a site, if a page with that title is not already there.
make_page() {
	local url="$1" status="$2" title="$3" content="$4"

	if ddev wp post list --post_type=page --field=post_title --url="$url" 2>/dev/null | grep -qxF "$title"; then
		note "\"${title}\" already exists on ${url}."
		return
	fi

	ddev wp post create \
		--post_type=page \
		--post_status="$status" \
		--post_title="$title" \
		--post_content="$content" \
		--url="$url" \
		--quiet

	note "Created \"${title}\" (${status}) on ${url}."
}

seed_site() {
	local url="$1"
	shift

	# --- A published page with two PDFs on it. The ordinary case, and the one that
	# --- should end up with two icons.
	if ! ddev wp post list --post_type=page --field=post_title --url="$url" 2>/dev/null | grep -qxF "Documents"; then
		local first second
		first="$(import_pdf "$url" "$1")"
		second="$(import_pdf "$url" "$2")"

		make_page "$url" publish "Documents" \
			"<p>Two documents on one published page, which should end up with two icons.</p>
<p><a href=\"${first}\">Read the first document</a></p>
<p><a href=\"${second}\">Read the second document</a></p>
<p>And <a href=\"https://example.org/somewhere-else.pdf\">a PDF on another site</a>, which the plugin must not touch.</p>"
	else
		note "\"Documents\" already exists on ${url}."
	fi

	# --- The same PDF linked twice on one page, to prove it is queued once and gets
	# --- an icon in both places.
	if ! ddev wp post list --post_type=page --field=post_title --url="$url" 2>/dev/null | grep -qxF "Linked Twice"; then
		local repeat
		repeat="$(import_pdf "$url" "$1")"

		make_page "$url" publish "Linked Twice" \
			"<p>The same PDF, linked twice: <a href=\"${repeat}\">once here</a> and <a href=\"${repeat}\">once here</a>.</p>"
	else
		note "\"Linked Twice\" already exists on ${url}."
	fi

	# --- A draft. Its PDF must never be converted.
	if ! ddev wp post list --post_type=page --post_status=draft --field=post_title --url="$url" 2>/dev/null | grep -qxF "Draft Page"; then
		local draft_pdf
		draft_pdf="$(import_pdf "$url" "unpublished-notes.pdf")"

		make_page "$url" draft "Draft Page" \
			"<p>A draft. <a href=\"${draft_pdf}\">This PDF</a> must never be converted.</p>"
	else
		note "\"Draft Page\" already exists on ${url}."
	fi

	# --- A private page. Same rule.
	if ! ddev wp post list --post_type=page --post_status=private --field=post_title --url="$url" 2>/dev/null | grep -qxF "Private Page"; then
		local private_pdf
		private_pdf="$(import_pdf "$url" "private-memo.pdf")"

		make_page "$url" private "Private Page" \
			"<p>Private. <a href=\"${private_pdf}\">This PDF</a> must never be converted either.</p>"
	else
		note "\"Private Page\" already exists on ${url}."
	fi

	# --- A 30-page PDF, which should end up marked "too long" rather than failed.
	if ! ddev wp post list --post_type=page --field=post_title --url="$url" 2>/dev/null | grep -qxF "A Very Long Manual"; then
		local long_pdf
		long_pdf="$(import_pdf "$url" "very-long-manual.pdf")"

		make_page "$url" publish "A Very Long Manual" \
			"<p><a href=\"${long_pdf}\">A 30-page manual</a>. Equalify Iris stops at 25 pages, so this should be listed as too long — not as a failure.</p>"
	else
		note "\"A Very Long Manual\" already exists on ${url}."
	fi
}

seed_site "$SITE_URL"                 "annual-report.pdf"     "meeting-minutes.pdf"
seed_site "${SITE_URL}/research"      "research-findings.pdf" "annual-report.pdf"
seed_site "${SITE_URL}/library"       "reading-list.pdf"      "meeting-minutes.pdf"

# ---------------------------------------------------------------------------
say "Done"

cat <<INFO

    Network dashboard   ${SITE_URL}/wp-admin/network/
    Plugin screen       ${SITE_URL}/wp-admin/network/admin.php?page=equalify-iris
    Username            ${ADMIN_USER}
    Password            ${ADMIN_PASS}

    The three sites:
      ${SITE_URL}
      ${SITE_URL}/research
      ${SITE_URL}/library

    NEXT STEPS

      1. Connect to Equalify Iris, which needs your GitHub account:

             ddev wp equalify-iris connect

         Or press "Connect with GitHub" on the plugin's Settings screen.

      2. Start the process:

             ddev wp equalify-iris start

      3. Run the background job by hand, as often as you like:

             ./tick.sh          # one tick
             ./tick.sh 10       # ten ticks in a row

      4. Check on it:

             ddev wp equalify-iris status
             ddev wp equalify-iris doctor
             ddev wp equalify-iris list
             ddev wp equalify-iris log

    Useful things:

      ddev launch /wp-admin/network/          Open the dashboard in a browser
      ddev logs -f                            Watch the web server log
      ddev exec tail -f wp-content/debug.log  Watch PHP notices
      ./reset.sh                              Throw it all away and start again

INFO
