# Equalify Iris for WordPress

**Every PDF on your network gets an accessible HTML version, automatically.**

A screen reader user clicks a PDF link and often finds nothing usable: no headings, no table
structure, no reading order. This plugin fixes that without asking anyone to do extra work. It
finds the PDFs linked from published pages across a WordPress multisite network, sends each one to
[Equalify Iris](https://github.com/EqualifyEverything/equalify-iris), and publishes the accessible
HTML that comes back as a real web page. Next to every PDF link, visitors then see a small icon
that opens that version.

Editors do nothing. They never see the plugin. One super admin turns it on once.

---

## What you need before you start

| Requirement | Why |
| --- | --- |
| **WordPress multisite** | The plugin is network-activated only. Its whole interface lives in the Network Admin dashboard. |
| **PHP 7.4 or newer** | Uses typed properties and return types. |
| **Nothing to sign up for** | Equalify Iris has no accounts. Against an open deployment — including the public one — the plugin works out of the box. A gated deployment needs one shared secret from whoever runs it. |
| **A real cron job (strongly recommended)** | WordPress's built-in cron only runs when somebody visits the site. See [Make the background job reliable](#4-make-the-background-job-reliable). |

You do **not** need a PDF library, ImageMagick, Ghostscript, or any PHP extension beyond what
WordPress itself requires. cURL is used when available and the plugin falls back gracefully when
it is not.

---

## Install it

Copy the `equalify-iris` folder into your network's plugin directory:

```
wp-content/plugins/equalify-iris/
```

Then activate it **for the network**:

- **In the browser:** Network Admin → Plugins → Equalify Iris → *Network Activate*.
- **On the command line:** `wp plugin activate equalify-iris --network`

Activating creates two database tables (`wp_equalify_iris_documents` and
`wp_equalify_iris_sightings`) and registers the background job. It does not start converting
anything yet.

If you try to activate it on a single site instead of the network, the plugin stops and tells you
why. That is deliberate — there is nowhere for its controls to live on a single site.

---

## Get to your first converted PDF

Four steps. Budget about ten minutes, most of which is waiting for Iris.

### 1. Check that Equalify Iris will accept you

Go to **Network Admin → Equalify Iris → Settings** and press **Check the connection**.

**There is nothing to sign into.** Equalify Iris has no user accounts — it holds its own GitHub
credential and files everything as its own account. Most deployments, including the public one, are
[open](GLOSSARY.md#open-deployment): you need no credential at all, and this check should simply
confirm it.

If instead it says the deployment **needs a shared API token**, that deployment is
[gated](GLOSSARY.md#gated-deployment) — its operator has set a secret to keep strangers out. Ask them
for it and paste it into the **A shared API token** box on the same screen. It is tested as soon as
you save, so you know straight away whether it is right.

That secret is a door key, not an account. It identifies nobody, and it does not change how
conversions are attributed.

**Keep it out of the database.** If you have one, prefer `wp-config.php`:

```php
define( 'EQUALIFY_IRIS_API_TOKEN', '...' );
```

The plugin uses the constant if it is set, and the Settings screen says so instead of offering a
field.

### 2. Press Start

Go to **Overview** and press **Start**.

This begins the [sweep](GLOSSARY.md#sweep): a slow walk through every site in the network, a few
published posts at a time, collecting the PDFs they link to. On a large network the sweep takes
hours or days. That is intentional. It is designed to be invisible, not fast.

### 3. Watch the Overview screen

The Overview screen shows, in order:

1. **Anything that needs your attention** — Iris refusing you, tables missing, the background job
   not running, Iris unreachable.
2. **The Start/Stop switch** and whether the plugin is watching new content.
3. **Progress** — how far the sweep has got, and how many documents are at each stage.
4. **Detail** — the counts, and a measured estimate of how long the rest will take.

The estimate is measured from how many documents actually finished in the last 24 hours, not
calculated from an assumption. Early on it says it does not know yet, because it does not.

### 4. Make the background job reliable

All the real work happens in a WP-Cron job that runs every five minutes and does a small, capped
amount of work each time. WordPress's own cron is not a real scheduler: it only fires when
somebody loads a page. On a quiet network, nothing happens for hours.

Fix that once, with a real cron entry:

```
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --url=https://your-main-site.example
```

The exact line for your install, with the paths filled in, is printed on the Overview screen and
by `wp equalify-iris doctor`.

While you are there, it is worth also disabling the visitor-triggered version in `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

---

## What you will see when it works

On any published page that links to a converted PDF, an icon appears immediately after the link:

```html
<a href="/wp-content/uploads/2026/01/report.pdf">the report</a>
<a href="https://example.org/equalify-iris/1184/report/" class="equalify-iris-icon-link">
   <svg aria-hidden="true" focusable="false">…</svg>
   <span class="equalify-iris-icon-label">Accessible version of report</span></a>
```

The original link is never changed, moved, or replaced. The icon is a second, separate link.
Anyone who wants the PDF still gets the PDF. A screen reader hears "An accessible version of this
PDF is linked next." just before the PDF link, and then "Accessible version of report" for the icon.

The icon is there only while the accessible version is. Unpublish the page, give it a password,
take the link out, or deactivate the plugin, and the icon goes with it — and the accessible
version's address answers 404 rather than a copy nobody should be able to reach.

Following the icon lands on the accessible version. It is a viewer, not a post: the theme's header,
footer, stylesheets and web fonts are all left out, and what fills the page is the document.

Above it sits a quiet bar holding the document's title and two panels, both closed on arrival:

- **Contents** — the table of contents, when the document has enough headings to need one.
- **About this accessible version of a PDF** — two sentences saying where the page came from and what
  to do if it looks wrong, a link to the original with its page count and size, a link back to a page
  it appears on, a link to the site, and the date it was converted.

Everything the plugin has to say is therefore one labelled click away rather than in front of the
text. The panels are `<details>` elements: the plugin adds no JavaScript, so they work with scripting
off, and so does everything else on the page.

The document text is the HTML Iris returned, inserted as it came. That means its heading levels are
Iris's, not ours: a converted document commonly repeats its title as an `<h1>` on every page, so the
page can carry several. The table of contents is built from `<h2>` and below and is unaffected.

---

## The four screens

| Screen | What it is for |
| --- | --- |
| **Overview** | Start, stop, current state, progress, problems. The screen you check. |
| **Documents** | Every PDF the plugin knows about, filterable by stage, site, and filename. Where you retry failures. |
| **Settings** | The connection to Iris, how hard the plugin is allowed to work, what gets converted, and what Iris shares publicly. |
| **Activity Log** | The last few hundred things that happened, newest first, in sentences. |

All four require the `manage_network_options` capability — in practice, being a super admin.

---

## From the command line

```
wp equalify-iris status      # Is it on? Will Iris accept us? How many documents where?
wp equalify-iris doctor      # Eight checks, each with the fix if it fails
wp equalify-iris connect     # Ask Iris whether it will accept us
                             #   --token=<secret> for a gated deployment
                             #   --forget          to remove a stored one
wp equalify-iris start       # Same as pressing Start (--restart-search to sweep again)
wp equalify-iris stop
wp equalify-iris tick        # Run the background job once, now (--count=10 for ten)
wp equalify-iris sweep --all # Run the sweep to completion, ignoring the tick budget
wp equalify-iris retry 12 34 # Retry documents by ID (no IDs = retry every failure)
wp equalify-iris list --status=failed
wp equalify-iris log
```

`doctor` is the one to reach for when nothing seems to be happening. It checks multisite,
the tables, the connection, whether the process is started, whether cron is scheduled, when the
job last ran, whether the circuit breaker is open, and whether anything is stuck — and prints a
specific fix for each failure.

---

## Things worth knowing before you turn it on

- **PDFs longer than 25 pages are not converted.** Iris caps at 25 pages. The plugin checks the
  page count before uploading, marks those documents "too long", and lists them so you know
  exactly what was skipped. It does not split them.
- **Files over 50 MB are not converted**, for the same reason and with the same honesty.
- **A page the size of a poster or an architectural drawing cannot be converted.** Iris turns each
  page into an image at a fixed resolution, so a very large physical page becomes an image too big
  for it to read. The plugin cannot detect this in advance, so those documents are discovered on
  upload and marked failed with Iris's explanation. Ordinary page sizes never hit this.
- **A converted document can be missing a page, and the activity log will say so.** One awkward page
  — usually a very dense table — can fail on its own without failing the rest. The plugin publishes
  what came back, because most of a document is worth far more than none of it, and records which
  documents are incomplete so you are not left guessing.
- **Only published, non-password-protected content is scanned.** Drafts, private posts, and
  password-protected posts are ignored, and so are their PDFs.
- **Only PDFs hosted by your own network are converted.** A PDF linked from another domain is not
  uploaded to Iris.
- **If a PDF stops being linked from any published page, its accessible version is unpublished.**
  A document that is no longer public should not stay readable through a side door.
- **Equalify Iris files public GitHub issues about the documents it converts**, which can include
  extracts of the document. This cannot be turned off. The Settings screen says so plainly. Do not
  point this plugin at confidential documents.

---

## Where to read next

- **[HOW-IT-WORKS.md](HOW-IT-WORKS.md)** — the whole flow, for someone who has never seen this
  repo.
- **[GLOSSARY.md](GLOSSARY.md)** — every term in one sentence.
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** — symptom, cause, fix. Starts with "nothing is
  happening".
- **[DEVELOPING.md](DEVELOPING.md)** — local setup, pointing at a local Iris, adding a status.
- **[DECISIONS.md](DECISIONS.md)** — the settled decisions and why, so you do not have to
  re-argue them.
