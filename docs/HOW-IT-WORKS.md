# How it works

Written for someone who has never opened this repo before. No prior knowledge of the plugin is
assumed. Terms in **bold** are all defined in one sentence each in [GLOSSARY.md](GLOSSARY.md).

---

## The one-paragraph version

The plugin keeps a to-do list of PDFs in its own database table. A background job runs every five
minutes and moves a few items one step further along: upload a PDF to Equalify Iris, check on one
that is converting, import one that is finished. Importing means saving the returned HTML as a
page of a hidden post type, which gives it a public URL. When a visitor loads any page that links
to a PDF the plugin has finished, a content filter adds an icon next to that link pointing at the
HTML version. Nothing happens all at once, on purpose.

---

## The whole flow

```
Published post  ──find PDF links──▶  To-do list  ──upload──▶  Iris
                                          │                     │
                                          │                  converts
                                          │                     │
   Icon next to PDF link  ◀──publish──  Hidden CPT  ◀──HTML──────┘
```

### 1. Connect

**There is nothing to sign into.** Iris v1 has no user accounts. It holds its own GitHub credential
server-side, and files every issue as its own account — so nothing on this network needs a GitHub
login, and nothing identifies this network to GitHub.

Most deployments, including the production one, are **open**: anyone who can reach the address can
use it, and the plugin sends no credential at all. An operator who does not want strangers using
their deployment can set a shared secret (`server.api_token` in Iris's config), which makes it
**gated**. Then `GET /v1/me` and `POST /v1/sessions` answer `401` until that secret arrives in an
`Authorization: Bearer` header.

So "connecting" is one request to `GET /v1/me`, and the answer is the whole configuration:

| It answers | Which means |
| --- | --- |
| `200` | Usable. Ready to convert. |
| `401` "requires a shared API token" | Gated, and we have not got the secret. A human must paste one in. Processing stops. |
| `401` "could not authenticate to GitHub" | The deployment's own GitHub credential is failing. Nothing here is wrong; Iris retries after 30 seconds. |

The secret, if there is one, is stored network-wide, or read from an `EQUALIFY_IRIS_API_TOKEN`
constant in `wp-config.php`, which takes priority and keeps it out of database backups. It is a door
key, not an identity: it says nothing about who is using it.

Because most deployments need no credential, the plugin never gates its own work on *holding* one —
only on Iris having actually refused it. Any `2xx` clears a previous refusal, so fixing a wrong
secret unblocks the background job without anyone pressing a button.

### 2. Sweep — find the PDFs that are already there

Pressing **Start** begins the **sweep**: a walk through every site in the network, looking at
published, non-password-protected posts and pages, and extracting every link that ends in `.pdf`.

The sweep is resumable. It stores a **cursor** — which site, which post ID it got to — as a
network option. Each run picks up from the cursor, handles a small batch (20 posts by default),
saves the cursor, and stops. If it is interrupted mid-batch by a timeout, a deploy, or a server
restart, the next run repeats at most one batch. Nothing is lost.

For each PDF link found, the sweep does two things:

- Adds a row to the **documents** table if this is a PDF the plugin has not seen (site + attachment
  ID is the unique key).
- Adds a **sighting**: a row saying "document 42 appears on post 118 of site 3".

Sightings matter more than they look. They answer two questions cheaply:

1. *Is this PDF still public anywhere?* — needed for retirement, in step 8.
2. *Does this page I am rendering contain any converted PDFs?* — needed for the icon, in step 6.
   Without sightings, the front end would have to search post content on every page load.

Only PDFs that belong to the network are queued. A link to a PDF on another domain is skipped:
uploading arbitrary URLs to a converter on somebody's behalf is a server-side request forgery
waiting to happen, and it is not what this plugin is for.

### 3. Send — upload one PDF to Iris

The background job takes the oldest **pending** document and, before touching the network,
inspects the file:

- **Page count.** Counted by scanning the PDF bytes in chunks for page markers, with a small
  overlap between chunks so a marker split across a boundary is still found. No PDF library
  needed. If the count cannot be determined, the answer is "unknown", and unknown is allowed
  through — refusing a convertible file because we could not count it would be worse than trying.
- **Size.**

More than 25 pages becomes `too_long`. More than 50 MB becomes `too_big`. Both are *final states*,
not failures: they will never succeed, so retrying them is pointless, and keeping them out of the
failure count means the failure count is a number an admin can actually drive to zero.

Otherwise the file is uploaded as a multipart form to `POST /v1/sessions`, streamed from disk with
cURL so memory stays flat regardless of file size. (Without cURL, the plugin falls back to
WordPress's HTTP API, but only if the file will fit in memory; if not, it waits an hour and tries
again in case cURL comes back.)

Iris replies with a session ID. The document becomes `converting`.

**At most two documents are ever in flight**, matching the two conversions Iris runs at a time. The
job re-counts in-flight documents inside its own loop, not once at the start, so it cannot overshoot.
(Iris would accept more and queue them; keeping the queue on our side is what makes the dashboard's
numbers mean something. See [DECISIONS.md](DECISIONS.md).)

If Iris refuses the upload outright — not a PDF, too many pages, or a page too physically large to
render — that is final. The document is marked `failed` with Iris's own explanation and is not
retried, because the same file gets the same answer.

### 4. Wait — poll for the result

Iris is asynchronous; conversion takes minutes. There are no webhooks, so the plugin polls
`GET /v1/sessions/{id}`.

Polling uses **exponential backoff**: 60 seconds after the first check, then 2, 4, 8… minutes,
capped at 15 minutes. A slow document therefore does not mean constant polling, and a fast one is
still noticed quickly.

Iris's status becomes ours:

| Iris says | We do |
| --- | --- |
| `queued`, `running` | Wait longer. Back off. |
| `ready_for_review`, `closed` | Mark **ready**. |
| `failed` | Mark **failed**, storing Iris's own wording as the reason. |
| anything else | Treat as still working. Iris may add statuses; an unknown status is not an error. |

A document still converting after two hours is failed on timeout, so nothing sits in flight
forever holding one of the two slots.

### 5. Import — turn HTML into a page

For a `ready` document the job fetches `GET /v1/sessions/{id}/output`, then:

1. **Cleans the HTML** against an allowlist — a list of what is permitted, not a list of what is
   banned, because a blocklist is always one tag out of date. The allowlist is WordPress's own
   `wp_kses` list extended with the attributes accessibility depends on: `scope`, `headers`,
   `colspan`, `rowspan`, `aria-*`, `role`, `lang`, `id`.
2. **Extracts headings** into post meta, which is what the table of contents on the page is built
   from.
3. **Inserts a post** of the hidden `equalify_iris_doc` post type, with the cleaned HTML as
   `post_content`.
4. **Closes the Iris session**, freeing the slot upstream.

Two details in that third step are easy to get wrong:

- The insert is wrapped in `kses_remove_filters()` / `kses_init_filters()`. Cron has no logged-in
  user, so WordPress's own content filtering would strip tags from our already-cleaned HTML on the
  grounds that "nobody" is not allowed to post them.
- The post slug is stored as `{attachment_id}-{title-slug}`. That is what makes the URL
  `/equalify-iris/1184/report/` resolvable with **no database lookup at all**: the rewrite rule
  captures two segments, glues them back together with a hyphen, and hands WordPress an ordinary
  post name.

A `409` from the output endpoint means "not ready yet, despite what the status said". That is not
a failure; the document goes back to `converting` for another minute.

**A finished document can still be missing a page.** Iris converts pages separately, and one page
can fail on its own — usually a very dense table — without failing the document. Iris marks each
hole with a `@page-failed` comment after the closing `</main>`. The plugin counts those before
cleaning (cleaning removes comments), publishes the document anyway, and writes a warning to the
activity log naming the document and how many pages are missing. The count is also stored on the
page as `_equalify_iris_pages_missing`, so incomplete documents can be found later.

Publishing an incomplete document is deliberate: 24 readable pages out of 25 beats a PDF a screen
reader cannot open. Saying so is equally deliberate, because nobody reading the page could tell.

### 6. Link — the icon

`the_content` gets one filter. It:

1. Bails immediately if the content does not contain `.pdf` at all. That is the case on almost
   every page, and it costs one string search.
2. Otherwise runs **one** indexed query — a join of sightings to documents — asking "which
   converted PDFs appear on this post?".
3. Rewrites matching anchors: tags the original with `data-equalify-iris-url` and
   `data-equalify-iris-title`, and appends a separate icon link straight after it.

The icon link carries an `aria-label` naming the document ("Open report in Equalify Iris"), an
inline SVG marked `aria-hidden`, and a visually hidden text label. Three ways to name the same
control, because a decorative icon with no accessible name is the exact failure this plugin exists
to fix.

The original PDF link is never modified beyond those two data attributes, and never replaced.

**There is no front-end JavaScript.** The icon is in the HTML the server sends, which means it
survives page caching, works with JavaScript disabled, and needs no content-security-policy
exception.

### 7. Keep up — new content

`transition_post_status` fires whenever anything is published, updated, or unpublished, on any
site. The plugin:

- **Published** → scan the content for PDFs, queue anything new, refresh its sightings.
- **Unpublished or deleted** → clear its sightings, which may make some documents orphans (step 8).

This is why the sweep only has to happen once. After it finishes, the network stays current by
itself.

### 8. Retire — when a PDF stops being public

A document with no sightings on any published post is an **orphan**: the post was unpublished, the
post was deleted, or somebody removed the link. Its accessible version is set back to draft and
the document is marked `retired`.

This is a privacy obligation, not housekeeping. Somebody unpublished a page; if our copy of its
PDF stayed readable at a public URL, we would have quietly undone their decision.

Orphan detection has a 10-minute grace period on `created_at`, because a document row is written a
fraction of a second before its first sighting. Without the grace period, a cron tick landing in
that window would retire a PDF it had only just discovered.

If the PDF comes back — the page is republished — the document is **revived**: the same draft page
is republished at the same URL, so old links keep working.

---

## The background job, in detail

One WP-Cron hook, `equalify_iris_tick`, on a custom five-minute schedule, registered on the main
site only.

Every tick is capped, and the caps are checked **between** operations rather than only at the
start, so one slow upload cannot blow the budget:

| Cap | Default | Meaning |
| --- | --- | --- |
| Time budget | 20 seconds | Stop starting new work after this. |
| Uploads | 1 | Files sent to Iris per tick. |
| Status checks | 5 | Polls per tick. |
| Imports | 2 | HTML documents saved per tick. |
| Posts swept | 20 | Posts examined per tick. |
| In flight | 2 | Total conversions running at Iris. |

The order of work inside a tick is deliberate: **finishing beats starting.** Imports run before
uploads, because an import frees a slot at Iris while an upload consumes one. A tick that only
started work would grow the queue; a tick that finishes work shrinks it.

Two ticks can overlap — WordPress cron makes no promise otherwise. Two things prevent that from
causing double work:

1. A transient **lock**, which is a cheap optimisation and explicitly *not* the guarantee, since
   transients can be evicted.
2. **`claim_next()`**, which is the real guarantee: a conditional `UPDATE … WHERE status = 'ready'`
   that moves a row to `importing` and checks how many rows it changed. Exactly one process can win
   that update. Whoever changed a row owns it.

That second mechanism is the reason `ready` and `importing` are separate statuses. MySQL reports
zero affected rows for an update that changes nothing, so claiming `importing → importing` would
silently never claim anything.

### The circuit breaker

Five consecutive failures talking to Iris opens a **circuit breaker**: the plugin stops calling out
for 15 minutes. A service that is down does not want a WordPress network retrying every five
minutes, and there is nothing useful to learn from the sixth timeout in a row.

While the breaker is open, the tick still runs retirement and sweeping, because neither needs the
network. `429 Too Many Requests` is honoured via `Retry-After`.

---

## The document lifecycle

```
        pending ──▶ uploading ──▶ converting ──▶ ready ──▶ importing ──▶ published
           │            │              │                                     │
           │            │              └──▶ failed (retry brings it back)     │
           │            │                                                     ▼
           │            └──▶ too_long / too_big  (final — never retried)  retired
           │                                                                  │
           └──▶ skipped                                    revive ◀───────────┘
```

| Status | Meaning |
| --- | --- |
| `pending` | On the to-do list, not started. |
| `checking` | Being inspected for page count and size. |
| `uploading` | Being sent to Iris. |
| `converting` | Iris is working. We poll. |
| `ready` | Iris finished; we have not saved the HTML yet. |
| `importing` | Claimed by a tick that is saving the HTML now. |
| `published` | Live at a public URL. The goal. |
| `retired` | No longer linked from anything public. Page set to draft. |
| `too_long` | More than 25 pages. Final. |
| `too_big` | More than 50 MB. Final. |
| `failed` | Something went wrong. Retryable, up to 5 attempts. |
| `skipped` | Deliberately not converted. |

---

## The files, and what each one is responsible for

### `equalify-iris.php`

The plugin header and bootstrap. Declares `Network: true`, defines four constants, `require`s every
class in dependency order — plainly, not through an autoloader, so you can read the load order —
registers activation and deactivation, and hands off to `Equalify_Iris_Plugin::boot()` on
`plugins_loaded`.

### `includes/`

| File | Responsibility |
| --- | --- |
| `class-plugin.php` | Wires everything together. Constructs objects in dependency order and lets each register its own hooks. The map of the plugin. |
| `class-settings.php` | Every setting, its default, and its bounds. Reads and writes network options. Owns the optional API token, the constant override, and the recorded auth state. |
| `class-database.php` | Creates and upgrades the two tables. Knows the schema version. |
| `class-documents.php` | The to-do list. Every read and write of the documents and sightings tables, including `claim_next()`, the status lifecycle, and orphan detection. |
| `class-logger.php` | The activity log: a capped list of sentences with levels. |
| `class-api-client.php` | Every HTTP call to Iris, plus the two kinds of `401` and the circuit breaker. The only file that talks to the network. |
| `class-pdf-inspector.php` | Page count and size, by reading bytes. No PDF library. |
| `class-html-cleaner.php` | The allowlist, and heading extraction. |
| `class-post-type.php` | The hidden CPT, its rewrite rule, its permalinks, its template, and the capability map that stops anyone editing a document by hand. |
| `class-discovery.php` | Finds PDF links in content, resolves them to attachments, records sightings, and handles publish/unpublish/delete. |
| `class-sweeper.php` | The resumable walk through the network. Owns the cursor. |
| `class-worker.php` | One tick: retire, import, check, upload, sweep — within the budget. |
| `class-scheduler.php` | The cron schedule, the hook, and the lock. |
| `class-frontend.php` | The `the_content` filter, the icon markup, and the stylesheet. |
| `class-cli.php` | `wp equalify-iris …`, including `doctor`. |

### `admin/`

| File | Responsibility |
| --- | --- |
| `class-admin.php` | The network menu, and one `admin_post` handler for every button. Capability check, nonce check, act, then redirect. |
| `class-admin-overview.php` | Problems, the switch, progress, detail. |
| `class-admin-documents.php` | The filterable, paginated list, and the Retry button. |
| `class-admin-settings.php` | Connection, limits, rules, and the plain statement about what Iris publishes. |
| `class-admin-log.php` | The activity log, newest first. |

### `templates/` and `assets/`

| File | Responsibility |
| --- | --- |
| `templates/single-document.php` | The accessible page itself. The thing the whole plugin exists to produce. A theme overrides it with `single-equalify_iris_doc.php`. |
| `assets/css/icon.css` | The icon. Loads on every page, so it is deliberately tiny and takes its colour from the theme. |
| `assets/css/document.css` | The document page. Styles our furniture, not the document. |
| `assets/css/admin.css` | Only the handful of things WordPress core has no class for. |

### `uninstall.php`

Runs on delete, not deactivate. Drops the tables and the settings. **Does not delete the converted
pages**, because those are live URLs people have shared, and deleting a thousand of them because
somebody removed a plugin is not recoverable.

---

## Every hook the plugin registers

| Hook | What it does |
| --- | --- |
| `plugins_loaded` | Boot. |
| `init` | Register the post type, its rewrite rule, translations; flush rewrites if needed. |
| `admin_init` | Upgrade the tables if the schema changed; make sure cron is scheduled. |
| `network_admin_menu` | The four screens. |
| `admin_post_equalify_iris_action` | Every button. |
| `admin_enqueue_scripts` / `wp_enqueue_scripts` | The stylesheets. |
| `cron_schedules` | Adds the five-minute interval. |
| `equalify_iris_tick` | The background job. |
| `transition_post_status` | Content published, updated, or unpublished. |
| `before_delete_post` | Content deleted. |
| `wp_initialize_site` | A new site joined the network — reopen the sweep if it had finished. |
| `the_content` | Add the icons. |
| `post_type_link`, `template_redirect`, `template_include` | Document URLs and the template. |
| `map_meta_cap` | Nobody edits a converted document by hand. |

---

## Filters you can use

| Filter | Purpose |
| --- | --- |
| `equalify_iris_icon_label` | The `aria-label` on the icon link. |
| `equalify_iris_icon_short_label` | The visually hidden text label. |
| `equalify_iris_icon_link` | The whole icon link markup. |

---

## Security boundaries

- **Only network-owned attachments are uploaded.** `Discovery::resolve_attachment()` returns 0 for
  anything it cannot match to an attachment on this network, and 0 means "do not queue".
- **Only `publish`, non-password-protected posts are scanned.**
- **Every admin action checks `manage_network_options` and then a nonce**, in that order, and then
  redirects so nothing re-runs on refresh.
- **Every button is a POST form, never a link.** Link prefetchers and email preview services
  follow links, and "Stop the whole process" is not something an email client should be able to do.
- **Notices come from a server-side lookup table**, keyed by a code in the URL — never from text in
  the URL, which would let anyone craft a convincing fake message.
- **Incoming HTML is allowlisted, once, on the way in.**
- **The API token, if a deployment needs one, can live in `wp-config.php`** instead of the database,
  and the UI says which is in use. The field is a password field and is never echoed back.
