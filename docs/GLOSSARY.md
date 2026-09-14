# Glossary

Every term this project uses, in one sentence. Alphabetical. If you find a word in the code or the
admin screens that is not here, that is a bug in this file.

---

**Allowlist** — A list of the HTML tags and attributes we permit, used instead of a list of ones we
ban, because a banned list is always one new tag out of date.

**API token** — The optional shared secret an Iris operator can set to stop strangers using their
deployment. Sent in the `Authorization: Bearer` header when we hold one, omitted entirely when we do
not. It identifies nobody — it is a door key, not an account. See **Open deployment**, **Gated
deployment**.

**Attachment** — WordPress's word for an uploaded file, which for us means a PDF in the Media
Library; the plugin only converts PDFs it can match to an attachment on this network.

**Backoff** — Waiting longer between each check on a document that is still converting (1, 2, 4, 8
minutes, capped at 15), so a slow document does not mean constant polling.

**Circuit breaker** — After five consecutive network failures, the plugin stops calling Iris for
15 minutes, because a service that is down does not benefit from being retried every five minutes.

**Claim** — A conditional `UPDATE … WHERE status = 'ready'` that moves one row to `importing`; only
one process can win it, which is how two overlapping cron ticks avoid doing the same work twice.

**Cursor** — The sweep's bookmark: which site and which post ID it last reached, stored as a
network option so the walk survives being interrupted.

**Deployment** — One running copy of Iris, at one address. Whoever runs it supplies its GitHub
account and decides whether it is open or gated. The plugin talks to exactly one at a time.

**Document** — One row in the plugin's to-do list: one PDF, on one site, with the status of its
conversion.

**Failed** — Something went wrong that might not go wrong next time; failed documents can be
retried, up to five attempts.

**Gated deployment** — An Iris deployment whose operator has set a shared secret. `GET /v1/me` and
`POST /v1/sessions` answer `401` without it. Paste the secret in and it behaves exactly like an open
one.

**Grace period** — The 10 minutes after a document is created during which it cannot be retired,
because its first sighting is written a fraction of a second after the document itself.

**Hidden CPT** — A custom post type that is `public` (so visitors can read it) but has `show_ui`
off (so editors never see it in the admin); it holds the converted HTML.

**In flight** — A document that is currently uploading, converting, ready, or importing; at most
two are ever in flight, matching the two conversions Iris runs at a time. Iris would accept more and
queue them, so this is our limit rather than its refusal.

**Incomplete document** — A converted page that is missing one of the source PDF's pages, because
that one page failed on its own while the rest converted. It is published anyway and the activity
log says so.

**Iris** — [Equalify Iris](https://github.com/EqualifyEverything/equalify-iris), the service that
converts a PDF into accessible HTML; it runs at `https://iris.equalify.uic.edu/`.

**Multisite** — One WordPress install running many sites, with one Network Admin dashboard above
them; this plugin only runs on multisite and only has an interface there.

**Network option** — A setting stored once for the whole network rather than per site
(`get_site_option`); everything this plugin remembers is one of these.

**Orphan** — A document that no longer appears on any published post, which means its accessible
version should be unpublished.

**Open deployment** — An Iris deployment with no shared secret set, which is the default and what
the production one at `iris.equalify.uic.edu` is. Anyone who can reach it can use it, so the plugin
needs no credential at all.

**Output** — The HTML Iris produces, fetched from `GET /v1/sessions/{id}/output`; a `409` there
means "not ready yet" rather than "something is wrong".

**Permanent rejection** — Iris looking at an uploaded file and refusing it (HTTP 400, 413 or 422).
Retrying sends the same bytes for the same answer, so these fail on the first attempt and report
Iris's own explanation.

**Published** (as a document status) — The accessible HTML version is live at a public URL; this is
the goal state, and it is spelled `published`, which is deliberately *not* the same string as
WordPress's own post status `publish`.

**Retire** — Unpublishing a converted document because its PDF is no longer linked from anything
public; a privacy obligation, not housekeeping.

**Revive** — Republishing a retired document at its original URL because the PDF became public
again, so old links keep working.

**Screen reader** — Software that reads a page aloud or sends it to a braille display, and the
reason this plugin exists.

**Session** — One conversion job at Iris, created by uploading a file and identified by an ID; it
is created, polled, read, and then closed to free a slot.

**Sighting** — A row saying "this document appears on this post of this site"; sightings answer
both "is this PDF still public?" and "does this page need icons?" without searching post content.

**Skip link** — The hidden link at the top of a converted document that jumps a keyboard user
straight to the document text, past everything the plugin added.

**Sweep** — The one-time walk through every site in the network looking for PDFs on published
content; it is slow on purpose and resumable.

**Tick** — One run of the background job: a small, capped amount of work — retire, import, check,
upload, sweep — every five minutes.

**Too big** — A PDF over 50 MB, which Iris will not accept; a final state, not a failure, because
retrying it will never help.

**Too long** — A PDF over 25 pages, which Iris will not accept; also a final state, and listed in
the dashboard so an admin knows exactly what was not converted.

**Work budget** — The caps on a single tick (20 seconds, 1 upload, 5 checks, 2 imports, 20 posts),
checked between operations so one slow call cannot overrun them.

**WP-Cron** — WordPress's scheduler, which is not really cron: it only runs when somebody visits
the site, which is why a real server cron entry is strongly recommended.
