# The test WordPress multisite

A throwaway three-site WordPress network for testing the Equalify Iris plugin. It is not part of
the plugin and it is not committed: everything WordPress-shaped in here is ignored by git, and one
script builds it from nothing.

```
./setup.sh
```

First run takes a few minutes, mostly downloading container images and WordPress. Later runs take
seconds and skip anything already done.

When it finishes it prints the URLs and the login. The short version:

| | |
| --- | --- |
| Network dashboard | https://equalify-iris-test.ddev.site/wp-admin/network/ |
| Plugin | https://equalify-iris-test.ddev.site/wp-admin/network/admin.php?page=equalify-iris |
| Login | `admin` / `admin` |

---

## What you need

- **DDEV** — `brew install ddev/ddev/ddev`
- **A Docker engine that is running** — Docker Desktop, colima (`colima start`), or OrbStack.

Nothing else. WordPress, the database, PHP, and WP-CLI all live in the containers.

**Why DDEV rather than something lighter?** WordPress multisite needs MySQL or MariaDB. The
lightweight options — wp-env's SQLite mode, `wp server`, Valet — either cannot do multisite at all
or need more setting up than DDEV does.

---

## What the setup script builds

**Three sites**, as a subdirectory network:

- `https://equalify-iris-test.ddev.site` — the main site
- `https://equalify-iris-test.ddev.site/research` — Research Office
- `https://equalify-iris-test.ddev.site/library` — University Library

Subdirectories rather than subdomains because `*.ddev.site` only resolves one level deep, so
`research.equalify-iris-test.ddev.site` would need an `/etc/hosts` edit on every machine.

**The plugin, mounted live** from `../plugin/equalify-iris` and network-activated. It is a bind
mount, not a copy: edit the real source and the test site changes immediately. There is no build
step and no way to accidentally test a stale copy.

**Content on every site**, chosen so that the interesting cases are all present from the start:

| Page | Status | Why it is there |
| --- | --- | --- |
| Documents | published | Two PDFs on one page → two icons. Also links a PDF on another domain, which must be ignored. |
| Linked Twice | published | The same PDF linked twice → queued once, two icons. |
| A Very Long Manual | published | A 30-page PDF → "too long", not "failed". |
| Draft Page | draft | Its PDF must never be converted. |
| Private Page | private | Same. |

---

## Using it

```bash
# Check that Equalify Iris will accept us. Nothing to sign into: the
# production deployment is open, so this should just say so.
ddev wp equalify-iris connect

# Only if you are pointed at a deployment that has been given a shared
# secret (`server.api_token` in the Iris config):
# ddev wp equalify-iris connect --token=THE-SECRET

# Turn the process on
ddev wp equalify-iris start

# Run the background job by hand — this is the one you will use constantly
./tick.sh          # one tick
./tick.sh 10       # ten in a row

# Look at what happened
ddev wp equalify-iris status
ddev wp equalify-iris doctor
ddev wp equalify-iris list
ddev wp equalify-iris log
```

**Why run ticks by hand?** In production the job runs every five minutes and does a small, capped
amount of work each time — at most one upload, five status checks, two imports, twenty posts swept.
Waiting five minutes between each step of a test is intolerable, and one tick is rarely enough to
carry a document from "found" to "published". So: several ticks, on demand.

Expect a wait in the middle. Iris takes minutes to convert. Ticks during that wait genuinely have
nothing to do but poll.

### Other useful commands

```bash
ddev launch /wp-admin/network/          # open the dashboard
ddev logs -f                            # web server log
ddev exec tail -f wp-content/debug.log  # PHP notices (WP_DEBUG_LOG is on)
ddev wp db query "SELECT status, COUNT(*) FROM wp_equalify_iris_documents GROUP BY status"
ddev ssh                                # a shell in the container
ddev xdebug on                          # step through a tick
./reset.sh                              # delete everything and start again
```

---

## Testing against a local Iris

Recommended, and not only for speed. Iris reports conversion problems as **public GitHub issues**,
which can include extracts of the document it was converting. Test documents are usually nonsense,
but the habit is worth having.

```bash
cd ../../equalify-iris && npm run dev     # Iris on port 8080
cd -
./bin/point-at-local-iris.sh              # point the test site at it
./bin/point-at-local-iris.sh --production # put it back
```

The URL it sets uses `host.docker.internal`, because inside the container "localhost" means the
container, not your machine.

---

## Things worth deliberately testing

The list below is the one that catches real bugs. Most of these have gone wrong at least once.

**Discovery**

- A PDF on a published page is found; on a draft or private page it is not.
- A PDF on another domain is never uploaded.
- The same PDF linked twice is one document with two icons.
- Publishing a new page with a PDF queues it without a sweep.
- Adding a site to the network reopens a sweep that had finished.

**Limits**

- A 30-page PDF becomes "too long", is not retried, and has no Retry button.
- An oversized file becomes "too big" (`./bin/make-oversized-pdf.sh`).
- Never more than two documents in flight at once, whatever the settings say.
- A file Iris rejects outright fails **once**, not five times: `attempts` stays at 0 and the reason
  is Iris's own sentence. Easiest to force against a local Iris by uploading a non-PDF, or by
  temporarily lowering `MAX_PDF_PAGES` upstream so a 4-page sample is refused.
- `wp equalify-iris doctor` reports the page-limit check as OK. Lower `MAX_PDF_PAGES` in a local
  Iris and it should say the two numbers disagree.

**Partial conversions**

- A document Iris delivers with a `@page-failed` comment still publishes, and the activity log
  carries a warning naming it and the number of missing pages.
- That page has `_equalify_iris_pages_missing` set above 0; a complete one has 0.
- Easiest to force with a mock output file containing a `@page-failed 2` comment after `</main>`.

**The queue**

- `./tick.sh 20` from scratch gets at least one document published.
- Two ticks at once do not double-import: `./tick.sh 1 & ./tick.sh 1 & wait`.
- Stop halts everything; Start resumes where it left off, not from the beginning.
- A failure can be retried, and stops retrying after five attempts.

**Retirement — the one that matters most**

- Unpublish a page with a converted PDF → within a tick or two the document page is a draft and its
  URL is no longer public.
- Republish it → the same URL works again.
- Remove just the link from a published page → same as unpublishing.
- Delete the page outright → same.

**The front end**

- The icon appears immediately after the PDF link, and the original link is unchanged.
- Tab to the icon: it has a visible focus ring and a screen reader announces the document's name.
- The document page has exactly one `<h1>`, and the document's own headings start at `<h2>`.
- The skip link works, and the table of contents appears only with three or more headings.
- Disable JavaScript entirely. Nothing should change — the plugin ships none.

**The admin**

- Every screen with a non-super-admin user: no menu, and a direct URL is refused.
- Every button twice in a row, and refresh after each — nothing should re-run.
- With Iris unreachable (`./bin/point-at-local-iris.sh 9999`): five ticks open the circuit breaker,
  the Overview says "paused" in plain words, and retirement still happens.

**Uninstall**

- Deactivate → nothing is deleted, reactivate and carry on.
- Delete → tables and settings gone, converted pages still in `wp_posts`. That is deliberate: they
  are public URLs people have shared.

---

## When it goes wrong

| Symptom | Fix |
| --- | --- |
| `ddev start` fails | Is Docker running? `colima start`, or start Docker Desktop. |
| Browser warns about the certificate | `mkcert -install`, then `ddev restart`. |
| Site 404s everywhere | `ddev wp rewrite flush` |
| Sub-site admin pages 404 | Subdirectory multisite needs the nginx rules in `.ddev/nginx_full/`. `ddev restart`. |
| Plugin missing from the plugin list | The bind mount did not attach. `ddev restart`, then check `.ddev/docker-compose.plugin.yaml`. |
| Confusing state you no longer trust | `./reset.sh` — it is meant to be cheap. |
| White screen | `ddev exec tail -50 wp-content/debug.log` |

---

## What is committed and what is not

**Committed** — the scaffolding: `.ddev/config.yaml`, `.ddev/docker-compose.plugin.yaml`,
`setup.sh`, `reset.sh`, `tick.sh`, `bin/`, this file.

**Ignored** — everything they produce: `wp/` (all of WordPress, including `wp-config.php` and
uploads) and `samples/` (the generated PDFs).

So a fresh clone contains no WordPress at all, and `./setup.sh` produces a working one.
