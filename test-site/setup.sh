#!/usr/bin/env bash
#
# Build a WordPress multisite for testing the Equalify Iris plugin.
#
# WHAT THIS GIVES YOU
#
#   - A three-site multisite network, running locally over HTTPS.
#   - The plugin mounted live from ../plugin/equalify-iris and network-activated.
#   - Sample PDFs in every site's media library: some linked from published pages,
#     a 30-page PDF that is too long to tag, and some that are not public (linked
#     only from a draft or a private page, or not linked at all), which the plugin
#     must never list or send.
#
# WHAT IT DOES NOT DO
#
#   Tag anything, or point at a real Equalify Iris. Automatic tagging starts off on
#   every site. The script prints how to start the mock Iris at the end.
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

# The plugin is mounted inside wp-content (.ddev/docker-compose.plugin.yaml). On Linux, Docker
# creates a missing mount point as root, which leaves wp-content and uploads unwritable, so make
# them first as you.
mkdir -p wp/wp-content/plugins/equalify-iris wp/wp-content/uploads

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

	note "Turning on pretty permalinks."
	ddev wp rewrite structure '/%postname%/' --quiet
	ddev wp rewrite flush --quiet
else
	note "The network is already installed. Leaving it alone."
fi

# ---------------------------------------------------------------------------
say "Creating the other sites"

# Read the list once: with pipefail, grep -q closing the pipe early can make the
# whole check fail.
existing="$(ddev wp site list --field=url 2>/dev/null || true)"

for site in "research:Research Office" "library:University Library"; do
	slug="${site%%:*}"
	title="${site#*:}"

	if grep -q "/${slug}/" <<<"$existing"; then
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
make_pdf "very-long-manual.pdf"   30 "A Manual Too Long To Convert"
make_pdf "unpublished-notes.pdf"  2  "Unpublished Notes"
make_pdf "private-memo.pdf"       1  "Private Memo"
make_pdf "unlinked-file.pdf"      1  "Nobody Links To This"
make_pdf "field-brochure.pdf"     1  "Linked From A Custom Field"
make_pdf "category-guide.pdf"     1  "Linked From A Category Description"
make_pdf "menu-handout.pdf"       1  "Linked From A Menu"
make_pdf "widget-flyer.pdf"       1  "Linked From A Widget"

# ---------------------------------------------------------------------------
say "Adding content"

# Upload one PDF to a site and return the URL WordPress gives it.
import_pdf() {
	local url="$1" file="$2"

	local id
	id="$(ddev wp media import "${CONTAINER_SAMPLES}/${file}" --url="$url" --porcelain | tail -1 | tr -d '\r')"
	case "$id" in
		'' | *[!0-9]*)
			echo "Could not add ${file} to ${url}. WordPress said: ${id:-nothing}" >&2
			return 1
			;;
	esac

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

	# --- A published page with two PDFs on it, and one on another domain, which the
	# --- plugin must never touch.
	if ! ddev wp post list --post_type=page --field=post_title --url="$url" 2>/dev/null | grep -qxF "Documents"; then
		local first second
		first="$(import_pdf "$url" "$1")"
		second="$(import_pdf "$url" "$2")"

		make_page "$url" publish "Documents" \
			"<p>Two documents on one published page.</p>
<p><a href=\"${first}\">Read the first document</a></p>
<p><a href=\"${second}\">Read the second document</a></p>
<p>And <a href=\"https://example.org/somewhere-else.pdf\">a PDF on another site</a>, which the plugin must not touch.</p>"
	else
		note "\"Documents\" already exists on ${url}."
	fi

	# --- The same PDF linked twice, so both links have to switch.
	if ! ddev wp post list --post_type=page --field=post_title --url="$url" 2>/dev/null | grep -qxF "Linked Twice"; then
		local repeat
		repeat="$(import_pdf "$url" "$1")"

		make_page "$url" publish "Linked Twice" \
			"<p>The same PDF, linked twice: <a href=\"${repeat}\">once here</a> and <a href=\"${repeat}\">once here</a>.</p>"
	else
		note "\"Linked Twice\" already exists on ${url}."
	fi

	# --- A 30-page PDF, which should fail with "too long" before it is ever uploaded.
	if ! ddev wp post list --post_type=page --field=post_title --url="$url" 2>/dev/null | grep -qxF "A Very Long Manual"; then
		local long_pdf
		long_pdf="$(import_pdf "$url" "very-long-manual.pdf")"

		make_page "$url" publish "A Very Long Manual" \
			"<p><a href=\"${long_pdf}\">A 30-page manual</a>. Equalify Iris stops at 25 pages, so this one cannot be tagged.</p>"
	else
		note "\"A Very Long Manual\" already exists on ${url}."
	fi

	# --- PDFs that are not public. None of these may appear in the plugin's list.
	if ! ddev wp post list --post_type=page --post_status=any --field=post_title --url="$url" 2>/dev/null | grep -qxF "Draft Page"; then
		local draft_pdf private_pdf
		draft_pdf="$(import_pdf "$url" "unpublished-notes.pdf")"
		private_pdf="$(import_pdf "$url" "private-memo.pdf")"
		import_pdf "$url" "unlinked-file.pdf" >/dev/null

		make_page "$url" draft "Draft Page" \
			"<p><a href=\"${draft_pdf}\">Notes</a> on a page nobody can see yet. Publish the page and the PDF appears in the list.</p>"
		make_page "$url" private "Private Page" \
			"<p><a href=\"${private_pdf}\">A private memo</a>.</p>"
	else
		note "\"Draft Page\" already exists on ${url}."
	fi
}

# PDFs linked from somewhere other than a page's content: a custom field, a category
# description, and the site's navigation. The main and research sites keep the block
# theme, so their menu is a Navigation block; the library switches to a classic theme
# for a classic menu and a widget.
seed_elsewhere() {
	local url="$1"

	if ddev wp post list --post_type=page --field=post_title --url="$url" 2>/dev/null | grep -qxF "Custom Field"; then
		note "The PDFs outside page content already exist on ${url}."
		return
	fi

	local field category menu
	field="$(import_pdf "$url" "field-brochure.pdf")"
	category="$(import_pdf "$url" "category-guide.pdf")"
	menu="$(import_pdf "$url" "menu-handout.pdf")"

	local page
	page="$(ddev wp post create --post_type=page --post_status=publish --post_title="Custom Field" \
		--post_content="<p>The PDF for this page is in its brochure_pdf custom field, not here.</p>" --porcelain --url="$url" | tail -1 | tr -d '\r')"
	ddev wp post meta add "$page" brochure_pdf "$field" --url="$url" --quiet

	ddev wp term update category 1 --description="<a href=\"${category}\">A guide to this category</a>" --url="$url" --quiet

	if [ "$url" = "${SITE_URL}/library" ]; then
		local flyer
		flyer="$(import_pdf "$url" "widget-flyer.pdf")"

		ddev wp theme install twentytwentyone --activate --url="$url" --quiet 2>/dev/null || ddev wp theme activate twentytwentyone --url="$url" --quiet
		ddev wp menu create "Main" --url="$url" --porcelain >/dev/null
		ddev wp menu item add-custom main "Handout (PDF)" "$menu" --url="$url" --quiet
		ddev wp menu location assign main primary --url="$url" --quiet
		ddev wp widget add custom_html sidebar-1 1 --title="Flyer" --content="<a href=\"${flyer}\">This week's flyer</a>" --url="$url" --quiet
	else
		ddev wp post create --post_type=wp_navigation --post_status=publish --post_title="Header navigation" \
			--post_content="<!-- wp:navigation-link {\"label\":\"Handout (PDF)\",\"url\":\"${menu}\",\"kind\":\"custom\"} /-->" --url="$url" --quiet
	fi

	note "Linked PDFs from a custom field, a category description and the navigation on ${url}."
}

seed_site "$SITE_URL"                 "annual-report.pdf"     "meeting-minutes.pdf"
seed_site "${SITE_URL}/research"      "research-findings.pdf" "annual-report.pdf"
seed_site "${SITE_URL}/library"       "reading-list.pdf"      "meeting-minutes.pdf"

seed_elsewhere "$SITE_URL"
seed_elsewhere "${SITE_URL}/research"
seed_elsewhere "${SITE_URL}/library"

# ---------------------------------------------------------------------------
say "Done"

cat <<INFO

    Network dashboard   ${SITE_URL}/wp-admin/network/
    Network screen      ${SITE_URL}/wp-admin/network/admin.php?page=equalify-iris
    Site screen         ${SITE_URL}/wp-admin/admin.php?page=equalify-iris
    Username            ${ADMIN_USER}
    Password            ${ADMIN_PASS}

    The three sites:
      ${SITE_URL}
      ${SITE_URL}/research
      ${SITE_URL}/library

    NEXT STEPS

      1. Point the site at a fake Iris, so no PDF leaves your machine:

             ./bin/start-mock-iris.sh

      2. Tag something: Equalify Iris → "Send to Iris for Tagging" on a PDF, or turn
         on automatic tagging on the same screen.

      3. Run the background job by hand instead of waiting for WP-Cron:

             ./tick.sh          # one run
             ./tick.sh 5        # five in a row

      4. Check on it:

             ddev wp equalify-iris status

    Useful things:

      ddev launch /wp-admin/network/          Open the dashboard in a browser
      ddev exec tail -f wp-content/debug.log  Watch PHP notices
      ./reset.sh                              Throw it all away and start again

INFO
