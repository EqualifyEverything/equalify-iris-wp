# Troubleshooting

Symptom, cause, fix. Start at the top — "nothing is happening" is by far the most common one, and
it is almost always cron.

**Before anything else, run this:**

```
wp equalify-iris doctor
```

It runs eight checks and prints a specific fix for each one that fails. Most of this page is just
the longer explanation of what `doctor` tells you in one line.

---

## Nothing is happening

Nothing is converting, the counts do not move, and the Overview screen looks idle.

Work through these in order.

### 1. Is the process started?

Overview → the switch says **OFF**.

The plugin does nothing at all until a super admin presses **Start**. A working connection is not
enough.

```
wp equalify-iris start
```

### 2. Is the background job actually running?

Overview → *"The background job has not run for 3 hours."*

This is the usual answer, and the cause is almost always the same: **WP-Cron is not real cron.**
WordPress only fires scheduled jobs when somebody loads a page. A network with modest traffic can
go hours without a tick, and a staging site can go days.

Add a real cron entry — the exact line for your install is printed on the Overview screen and by
`wp equalify-iris doctor`:

```
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --url=https://your-main-site.example
```

And turn off the visitor-triggered version, so the two are not fighting:

```php
define( 'DISABLE_WP_CRON', true );
```

To confirm the job itself works, independently of scheduling, run one tick by hand:

```
wp equalify-iris tick
```

If that does work and the scheduled version does not, the problem is scheduling, not the plugin.

### 3. Is the job scheduled at all?

```
wp cron event list | grep equalify_iris
```

Nothing listed? Deactivate and reactivate it network-wide, which schedules it immediately, or visit
any Network Admin page — the plugin also reschedules itself on `admin_init`.

Note the job is only scheduled on the **main site** of the network. That is correct: one job for the
whole network, not one per site.

### 4. Is another plugin turning cron off?

Some caching, security, and "performance" plugins disable WP-Cron or filter `cron_schedules`. If
`wp cron event list` shows the event but it never runs on a real cron entry either, try the tick by
hand and check the activity log.

### 5. Is there anything to do?

Overview → Documents shows `0 pending`, and the sweep says complete.

Then nothing is wrong. Everything convertible has been converted.

---

## "Equalify Iris is refusing us"

**First, the thing that surprises people: there is nothing to sign into.** Iris has no user accounts
and the plugin usually sends no credential at all. So this message never means "you forgot to log
in". It means Iris answered `401`, and there are only two reasons it does that.

Settings → **Check the connection**, or:

```
wp equalify-iris connect
```

### "needs a shared API token"

The deployment is **gated**: whoever runs it has set a shared secret to stop strangers using it. Ask
them for it, then:

```
wp equalify-iris connect --token=THE-SECRET
```

or paste it into Settings → **A shared API token**. It is tested immediately, so you find out at once
whether it is the right one. Processing is stopped while this is outstanding, because every upload
would be refused.

The secret is not an identity. It says nothing about who is using it, and it does not affect how
contributions are attributed — see below.

### "cannot authenticate to GitHub"

**Nothing on this network is wrong, and there is nothing here to fix.** This is the Iris deployment's
own GitHub credential failing, and while it lasts that deployment is converting nothing for anybody.
Iris retries after 30 seconds and it often clears up by itself, so the plugin treats it as temporary
and keeps working. If it persists, tell whoever runs Equalify Iris.

### You set the constant and it still says something odd

If `EQUALIFY_IRIS_API_TOKEN` is defined in `wp-config.php`, the plugin uses it and ignores the stored
token, and the Settings screen says so instead of offering a field. If the constant holds a stale or
wrong value, no amount of clicking in the admin will help — fix the constant.

### You pasted a token at an open deployment

Harmless. An open deployment ignores an `Authorization` header it did not ask for. If you want it
gone anyway, Settings → **Remove the stored token**, or `wp equalify-iris connect --forget`.

---

## Documents are stuck in "converting"

### This is often just how it looks

Iris conversions take minutes, not seconds, and the plugin deliberately checks less often the
longer a document has been going — up to 15 minutes between checks. A document sitting in
`converting` for 20 minutes is normal.

### Nothing has moved for hours

Check the activity log, then check whether the circuit breaker is open (see below). Any document
still converting after **two hours** is failed automatically on timeout, so nothing occupies one of
the two Iris slots forever.

### Only two at a time, forever

That is the cap in Settings (`max_in_flight`), and 2 is the number Iris itself runs at once.

Raising it will not make anything faster. Iris does not reject the extra uploads — it queues them,
so the same documents finish at the same time, and the waiting simply happens on Iris's side where
the dashboard cannot show it to you. On a shared deployment it also means your network is competing
with itself. Leave it at 2.

---

## "Paused" — the circuit breaker is open

Overview → *"Paused after repeated problems reaching Equalify Iris."*

Five consecutive network failures stop the plugin calling out for 15 minutes. This is intentional:
there is nothing to learn from the sixth timeout in a row, and hammering a service that is down
helps nobody.

**It resolves itself.** Wait 15 minutes.

To find out *why* it opened, read the Activity Log — the underlying error is recorded there. Common
causes:

| Cause | Sign |
| --- | --- |
| Iris is down or deploying | Timeouts, or 502/503 responses |
| The deployment was gated, or its own GitHub credential failed | 401 responses |
| Your server cannot reach the internet | Timeouts on every call, including `wp equalify-iris status` |
| A firewall blocks outbound HTTPS | Same, and often instant rather than slow |

While the breaker is open, ticks still retire orphans and continue the sweep, because neither of
those needs the network.

---

## A document says "too long" or "too big"

That is the answer, not an error.

- **Too long** — more than 25 pages. Iris caps at 25 and rejects longer files.
- **Too big** — more than 50 MB.

These are final states. There is no Retry button on them, because retrying cannot help: the file
will still be too long next time.

What to do about it is a content decision, not a technical one. Splitting the PDF into shorter
documents in the Media Library will get each part converted. The plugin will not split it for you —
doing that inside PHP needs a PDF library on the host and produces a worse reading experience across
stitched parts.

---

## A document failed and the reason mentions a page being too large

Something like *"page 3 of drawing.pdf is too large"*.

This is about the page's **physical size**, not the file's. Equalify Iris turns every page into an
image at a fixed resolution, so a page the size of a poster or an architectural drawing becomes an
image larger than the model that reads it will accept. A letter-sized page never hits this, however
many megabytes the file is.

The plugin cannot see this coming — nothing in a PDF's page count or byte size predicts it — so this
one is only discovered by uploading. It stops on the first attempt rather than retrying, since the
answer will not change.

**What to do:** re-export or scan the drawing at a smaller physical page size, or split it into
letter-sized tiles, and replace the file in the Media Library. Or accept that this document is not
convertible and leave it.

---

## The activity log says a document was published but pages are missing

*"Published an accessible version of "report.pdf", but Equalify Iris could not read 1 of its pages,
so that page is missing from it."*

Exactly what it says. Iris converts each page on its own, and a page can fail by itself — usually a
very dense table or form that overran the limit on how much the model may write in one answer. The
rest of the document converted fine and has been published.

**Why publish it anyway?** Because 24 readable pages out of 25 is still far better than a PDF a
screen reader cannot open at all. Withholding it would help nobody.

**What to do about it:**

1. Read the document page and see whether the missing page matters. Often it is an appendix.
2. If it does matter, press **Retry** in Documents. A retry is a fresh conversion, and a page that
   failed on a timing or size boundary may well succeed.
3. If it fails the same way twice, the page itself is the problem. A simpler version of that page —
   a table split in two, a form exported separately — will convert.

To find every incomplete document on a site:

```bash
wp post list --post_type=equalify_iris_doc --meta_key=_equalify_iris_pages_missing \
  --meta_value=0 --meta_compare='>' --fields=ID,post_title --url=https://example.org
```

---

## A document failed

Documents → filter by **Failed** → each row shows the reason in its own words, often Iris's.

Press **Retry** on one, or:

```
wp equalify-iris retry 42        # one document
wp equalify-iris retry           # every failure
```

Documents stop retrying automatically after five attempts, so a permanently broken file does not
consume the queue forever. A manual retry resets that.

| Reason you might see | What it usually means |
| --- | --- |
| Iris's own failure message | The PDF defeated the converter — often a scan with no text layer at all. |
| Timed out after 2 hours | The conversion never finished. Worth one retry. |
| Could not read the file | The attachment file is missing from disk, even though the post exists. |
| 401 / not authorized | Either the deployment now wants a shared secret, or its own GitHub credential is failing. `wp equalify-iris connect` says which. |

---

## The icon does not appear next to a PDF link

Check in this order.

### 1. Has that PDF actually been converted?

Documents → search for the filename. If it is not `published`, there is nothing to link to yet, and
the missing icon is correct.

### 2. Is the page published?

Only `publish`, non-password-protected posts are scanned and get icons. A draft or private page
will not show one, by design.

### 3. Is the link a real `<a href="…pdf">`?

The content filter rewrites anchors in post content. It cannot rewrite:

- A PDF embedded in an `<iframe>`, `<object>`, or a viewer block.
- A link built by JavaScript after page load.
- A link output by a theme template, a widget, or a page builder that does not run its content
  through `the_content`.
- A link to a PDF on another domain (which is also never converted).

### 4. Is the page cached?

The icon is server-rendered, which means it is in the cached HTML — good — but it also means a page
cached *before* the document was published still has no icon. The plugin calls `clean_post_cache()`
on every linking page when an accessible version goes live or comes down, which most page-cache
plugins act on. A CDN or Varnish will not; purge from the `equalify_iris_linking_pages_changed`
action, or clear the cache for that page by hand.

The same applies the other way round: a page cached while the plugin was on keeps its icons after
deactivation until that cache expires, and those icons now lead to a 404.

### 5. Is the PDF link pointing somewhere unexpected?

URL matching compares the lowercased path only, ignoring the scheme, the query string, and case.
That handles http/https and mixed case. It does not handle a CDN on a different hostname, or a
signed URL that rewrites the path.

### 6. Does the page have a sighting?

Sightings are the fast path, not the only one. A PDF linked from a post the sweeper has reached is
found by one indexed lookup; a PDF linked from a widget, a block-theme template part or an excerpt —
none of which the sweeper scans — is found by matching the URL path instead. So a missing sighting is
not by itself the explanation. What a missing sighting does cost is the "Appears on" link on the
document page, which has no fallback.

---

## The document page looks wrong

### It does not look like the rest of the site, and that is deliberate

The document page is a standalone HTML document. It does not print the theme's header or footer, and
`class-frontend.php` takes the theme's stylesheets and web fonts out of the queue for that one page.
It was not always so — see the top of `templates/single-document.php` for the three reasons it
changed, the worst of which is that on a block theme `get_header()` fell back to WordPress's
deprecated theme-compat header and gave the page a second `<h1>` holding the site name.

There is no site furniture at all any more, not even the site's name. The link home is inside the
About panel, described below.

### Where is the download link? And the title, the date, the contents?

Behind the two panels at the top of the page — **Contents**, and **About this accessible version of
a PDF**. Both are closed when the page loads. Nothing is missing; it is one click away, and the
click is labelled.

That is the point of the design. The page exists to be read, so what a reader meets first is the
document, not four paragraphs of the plugin explaining itself. The About panel holds two sentences
saying where the page came from and what to do if it looks wrong, the link to the original PDF with
its page count and size, the page the PDF appears on, the site it belongs to, and the date it was
converted.

"Equalify Iris" in that note links to the project. To point it somewhere else — a network running its
own Iris usually has its own page explaining it — filter `equalify_iris_project_url`. Returning an
empty string leaves the name as plain text.

The document's own title stays visible above the panels, set small. It is deliberately not inside a
panel: the content of a closed `<details>` is removed from the accessibility tree, so hiding it
would leave the page with no heading at all until the document's own headings began.

The panels are `<details>` elements. There is no JavaScript on the page, so they work with scripting
off, in a text browser, and if this plugin's stylesheet fails to load.

### The panels do not open

Then something is overriding the page's CSS or the browser is very old — `<details>` needs no
script, so there is nothing here that can fail on its own. Check for another plugin injecting CSS
onto the front end, and check the browser console for a content-security-policy error.

### A printed copy is missing the panels

Correct, and it is not losing anything important. A closed `<details>` cannot be forced open by CSS,
in print or anywhere else, so the one line that must survive onto paper — that the page was made
automatically from a PDF — is printed by a separate paragraph that is `display: none` on screen,
along with the address of the original PDF. The document's title prints too, at full size.

### I want the site's header and footer back

Return false from the `equalify_iris_document_standalone` filter. That leaves the theme's stylesheets
in the queue, and it is worth pairing with a `single-equalify_iris_doc.php` template in the theme
that calls `get_header()` and `get_footer()` itself — the filter controls the stylesheets, not the
markup, because the markup belongs to whichever template is running.

```php
add_filter( 'equalify_iris_document_standalone', '__return_false' );
```

### I want to change the colours or the type size

There is no need to replace anything. Every colour, size and space is a custom property, declared on
both `.equalify-iris-viewer` (the page) and `.equalify-iris-document` (the document region). Set them
again from a small stylesheet of your own:

```css
.equalify-iris-viewer,
.equalify-iris-document {
	--eq-measure: 44rem;   /* the reading column; 38rem by default */
	--eq-link: #7b1fa2;
	--eq-font-doc: "Atkinson Hyperlegible", Georgia, serif;
}
```

The full list is at the top of `assets/css/document.css`. Note the contrast note there before
changing `--eq-ink` or `--eq-link`: the defaults are at least 7:1 against their background, which is
AAA, and the point of the page is being readable.

### There is no Contents panel

It only appears when the document has three or more headings. Below that, a contents list costs more
to read than it saves. The About panel is always there.

### The stored content contains a `<main>` and a `<title>` and the page does not

Both are removed on the way out, by a filter in `class-frontend.php`, and neither is stored any more
for anything converted since. Iris converts a PDF into a whole HTML document, so its output arrives
wrapped in the furniture of a page: a `<main>` that would nest inside the template's own and give the
page two main landmarks, and a `<title>` holding the source filename, which can decide what the
browser tab and any bookmark say.

Documents converted before that fix still have both in the database. That is deliberate — the filter
removes them when the page renders rather than rewriting thousands of posts, because a migration can
half-finish and it edits the only copy of the converted document there is. Nothing needs doing.

### The URL 404s

The rewrite rule is registered on `init` and needs the rules flushed once. Visiting Network Admin
does that automatically; if it is stuck, go to any site's Settings → Permalinks and press Save,
which forces a flush.

If the network runs without pretty permalinks, document pages fall back to plain
`?post_type=…&p=…` URLs. They work, they are just ugly. `doctor` warns about this.

---

## Progress is slower than I expected

That is the design. The whole point of the work budget is that the plugin never becomes the reason a
page is slow or a host complains.

If you genuinely need it faster and your host can take it, Settings → the limits section. Every
field is clamped to a safe range. The useful ones:

| Setting | Effect | Caution |
| --- | --- | --- |
| Uploads per tick | More PDFs sent per five minutes | Pointless above the in-flight cap of 2 |
| Status checks per tick | Faster detection of finished work | Cheap; safe to raise |
| Imports per tick | More HTML saved per five minutes | Each import is a `wp_insert_post` — the heaviest thing a tick does |
| Posts per tick | Faster sweep | Raises database load during the sweep only |
| Time budget | A longer tick | Do not exceed your PHP `max_execution_time` |

To run the sweep to completion in one go, ignoring the budget entirely — good on a staging site,
risky on production:

```
wp equalify-iris sweep --all
```

**Do not raise in-flight above 2.** That limit is Iris's, not ours.

---

## A page I unpublished still has an accessible version

It should not, for more than a few minutes.

Unpublishing clears that post's sightings. The next tick notices the document has no sightings left,
sets its page back to draft, and marks it `retired`. If you unpublished the page seconds ago, wait
for the next tick, or run `wp equalify-iris tick`.

If it persists past a couple of ticks, the PDF is probably still linked from *another* published
page. Documents → find it → its sightings will tell you where.

---

## I republished a page and the old accessible URL still works

Correct. That is `revive`: the same document is republished at the same URL, so links people
already shared keep working.

---

## The tables are missing

`doctor` says **FIX Database tables**.

Deactivate and reactivate the plugin network-wide. Table creation happens on activation.

If it fails repeatedly, the database user probably lacks `CREATE TABLE`. Check the Activity Log and
your PHP error log.

---

## Uninstalling and starting over

- **Deactivating** stops the job, removes the icons, and makes every accessible version's address
  answer 404. It deletes nothing. Reactivating brings them back, retires any whose only linking page
  was unpublished in the meantime, and reopens the sweep to catch other edits.
- **Deleting** the plugin drops the two tables and every setting — but deliberately **leaves the
  converted pages in place**, because they are live URLs people have bookmarked and shared. They
  become invisible orphaned posts, harmless, and still there if the plugin comes back.

To sweep the network again from scratch without losing conversions:

```
wp equalify-iris start --restart-search
```

---

## Still stuck

1. **Activity Log** — the last few hundred things that happened, in sentences, newest first.
2. **`wp equalify-iris log`** — the same, from the terminal.
3. **`wp equalify-iris status`** — a summary you can paste into a bug report.
4. **`wp equalify-iris list --status=failed`** — every failure with its reason.
5. Your PHP error log, for anything that crashed before the plugin could log it.
