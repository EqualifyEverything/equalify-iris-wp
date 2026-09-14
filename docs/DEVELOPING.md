# Developing

How to get a working setup, how to change things safely, and where the traps are.

If you have not read [HOW-IT-WORKS.md](HOW-IT-WORKS.md) yet, read that first. This file assumes it.

---

## The repo

```
equalify-iris-wp/
├── PRD.md                      The product requirements this was built from
├── docs/                       You are here
├── plugin/equalify-iris/       The plugin. This is the thing that ships.
└── test-site/                  A disposable WordPress multisite for testing
```

Only `plugin/equalify-iris/` is the deliverable. `test-site/` exists so you can run the plugin, and
everything WordPress-shaped inside it is gitignored.

---

## Local setup

One command, from a fresh clone:

```bash
cd test-site && ./setup.sh
```

You need [DDEV](https://ddev.readthedocs.io/) (`brew install ddev/ddev/ddev`) and a running Docker
engine (Docker Desktop, colima, or OrbStack). Nothing else — PHP, MariaDB, and WP-CLI are all in
the containers.

First run takes a few minutes. It builds a three-site network, mounts the plugin live from
`plugin/equalify-iris`, network-activates it, and seeds every site with PDFs — including the awkward
cases: a draft, a private page, a duplicate link, an external PDF, and a 30-page document.

Full detail, including the list of things worth deliberately testing, is in
[test-site/README.md](../test-site/README.md).

**The plugin is bind-mounted, not copied.** Edit `plugin/equalify-iris/...` and the change is live
immediately. There is no build step, no watcher, and no way to accidentally test a stale copy.

### The loop you will actually use

```bash
cd test-site

ddev wp equalify-iris connect     # check Iris will accept us; no sign-in
ddev wp equalify-iris start

./tick.sh 5                       # run the background job five times
ddev wp equalify-iris log         # see what it did
```

`./tick.sh` matters more than it looks. In production the job runs every five minutes and does a
capped amount of work per run, so a document needs several ticks to get from "found" to "published".
Waiting five minutes per step while debugging is intolerable.

### Pointing at a local Iris

Recommended for anything more than a smoke test:

```bash
cd ../../equalify-iris && npm run dev     # Iris on port 8080
cd -
./bin/point-at-local-iris.sh
```

Two reasons beyond speed. Conversions on the shared service use real capacity. And Iris reports
conversion problems as **public GitHub issues**, which can include extracts of the document — fine
for the nonsense PDFs `setup.sh` generates, less fine for anything real you drop in.

`./bin/point-at-local-iris.sh --production` puts it back.

---

## Checking your work

There is no automated test suite yet. Until there is, these are the checks worth running every
time, and they are all fast.

### Syntax

```bash
cd plugin/equalify-iris
find . -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors'
```

Silence means clean. Run this before every commit — a parse error in a network-activated plugin
takes down every site on the network at once.

### Coding standards

```bash
composer global require wp-coding-standards/wpcs
phpcs --standard=WordPress plugin/equalify-iris
```

The code is written to WordPress standards: tabs, `snake_case`, `Equalify_Iris_` class prefixes, no
namespaces, Yoda conditions, escaping on output. Where a rule is deliberately suppressed there is a
`phpcs:ignore` with a comment saying why — direct database queries in `class-documents.php`, and the
custom cron interval in `class-scheduler.php`.

### Manual checks

The list in [test-site/README.md](../test-site/README.md#things-worth-deliberately-testing) is the
one that catches real bugs. If you change the queue, the retirement path, or the front end, run the
relevant section before you commit. Retirement especially: it is the part with a privacy consequence
if it breaks.

---

## Where to make a change

| You want to change… | Go to |
| --- | --- |
| A setting, its default, or its bounds | `includes/class-settings.php` |
| The database schema | `includes/class-database.php` **and** bump the schema version |
| What counts as a PDF worth converting | `includes/class-discovery.php` |
| How pages are counted | `includes/class-pdf-inspector.php` |
| Which HTML tags survive | `includes/class-html-cleaner.php` |
| What one tick does, and in what order | `includes/class-worker.php` |
| Any HTTP call, or the circuit breaker | `includes/class-api-client.php` |
| The icon's markup or the URL matching | `includes/class-frontend.php` |
| The document page | `templates/single-document.php` |
| An admin screen | `admin/class-admin-*.php` |
| A button's behaviour | `admin/class-admin.php` (one handler for all of them) |
| A CLI command | `includes/class-cli.php` |

New classes go in `includes/`, and get a `require_once` in `equalify-iris.php` in dependency order.
There is no autoloader on purpose: fourteen files, and the load order is the first useful thing a
newcomer reads.

---

## How to add a status

The most likely change, and the one with the most sharp edges. Suppose you want `needs_review`.

1. **Add the constant** in `class-documents.php`:
   ```php
   const NEEDS_REVIEW = 'needs_review';
   ```

2. **Add a human label** to `status_labels()`. Write it for a person: "Waiting for review", not
   `NEEDS_REVIEW`. This string appears in the dashboard.

3. **Decide whether it is in flight.** If a document in this status is occupying a slot at Iris, add
   it to `in_flight_statuses()`. Get this wrong and you either exceed Iris's two-conversion limit or
   deadlock the queue at zero.

4. **Decide whether it can be orphaned.** `orphaned()` lists the statuses eligible for retirement.
   A transient status usually should not be there; a settled one usually should.

5. **Decide whether it can be retried.** `class-admin-documents.php` chooses which statuses get a
   Retry button. If retrying cannot possibly help — as with `too_long` — do not offer it.

6. **Handle it in the worker.** Something has to move a document out of the new status, or it sits
   there forever. If nothing does, that is a leak.

7. **If you are claiming it with `claim_next()`, the from and to statuses must differ.** This is the
   trap. MySQL reports zero affected rows for an `UPDATE` that changes nothing, so
   `claim_next( X, X )` silently claims nothing, forever, with no error. It is exactly why `ready`
   and `importing` are two statuses instead of one.

8. **No database migration needed.** The status column is a varchar; a new value needs no schema
   change.

---

## How to change the schema

1. Edit the `CREATE TABLE` in `class-database.php`.
2. Bump the schema version constant in the same file.
3. Make sure `maybe_upgrade()` handles the change. It runs `dbDelta()`, which adds columns and
   indexes but **never drops or renames** anything.
4. Test the upgrade path, not just the fresh install: activate the old version, add documents, then
   swap in the new code and load a Network Admin page.

For a change `dbDelta()` cannot make, `./reset.sh` in the test site and start clean — then write the
migration deliberately.

---

## Working with the Iris API

Everything network-facing is in `class-api-client.php`. Nothing else in the plugin makes an HTTP
call, which means one file to read when Iris changes.

The conversion flow is four calls:

```
POST /v1/sessions              multipart upload, returns 201 + a session ID
GET  /v1/sessions/{id}         status: queued | running | ready_for_review | closed | failed
GET  /v1/sessions/{id}/output  the HTML; 409 means "not ready yet"
POST /v1/sessions/{id}/close   finish up, free the server's disk
```

Plus two that are not part of converting anything:

```
GET  /v1/me                    who this deployment files issues as; also the auth check
GET  /v1/limits                what this deployment accepts — never gated, used by `doctor`
```

Things that are easy to get wrong:

- **The upload part is called `images`**, even for a PDF. That is Iris's name, not a mistake here.
- **A `409` from `/output` is not an error.** It means the status raced ahead of the output. The
  document goes back to `converting` for a minute.
- **An unknown status means "still working".** Iris may add statuses; treating an unfamiliar string
  as a failure breaks the plugin the moment upstream ships an improvement.
- **`400`, `413` and `422` are permanent.** They are Iris looking at the file and saying no, so
  retrying sends the same bytes for the same answer. `handle()` tags them `permanent` and
  `upload_one()` stops rather than spending five attempts. Everything else is retried.
- **Two conversions at a time is *our* number, not Iris's.** A deployment runs
  `max_concurrent_runs` pipelines at once (default 2, and an operator can raise it), and uploads
  past that **wait in `queued`** rather than being rejected. So `max_in_flight` is politeness on a
  shared service, not a rule — see [DECISIONS.md](DECISIONS.md).
- **There is nothing to sign into, and usually no credential to send.** Iris v1 has no user
  accounts. An operator may set a shared secret, in which case `/v1/me` and `/v1/sessions` answer
  `401` without it; otherwise the deployment is open. `auth_headers()` sends `Authorization` only
  when we actually hold a token, and sending a stray one at an open deployment is ignored.
- **The two kinds of `401` are not the same thing and must not be handled the same way.** "Requires
  a shared API token" is permanent: a human has to paste a secret in, so we stop. "Could not
  authenticate to GitHub" is the *deployment's* own credential failing, which Iris retries after
  30 seconds — so it is retryable, and nothing on this network needs fixing.
  `handle_unauthorized()` tells them apart on the message text.
- **Uploads stream from disk with cURL** so memory stays flat. The WP HTTP fallback loads the file
  into memory, so it is gated on the file fitting there.
- **A converted document can be missing a page and still be a success.** Iris converts pages
  separately; one page can fail on its own and the rest is delivered, marked with a `@page-failed`
  comment. `count_missing_pages()` finds those and the activity log says so.

### The two numbers we hard-code

`MAX_PDF_PAGES` (25) and `MAX_FILE_BYTES` (50 MB) in `class-settings.php` exist so a PDF that cannot
be converted is a sentence in the dashboard instead of a wasted upload.

They are not the same kind of number. `MAX_PDF_PAGES` really is Iris's limit, mirroring
`MAX_PDF_PAGES` in `src/util/pdf.ts` upstream. `MAX_FILE_BYTES` is **ours**, and deliberately
conservative: the real ceiling is the deployment's `upload.max_request_bytes` (128 MB by default).
Iris caps each *image* at about 3.75 MB, but a PDF is exempt from that — it is rasterized server-side
and each rendered *page* is measured instead, so a large-format PDF can be refused with a `400`
naming the page no matter how small the file is. Nothing in the byte count predicts that, which is
why 50 MB is a judgement call rather than a mirror.

They are constants rather than something read from `GET /v1/limits`, deliberately: the worker has to
decide "is this PDF too long?" while holding a file, and a network call there would mean no document
can be inspected while Iris is unreachable. `doctor` asks `/v1/limits` instead and complains if the
page cap has moved. Note that `/limits` publishes `max_pages` but **not** the 50 MB per-file
ceiling — the number it does publish, `upload.max_request_bytes`, is a bigger, different limit on
the whole request.

### Rejections we cannot see coming

The plugin checks pages and bytes before uploading, which catches most refusals. Two it cannot:

- **A physically large page.** Iris rasterizes every page at a fixed resolution, so a poster or an
  architectural drawing becomes an image too big for the vision model, and the upload gets a `400`
  naming the page. Nothing readable from the file's byte count predicts this.
- **A PDF that will not rasterize** — `422 pdf_conversion_failed`.

Both land as `failed` with Iris's own sentence, on the first attempt.

### Rate limits

Iris limits 240 general requests and 12 uploads per minute, plus a cap on upload bytes in flight
across all callers. With no accounts to count against, both are counted **per address** — so every
site behind one institutional IP shares a budget, and on a shared deployment so does everyone else.
Over budget is a `429` with `Retry-After`, which `handle()` honours by opening the circuit breaker.

One tick makes at most a handful of requests, so the plugin is nowhere near these on its own. The
handling matters because the budget is not its own.

To watch what is being sent, `ddev xdebug on` and set a breakpoint in `create_session()`, or run
Iris locally and read its own request log — usually faster.

---

## Debugging

```bash
cd test-site

ddev exec tail -f wp-content/debug.log   # PHP notices; WP_DEBUG_LOG is on
ddev logs -f                             # web server
ddev wp equalify-iris log                # the plugin's own activity log
ddev wp equalify-iris doctor             # eight checks with fixes
ddev wp db query "SELECT id, status, error, attempts, pdf_url FROM wp_equalify_iris_documents ORDER BY id DESC LIMIT 20"
ddev wp db query "SELECT * FROM wp_equalify_iris_sightings LIMIT 20"
ddev wp network meta list 1 | grep equalify   # every setting, as stored
```

### Reading the activity log

The log is the plugin explaining itself to a person who is not you and cannot read the code. When
you add a message, write it that way:

- **Bad:** `import_ready() failed: status 409`
- **Good:** `Equalify Iris says "annual-report.pdf" is not quite ready. Trying again in a minute.`

Include the document name, say what will happen next, and pick the level honestly: `error` for
something that needs a person, `warning` for something that recovered, `note` for the ordinary
course of events.

### The debugging trick worth knowing

When a document is stuck, look at `status`, `attempts`, `next_check_at`, and `error` on its row —
in that order. Those four columns tell you which stage owns it, whether it has been trying, when it
will try next, and what it last said. It is almost always enough.

---

## Conventions

Match the code that is already there. Specifically:

- **Tabs, not spaces.** WordPress standard.
- **`snake_case` functions, `Equalify_Iris_Thing` classes, no namespaces.**
- **Every class file opens with a `WHAT IS THIS FILE?` block** saying what it is for and why it
  exists, in plain language, before any code.
- **Comments explain why, not what.** A comment restating the code goes stale and helps nobody. A
  comment explaining a decision is the only record of it. The MySQL affected-rows note in
  `class-documents.php` is the model.
- **Admin copy is for a person.** "Paused after repeated problems reaching Equalify Iris", not
  "circuit breaker open".
- **Escape on output, always.** `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`.
- **Prepare every query.** `$wpdb->prepare()`, every time, no exceptions.
- **Capability check, then nonce check, then act, then redirect.** In that order.
- **No front-end JavaScript.** This is a hard rule, not a preference. See
  [DECISIONS.md](DECISIONS.md).

### Plain language is a requirement here, not a nicety

This plugin exists to make documents readable. Code and copy that only a senior developer can follow
would be an odd way to go about that. Concretely: prefer a named variable to a clever expression,
prefer an early return to a nested condition, and write the comment you would want if you opened
this file for the first time at 5pm on a Friday.

---

## Traps, collected

The things that have actually caused bugs here.

| Trap | What happens |
| --- | --- |
| `claim_next( X, X )` | Silently claims nothing forever. MySQL counts changed rows, not matched rows. |
| `Documents::PUBLISHED` vs `'publish'` | Ours is `'published'` (a pipeline stage); WordPress's post status is `'publish'`. One letter apart, and a comparison against the wrong one is always true. |
| `wp_insert_post()` under cron | No logged-in user, so kses strips your already-cleaned HTML. Wrap in `kses_remove_filters()` / `kses_init_filters()`. |
| Retiring a brand-new document | A document row exists a fraction of a second before its first sighting. Hence the 10-minute grace period in `orphaned()`. |
| A shared settings form with a checkbox | An unchecked box sends nothing, so a shared handler silently turns it off. Hence the `has_auto_process` hidden marker. |
| `get_error_data()` returning null | `$error->get_error_data()['status']` warns. Check `is_array()` first. |
| `get_option()` on a network setting | Every setting here is a **network** option. `get_site_option()`, always. |
| Testing on a single site | The plugin is network-only and refuses to activate. That is correct behaviour, not a bug. |
| Raising `max_in_flight` above 2 | Nothing goes faster. Iris queues the extra uploads instead of running them, so the wait moves to its side of the wire and out of our sight. |
| Retrying a `400` from Iris | It will be a `400` again. Check `is_permanent_rejection()` before calling `fail()`. |

---

## Before you commit

```bash
find plugin/equalify-iris -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors'
```

Then, honestly:

- Does the change need a line in [DECISIONS.md](DECISIONS.md)? If you chose between two reasonable
  options, yes.
- Does it add a word that is not in [GLOSSARY.md](GLOSSARY.md)?
- Does it add a failure a user could hit? Then [TROUBLESHOOTING.md](TROUBLESHOOTING.md) needs a row.
- Did you test the retirement path? If you touched sightings, documents, or post status, you did
  touch it.
