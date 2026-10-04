# Developing

Read [HOW-IT-WORKS.md](HOW-IT-WORKS.md) first. This file assumes you have.

## Local setup

```bash
cd test-site && ./setup.sh
```

You need [DDEV](https://ddev.readthedocs.io/) (`brew install ddev/ddev/ddev`) and a running Docker
engine (Docker Desktop, colima, or OrbStack). It builds a three-site network, mounts
`plugin/equalify-iris` into it live, network-activates it, and gives every site sample PDFs. Edit
the plugin and the change is live; there is no build step. Details are in
[test-site/README.md](../test-site/README.md).

## The loop

```bash
cd test-site
./bin/start-mock-iris.sh          # a fake Iris inside the container; nothing leaves your machine

ddev wp equalify-iris tag 5       # queue attachment 5 (or use the Equalify Iris screen)
./tick.sh 3                       # run the network's background job three times, then show the status
```

A PDF takes two or three runs against the mock: one to upload, then one to fetch the tagged copy
once it has "converted". A run covers every site that is due; `./tick.sh 3
https://equalify-iris-test.ddev.site/research` shows another site's status afterwards.

`ddev wp equalify-iris read` makes a site read its content again; `--network` does every site.

The mock (`bin/mock-iris.php`) answers the five endpoints the plugin uses. A file whose name
contains `fail` fails to convert, one containing `encrypted` is refused with a `422`, and one
containing `slow` takes 45 seconds over `/pdf`, so the failure and timeout paths can be tested
without a real Iris. `ddev wp equalify-iris run --seconds=40` makes a run too short to wait for it.

For a real conversion, run Iris from its own repo and `./bin/point-at-local-iris.sh`. Avoid the
production service for testing: it reports conversion problems as public GitHub issues.

## Checking your work

There is no automated test suite. Before every commit:

```bash
find plugin/equalify-iris -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors'
phpcs --standard=WordPress plugin/equalify-iris     # composer global require wp-coding-standards/wpcs
```

A parse error in a network-activated plugin takes down every site at once. Then run whichever parts
of the checklist in [test-site/README.md](../test-site/README.md#things-worth-testing) your change
touches.

## Where to make a change

| You want to change… | Go to |
| --- | --- |
| A setting, or whether it is per site or per network | `includes/class-settings.php` |
| The page or size limit | `MAX_PDF_PAGES`, `MAX_FILE_BYTES` in `includes/class-settings.php` |
| Any request to Iris, or which errors are permanent | `includes/class-api-client.php` |
| Which sites a run visits, how long it lasts, how many PDFs are at Iris | `includes/class-runner.php` |
| What one site's turn does | `includes/class-tagger.php` |
| What counts as a public PDF, or where links are looked for | `includes/class-discovery.php` |
| Which links are switched | `includes/class-links.php` |
| The screens, their actions, or the notice | `admin/class-admin.php` |
| The list's columns, or a warning's wording | `admin/class-list-table.php` |
| A CLI command | `includes/class-cli.php` |

New classes get a `require_once` in `equalify-iris.php` and an `init()` call in
`includes/class-plugin.php`. There is no autoloader; there are eleven files.

## The Iris API

Everything network-facing is in `class-api-client.php`.

```
GET  /v1/limits                 includes `tagged_pdf`: false means this deployment cannot tag
POST /v1/sessions               multipart upload; the part is called `images`, even for a PDF
GET  /v1/sessions/{id}          queued | running | ready_for_review | closed | failed
POST /v1/sessions/{id}/pdf      {"retag": true} → {filename, pdf (base64), report: {warnings}}
POST /v1/sessions/{id}/close    frees the server's disk
```

Things that are easy to get wrong:

- **`/pdf` is slow, and synchronous.** Iris can take up to 300 seconds and answers `504` after
  that. The job waits as long as the run has left (about 80 seconds by default), fetches at most
  one per site per run, and gives up on a PDF after three timeouts. A PDF that needs longer can
  only be tagged with a longer `EQUALIFY_IRIS_RUN_SECONDS` on a host that allows it, or once Iris
  can build the tagged PDF in the background.
- **`503 busy` and `409 invalid_state` mean "not yet".** They come with no penalty.
- **`400`, `401`, `403`, `413` and `422` are permanent.** Retrying sends the same bytes for the same
  answer. The exception is a `401` saying Iris could not authenticate to GitHub: that is the
  deployment's own credential, and it recovers by itself.
- **A tagged PDF can have warnings.** They are codes (`missing_alt`, `page_not_tagged`,
  `font_not_embedded`, …). `Equalify_Iris_List_Table::notes()` turns them into sentences; an
  unknown code is shown as it is.
- **Uploads stream from disk with cURL**, so memory stays flat. The WP HTTP fallback reads the file
  into memory.

## Conventions

Match the code that is there:

- Tabs, `snake_case`, `Equalify_Iris_` class prefixes, no namespaces. WordPress coding standards.
- Every class file opens with a `WHAT IS THIS FILE?` block, in plain language.
- Comments say why, not what.
- Admin copy is for a person who will never read the code.
- Escape on output. Check the capability, then the nonce, then act, then redirect.
- Network settings use `get_site_option()`; site settings use `get_option()`. Mixing them up is the
  most likely bug here, so both live behind functions in `class-settings.php`.
- No front-end JavaScript or CSS. The plugin only changes link addresses.

## Traps

| Trap | What happens |
| --- | --- |
| Running the job without the lock | Two runs upload the same PDF twice. Always go through `Equalify_Iris_Runner::run()`. |
| Trusting `switch_to_blog()` | The switched-to site's plugins and theme are not loaded, so its custom post types, taxonomies and sidebars do not exist, and `wp_get_upload_dir()` builds the URL from the main site's `WP_CONTENT_URL`. Use `Discovery::profile()` and `Links::upload_file()`, which allow for both. |
| `get_site_meta()` for `equalify_iris_due` | The job queries that table in SQL, so a cached copy is always stale. `Runner::wake()` and `set_due()` write it directly. |
| `clean_post_cache()` in a loop | Page-cache plugins purge the CDN for every post. The runner flushes the runtime cache instead. |
| A query per site | A network can have 100,000. Anything every site needs goes through one query on `blogmeta`, like `wake_all()`. |
| A run longer than the host allows | Pantheon kills PHP at 120 seconds, mid-write. Every Iris request is cut to the run's deadline; a new one must be too. |
| A checkbox in a settings form | An unchecked box sends nothing. Treat a missing field as off. |
| `get_error_data()` returning null | `$error->get_error_data()['status']` warns. Use `Equalify_Iris_API_Client::status()`. |
| Making the tagged copy an attachment | It would show up in the media library as a second PDF. It is a plain file on purpose. |
| Queuing a PDF without `queue()` | `queue()` refuses PDFs that are not public. Setting the status meta directly skips that check, though the upload step checks again. |
| A post type registered late | The profile is saved on `wp_loaded`. A type registered after that is not counted. |
| Testing links while logged in | Logged-in pages skip most page caches, so a cache problem only shows logged out. |
