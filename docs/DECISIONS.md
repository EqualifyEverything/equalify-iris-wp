# Decisions, and why

Each of these has a plausible alternative. They are written down so the next developer does not
have to re-argue them — and so that if one of them turns out to be wrong, it is obvious what was
being traded away.

Keep this file current. A decision that changed and was not recorded here becomes folklore.

---

## Product shape

### All control lives in the Network Admin dashboard. There is no per-site or per-attachment UI.

The older `equalify-reflow-wp` plugin put a panel on every PDF in the Media Library and asked an
editor to press a button. That does not scale to a network with thousands of PDFs and hundreds of
editors, and it made accessibility opt-in per document — which in practice means "mostly off".

Here, conversion is automatic and network-wide, and editors have no switch to forget to flip. The
cost is that a single site owner cannot exclude one document without a super admin. That is the
right trade: this is an accessibility floor, not a preference.

### The original PDF is never touched, moved, or replaced.

We are not a remediation tool. The PDF stays where it is, its link keeps working, and it remains
the authoritative version — which the converted page says out loud. Some people need the PDF: to
print, to file, because it is the record.

### The icon is a second, separate link, not a replacement.

Replacing the PDF link with a link to our version would be a bigger, more helpful-seeming change
that takes a choice away from the reader. Adding a link takes nothing away.

---

## Authentication

> **Superseded.** Until Iris v1 this section described a GitHub device-flow sign-in: the super admin
> approved a code at github.com and the plugin stored the resulting user token. Iris v1 removed
> client sign-in entirely. That whole mechanism is gone from the plugin — no device flow, no GitHub
> token, no expiry warning — and what follows replaces it. The old decision is noted rather than
> deleted because an install predating the change still has a live GitHub credential sitting in
> `wp_sitemeta`, which is why `uninstall.php` deliberately still deletes `equalify_iris_token`.

### There is nothing to sign into, so the plugin does not pretend there is.

Iris v1 has no user accounts and issues no credentials. It holds its own GitHub credential
server-side and files every contribution as its own account. So there is no identity for this plugin
to establish, and any screen offering to "connect an account" would be describing something that does
not exist.

What a deployment *may* have is a shared secret — `server.api_token` in its config — which its
operator sets to stop strangers using it. It gates exactly two things, `GET /v1/me` and
`POST /v1/sessions`. A deployment without one is **open**; with one, **gated**. The production
deployment is open.

So the plugin models auth as one optional secret and nothing else: send `Authorization: Bearer` when
we hold one, omit the header entirely when we do not.

### The connection check asks "will Iris accept us", not "do we hold a credential".

This is the important one, and getting it backwards is what broke the plugin against v1.

The obvious `is_connected()` — do we have a non-empty token? — gives the wrong answer for the
*normal* case. Against an open deployment the correct token is no token, so a plugin that requires
one before working converts nothing at all on a perfectly healthy network, and offers a sign-in
button that 404s. That is precisely what happened.

So the question became a real one, answered by Iris rather than by our own database:
`GET /v1/me`. `200` means usable, whether or not we sent anything. `401` means refused, and only then
does the plugin stop.

Two consequences worth stating:

- **`blocked_by_auth()` is pessimistic in one direction only.** It is true only when Iris actually
  answered `401` asking for a secret we do not have. "Not checked yet" is not a refusal, and a
  network that has never pressed a button still works.
- **Any `2xx` clears a previous refusal.** `note_authorized()` runs on every successful response, so
  correcting a wrong secret unblocks the background job without anyone hunting for a button to press.

### The two kinds of `401` are different problems and are told apart.

Iris answers `401` for two unrelated reasons, and treating them alike would be actively harmful:

| Iris says | Whose problem | What we do |
| --- | --- | --- |
| requires a shared API token | The super admin's | **Permanent.** Stop. A human has to obtain and paste a secret; retrying cannot help. |
| could not authenticate to GitHub | The *deployment operator's* | **Retryable.** Iris retries GitHub after 30 seconds and it often clears by itself. Keep working. |

The second is the one worth the code. Reported as an authentication failure it would send a super
admin hunting through their own configuration for a fault that is not there, possibly for an
afternoon. The admin screens therefore say it in as many words: nothing on this network needs fixing,
tell whoever runs Equalify Iris. `handle_unauthorized()` distinguishes them on the message text,
which is fragile in principle but is the only signal Iris gives — and the fallback is the safe one:
an unrecognised `401` is treated as "needs a token", which stops rather than looping.

### A `wp-config.php` constant beats the database.

`EQUALIFY_IRIS_API_TOKEN`, if defined, wins. A secret in a file that is not in the web root and not in
the database is easier to rotate, easier to keep out of a database dump, and easier to manage from
configuration. The Settings screen says which source is in use, and offers no field at all when the
constant is set — an editable box that silently has no effect is worse than no box.

When the secret is in the database instead, the field is a password field, never echoed back, and the
screen suggests moving it to the constant.

### Who owns the deployment's GitHub account is not this network's concern.

This is a genuine reduction in scope from the old decision, and cuts both ways. Nothing identifies
this network as the source of a contribution, so no institutional account has to be provisioned,
shared, or handed over when someone leaves. Equally, **the institution gets no credit** for what it
contributes. The Settings screen states both halves of that trade rather than only the convenient
one.

---

## The Iris relationship

### PDFs longer than 25 pages are marked unsupported, not split.

Iris hard-caps at 25 pages. Splitting a PDF inside PHP needs a PDF library on the host and produces
a worse reading experience across stitched parts — a table of contents that stops, cross-references
that go nowhere.

Instead the plugin counts pages before uploading, marks the document `too_long`, and lists it in the
dashboard so the admin knows exactly what was not converted. Honest and simple beats half-working.

### `too_long` and `too_big` are not failures.

They are final states with no Retry button. Two reasons: retrying cannot possibly help, and keeping
them out of the failure count means the failure count is a number an admin can drive to zero. A
dashboard that permanently shows "47 failures" trains people to ignore it.

### An unknown page count is allowed through.

The page counter reads raw bytes and can be defeated by an unusual PDF. Refusing a convertible file
because we could not count its pages would be worse than trying and letting Iris decide. Iris
rejects what it cannot take, and that rejection is recorded.

### Polling, not webhooks.

Iris v1 has no webhooks. Clients poll. That is why the backoff schedule matters: 1, 2, 4, 8 minutes
capped at 15, so a slow document does not mean constant polling.

### At most two conversions in flight.

A deployment runs `max_concurrent_runs` pipelines at once, which defaults to 2. Uploads past that are
not rejected — they **wait in `queued`**, in order, for as long as it takes.

So this is our number, not Iris's, and raising it still does not make anything faster: it moves the
queue from our side of the wire to Iris's, where the dashboard cannot see it and where our documents
compete with everyone else's on a shared deployment. Two in flight means our queue length is a number
an admin can read, and the shared service is not being crowded by one WordPress network. Nothing
breaks if it is raised; the honest answer is just that it buys nothing.

### The 25-page and 50 MB caps are copied into the plugin, not fetched.

Both are Iris's limits — `MAX_PDF_PAGES` in `src/util/pdf.ts` and the multer `fileSize` limit in
`src/routes/sessions.ts` — and the plugin holds its own copy of each.

Iris does publish some of this at `GET /v1/limits`, and reading from there would keep the numbers in
step automatically. It is not used at upload time on purpose: the worker decides "is this PDF too
long?" while holding a file, and making that decision depend on a network call means no document can
be inspected while Iris is unreachable — turning a clear "too long" into a stall. The check would
also have to work when the circuit breaker is open, which is exactly when it cannot ask.

`wp equalify-iris doctor` asks `/v1/limits` instead and says so if the page cap has moved. That puts
the answer where somebody is already looking for one, and costs nothing when nobody is.

### Iris refusing a file is not a failure to retry.

A `400`, `413` or `422` is Iris looking at what we sent and saying no. The same bytes will get the
same answer in five minutes, so those stop on the first attempt and report Iris's own sentence —
the same treatment as `too_long` and `too_big`, which the plugin catches for itself.

This matters most for the refusal the plugin cannot predict: Iris renders every page at a fixed
resolution, so a poster-sized or drawing-sized page becomes an image too large for the vision model,
and nothing about the file's page count or byte size says so in advance. Without this distinction
such a PDF costs five uploads over two and a half hours and then reports "gave up after several
attempts" instead of the reason.

### A document missing a page is still published, and the log says so.

Iris converts each page separately, and one page can fail on its own — a dense table that overran the
model's output limit, say — without failing the document. Everything else is delivered, with the hole
marked by a `@page-failed` comment.

The plugin publishes it. A page with 24 of 25 pages readable is still an enormous improvement on a
PDF a screen reader cannot open at all, and withholding it helps nobody.

But it does not publish it silently, because a reader has no way to tell that something is missing
and neither would an admin looking at a green "published" row. So the count goes into the activity
log as a warning naming the document, and onto the page as `_equalify_iris_pages_missing` for anyone
who wants to find them all later.

### An unknown status from Iris means "still working", not "broken".

We map `queued`/`running` to waiting, `ready_for_review`/`closed` to ready, `failed` to failed — and
anything else to waiting. Iris may add statuses. A client that treats every unfamiliar string as an
error breaks the moment upstream ships an improvement.

### A circuit breaker, not endless retries.

Five consecutive network failures stop outbound calls for 15 minutes. There is nothing to learn from
the sixth timeout in a row, and a WordPress network retrying a downed service every five minutes
helps nobody. While the breaker is open, ticks still retire orphans and continue the sweep, because
neither needs the network.

### Iris files public GitHub issues about the documents it converts. We accept this and say so.

Iris's contribution loop reports conversion problems as public GitHub issues, and those reports can
include extracts of the document. **This cannot be turned off from the plugin.** The Settings screen
states it plainly, in its own panel, rather than burying it in a docblock — because the person who
needs to know is the super admin deciding whether to point this at their network, not a developer
reading source.

The practical guidance is one sentence: do not point this plugin at confidential documents.

Note that these issues are filed as the *deployment's* GitHub account, not this network's. That
removes an account-management problem and means nothing identifies this network as the source — but it
also means the institution is not credited for what it contributes, and cannot watch the issues as
itself.

---

## Naming

### Everything is `equalify-iris`, not `equalify-reflow`.

The plugin, the service, the URLs, and the CSS class names should all say the same thing. This
changes public URLs and CSS class names relative to the earlier Reflow plugin, and that is accepted:
the URLs are new, so nothing breaks, and a consistent name is worth more than continuity with a
plugin this one replaces.

---

## The front end

### The icon is rendered server-side in PHP. The plugin ships no front-end JavaScript at all.

The earlier plugin injected icons with JavaScript after page load. That makes the accessible
alternative depend on JavaScript — exactly the wrong dependency for this audience — and it breaks
under page caching and content-security policies.

Rendering in `the_content` means the link is in the HTML the server sends: it survives caching, works
with scripting disabled, and needs no CSP exception.

### One indexed query per page, from the sightings table.

The alternative is searching post content at render time, on every page load, for every PDF the
plugin knows about. The sightings table already exists for retirement, so the front end gets its
answer from a single join on an indexed key. Almost every page also short-circuits before the query
runs, because the content does not contain `.pdf` at all.

### The icon link carries three accessible names, deliberately.

An `aria-label`, an `aria-hidden` SVG, and a visually hidden text label. Redundant on purpose: an
icon-only control with no accessible name is the precise failure this plugin exists to fix, and it
is not acceptable to reproduce it in our own markup.

### `icon.css` is a guest, not a landlord.

It loads on every page of every site in the network. So: no reset, no `!important`, no web font, and
no colour rules at all — the SVG uses `fill="currentColor"` and inherits whatever contrast the theme
has already got right.

### The visually hidden label repeats WordPress's `.screen-reader-text` rules instead of relying on them.

We cannot assume the theme defines that class. If it does not and we do not, the label becomes
visible text next to every icon. And it is clipped rather than `display: none`, because
`display: none` and `visibility: hidden` hide text from screen readers too, which would defeat the
whole point.

---

## The converted page

### The HTML lives in a hidden custom post type, in `post_content`.

It needs a permanent public URL, a title, and a slug — that is a post. `public => true` so visitors
can read it; `show_ui => false` so editors never see it, because they have no job to do here. A
`map_meta_cap` filter returns `do_not_allow` for editing, so nobody hand-edits a machine-generated
document and then loses it on the next conversion.

Storing the HTML in `post_content` rather than post meta means WordPress search, sitemaps, and themes
treat it as real content — which is the point.

### The URL is `/equalify-iris/{attachment_id}/{title-slug}/`, resolved with no database lookup.

The post slug is stored as `{attachment_id}-{title-slug}`. The rewrite rule captures two segments and
glues them back with a hyphen, handing WordPress an ordinary post name. The ID makes the URL stable
and unique; the slug makes it readable and shareable; and nothing has to be looked up to resolve it.

### Incoming HTML is cleaned once, on the way in, against an allowlist.

An allowlist, not a blocklist, because a blocklist is always one tag out of date. It is WordPress's
own `wp_kses` list extended with what accessibility needs: `scope`, `headers`, `colspan`, `rowspan`,
`aria-*`, `role`, `lang`, `id`.

Cleaning at save time rather than render time means the cost is paid once per document instead of
once per visitor.

### The insert is wrapped in `kses_remove_filters()` / `kses_init_filters()`.

Cron has no logged-in user, and WordPress's own content filtering would strip tags from our
already-cleaned HTML on the grounds that "nobody" is not allowed to post them. Removing the filters
for the duration of one insert is the narrowest fix available; the content has already been
allowlisted at this point.

### The page says it is machine-made, and links the original prominently.

Not a disclaimer for our benefit — useful information. It explains any oddity the reader hits, and it
makes sure nobody is trapped in our version. The link includes page count and file size, because
"PDF, 4 MB, 18 pages" lets someone on a metered connection decide before downloading.

### `document.css` styles our furniture, not the document.

A converted document should look like it belongs to the site it is on. The exceptions are tables and
figures, which get the minimum needed to stay readable — themes handle unexpected tables badly, and a
data table that has lost its borders is exactly the problem this plugin sets out to fix.

### The table of contents is flat, indented by level, never nested.

A screen reader announces a nested list's depth, which would be useful. But building real nesting
from a flat list of headings goes wrong the moment a document skips a level, and real documents skip
levels constantly. A flat list that is always correct beats a nested one that is sometimes wrong.

### A theme overrides the page with `single-equalify_iris_doc.php`.

Ordinary template hierarchy. No plugin-specific knowledge, no filter to discover, no documentation
to read.

---

## The background job

### A custom database table holds the to-do list.

The list is network-wide, needs indexed lookups by status and by "what is due now", and will hold
tens of thousands of rows. Options and post meta are the wrong shape for all three, and putting this
in the options table would bloat the cache that loads on every request.

### The sightings table serves two purposes, and that is on purpose.

It answers "is this PDF still public anywhere?" (retirement) and "does this page need icons?" (the
front end). One table, two indexed questions, no content searching.

### A tick has hard caps, checked between operations.

20 seconds, 1 upload, 5 status checks, 2 imports, 20 posts swept. Checked *between* operations rather
than only at the start, so one slow upload cannot blow the budget.

The plugin must never be the reason a page is slow or a host complains. Progress being slower than an
admin would like is an acceptable cost; being the thing that got the network throttled is not.

### Inside a tick, finishing beats starting.

Imports run before uploads. An import frees a slot at Iris; an upload consumes one. A tick that only
started work would grow the queue, and a queue that grows faster than it drains never converges.

### `claim_next()` is the concurrency guarantee. The transient lock is only an optimisation.

Two ticks can overlap; WordPress cron makes no promise otherwise. The transient lock is cheap and
usually works, but transients can be evicted, so it cannot be the guarantee. The guarantee is a
conditional `UPDATE … WHERE status = 'ready'` and a check of how many rows changed. Exactly one
process wins. Whoever changed a row owns it.

### `ready` and `importing` are separate statuses because MySQL counts changed rows, not matched rows.

An `UPDATE` that changes nothing reports zero affected rows. Claiming `importing → importing` would
therefore silently never claim anything, and the import stage would quietly do nothing forever. Two
distinct statuses make the claim observable.

This is the single subtlest thing in the plugin. It is also the reason the status list is longer than
it first looks.

### Orphan detection has a 10-minute grace period.

A document row is written a fraction of a second before its first sighting. Without the grace period,
a tick landing in that window would retire a PDF it had only just discovered — and then the icon
would never appear.

### Retirement is a privacy obligation, not housekeeping.

Somebody unpublished a page. If our copy of its PDF stayed readable at a public URL, we would have
quietly undone their decision. That is why retirement runs even when the circuit breaker is open: it
needs no network, and it is not optional.

### A revived document keeps its original URL.

If a page is republished, the same draft page is republished at the same address, so links people
already shared keep working. `revive()` compares against the literal `'publish'` — WordPress's post
status — and not `Documents::PUBLISHED`, which is our own pipeline stage and spelled `'published'`.
Those two strings being one letter apart is a genuine trap.

### The sweep is resumable and slow on purpose.

A cursor of site plus post ID, stored as a network option. Each run handles one small batch and saves
the cursor. Interrupted by a timeout, a deploy, or a restart, the next run repeats at most one batch.

### A new site joining the network reopens a completed sweep.

`wp_initialize_site` clears the "complete" flag so the new site gets walked. Otherwise a network that
finished sweeping would silently never look at anything added later.

---

## Security

### Only attachments this network owns are uploaded.

`Discovery::resolve_attachment()` returns 0 for anything it cannot match to an attachment on this
network, and 0 means "do not queue". Uploading arbitrary URLs to a converter on somebody else's
behalf is a server-side request forgery waiting to happen — and converting other people's documents
is not what this plugin is for.

### Only `publish`, non-password-protected posts are scanned.

A PDF on a draft or a private page is not public, so an accessible copy of it must not be public
either.

### Every admin action is a POST form, never a link.

Link prefetchers, email preview services, and security scanners follow links. "Stop the whole
process" is not something an email client should be able to trigger. Every button is a form with a
nonce, checked after the capability check, then redirected so nothing re-runs on refresh.

### Notices come from a server-side lookup table, keyed by a code in the URL.

Never from text in the URL. Otherwise anyone could send a super admin a link that displays a
convincing fake message in the WordPress admin.

---

## Code style

### Plain `require` calls, in dependency order, not an autoloader.

Fourteen files. An autoloader would hide the load order, which is one of the more useful things a
newcomer can read off the bootstrap file in ten seconds.

### WordPress conventions throughout: tabs, `snake_case`, `Equalify_Iris_` prefixes, no namespaces.

Code should read like the platform it runs on. A WordPress developer of any experience level should
be able to open any file here and recognise it.

### Every class has a "WHAT IS THIS FILE?" header, and comments explain why, not what.

A comment that restates the code is noise that goes stale. A comment explaining why the code is
shaped this way is the only record of a decision made once and never revisited. The MySQL
affected-rows note above is worth more than any amount of `// Loop through documents`.

### Admin copy is written for a person, not for a developer.

"Ready to save", not `READY`. "Paused after repeated problems reaching Equalify Iris", not "circuit
breaker open". The state badge pairs a colour with a word, so it survives being printed in black and
white or read aloud.

---

## Uninstalling

### Deleting the plugin drops the tables and settings but keeps the converted pages.

Those pages are live URLs people have bookmarked, shared, and linked to. Deleting a thousand of them
because somebody removed a plugin would be a shock, not a cleanup — and it is not recoverable. They
become invisible orphaned posts of an unregistered post type: harmless, and still there if the plugin
comes back.

### Deactivating deletes nothing at all.

It unschedules the job and flushes rewrite rules. Deactivation is what you do to diagnose a problem;
it should never be the thing that loses your data.
