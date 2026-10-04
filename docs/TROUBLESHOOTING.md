# Troubleshooting

Symptom, cause, fix. Two commands answer most questions:

```bash
wp equalify-iris check --url=example.org            # can Iris tag PDFs for us?
wp equalify-iris status --url=example.org/research  # settings, next run, and every PDF on that site
```

## Nothing is happening

PDFs say "Queued" and never move, or a site's list says it is still reading.

1. **Is the job running?** Network Admin → Equalify Iris → **Background job** shows when it last
   ran, and warns when sites are waiting and it has not run for fifteen minutes. `wp equalify-iris
   status` shows the same. The job is WP-Cron on the main site, which only runs when the main site
   is visited, and never on Pantheon multisite or with `DISABLE_WP_CRON`. Fix: run it from a
   scheduler every five minutes:

   ```
   */5 * * * * wp equalify-iris run --url=example.org --path=/var/www/wordpress
   ```

   On Pantheon, from a CI pipeline or any machine with Terminus:
   `terminus wp <site>.<env> -- equalify-iris run`. One command covers the whole network.

2. **Run it by hand** to see what happens: `wp equalify-iris run`. It says how many sites it
   visited and what it did. "Another run is going" means one is already working; it finishes within
   two minutes, and a run that died hands over its lock after about three and a half.

3. **Is Iris full?** At most four PDFs are at Iris at once across the network
   (`EQUALIFY_IRIS_MAX_AT_IRIS`), so on a network with thousands queued, a PDF can wait days for its
   turn. "PDFs at Iris now" on the network screen shows it.

## PDFs stay at "Being tagged"

Iris takes a few minutes to convert a PDF, then has to build the tagged copy while the job waits for
it, which can take minutes more.

- **"Equalify Iris took longer than the N seconds this server lets the job wait."** The job waits
  as long as its run has left, about 80 seconds on Pantheon. It tries three times, then gives up.
  Where the host allows longer PHP processes, raise `EQUALIFY_IRIS_RUN_SECONDS` (keep it 30
  seconds under the limit). Otherwise the PDF has to wait until Iris can build tagged PDFs in the
  background.
- **A run died.** The next run takes over its lock once it is stale, then carries on.
- The Equalify Iris screen shows the last problem under the status while it is retrying. Ten
  problems in a row and the PDF is marked failed.

## A PDF says it could not be tagged

The reason is shown on the Equalify Iris screen and by `status`.

| Reason | What to do |
| --- | --- |
| More than 25 pages | Split it, or leave it. Iris will not tag it. |
| Larger than 50 MB | Compress it, or leave it. |
| Encrypted or password protected | Save an unprotected copy and upload that. |
| A page is too large | Posters and drawings rasterize too large for Iris. Nothing to do. |
| Gave up after several attempts | Iris was unreachable or busy for a long time, or took longer than the job can wait (see above). Choose **Send to Iris for Tagging** to try again. |
| Needs a shared API token | The deployment has a token set. Network Admin → Equalify Iris. |
| Cannot tag PDFs | The deployment has tagged PDFs turned off. **Save and check the connection** says so. |

## A link still opens the original PDF

1. **Is it tagged?** On the Equalify Iris screen it says "Tagged" and **View Iris-Tagged Version**
   works.
2. **Is the page cached?** Clear the page cache. Check logged out; logged-in visitors usually skip
   the cache.
3. **Is the page built by WordPress's front end?** The whole page is switched as it is sent, so
   menus, widgets and theme templates are covered. Pages served some other way (a headless
   front end, a static export, the REST API) are not.
4. **Does it point at this site's uploads folder?** Links to another site in the network, a CDN
   address, or a download-manager URL are not recognised. Plugins that move uploads to S3 or
   similar are not supported.

## A PDF is missing from the list

Only PDFs that something visitors can see links to are listed. Check that:

- the page is published, not a draft, private, scheduled, or password-protected;
- the link is somewhere the plugin looks: content, excerpt or custom fields of a public post type,
  a menu in a theme location, a widget in an active sidebar, a block template or its navigation, a
  category or tag description, or an ACF options page. Links in a theme's PHP files, and in page
  builders that store their layout elsewhere, are not found;
- the link points at this site's uploads folder, not another site's or a CDN;
- the site has been read. A site is not read until it uses the plugin; the network screen says
  "Not read yet" and has **Look for PDFs**, or `wp equalify-iris read --url=…`. While it is reading,
  the screen says so and `wp equalify-iris status` shows "Content read: not yet";
- for a **custom post type**, a theme's sidebar, or a plugin's taxonomy: the site has had a
  front-end visit since that was added. The job cannot see a site's own plugins and theme, so each
  site writes down its public types when it is visited, and is read again when they change.

Saving the page again records its links straight away.

## The automatic tagging switch is missing for a site admin

That is intended when a super admin has turned on automatic tagging for every site. The site's
PDFs are being tagged, and the dashboard notice is hidden for the same reason. The list is still
there.

## The dashboard notice will not go away

It shows while automatic tagging is off and any public PDF is not tagged, including failed ones.
Turn on automatic tagging, or tag every PDF in the list. A PDF that will never tag (over 25 pages,
say) keeps it showing until it is replaced or unlinked.

## Starting over

- **Delete one tagged copy:** **Delete Iris-Tagged Version**. Links go back to the original.
- **Stop everything:** deactivate the plugin. Links go back; tagged files stay for when you
  reactivate.
- **Remove everything:** delete the plugin. Every tagged file, post meta and setting is removed, on the sites that used the plugin. On a very
large network the host may stop the request partway; what is left is never read again.
