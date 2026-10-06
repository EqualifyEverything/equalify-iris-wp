# How it works

## The short version

A PDF linked from a site's published pages is uploaded to Equalify Iris, which converts it to HTML. The plugin
then asks Iris for the original PDF back with accessibility tags built from that HTML, saves it next
to the original as `name-accessible.pdf`, and from then on every link to the original on that site
points at the tagged copy instead. The links are changed as each page is shown, not in the
database, so deactivating the plugin or deleting a tagged copy puts them back.

## Which PDFs are public

Iris reports problems as public GitHub issues that can quote the document, so only PDFs that are
already public are ever sent. Public means: a file in this site's media library that something
visitors can see links to.

- **Posts.** A post that is published, has no password, and is of a post type visitors can view:
  its content, excerpt, custom fields (ACF file fields included, by attachment id), and the synced
  patterns it shows. Each PDF it links to gets one `_equalify_iris_linked_from` row with the post's
  id, and the post keeps the list in `_equalify_iris_links` so it can be forgotten cheaply.
  Whether the post is still published is checked when asked, so unpublishing, trashing or
  password-protecting a page takes its PDFs off the list straight away.
- **The site as a whole.** Menus in a theme location or a menu widget; widgets in an active
  sidebar; a block theme's templates and template parts, with the navigation menus and theme
  patterns they show; category and tag descriptions; ACF options pages. Each PDF gets one
  `_equalify_iris_shown_in` row per place. Saving a menu, widget, template, term or option, or
  switching theme, sets `equalify_iris_places_stale`, and the places are read again.

Not found: links written into a theme's PHP files, page builders that keep their layout outside
the post content and custom fields, and links to another site's uploads.

### Reading what was there before

Saving a post reads it at once. Content written before the plugin was active is read by the
background job, about 20 seconds' worth per site per run, until it has seen everything.
`equalify_iris_indexed` holds `generation:last post id read`, or `generation:done`. Bumping the
network's `equalify_iris_read_generation` (on activation) makes every site start again.

A site is only read once it uses the plugin: someone opens its Equalify Iris screen, asks for a PDF
to be tagged, chooses **Look for PDFs** on the network screen, or turns on automatic tagging (for
the site, or for the network). The other 99,000 sites of a big network cost nothing.

### Which post types count, from the background job

The job reads every site from the main one with `switch_to_blog()`, which does not load that
site's own plugins and theme, so their custom post types, taxonomies and sidebars are not
registered. Each site therefore writes down its own in `equalify_iris_profile` whenever it loads
itself: on a front-end visit, or any request while it has no profile yet. The job goes by that.
Until a site has one, the job counts only posts, pages, categories and tags. When the profile
changes, the site's places are read again, and if its types changed, its posts too.

Custom fields saved without saving the post (imports, scripts) are caught on `updated_post_meta`
and the post read again at the end of the request. Over 200 posts in one request, and the site is
read again from the start instead.

## Asking for a PDF to be tagged

- **By hand.** Equalify Iris → **Send to Iris for Tagging**, on one PDF, in bulk, or on every untagged
  one. A super admin can do the same for a whole site from Network Admin → Equalify Iris.
- **Automatically.** When it is on for the site or for the whole network, a PDF is queued as soon as
  a page linking to it is published, and public PDFs already linked are queued 50 per run.

Either way the PDF is only queued, and the site made due. As the request ends, a run is started
straight away (at most once a minute) so the person who asked sees it move without waiting for the
schedule. Nothing is sent to Iris during the request
that asked, and a queued PDF whose page has been unpublished by the time its turn comes is not sent.

## The background job

One WP-Cron event for the whole network, `equalify_iris_run`, every five minutes on the main site.
A real scheduler can call `wp equalify-iris run` instead, and an admin page anywhere on the network
starts a run (a non-blocking request to `wp-cron.php`) when the last one is over ten minutes old.

**Which sites have work.** A site with something to do has an `equalify_iris_due` row in the
network's `blogmeta` table, holding when it is next due. A run asks for due sites, longest-waiting
first, and gives each one a turn before any gets a second. A site with nothing to do has no row, so
quiet sites cost one indexed query per run. The table is read and written with direct SQL, not
`get_site_meta()`, because a cached copy would always be out of date.

**How long.** `EQUALIFY_IRIS_RUN_SECONDS`, 90 by default, inside the 120 seconds Pantheon allows.
Every request to Iris is cut to what is left of the run. A run holds a database lock (`GET_LOCK`),
which MySQL and MariaDB release by themselves if the run dies, and notes it in `equalify_iris_lock`
for the screens. Where the database has no such locks, the option is the lock, and one older than the
run plus two minutes is taken over with a single conditional update, so only one run can win. A
site whose run throws an error is logged and tried again in 15 minutes; the rest of the network
carries on.

**How many at Iris.** `EQUALIFY_IRIS_MAX_AT_IRIS`, 4 by default, across the network, kept in the
network option `equalify_iris_at_iris` as `site:attachment => when uploaded`. No site has more than
two at once, so one site cannot take every slot. Entries over 15 minutes old whose PDF is no longer
being worked on are dropped at the start of a run.

Each site's turn, switched to it:

1. **Reads content** it has not read yet, for up to 20 seconds.
2. **Catches up.** If automatic tagging is on and has not caught up since it was switched on, queues
   up to 50 public PDFs nobody has asked about.
3. **Checks on PDFs at Iris.** `GET /sessions/{id}`. A failed conversion is marked failed with Iris's
   reason.
4. **Uploads.** While the network and the site have room at Iris, checks that the next queued PDF is
   still public, checks its size and page count, and uploads it (`POST /sessions`, up to 60
   seconds).
5. **Fetches one.** For **one** PDF that is ready, `POST /sessions/{id}/pdf` with `retag: true`,
   waiting as long as the run has left, if that is at least 30 seconds. The tagged copy is saved,
   added to the link map, and the session closed.

Then the site's due time is set: now if it has more to read or queue; in a minute if it has queued
PDFs (five while the network has no room at Iris); in two if it has PDFs at Iris; otherwise the row
is deleted. After each site the runtime object cache is flushed, not
`clean_post_cache()`, which makes page-cache plugins purge the CDN.

`retag: true` is always sent. Without it, Iris refuses a PDF that already has tags, and a PDF on a
website almost always has the empty or broken tags an authoring tool added on export.

### When something goes wrong

| What happened | What the plugin does |
| --- | --- |
| Over 50 MB | Failed before upload, with the reason |
| Over 25 pages | Refused by Iris as soon as it arrives (400), before converting anything; failed, with Iris's sentence |
| Iris refused it: not a PDF, encrypted, no tagger (400, 413, 422, `no_source_pdf`, `tagged_pdf_unavailable`) | Failed, with Iris's own sentence |
| Iris will not let the network in: no token, or the wrong one (401, 403) | Nothing sent for 15 minutes or until the settings are saved; both screens say why; the PDF loses nothing |
| What came back is not a whole PDF, or is far bigger than the original | Tries again, or fails when it is too big |
| Iris failed to convert it | Failed, with Iris's reason |
| Iris is busy (`503 busy`) or not ready (`409 invalid_state`) | Waits for the next run, no penalty |
| Iris unreachable, 5xx, 429 | Tries again next run, up to 10 times, then failed |
| Iris took longer than the run could wait for the tagged PDF | Tries again next run, 3 times, then failed |
| Iris forgot the session (404) | Uploads it again |

A failed PDF stays failed until someone chooses **Send to Iris for Tagging** again.

## Where things are stored

**On each PDF attachment** (post meta):

| Key | Holds |
| --- | --- |
| `_equalify_iris_status` | `queued`, `working`, `tagged`, `failed` or `removed`. Absent = never asked about |
| `_equalify_iris_session` | The Iris session id while at Iris |
| `_equalify_iris_since` | When it reached its current status, shown as "Sent 3 minutes ago" |
| `_equalify_iris_stage` | What Iris last said about it while at Iris: `queued`, `running`, `ready_for_review` |
| `_equalify_iris_error` | Why it failed, or the last problem while retrying |
| `_equalify_iris_attempts` | Problems in a row while retrying |
| `_equalify_iris_file` | The tagged copy, relative to the uploads folder |
| `_equalify_iris_warnings` | The tagger's warning codes, shown as notes in the list |
| `_equalify_iris_linked_from` | One row per published post that links to it |
| `_equalify_iris_shown_in` | One row per site-wide place (menu, widgets, templates…) that links to it |

**On each post**: `_equalify_iris_links`, the PDFs it links to.

`removed` means a site admin deleted the tagged copy. Automatic tagging leaves those alone.

**Per site** (options): `equalify_iris_auto_tag` (the site's switch), `equalify_iris_map` (original
file → tagged file, both relative to uploads, the tagged file ending in `?v=` and when it was saved), `equalify_iris_indexed` (how far through the content
it has read), `equalify_iris_places_stale`, `equalify_iris_profile` (its public types, taxonomies
and sidebars), and `equalify_iris_scanned` (see below).

**Per site, in the network's `blogmeta`**: `equalify_iris_due` (when the job next needs it),
`equalify_iris_auto` (its admin turned automatic tagging on), and `equalify_iris_used` (it has data
for uninstall to clean up).

**Per network** (site options): `equalify_iris_api_url`, `equalify_iris_api_token`,
`equalify_iris_network_auto`, `equalify_iris_generation`, `equalify_iris_read_generation`,
`equalify_iris_lock`, `equalify_iris_last_run`, `equalify_iris_at_iris`, `equalify_iris_refused`, and
`equalify_iris_home` (the address and environment type the job runs on; see below).

### Turning automatic tagging on for a thousand sites

Switching it on network-wide bumps `equalify_iris_generation` and makes every site due, in two
queries whatever the network's size. A site whose `equalify_iris_scanned` does not match reads its
content, queues its public PDFs, and records the number once it has queued them all. So the switch
takes effect on every site without the settings screen visiting each one.

## Swapping the links

On the front end, a site with any tagged PDFs buffers the whole page from `template_redirect` and
changes it once it is finished, so content, menus, widgets, templates and links a theme prints from
PHP are all covered. Every `href`, `data` or `src` attribute (the File block's inline preview uses
`data`; embeds and iframes use `src`)
that points at a file in this site's uploads folder, and has an entry in the link map, is pointed at
the tagged copy, with `?v=` and when that copy was saved. A re-tagged PDF keeps its filename, so the
new address keeps CDNs and browsers from serving the copy it replaced. The link's own query string
follows ours, and its fragment is kept. Links to other sites, and to PDFs with no
tagged copy, are left alone. The map is one autoloaded option, so this costs no queries (once it
passes 64 KB, thousands of PDFs, it stops being autoloaded and costs one), and a site with no
tagged PDFs is not buffered at all. If the page is too big for PHP's regular expressions, it is
sent as it was.

When a tagged copy is saved or deleted, the posts that link to it are cleaned from the cache
(`clean_post_cache()`, which page-cache plugins also purge on), and `equalify_iris_links_changed`
fires with the attachment id for anything else. Menus, widgets and archives showing the PDF are
not purged: a page cache keeps serving their old links until it is cleared.

## Lifecycle

| Event | What happens |
| --- | --- |
| A page linking to a PDF is published | The PDF is queued, if automatic tagging is on |
| That page is unpublished | The PDF leaves the list, unless it has a tagged copy to delete. A tagged copy stays and links still switch |
| **Delete Iris-Tagged Version** | Tagged file deleted, map entry removed, status `removed` |
| The attachment is deleted | Its tagged file and map entry go with it |
| Deactivate | The job is unscheduled. Links go back to the originals because nothing swaps them. Tagged files stay |
| Activate | Sites that used the plugin read their content again, in case it changed meanwhile, and carry on |
| The database is copied to another address or environment type | The copy is paused: no runs, nothing sent to Iris, so live's PDFs at Iris stay live's. **Tag PDFs Here Too**, or `wp equalify-iris resume`, records the new place and sends the PDFs that were at Iris again from there |
| Delete the plugin | `uninstall.php` deletes every tagged file, all our post meta, options and blogmeta, on the sites marked `equalify_iris_used` only, with direct queries |

## The files

```
equalify-iris.php              Header, constants, requires
uninstall.php                  Cleanup on delete
includes/class-plugin.php      Wiring, activation, deactivation
includes/class-settings.php    Every setting, and whether it is per site or per network
includes/class-api-client.php  Every request to Iris
includes/class-pdf-inspector.php  Size and page-count checks before upload
includes/class-discovery.php   Which PDFs are linked from something visitors can see
includes/class-tagger.php      The status of each PDF, and one site's turn of the job
includes/class-runner.php      The network's background job: which sites, how long, how many at Iris
includes/class-links.php       The front-end link swap
includes/class-cli.php         wp equalify-iris
admin/class-admin.php          Both Equalify Iris screens, their actions, and the dashboard notice
admin/class-list-table.php     The list of a site's public PDFs
```

## Security

- Every form and action link carries a nonce. The site screen and its actions need
  `manage_options`; the network screen and its actions need `manage_network_options`.
- A PDF is checked for being public when it is queued and again just before it is uploaded.
- The token field is a password field and is never echoed back.
- The tagged copy is a plain file, not an attachment, so it is never itself queued for tagging.
