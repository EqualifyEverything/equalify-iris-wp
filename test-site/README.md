# The test WordPress multisite

A throwaway three-site WordPress network for testing the Equalify Iris plugin. Everything
WordPress-shaped in here is ignored by git, and one script builds it from nothing.

```
./setup.sh
```

First run takes a few minutes. Later runs take seconds and skip anything already done.

| | |
| --- | --- |
| Network screen | https://equalify-iris-test.ddev.site/wp-admin/network/admin.php?page=equalify-iris |
| Site screen | https://equalify-iris-test.ddev.site/wp-admin/admin.php?page=equalify-iris |
| Login | `admin` / `admin` |

## What you need

- **DDEV**: `brew install ddev/ddev/ddev`
- **A running Docker engine**: Docker Desktop, colima (`colima start`), or OrbStack.

WordPress, the database, PHP and WP-CLI all live in the containers. DDEV rather than something
lighter because multisite needs MySQL or MariaDB.

## What it builds

**Three sites**: `/` (main), `/research`, `/library`. Subdirectories, because `*.ddev.site` only
resolves one level deep.

**The plugin, mounted live** from `../plugin/equalify-iris` and network-activated.

**On every site**, PDFs in the media library linked from these pages:

| Page | Status | Why |
| --- | --- | --- |
| Documents | published | Two PDFs, plus a PDF on another domain that must never be touched |
| Linked Twice | published | The same PDF twice, so both links have to switch |
| A Very Long Manual | published | A 30-page PDF, refused before upload |
| Draft Page | draft | Its PDF must not be listed until the page is published |
| Private Page | private | Its PDF must never be listed |

| Custom Field | published | A PDF linked only from a `brochure_pdf` custom field |

And PDFs linked from places that are not posts:

- the **Uncategorized** category's description (`category-guide.pdf`);
- on `/library`, a classic theme (Twenty Twenty-One): a **Main** menu in the primary location
  (`menu-handout.pdf`) and a Custom HTML widget in the footer (`widget-flyer.pdf`);
- on the other sites, a block theme: a navigation menu linking to `menu-handout.pdf`.

Plus `unlinked-file.pdf`, which nothing links to and must never be listed.

## Using it

```bash
./bin/start-mock-iris.sh       # a fake Iris in the container, and point the network at it

ddev wp equalify-iris tag 5    # or Equalify Iris → Send to Iris for Tagging
./tick.sh 3                    # three runs of the network's background job, then the status
./tick.sh 3 https://equalify-iris-test.ddev.site/research   # the same, showing /research's status
```

**Use the mock unless you mean otherwise.** Real Iris reports problems as public GitHub issues.
The mock tags nothing; it hands back the original PDF with a comment added, so you can see the link
switch. Upload a file with `fail` in its name and link it from a published page to see a failed
conversion, `encrypted` to see a refusal, or `slow` to see the job give up waiting (with
`ddev wp equalify-iris run --seconds=40`).

For real conversions, run Iris locally (`cd ../../equalify-iris && npm run dev`), then
`./bin/point-at-local-iris.sh`. `--production` points at the public service.

The mock stops when the container restarts; run `./bin/start-mock-iris.sh` again.

### Other commands

```bash
ddev wp equalify-iris status            # settings, the job, every PDF on the site
ddev wp equalify-iris check             # can Iris tag PDFs?
ddev wp equalify-iris read [--network]  # read a site's content again, or every site's
ddev wp equalify-iris run               # one run of the job, as a scheduler would
ddev wp equalify-iris remove 5          # delete a tagged copy
ddev exec tail -f wp/wp-content/debug.log
./bin/make-oversized-pdf.sh             # a 51 MB PDF, for the size limit
./reset.sh                              # delete everything and start again
```

## Things worth testing

**Only public PDFs**

- The list has the PDFs from Documents, Linked Twice, A Very Long Manual and Custom Field, the
  category description, and the menu (plus the widget on `/library`), and nothing else. **Linked
  from** names the menu, widgets or category.
- Remove the PDF from the menu: it leaves the list on the next run.
- Publish Draft Page: its PDF appears, and with automatic tagging on it is queued at once.
- Unpublish it again: the PDF leaves the list. If it was still waiting, it is never uploaded.
- `wp equalify-iris tag` on the private memo's id is refused.

**The list**

- **Send to Iris for Tagging** from a row, from the bulk action, and with the "all untagged" button.
- After a few ticks: **View Iris-Tagged Version** opens `name-accessible.pdf`; **Delete Iris-Tagged
  Version** asks first, then removes it.
- The 30-page PDF fails with a sentence about pages, and is never uploaded.
- An editor or subscriber has no Equalify Iris menu, and a direct URL is refused.

**Links**

- Logged out, every link to a tagged PDF opens the tagged copy, including both on Linked Twice, and
  the ones in the menu, widget and category archive.
- The example.org link and untagged PDFs are unchanged.
- Delete the tagged copy, or deactivate the plugin: the links go back.

**Automatic tagging**

- Site switch on: public PDFs are queued on the next run.
- Off, with untagged public PDFs: the dashboard notice shows on every admin page but the Equalify
  Iris screen.
- Network switch on: every site's public PDFs are queued, and site admins see the list but no switch
  and no notice.
- Network screen: each site's counts, **Manage PDFs** opens that site's list, and **Send N untagged
  PDFs to Iris for Tagging** queues them.

**The background job**

- Network screen → **Background job**: last run, sites waiting, PDFs at Iris.
- A new site shows "Not read yet"; **Look for PDFs** reads it on the next run.
- Search the site list by address.
- A `slow` PDF with `run --seconds=40` fails after three runs, saying the job could not wait long
  enough.

**Lifecycle**

- Deleting an attachment deletes its tagged copy.
- Deactivate halfway through, reactivate: sites that used it read their content again and carry on.
- Delete the plugin: no `-accessible.pdf` files, no `_equalify_iris_` meta, no `equalify_iris_`
  options left.

## When it goes wrong

| Symptom | Fix |
| --- | --- |
| `ddev start` fails | Start Docker: `colima start`, or Docker Desktop. |
| "could not find a project" | Run the command from inside `test-site/`. |
| Certificate warning | `mkcert -install`, then `ddev restart`. |
| Sub-site admin pages 404 | Needs the nginx rules in `.ddev/nginx_full/`. `ddev restart`. |
| Plugin missing | The bind mount did not attach. `ddev restart`, check `.ddev/docker-compose.plugin.yaml`. |
| Runs fail to reach Iris | The mock stopped. `./bin/start-mock-iris.sh`. |
| State you no longer trust | `./reset.sh` |

## What is committed

The scaffolding: `.ddev/config.yaml`, `.ddev/docker-compose.plugin.yaml`, the nginx rules,
`setup.sh`, `reset.sh`, `tick.sh`, `bin/`, this file. Not `wp/` (WordPress, `wp-config.php`,
uploads) or `samples/` (the generated PDFs).
