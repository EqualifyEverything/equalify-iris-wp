# Rules for the Iris WP Maintainer

Both maintainer workflows tell the model to read this file first: `maintainer.yml`, which builds an
issue, and `maintainer-revise.yml`, which answers reviews of that pull request. It sits under
`.github/`, which the maintainer may not change, so it cannot rewrite its own rules.

## What matters, in order

1. **Nothing reaches a real Iris.** Iris files conversion problems as public GitHub issues that can
   quote the document. The test network is pinned to the mock at `http://127.0.0.1:8099/v1` by a
   must-use plugin, and `iris.equalify.uic.edu` does not resolve in its container. Leave both alone.
   Never point anything at a real deployment, and never upload a document anywhere.
2. **Security.** Check the capability, then the nonce, then act, then redirect. Escape on output.
   The `EQUALIFY_IRIS_API_TOKEN` constant wins over the stored token; the token field stays a
   password field and the token is never echoed, logged or shown. Only PDFs visitors can reach are
   sent (`Tagger::queue()` and the upload step both check). Written files stay inside uploads
   (`Tagger::tagged_path()`). The API address must pass `Settings::url_is_allowed()`. Every request
   about a PDF goes through `API_Client::blocked()`, so a staging copy never touches live's sessions.
3. **Performance on a big network.** No query per site; anything every site needs is one query on
   `blogmeta`. Nothing new on every page load: the front end only swaps links from the in-memory
   map. Do not add page-load or admin-screen triggers to make tagging feel faster; the background
   job on a five-minute schedule is the design, and the server's load matters more than speed.
   Every Iris request is cut to the run's deadline, because Pantheon kills PHP at 120 seconds.
4. **Multisite scoping.** Network settings use `get_site_option()`, site settings `get_option()`,
   and only through `includes/class-settings.php`.
5. **Any host.** Pantheon matters, but nothing may work only on Pantheon.
6. **Uninstall leaves nothing**: no `equalify_iris_` options, no `_equalify_iris_` meta, no
   `-accessible.pdf` files. A new option or meta key must be covered.
7. **Plain language.** Admin copy is for a person who will never read the code. Docs are short
   sentences in everyday words, like the ones there now.

## Hard limits

- **Never touch** `.github/**`, `LICENSE` or `.gitignore`. A check that does not consult you drafts
  any pull request that does, and the maintainer stops working on it.
- **This repository is public.** Never commit WordPress, `wp-config.php`, database dumps, PDFs, or
  anything under `test-site/wp/` or `test-site/samples/`. Check `git status` before every commit.
- **Never push to `main`**, and never to a branch other than the one you were given.
- **Do not release.** No version bump, no tag, no GitHub release, no zip. That is a human's call
  after merging.
- **No new dependencies, no build step, no front-end JavaScript or CSS.**
- **One pull request**, under about 300 changed lines excluding docs and tests.
- Do not merge, close, or comment anywhere. The workflow posts what you write in the result file.

## Testing

From `test-site/`, against the mock only:

- `ddev wp equalify-iris status`, `tag <id>`, `run`, `read`, `remove <id>`; `./tick.sh 3`.
- `./smoke.sh`: one PDF from upload to tagged link. It must pass. If your change is end-to-end,
  extend it with a check that fails without your change.
- The parts of "Things worth testing" in `test-site/README.md` your change touches. Pages logged
  out: `curl -sk https://equalify-iris-test.ddev.site/...`. Admin screens: log in with
  `admin`/`admin` through `wp-login.php` with a cookie jar, and take each form's nonce from the page.
- `ddev exec tail -n 50 wp/wp-content/debug.log`: no new notices from the plugin.
- Delete any content you added.

From the repository root: `.github/scripts/php-checks.sh <changed files>`. Lint and compat must
pass. Read the security sniffs for your files: a hit is a lead, not a verdict.

## Docs

When behaviour changes, change the docs that describe it in the same pull request: `README.md` for
site owners, `docs/TROUBLESHOOTING.md`, `docs/HOW-IT-WORKS.md`, the traps table in
`docs/DEVELOPING.md`, and `test-site/README.md` for what to test.

## Code

Match the code around you: tabs, `snake_case`, `Equalify_Iris_` prefixes, a `WHAT IS THIS FILE?`
block in each class file, comments that say why. Commit with a plain one-line subject in the style
of `git log`.
