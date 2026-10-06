# Equalify Iris for WordPress

A WordPress multisite plugin that adds accessibility tags to the PDFs linked from each site's
published pages, posts, menus and widgets, using [Equalify Iris](https://github.com/EqualifyEverything/equalify-iris),
and points every link to those PDFs at the tagged copy.

A screen reader meets an untagged PDF as one long run of text, or as nothing at all. Iris reads each
page, works out its headings, lists, tables and reading order, and writes that structure back into
the PDF as tags. Visitors still click the same links and get a PDF; it is just one a screen reader
can read. The original file is kept, and nothing in your posts is rewritten.

**Only public PDFs are touched.** A PDF counts as public when something visitors can see links to
it:

- a published, non-password-protected page, post or public custom post type: its content, excerpt,
  custom fields (ACF file fields included), and the synced patterns it shows;
- a menu in a theme location or a menu widget, and widgets in an active sidebar;
- a block theme's templates and template parts, and the navigation menus and patterns they show;
- a category or tag description, and ACF options pages.

PDFs that are only in the media library, or linked only from drafts and private pages, are never
listed and never sent to Iris. Links written into a theme's PHP files, and page builders that keep
their layout outside the post content and custom fields, are not seen.

## What site admins see

**Equalify Iris**, in the admin menu:

- **The list of public PDFs**, with what links to each one. Every PDF has **Send to Iris for
  Tagging**. Each row says where its PDF is up to (waiting, converting at Iris, adding tags), since
  when, and Iris's reason when something goes wrong; **Being tagged** and **Could not be tagged**
  show just those. Once tagged, **View Iris-Tagged Version** and **Delete Iris-Tagged
  Version**, one at a time or as bulk actions.
- **Automatic PDF tagging.** One switch: tag every public PDF, including ones linked from pages
  published later.
- **A dashboard notice** while that switch is off and a public PDF is untagged: *"You are
  currently displaying inaccessible PDFs. Visit Equalify Iris settings to turn on automatic PDF
  tagging."*

## What super admins see

**Network Admin → Equalify Iris**:

- **Turn on automatic PDF tagging for every site.** Every site's public PDFs are tagged, and site
  admins no longer see the switch or the notice. They still see the list.
- **The background job**: when it last ran, how many sites are waiting for it, and how many PDFs
  are at Iris. A warning if it has stopped running.
- **Every site's progress**, searchable: public PDFs, tagged, being tagged, and failed, with a link
  to each site's list, a button to tag a site's untagged PDFs, and **Look for PDFs** for a site
  that has not been read yet.
- The Iris API address, and a shared token if your deployment needs one. The public deployment
  needs none. A token in `wp-config.php` as `EQUALIFY_IRIS_API_TOKEN` takes priority over the
  stored one.

## Install

1. Download `equalify-iris.zip` from the
   [latest release](https://github.com/EqualifyEverything/equalify-iris-wp/releases/latest).
2. Network Admin → Plugins → Add New → Upload Plugin, then **Network Activate**.
3. Network Admin → Equalify Iris → **Save and check the connection**.

Needs WordPress 6.4+, PHP 8.0+, and an Iris deployment with tagged PDFs turned on. The public one
has them.

## Large networks, and Pantheon

One background job serves the whole network. It runs on the main site every five minutes and works
through only the sites that have something to do, a slice of each, so quiet sites cost nothing.

- **A site is only read once it uses the plugin**: someone opens its Equalify Iris screen, tags a
  PDF, or turns on automatic tagging. Turning automatic tagging on for the network reads every
  site, a few at a time.
- **Each run lasts 90 seconds**, inside the 120 seconds Pantheon allows any PHP process, and every
  request to Iris is cut to fit.
- **At most 4 PDFs are at Iris at once** across the network, however many sites there are.

WP-Cron only runs when the main site is visited, and Pantheon does not run it for multisite. Run the
job from a scheduler every five minutes instead, for example from a CI pipeline or any machine with
[Terminus](https://docs.pantheon.io/terminus):

```bash
terminus wp <site>.<env> -- equalify-iris run
```

Elsewhere, `wp equalify-iris run --url=<main site>` from cron does the same. Without a scheduler,
an admin page anywhere on the network starts a run once the job is ten minutes late.

Two constants in `wp-config.php` change the defaults:

| Constant | Default | What it sets |
| --- | --- | --- |
| `EQUALIFY_IRIS_RUN_SECONDS` | `90` | How long one run may last. Keep it 30 seconds under the host's limit. |
| `EQUALIFY_IRIS_MAX_AT_IRIS` | `4` | PDFs at Iris at once, across the network. |

### Hosting notes

These hold on any host. Pantheon is the example because it has all of them.

- **Staging and development copies pause themselves.** The job remembers the network's address and
  environment type (`WP_ENVIRONMENT_TYPE`). A copy made from the live database (Pantheon's dev,
  test and multidev environments, or a local copy) has a different address or type, so it sends
  nothing to Iris until a super admin presses **Tag PDFs Here Too** on the network screen, or runs
  `wp equalify-iris resume`. Otherwise the copy would collect, and close, the PDFs live had at
  Iris. Moving the live site to a new domain pauses it the same way, and the same button resumes it.
- **CDNs get a fresh address for each tagged copy.** Tagging a PDF again keeps its filename, so
  links carry `?v=` and the time it was saved. Pantheon's CDN and others then never serve the copy
  it replaced.
- **Locked environments.** Behind HTTP authentication (Pantheon's Lock Environment, or basic auth
  anywhere), WordPress cannot reach its own `wp-cron.php`. Use the scheduler above there.
- **Only the uploads folder needs to be writable.** On Pantheon that is `files/`. Nothing is
  written to `/tmp`, which Pantheon's app servers do not share.
- **An Iris deployment that only accepts known IP addresses** needs your host's outgoing address,
  and Pantheon's is not fixed without Secure Integration.

**How fast it goes** is set by Iris, not WordPress: a PDF takes a few minutes to tag, and the public
deployment works on two at once. Expect hundreds of PDFs a day, not thousands. Reading content is
quicker: a run reads roughly 20 seconds' worth of posts on each site before moving to the next.

## Before pointing this at real content

- **Iris reports problems as public GitHub issues**, which can quote parts of the document. This
  cannot be turned off, and it is why only PDFs linked from published pages are sent. A PDF that
  should not be public should not be linked from a published page. The screens say so next to the
  switches that turn automatic tagging on.
- **The deployment you point it at is trusted.** Its tagged PDFs are served in place of the
  originals. The API address must use https.
- **PDFs over 25 pages or 50 MB are not tagged.** The plugin checks the size before uploading;
  Iris checks the pages the moment the file arrives, before it converts anything or files an
  issue. Either way the screen says why.
- **Iris hands back a tagged PDF in a single request that can take minutes.** A run waits as long
  as it has left, about 80 seconds on Pantheon. A PDF Iris takes longer than that over is tried
  three times, then marked as could not be tagged, with the reason. Large or complicated PDFs are
  the ones this hits.
- **Links are switched on the whole page**, menus and widgets included, as it is sent to the
  browser. Clear the page cache after a PDF is tagged to see it straight away.
- The tagged copy is written to the uploads folder next to the original. Plugins that move uploads
  to external storage (S3 and similar) are not supported.

## What is in this repo

```
docs/                     How it works, developing, troubleshooting
plugin/equalify-iris/     The plugin. This is the thing that ships.
test-site/                A disposable WordPress multisite for testing it
```

| If you want to… | Read |
| --- | --- |
| Understand how it works | [docs/HOW-IT-WORKS.md](docs/HOW-IT-WORKS.md) |
| Fix something that is not working | [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) |
| Change the code | [docs/DEVELOPING.md](docs/DEVELOPING.md) |
| Run it locally | [test-site/README.md](test-site/README.md) |
