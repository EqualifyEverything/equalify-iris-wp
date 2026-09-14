# Equalify Iris for WordPress — Product Requirements

**Status:** Draft for review
**Date:** 2026-08-17
**Repo:** `equalify-iris-wp`
**Depends on:** [Equalify Iris](https://github.com/EqualifyEverything/equalify-iris), running at `https://iris.equalify.uic.edu/`

---

## 1. Summary in one paragraph

PDFs are the most common accessibility dead end on a WordPress site. A screen reader user
clicks a link, gets a scanned document with no headings, no table structure, and no reading
order, and the content is effectively gone. This plugin fixes that automatically. It finds every
PDF linked from published content across a WordPress multisite network, sends each one to
Equalify Iris, gets back clean accessible HTML, and publishes that HTML as its own web page.
Next to every PDF link on the site, visitors see a small icon that opens the accessible
version. Nobody who edits content has to do anything, or even know the plugin exists. One super
admin turns it on once from the network dashboard and watches it work.

---

## 2. Who this is for

| Person | What they do with it |
| --- | --- |
| **Network super admin** | The only person with controls. Points the plugin at an Iris deployment, presses Start, watches progress, retries failures. |
| **Site editors** | Nothing. They publish content as usual. Conversion happens behind them. |
| **Visitors using screen readers** | Click the icon next to a PDF link and read an accessible HTML version instead. |
| **Visitors not using screen readers** | Also benefit — the HTML version is searchable, works on phones, and can be read aloud. |

Note the asymmetry: **all control lives in the network dashboard, and there is no per-site or
per-media-item interface at all.** This is a deliberate difference from the older
`equalify-reflow-wp` plugin, which put a panel on every PDF in the Media Library and asked an
editor to press a button. That approach does not scale to a network with thousands of PDFs and
hundreds of editors, and it made accessibility opt-in per document. Here, conversion is
automatic and network-wide, and editors have no switch to forget to flip.

---

## 3. Goals

1. **Every PDF on public content gets an accessible HTML alternative**, without an editor asking
   for it.
2. **A super admin can start and stop the whole process** from the network dashboard, and can
   see honestly how far along it is.
3. **The site stays fast.** The plugin does a small, strictly bounded amount of work per cron
   tick. It must never be the reason a page is slow or a host complains.
4. **The HTML alternative is genuinely accessible** — WCAG 2.2 AA, works without JavaScript,
   reachable in one click from the PDF link.
5. **A developer of any experience level can read the code and understand it.** Plain language in
   comments, documentation, and admin copy. No cleverness that needs a senior developer to
   decode.
6. **New content keeps being handled forever.** After the first sweep finishes, publishing or
   updating any post on any site queues any new PDFs it links to.

### Non-goals

- **Not a PDF remediation tool.** We do not fix or replace the original PDF. It stays exactly
  where it is, and its link keeps working.
- **No per-site or per-attachment admin UI.** Not in the Media Library, not in a site's dashboard,
  not in the block editor.
- **No editor-facing workflow.** No approval step, no "convert this one" button, no notifications
  to editors.
- **Not a general document viewer.** No annotation, no highlighting, no reading-position memory.
- **Not a replacement for accessibility review.** Iris output is very good and it is still
  machine-generated. The plugin says so on the page.

---

## 4. How it works, in plain language

Here is the whole system in the order things happen. Every later section is a detail of one of
these steps.

1. **Connect.** A super admin opens the network dashboard and presses **Check the connection**.
   There is nothing to sign into: Iris has no user accounts, and most deployments — including the
   production one — accept anyone who can reach them. Only a deployment whose operator has set a
   shared secret needs anything pasted in.
2. **Sweep.** The admin presses **Start**. The plugin begins walking every site in the network,
   a few posts at a time, looking at published pages and posts for links to PDF files. Each PDF
   it finds gets added to a to-do list. This walk is slow on purpose and survives being
   interrupted — it remembers where it got to.
3. **Send.** A background job picks the next PDF off the to-do list and uploads it to Iris. Only
   one or two PDFs are ever being converted at once, because that is all Iris runs at a time.
4. **Wait.** Iris converts asynchronously, which takes minutes, not seconds. The plugin checks
   back periodically, waiting longer between checks the longer it takes, so a slow document does
   not mean constant polling.
5. **Import.** When Iris says a document is ready, the plugin downloads the HTML, cleans it, and
   saves it as a page of a hidden post type. That page gets a permanent public URL.
6. **Link.** From then on, whenever a visitor loads a page containing that PDF link, the plugin
   adds an icon next to the link that opens the accessible HTML version.
7. **Keep up.** Any time anyone publishes or updates content anywhere on the network, the plugin
   checks that content for PDFs it has not seen, and adds them to the to-do list. Step 3 onward
   repeats forever.
8. **Retire.** If a PDF stops appearing on any published page — the post was unpublished, deleted,
   or the link was removed — the plugin unpublishes the HTML alternative, so a document that is no
   longer public does not stay readable through a side door.

```
Published post  ──find PDF links──▶  To-do list  ──upload──▶  Iris
                                          │                     │
                                          │                  converts
                                          │                     │
   Icon next to PDF link  ◀──publish──  Hidden CPT  ◀──HTML──────┘
```

---

## 5. Decisions already made, and why

These are settled. They are written down because each one has a plausible alternative, and the
next developer should not have to re-argue them.

| Decision | Why |
| --- | --- |
| **There is nothing to sign into, and the plugin gates on Iris refusing it rather than on holding a credential.** | Iris v1 has no user accounts and issues no credentials; it holds its own GitHub credential server-side and files everything as its own account. What a deployment may have is an operator-set shared secret (`server.api_token`) gating `GET /v1/me` and `POST /v1/sessions`; without one it is **open**, and the production deployment is open. So the plugin sends `Authorization: Bearer` only when it actually holds a secret, and asks *Iris* whether it will be accepted (`GET /v1/me`) rather than asking its own database whether it stored a string. Requiring a credential would convert nothing at all on a healthy network. See §5.1 for the two kinds of `401`. |
| **PDFs longer than 25 pages are marked unsupported, not split.** | Iris hard-caps at 25 pages and rejects longer files. Splitting a PDF inside PHP needs a PDF library on the host and produces a worse reading experience across stitched parts. Instead the plugin detects the page count before uploading, marks the document "too long", and lists it in the dashboard so the admin knows exactly what was not converted. Honest and simple beats half-working. |
| **All names use `equalify-iris`, not `equalify-reflow`.** | The plugin, the service, and the URLs should say the same thing. This changes public URLs and CSS class names relative to the earlier Reflow plugin, which is accepted. |
| **The icon is rendered server-side in PHP, not injected by JavaScript.** | The earlier plugin used JavaScript to add icons after page load. That makes the accessible alternative depend on JavaScript, which is exactly the wrong dependency for this audience, and it breaks under page caching and content-security policies. Rendering in the content filter means the link is in the HTML the server sends. **The plugin ships no front-end JavaScript at all.** |
| **The HTML lives in a hidden custom post type, in `post_content`.** | It needs a permanent public URL, a title, and a slug — that is a post. Hiding it from the admin menu, site search, and archives keeps it out of the way of editors, who have no job to do here. Storing the HTML in `post_content` rather than post meta means WordPress search, sitemaps, and themes treat it as real content. |
| **Iris output needs no image handling.** | Iris returns content-only semantic HTML: headings, tables with `<th scope>`, forms, figures with `<figcaption>`. It has no endpoint that serves extracted images, and charts come back as described data rather than pictures. So unlike the Reflow plugin, there is nothing to sideload into the Media Library. |
| **Polling, not webhooks.** | Iris v1 has no webhooks. Clients poll. This is fine, and it is why the backoff schedule in §9 matters. |
| **A custom database table holds the to-do list.** | The list is network-wide, needs indexed lookups by status and by "what is due now", and will hold tens of thousands of rows. Options and post meta are the wrong shape for all three, and stuffing this into the options table would bloat the cache that loads on every request. |

### 5.1 The two kinds of `401`

Iris answers `401` for two unrelated reasons. They need different handling, and conflating them
sends a super admin looking for a fault that is not on their network.

| Iris says | Whose problem it is | What the plugin does |
| --- | --- | --- |
| requires a shared API token | The super admin's. The deployment is gated and we have no secret. | **Permanent.** Stop processing and say so. Retrying cannot help — a human has to obtain the secret and paste it in. |
| could not authenticate to GitHub | The deployment operator's. Iris's own credential is failing, and it is converting nothing for anybody. | **Retryable.** Iris retries GitHub after 30 seconds and it often clears by itself. Keep working, and say plainly that nothing on this network needs fixing. |

`Equalify_Iris_API_Client::handle_unauthorized()` tells them apart on the message text — the only
signal Iris gives. An unrecognised `401` falls back to "needs a token", which stops rather than
loops.

Any `2xx` response clears a recorded refusal, so correcting a wrong secret unblocks the background
job without anyone pressing a button.

---

## 6. What we depend on: the Iris API

Grounded in `equalify-iris/docs/API.md` and the service source. Base URL for production is
`https://iris.equalify.uic.edu/v1`.

### The calls we make

| Call | Purpose | Notes |
| --- | --- | --- |
| `GET /v1/me` | Will this deployment accept us, and who does it file issues as | The whole connection check. `200` = usable; `401` = refused, see §5.1. Also returns the deployment defaults and `upstream_repo` |
| `POST /v1/sessions` | Upload one PDF | `multipart/form-data`, part name is `images`, even for a PDF |
| `GET /v1/sessions/{id}` | Check progress | `status` is `queued`, `running`, `ready_for_review`, `closed`, or `failed` |
| `GET /v1/sessions/{id}/output` | Download the HTML | `text/html`. Returns `409` while still running |
| `POST /v1/sessions/{id}/close` | Finalize and free server disk | Requires `ready_for_review`. We call this after a successful import |

We do **not** use `/feedback`, `/logs`, or `/diagnostics` in v1. The plugin has no human review
step, so there is no feedback to send. `/logs` and `/diagnostics` are worth reaching for later if
we need to debug a stuck document from the dashboard.

### The limits that shape the design

These are real numbers from the Iris code, not guesses. Every one of them becomes a rule in §9.

| Limit | Value | Consequence for the plugin |
| --- | --- | --- |
| Maximum pages per PDF | **25** (`MAX_PDF_PAGES`) | Check page count first; mark longer files "too long" and never upload them |
| Maximum file size | **50 MB** per upload | Refuse larger files locally with a clear reason |
| Concurrent conversions, server-wide | **2** by default (`max_concurrent_runs`) | Never have more than this in flight. Extra uploads just wait in a FIFO queue, so flooding Iris gains nothing and only makes our own polling noisier |
| Authentication | None required by default. An operator may set a shared secret (`server.api_token`) which gates `/v1/me` and `/v1/sessions` | Send `Authorization: Bearer` only when we hold a secret. Treat `401` as the only evidence of a problem, and tell its two causes apart (§5.1) |
| Rate limits | 240 general requests and 12 uploads per minute, plus a cap on upload bytes in flight | With no accounts to count against, both are per **address** — so a whole institution behind one IP shares a budget, as does everyone else on a shared deployment. Honour `Retry-After` on `429` |
| Webhooks | None in v1 | Poll with backoff |
| Contributions | Iris may file GitHub issues automatically during a run, as **its own** account, with no opt-out | Documented for the admin (§14). Not a blocker. Nothing identifies this network as the source — and equally, it gets no credit |

**The concurrency limit is global, not per user.** Two conversions at a time is the whole
deployment's capacity, shared with everyone else using that Iris instance. §17 does the honest
arithmetic on what that means for a large network.

---

## 7. Data model

Two small tables, created in the network's base database, plus one hidden post type per site.

### Table: `{base_prefix}equalify_iris_documents`

One row per PDF we know about. This is the to-do list. Read it as "things to convert".

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint, primary key | Our own id |
| `site_id` | bigint | Which site in the network the PDF belongs to |
| `attachment_id` | bigint | The PDF's WordPress attachment id on that site |
| `pdf_url` | text | Where the PDF lives, for display and matching |
| `file_hash` | char(64) | SHA-256 of the file. If this changes, the PDF was replaced and needs converting again |
| `page_count` | smallint, nullable | Filled in when we inspect the file |
| `status` | varchar(20) | See the status list below |
| `iris_session_id` | varchar(64), nullable | The Iris session currently converting this document |
| `doc_post_id` | bigint, nullable | The hidden-CPT post holding the finished HTML |
| `attempts` | smallint | How many times we have tried, for backoff and for giving up |
| `next_action_at` | datetime | The earliest time the background job should touch this row again |
| `last_error` | text, nullable | Plain-language reason for the current failure, shown in the dashboard |
| `created_at`, `updated_at` | datetime | |

- Unique key on `(site_id, attachment_id)` — one job per PDF per site.
- Index on `(status, next_action_at)` — this is the query the background job runs every tick, so
  it must never scan the table.

### Table: `{base_prefix}equalify_iris_sightings`

One row per place a PDF appears. Read it as "where we saw it".

| Column | Type | Meaning |
| --- | --- | --- |
| `document_id` | bigint | Which document, pointing at the table above |
| `site_id` | bigint | Which site |
| `post_id` | bigint | Which post or page links to the PDF |
| `last_seen_at` | datetime | Updated whenever we confirm the link is still there |

- Unique key on `(document_id, post_id)`.

This table exists for exactly one reason, and it is worth stating plainly: **we need to know
whether a PDF is still public.** If every post that linked to a PDF gets unpublished, the PDF is
no longer public, and its HTML alternative must not stay readable. Answering that question by
searching every post's content for a URL is far too expensive to do on a hook. Answering it from
this table is one indexed query. It also lets the dashboard say "appears on 4 pages", which is
useful context for an admin deciding whether a failure matters.

### Status values

Plain words, in the order a healthy document moves through them:

| Status | Means |
| --- | --- |
| `pending` | We know about it and have not started |
| `checking` | We are reading the file to get its page count and size |
| `uploading` | We are sending it to Iris right now |
| `converting` | Iris has it. We are waiting and checking back |
| `importing` | Iris finished. We are downloading and saving the HTML |
| `published` | Done. The HTML page is live and the icon appears |
| `retired` | The PDF is no longer on any published page, so the HTML page was unpublished |
| `too_long` | More than 25 pages. Iris cannot take it |
| `too_big` | Over the file-size limit |
| `failed` | Something went wrong. `last_error` says what, and the admin can retry |
| `skipped` | Deliberately excluded — the site or post type is off in settings |

The two "cannot convert" statuses are separate from `failed` on purpose. A `failed` document is
worth retrying; a 60-page PDF will never succeed, and mixing them would make the dashboard's
failure count meaningless and train admins to ignore it.

### Post type: `equalify_iris_doc`

Registered on every site in the network. "Hidden" is a specific set of choices, and it is worth
being exact, because getting one wrong either breaks the feature or leaks content:

```php
'public'              => true,   // MUST be true. The page has to be viewable by visitors.
'publicly_queryable'  => true,   // The single page resolves on the front end.
'show_ui'             => false,  // No admin menu, no list table. Editors have no job here.
'show_in_menu'        => false,
'show_in_nav_menus'   => false,
'show_in_rest'        => false,  // Not in the block editor or the REST API.
'exclude_from_search' => true,   // Keeps site search results about real content.
'has_archive'         => false,  // No index page listing every converted PDF.
'rewrite'             => false,  // We add our own rule; see below.
'supports'            => array( 'title', 'editor', 'custom-fields' ),
```

**"Hidden" means hidden from editors, not from visitors.** The page must be publicly reachable —
that is the entire point. If `public` is ever set to `false` to make it feel more hidden, the
feature stops working.

Search engines *are* allowed to index these pages. An accessible HTML version of a document is
more useful in search results than the PDF, and indexing it helps people who never see the icon.
It is excluded from internal site search only to keep a site's own search results focused on
pages someone wrote.

**URL shape.** To match the URLs already in use, the public address is:

```
https://example.org/equalify-iris/1184/docling-arxiv-paper/
                                  ^^^^  ^^^^^^^^^^^^^^^^^^
                          attachment id      title slug
```

The simple way to get this, with no database lookup at request time: store the post slug as
`1184-docling-arxiv-paper`, and add one rewrite rule that turns the two URL segments back into
that single slug.

```php
add_rewrite_rule(
    '^equalify-iris/([0-9]+)/([^/]+)/?$',
    'index.php?equalify_iris_doc=$matches[1]-$matches[2]',
    'top'
);
```

Attachment ids are unique per site, so the slug is guaranteed unique with no collision handling.
A `post_type_link` filter makes `get_permalink()` return the pretty two-segment URL so the rest of
the code never has to build it by hand. If a visitor arrives with the right id but a stale slug —
because the PDF was retitled — redirect once, permanently, to the current URL. Sites without
pretty permalinks fall back to `?equalify_iris_doc=1184-docling-arxiv-paper`.

---

## 8. The pipeline, step by step

### 8.1 Discovery — finding PDFs

Two ways in, and they share the same code for reading a piece of content.

**The sweep (one-time, resumable).** When the admin presses Start, the plugin schedules a
repeating job that walks the network. Each run does a small, fixed amount:

- One site at a time, in ascending site id order.
- Within a site, up to **20 posts** per run, in ascending post id order.
- Only `publish` status. Never draft, private, pending, future, or trashed.
- Only public post types (`get_post_types( array( 'public' => true ) )`), minus the plugin's own
  post type, minus anything the admin has excluded in settings.
- Progress is stored as a cursor in a network option: the site id and the last post id handled.
  If the job is interrupted — a deploy, a timeout, a stop — the next run picks up exactly where it
  left off. Nothing is scanned twice and nothing is missed.
- When the last site's last post is done, the sweep marks itself complete and unschedules. The
  dashboard stops saying "estimated".

**The live hook (forever after).** On `transition_post_status`, when a post becomes `publish`, scan
just that post. This is fast — string matching on one post's content, no HTTP, no file reads — so
it is safe to do inside the request that saves the post. New PDFs get a `pending` row; PDFs
already known get their `last_seen_at` refreshed.

The same hook handles the other direction. When a post leaves `publish`, or is trashed or deleted,
remove its sightings and mark any document with no remaining published sightings for retirement
(§8.6).

**Reading one piece of content** means: find every `<a href>` pointing at a `.pdf` on this site's
own uploads, resolve it to an attachment id with `attachment_url_to_postid()`, and record it. Only
this network's own files. A PDF hosted somewhere else is not ours to convert, and following
arbitrary URLs from post content would be a server-side request forgery hole.

Content is read from the **rendered** output of the post, so PDFs inside blocks, shortcodes, and
page-builder markup are found rather than only ones in raw HTML. That is more expensive than
reading `post_content` directly, which is exactly why the sweep does only 20 posts at a time.

### 8.2 Pre-flight check — before we upload anything

For a `pending` document, before touching the network:

1. Confirm the file exists on disk via `get_attached_file()`.
2. Check its size. Over the limit → `too_big`, with the actual size in `last_error`.
3. Read its page count. → over 25 → `too_long`, with the actual page count in `last_error`.
4. Compute the SHA-256 hash and store it.

Page count is read by scanning the PDF for page objects without rendering anything, so it costs a
file read and no external tools. If the count genuinely cannot be determined, treat it as unknown
and upload anyway — Iris rejects it cleanly with a message we can store, which is a better outcome
than refusing a file that would have worked.

Doing this check locally is the whole reason a 60-page PDF never becomes a wasted upload, a
minute of Iris capacity, and a confusing server-side error.

### 8.3 Upload

The background job uploads at most **one** PDF per tick, and only if the number of documents in
`converting` is below the configured in-flight limit (default 2, matching Iris).

Claiming a row must be safe against two cron runs overlapping, which does happen on busy sites.
The plugin claims work with a conditional update and checks how many rows changed:

```sql
UPDATE {prefix}equalify_iris_documents
   SET status = 'uploading', updated_at = NOW()
 WHERE id = %d AND status = 'pending'
```

If that changed zero rows, another process got there first, so this one moves on. No separate
lock, no race, and the database does the work it is good at. A second, coarser lock keeps two
whole ticks from running at once.

The request is `multipart/form-data` to `POST /v1/sessions` with the file in a part named
`images` — that is the part name even for a PDF. On success, store the returned `session_id`, set
status to `converting`, and set `next_action_at` to 60 seconds out.

**Memory matters here.** WordPress's HTTP functions build the whole request body in memory, so a
40 MB PDF becomes 40 MB of PHP memory plus overhead, which will exhaust a small host. So:

- Prefer a streaming upload with cURL's file handling when the cURL extension is available, which
  keeps memory flat regardless of file size.
- Fall back to building the body in memory only when the file is comfortably smaller than the
  remaining PHP memory limit.
- If neither is possible, leave the document `pending` with a clear reason and try again later
  rather than crashing the cron run. One oversized PDF must never stop the queue.

### 8.4 Waiting

For each `converting` document whose `next_action_at` has passed, `GET /v1/sessions/{id}`:

- `queued` or `running` → still going. Push `next_action_at` out and increment the check count.
- `ready_for_review` → set status to `importing` and let the same tick import it if it has budget
  left, otherwise next tick.
- `failed` → status `failed`, with Iris's own `error` text in `last_error` so the admin sees the
  real reason.

Backoff schedule, doubling and capped:

```
60s, 2m, 4m, 8m, 15m, 15m, 15m, ...
```

A document still `converting` after **2 hours** is treated as stuck: `failed`, with an error that
says it timed out and can be retried. Iris genuinely queues work when busy, so a long wait is not
proof of a problem — but a document that never resolves must not be polled forever.

Up to **5 status checks** per tick, across different documents.

### 8.5 Import and publish

For an `importing` document:

1. `GET /v1/sessions/{id}/output`. A `409` means it is not actually ready — go back to
   `converting`. Anything else non-2xx is a failure.
2. **Clean the HTML.** Run it through `wp_kses()` with an allowlist built from
   `wp_kses_allowed_html( 'post' )` plus the tags and attributes Iris relies on that the default
   list drops. At minimum: `scope`, `headers`, `colspan`, `rowspan`, `abbr` on table cells;
   `role` and every `aria-*` attribute; `lang`; `start` and `reversed` on lists; `readonly`,
   `value`, `for`, `id`, `aria-required` on form elements; `<form>`, `<fieldset>`, `<legend>`,
   `<label>`, `<input>`, `<caption>`, `<figure>`, `<figcaption>`, `<sup>`, `<cite>`, `<q>`,
   `<blockquote>`, `<dl>`, `<dt>`, `<dd>`. **Every one of these carries accessibility meaning** —
   stripping `scope` from a table header is exactly the damage this plugin exists to undo. The
   allowlist lives in one clearly commented file, and if cleaning removes anything, log a count so
   a missing tag shows up as a number in the dashboard instead of as silently degraded output.
3. **Neutralize unresolvable images.** Iris has no endpoint that serves extracted image files, so
   an `<img>` in the output has no file behind it. Drop the `<img>` and keep its
   `<figure>`/`<figcaption>`, which is where the meaning is anyway.
4. **Save the post.** Insert or update the `equalify_iris_doc` post: title from the attachment
   title, slug `{attachment_id}-{title-slug}`, content the cleaned HTML, status `publish`. Store
   `_equalify_iris_attachment_id`, `_equalify_iris_session_id`, `_equalify_iris_source_hash`, and
   `_equalify_iris_converted_at` as meta.
   - Re-conversions **update the existing post** so the URL never changes. A URL we have published
     next to a PDF link is a promise.
   - `kses` filters attach based on the current user's capabilities, and cron has no user, so
     sanitizing is done explicitly in step 2 rather than left to whatever WordPress happens to do
     on save. Do not rely on a side effect for something this important.
5. `POST /v1/sessions/{id}/close` to free Iris's disk. Best effort — if it fails, log it and carry
   on. We already have the HTML, and a failed cleanup call must never lose a finished document.
6. Status `published`.

Up to **2 imports** per tick.

### 8.6 Retirement

A document whose every sighting is gone or unpublished gets its HTML post set to `draft` and its
status set to `retired`. The row and the post are kept, not deleted, so if the post is republished
the alternative comes straight back with the same URL and no re-conversion.

This is a privacy requirement, not housekeeping. Without it, unpublishing a page would leave its
PDF's full text readable at a public URL, which is the kind of surprise that gets a plugin banned
from a network.

### 8.7 Re-conversion

A PDF whose `file_hash` no longer matches the file on disk goes back to `pending`. The existing
HTML page stays published and visible while the new version is converted, then is updated in place.
An accessible version that is slightly out of date is much better than none while we wait.

---

## 9. Resource rules

The plugin must be the kind of thing a host never notices. These are hard rules, all of them
configurable, all of them with conservative defaults.

**Per-tick budget** — one cron tick does at most:

| Thing | Default cap |
| --- | --- |
| Wall-clock time | 20 seconds, checked between operations and stopped cleanly when reached |
| PDF uploads | 1 |
| Status checks | 5 |
| Imports | 2 |
| Posts scanned by the sweep | 20 |
| Documents converting at once | 2 (matching Iris) |

**Schedule.** One custom five-minute interval, `equalify_iris_five_minutes`, driving one job. One
job doing bounded work is easier to reason about, easier to lock, and easier to explain than
several jobs interleaving.

**Every cap is checked between operations, never mid-operation.** A tick that runs out of time
finishes what it is doing, saves state, and returns. Nothing is left half-done, because state
lives in the database at every step rather than in a variable inside a long-running function.

**HTTP timeouts** — short, so a slow or unreachable Iris cannot hold a PHP worker:

| Call | Timeout |
| --- | --- |
| Status check | 10s |
| Upload | 30s |
| Output download | 60s |

**Circuit breaker.** After 5 consecutive network failures, stop calling Iris for 15 minutes and
show the admin why. A service that is down should cost us one failed request every quarter hour,
not one every tick per document.

**Rate limits.** On a `429`, honor `Retry-After` if present, otherwise back off 15 minutes.

**Giving up.** After 5 attempts a document stays `failed` until a human retries it. The dashboard
shows the count, so nothing disappears quietly.

**WP-Cron is not reliable, and the plugin must say so.** On a low-traffic site WP-Cron only fires
when someone visits. For a network converting thousands of documents, that is not good enough. The
documentation must recommend a real system cron with `DISABLE_WP_CRON` set, show the exact crontab
line, and the dashboard must show when the last tick ran so a stalled cron is visible at a glance
rather than looking like a stalled conversion.

---

## 10. The accessible HTML page

This page is the product. Everything else is plumbing to get here.

**It renders inside the active theme**, using a `single-equalify_iris_doc.php` template that the
plugin provides and any theme can override. Theme integration means the page carries the site's
branding, header, and navigation, so it feels like part of the site rather than a bolted-on
viewer — and it means one template to maintain instead of a standalone page that has to reinvent
navigation on every site in the network.

Every page includes:

1. **One `<h1>`** — the document's title.
2. **A short honest note at the top**, before the content: this is an accessible HTML version of a
   PDF, generated automatically by Equalify Iris, and here is the original. Machine-generated
   content should say that it is.
3. **A link to the original PDF**, clearly labelled with its size and page count.
4. **A link back to a page the PDF appears on**, so a visitor who arrived directly has context.
5. **A table of contents** built from the document's own headings, when there are enough of them
   to be worth having. Skipped for short documents, where it is noise.
6. **The converted content**, unmodified apart from the cleaning in §8.5.

Accessibility requirements for the page itself, which will be tested and not assumed:

- Valid heading order with no skipped levels — Iris produces this, and the template must not break
  it by wrapping content in its own headings at the wrong level.
- `lang` on the `<html>` element, and on any element Iris marked as a different language.
- A skip link to the document content.
- Tables keep their `<caption>`, `<thead>`, and `<th scope>`.
- Works completely with JavaScript disabled.
- Readable at 400% zoom and with a 320px viewport.
- Passes axe-core with no violations, on top of the review Iris already did.

---

## 11. The icon next to PDF links

A `the_content` filter, running at priority 20 so it sees the final markup after blocks and
shortcodes have rendered. For each PDF link whose document is `published`:

1. Add `data-equalify-iris-url` and `data-equalify-iris-title` to the existing anchor, so themes
   and other tools can find it.
2. Insert a separate icon link immediately after it.

```html
<a data-equalify-iris-url="https://example.org/equalify-iris/1184/docling-arxiv-paper/"
   data-equalify-iris-title="docling-arxiv-paper"
   href="https://example.org/wp-content/uploads/2026/02/docling-arxiv-paper.pdf">the document</a>
<a href="https://example.org/equalify-iris/1184/docling-arxiv-paper/"
   class="equalify-iris-icon-link"
   aria-label="Open docling-arxiv-paper in accessible HTML">
  <svg viewBox="0 0 2048 2048" width="24" height="24" fill="currentColor"
       aria-hidden="true" focusable="false"><!-- Equalify mark --></svg>
  <span class="equalify-iris-icon-label screen-reader-text">Open in accessible HTML</span>
</a>
```

Requirements, each one for a reason:

- **The icon is a real link in the server's HTML.** No JavaScript. A screen reader user gets it
  with scripting off and behind a strict content-security policy.
- **`aria-label` names the document**, not just "open" — a screen reader user listing links needs
  to tell twelve icons apart.
- **The SVG is `aria-hidden` with `focusable="false"`** and the accessible name comes from the
  label. `focusable="false"` is there for older Internet Explorer-derived engines that put SVGs in
  the tab order.
- **`fill="currentColor"`** so the icon inherits the theme's text color and stays legible on any
  background. The plugin must not hardcode a color it cannot know.
- **One icon per PDF, per piece of content.** WordPress's file block outputs two anchors to the
  same PDF — the filename and a Download button — and a naive filter gives that block two
  identical icons. Deduplicate by URL within the content and add the icon after the first
  occurrence only.
- **Minimum 24×24 target**, visible focus outline, and at least 3:1 contrast against its
  background for the graphic itself.
- The tooltip is decorative. Nothing is only available through hover.
- CSS is one small stylesheet, enqueued only on pages that actually got an icon.
- Skip everything on feeds, REST responses, and non-singular views where injecting links into
  excerpts would be noise.

`type="attachment"` and `id="1184"` in the original example come from WordPress's own link markup,
not from this plugin. We add only the two `data-` attributes and the icon link.

---

## 12. The network dashboard

Network Admin → **Equalify Iris**. Every screen and action requires `manage_network_options` and a
nonce. There is no per-site screen.

### Overview

- **Connection**: which Iris URL, whether that deployment is open or gated, which account it files
  contributions as, and a **Check the connection** button that calls `GET /v1/me`. If Iris is refusing
  us, this is loud, says which of the two `401`s it is (§5.1), and — when it is the gated one — links
  straight to the field for the secret.
- **Start / Stop**, one obvious button, with the current state in words: *Running — converting 2
  documents, 1,284 to go* or *Stopped — 340 of 1,624 converted*.
- **Progress**, counted by status: published, converting, pending, failed, too long, too big,
  retired. Labelled **estimated** until the sweep finishes, because until then we do not know the
  denominator — and a progress bar that quietly grows its total is worse than one that admits it.
- **Health**: when the last tick ran, whether it looks like WP-Cron is not firing, whether the
  circuit breaker is open.
- **Throughput**: documents converted in the last 24 hours, and a plain-language estimate of time
  remaining based on that rate.

### Documents

A paginated table over the documents table, filterable by site and status, searchable by filename.
Per row: site, PDF title and link, page count, status, when it changed, and the accessible page's
link when there is one. Bulk actions: Retry, Re-convert, Skip. Failed rows show `last_error` in
plain language.

### Settings

| Setting | Default |
| --- | --- |
| Iris base URL | `https://iris.equalify.uic.edu/v1` |
| Shared API token | Empty. Only needed by a gated deployment; overridden by an `EQUALIFY_IRIS_API_TOKEN` constant in `wp-config.php` |
| Automatic processing of new content | On |
| Documents converting at once | 2 |
| Uploads per tick | 1 |
| Posts scanned per tick | 20 |
| Maximum file size | 50 MB |
| Sites included | All, with an exclusion list |
| Post types included | All public types, with an exclusion list |

### Activity log

A ring buffer of the last 200 events — started, stopped, uploaded, published, failed,
rate-limited, refused by Iris — with timestamps. Capped so it cannot grow without bound. This is the
first thing anyone will ask for when something looks wrong.

---

## 13. WP-CLI

Essential on a large network, where a super admin should not have to wait for cron to see whether
anything works.

```bash
wp equalify-iris status                  # counts by status, connection state, last tick
wp equalify-iris start                   # begin the sweep and enable processing
wp equalify-iris stop                    # halt; in-flight documents finish
wp equalify-iris tick                    # run one background tick right now
wp equalify-iris sweep --site=3          # scan one site immediately
wp equalify-iris retry --status=failed   # requeue failures
wp equalify-iris convert --url=<pdf-url> # convert one PDF now, for testing
wp equalify-iris connect                 # will Iris accept us? --token=<secret> / --forget
wp equalify-iris doctor                  # check cron, permissions, connection, table schema
```

`doctor` is the one to build first. Most support questions about a plugin like this are "why is
nothing happening", and the answer is almost always cron, credentials, or file permissions.

---

## 14. Security and privacy

**The API token, if the deployment needs one.** Most do not: an open deployment requires no
credential and the plugin stores none. Where one exists it is a shared secret belonging to whoever
runs that deployment, not an identity — it says nothing about who is using it.

It is stored as a network option, and an `EQUALIFY_IRIS_API_TOKEN` constant in `wp-config.php` takes
precedence when set, which is what the documentation recommends for production: a constant is not in
a database backup and not editable through the admin. The secret is never sent to the browser, never
written to a log, and never echoed back into the form — the field is a password field that renders
empty, and when the constant is set no field is offered at all. **Removing the stored token deletes
it**, and `uninstall.php` deletes it too.

`uninstall.php` also still deletes the pre-v1 `equalify_iris_token` option. Nothing writes it any
more, but an install set up before Iris removed its sign-in has a live GitHub credential sitting in
`wp_sitemeta`, and uninstall is the last chance to take it out.

**Only our own files.** A PDF is only converted if it resolves to an attachment on the site where
the link was found. The plugin never fetches an arbitrary URL out of post content, which would be
a server-side request forgery path straight through the network's firewall.

**Only public content.** Discovery reads `publish` posts only. A PDF that appears solely on a
draft, private, or password-protected page is never uploaded to Iris. Retirement (§8.6) keeps that
true over time.

**The HTML is cleaned before it is stored** (§8.5), with an allowlist, not a blocklist.

**Every admin action** checks `manage_network_options` and a nonce. Nothing mutates state on a
`GET`.

**What leaves the network.** Be explicit with admins, because this is the question a university
compliance office will ask: the full contents of every converted PDF are uploaded to the Iris
deployment, where they are processed by a large language model and stored until the session is
closed. If a PDF is public on the site, that is a small step. If it is not, it should not be in
scope, and §8.6 is how we keep it out.

**Contributions to Iris.** Iris files GitHub issues automatically during some runs — a suggested
new agent, or an improvement to an existing one — with no way to opt out. **These reports can include
extracts of the document being converted.** The settings screen must say this plainly next to the
connection, because a super admin should not discover it from reading a public issue tracker. It is
not a blocker: the issues contain agent proposals and context, and this is the sustainability model
of the service we are using for free.

They are filed as the **deployment's own** GitHub account, not this network's. That cuts both ways
and the settings screen says both halves: nothing identifies this network as the source, so no
institutional account has to be provisioned or handed over — and equally, the institution gets no
credit for what it contributes and cannot watch those issues as itself.

---

## 15. Making this readable — requirements for code and documentation

The user asked for this explicitly, so it is a requirement with acceptance criteria, not a hope.

**Code**

- Every file opens with a comment answering two questions: *what is this* and *why does it exist*.
- Every function that is not obvious gets a short plain-language description above it. Describe
  what it does in words a competent developer who has never seen WordPress internals would follow.
- **Comments explain why, not what.** `$limit = 20; // 20 posts per tick` says nothing.
  `// 20 posts per tick keeps one tick under a second on shared hosting; raising it is the first
  thing to try on a fast server, and the first suspect if ticks start timing out` is worth
  writing.
- **A comment about a non-obvious decision should say what breaks without it.** The
  `equalify-iris` codebase does this consistently and it is the main reason that code is readable
  by someone new. Match it.
- Named constants, never magic numbers. `MAX_PDF_PAGES = 25` with a comment saying it mirrors the
  Iris server's own cap and must not be raised independently.
- No clever one-liners. A slightly longer function that reads top to bottom beats a chained
  expression that needs decoding.
- WordPress coding standards: tabs, `snake_case` functions, `Equalify_Iris_*` class names, no
  namespaces, `init()` methods that register hooks rather than constructors that do work. This
  matches the earlier plugin and the wider WordPress world, so a WordPress developer is never
  surprised.
- Enforced by `phpcs` with the WordPress ruleset in CI.

**Documentation** — in `docs/`, and reviewed for plain language as seriously as code is reviewed:

| File | Contents |
| --- | --- |
| `README.md` | What it does, install, check the connection, first run. Should get someone from zero to a first converted PDF. |
| `HOW-IT-WORKS.md` | The whole flow in plain language with the diagram from §4. Written for someone who has never seen this repo. |
| `GLOSSARY.md` | Every term defined in one sentence: sighting, sweep, tick, session, open and gated deployment, hidden CPT, backoff, circuit breaker. |
| `TROUBLESHOOTING.md` | Symptom → cause → fix. Starts with "nothing is happening", because that is the common one. |
| `DEVELOPING.md` | Local setup, how to point at a local Iris, how to run the tests, how to add a status. |
| `DECISIONS.md` | §5 of this document, kept current as decisions change. |

**Admin copy is documentation too.** Every message says what happened, why, and what to do next.
"API error 409" is unacceptable. "Iris is still converting this document — it will finish on its
own, usually within ten minutes" is the standard.

---

## 16. Testing

- **Unit tests** for the parts with real logic and no network: finding PDF links in messy content,
  the HTML allowlist, the backoff schedule, page-count reading, slug and URL building, and the
  retirement rule.
- **A test that a PDF on a sub-site resolves to its attachment while switched into that site.** On a
  subdirectory multisite, `wp_get_upload_dir()` reports a `baseurl` built from `WP_CONTENT_URL`, a
  constant fixed at bootstrap that `switch_to_blog()` cannot change — so the uploads URL it gives
  during a background tick is missing the sub-site's path segment and core's
  `attachment_url_to_postid()` matches nothing. Every PDF on every site but the main one silently
  became invisible, was never converted, and was then retired for having no sighting. Nothing about
  it looked like a failure, which is exactly why it needs a test.
- **Integration tests** against a mock Iris that returns every documented shape, including `409`,
  `429`, `failed`, **both** kinds of `401` from §5.1, and a truncated response. Every branch in §8
  should be reachable in a test. The gated/open distinction matters here: a mock that always requires
  a token would have hidden the bug where the plugin refused to work against an open deployment.
- **A multisite test** proving discovery walks sites correctly and never crosses site boundaries
  when resolving attachments.
- **Accessibility tests** with axe-core on a rendered document page and on a post with an injected
  icon, both asserting zero violations.
- **A resource test** asserting that a tick with a full queue stays inside its time budget and its
  operation caps.
- **A concurrency test** proving two overlapping ticks cannot upload the same document twice — the
  claim-by-update in §8.3 is the kind of thing that looks obviously correct and is worth pinning
  down.

---

## 17. What throughput to actually expect

This belongs in the PRD because it changes what we promise an admin.

Iris runs **2 conversions at a time for the entire deployment**, shared with every other client.
A document takes minutes, not seconds — call it 3 to 8 depending on page count.

| Documents | At ~30/hour | At ~15/hour |
| --- | --- | --- |
| 100 | ~3 hours | ~7 hours |
| 1,000 | ~1.5 days | ~3 days |
| 10,000 | ~2 weeks | ~4 weeks |

So: **a large network's first sweep takes days to weeks, and that is the expected behavior, not a
bug.** Three consequences.

1. The dashboard shows an estimated completion time from the observed rate, and the documentation
   sets this expectation before an admin presses Start.
2. Conversion order should be worth arguing about. First-found is the simple default. Prioritizing
   PDFs that appear on the most pages, or on the most-visited pages, would deliver
   accessibility where it matters soonest. **Recommended:** ship first-found in v1, order by
   sighting count in v2, and keep the ordering in one function so it is easy to change.
3. If this rate is too slow for the network we are targeting, the fix is on the Iris side —
   raising `max_concurrent_runs`, or a dedicated deployment — not in this plugin. Uploading faster
   just fills a FIFO queue and gains nothing.

---

## 18. What the admin sees when things go wrong

| What happened | Status | What the admin sees |
| --- | --- | --- |
| PDF has 60 pages | `too_long` | "This PDF has 60 pages. Iris converts up to 25. Split it into smaller files to convert it." |
| PDF is 80 MB | `too_big` | "This file is 80 MB. The limit is 50 MB." |
| Deployment is gated and we have no secret | `401`, permanent | "This Equalify Iris deployment is closed and needs a shared API token. Ask whoever runs it for the token, then enter it below." Processing pauses, nothing is lost. |
| The deployment cannot authenticate itself to GitHub | `401`, retryable | "Equalify Iris cannot authenticate itself to GitHub, so it is not converting anything for anyone. Nothing on this network needs fixing — tell whoever runs Equalify Iris. It often clears up by itself." Processing continues. |
| Iris is down | circuit breaker open | "Cannot reach Iris. Retrying in 15 minutes." |
| Iris rate-limited us | backing off | "Iris is busy. Waiting before trying again." |
| Conversion failed on the server | `failed` | Iris's own error text, plus a Retry button. |
| Conversion never finished | `failed` | "This document did not finish within two hours. Retry it." |
| WP-Cron is not firing | health warning | "No background activity for 30 minutes. Check that cron is running," with the crontab line. |
| File missing from disk | `failed` | "The PDF file could not be found on the server." |

Every one of these is a full sentence in the dashboard, not a code. That is the standard from §15.

---

## 19. Milestones

**Phase 1 — Foundation.** Plugin scaffold, network admin menu, both tables, the hidden post type
and its rewrite rule, settings screen, the connection check, and `GET /v1/me` working. Ends with:
a super admin can confirm Iris will accept this network, and see whether the deployment is open or
gated.

**Phase 2 — One document, end to end.** The Iris client, pre-flight checks, upload, poll, HTML
cleaning, and publishing. Driven by `wp equalify-iris convert --url=...`. Ends with: one PDF
becomes one accessible page at its real URL.

**Phase 3 — The icon.** Content filter, server-side icon, stylesheet, document template,
deduplication, and the axe-core tests. Ends with: a visitor can get from a PDF link to the
accessible version.

**Phase 4 — Automation at scale.** The cron tick with its full budget, the queue, claim-by-update,
backoff, circuit breaker, the resumable sweep, the publish hook, and retirement. Ends with: press
Start and walk away.

**Phase 5 — Operations.** Full dashboard with progress and throughput, documents table with bulk
actions, activity log, all WP-CLI commands including `doctor`, and the `docs/` set. Ends with:
someone other than the author can run this.

**Phase 6 — Hardening.** Test a real network with thousands of PDFs. Measure actual tick cost and
throughput. Fix what the numbers say instead of what we guessed.

Phases 1 through 3 are the risky, interesting part — if a PDF cannot become a good accessible page
at a stable URL, nothing after that matters. Phase 4 is well-understood WordPress work.

---

## 20. Acceptance criteria

**It works**

- [ ] A super admin can confirm from the network dashboard that Iris will accept this network, and
      see whether the deployment is open or gated and which account it files contributions as.
- [ ] Against an **open** deployment the plugin converts without any credential being entered
      anywhere.
- [ ] Against a **gated** deployment, pasting the shared secret is enough, and the plugin says so
      immediately rather than at the next background tick.
- [ ] Pressing Start finds every PDF linked from published content on every site, and the sweep
      resumes correctly after being interrupted.
- [ ] PDFs on **sub-sites** are found, not only those on the main site — the sweep runs switched into
      each site, where the uploads URL WordPress reports is not the one stored in the content.
- [ ] Each PDF becomes a published page at `/equalify-iris/{id}/{slug}/` whose content matches the
      Iris output apart from documented cleaning.
- [ ] Every PDF link on public content is followed by exactly one icon link — including inside a
      file block, which outputs two anchors.
- [ ] Publishing a new post with a PDF queues it without a sweep.
- [ ] Unpublishing every post that links to a PDF unpublishes its accessible version.
- [ ] Replacing a PDF file re-converts it and keeps the same URL.
- [ ] Pressing Stop halts new work; in-flight documents finish cleanly.

**It is accessible**

- [ ] A document page passes axe-core with zero violations, at 400% zoom and 320px wide.
- [ ] The icon works with JavaScript disabled and has an accessible name naming the document.
- [ ] Tables keep `<caption>`, `<thead>`, and `<th scope>` through cleaning and storage.
- [ ] A screen reader user can get from a PDF link to the accessible version in one action.

**It is resource-friendly**

- [ ] A tick with 1,000 documents queued stays within its time budget and operation caps.
- [ ] Never more than the configured number of documents in flight at Iris.
- [ ] Two overlapping ticks never upload the same document twice.
- [ ] Iris being unreachable costs at most one request per 15 minutes.
- [ ] Front-end page-load cost of the content filter is measured and stated.

**It handles limits honestly**

- [ ] A 26-page PDF is marked `too_long` before any upload, with its real page count shown.
- [ ] An oversized PDF is marked `too_big` and never loaded into memory.
- [ ] A gated deployment with no secret pauses processing with a clear prompt and loses nothing.
- [ ] A deployment whose own GitHub credential is failing does **not** pause processing, and is
      reported as somebody else's problem.
- [ ] Correcting a wrong secret resumes the background job without any further button press.
- [ ] The dashboard names the account Iris files contributions as, and states that this network is
      neither identified nor credited.
- [ ] Every failure state in §18 renders as a full sentence, with no raw HTTP codes.

**It is readable**

- [ ] `phpcs` with the WordPress ruleset passes in CI.
- [ ] Every file has a what-and-why header; every non-obvious function has a plain-language
      description.
- [ ] All six `docs/` files exist and are current.
- [ ] A developer who has not seen the repo can explain the flow after reading `HOW-IT-WORKS.md`,
      and can fix a seeded bug. This gets tested on a real person before release.

---

## 21. Open questions

1. ~~**Whose GitHub account should the network use?**~~ **Resolved — the question no longer exists.**
   Iris v1 removed client sign-in altogether: it holds its own GitHub credential server-side and
   files everything as its own account. There is no personal credential to inherit, no
   authorization for a departing admin to revoke, and nothing to record in `docs/` about who owns
   it. The whole failure mode this question was about — one person leaving and stopping every
   conversion on the network — is gone.

   What is left is the other side of the same coin, and it is a policy question, not a technical
   one: **the institution gets no credit** for what it contributes, and cannot watch the issues Iris
   files as itself. The settings screen states this rather than only the convenient half. If
   attribution turns out to matter to the institution, that is a change to ask Iris for upstream —
   the plugin cannot create it.

   One requirement survives: the dashboard names the account Iris files as, so it is never a mystery
   where a contribution came from.
2. **How many PDFs on the target network exceed 25 pages?** If it is a large share, "mark
   unsupported" leaves a real accessibility gap and splitting moves back into scope. A one-off
   count over the network's uploads answers this cheaply and should happen before Phase 2.
3. **Who pays for model usage, and is there a budget ceiling?** Ten thousand documents is a real
   cost at the Iris deployment. The plugin may need a daily conversion cap.
4. **Is the UIC Iris deployment sized for this?** Two concurrent runs shared with all other users
   is the current capacity. A network-wide backfill will saturate it for weeks and affect other
   users of that deployment. Worth agreeing before we press Start.
5. **Should the sweep include PDFs in Media Library but not linked anywhere?** The current scope is
   "PDFs on published content", which is the right accessibility boundary. An admin may reasonably
   want everything.
6. **What happens on a site with an aggressive page cache?** The icon is in the cached HTML, so a
   PDF converted after a page was cached shows no icon until the cache clears. Options: accept it,
   or purge the relevant pages on publish. Needs a decision, and it is easy to miss.

---

## 22. Explicitly out of scope for v1

- Splitting PDFs longer than 25 pages.
- Any per-site or per-attachment admin interface.
- An editor-facing workflow, approval step, or notification.
- Sending feedback to Iris to improve a conversion (`POST /feedback` exists; no human is reviewing).
- Translating converted documents.
- Converting Word, PowerPoint, or Excel files.
- Single-site (non-multisite) support. Worth adding later; the network-only assumption is
  load-bearing throughout, so it is not free.
- A front-end index of every converted document.
