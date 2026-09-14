# Equalify Iris for WordPress

A WordPress multisite plugin that gives every PDF on the network an accessible HTML version,
automatically, using [Equalify Iris](https://github.com/EqualifyEverything/equalify-iris).

A screen reader user clicks a PDF link and often finds nothing usable. This plugin finds the PDFs
linked from published pages across the network, converts them, publishes the result as a real web
page, and adds a small icon next to every PDF link that opens it. Editors do nothing and never see
the plugin. One super admin turns it on once, from the network dashboard.

---

## What is in this repo

```
PRD.md                    The product requirements this was built from
docs/                     Documentation — start here
plugin/equalify-iris/     The plugin. This is the thing that ships.
test-site/                A disposable WordPress multisite for testing it (gitignored)
```

## Where to start

| If you want to… | Read |
| --- | --- |
| Install it and convert a first PDF | [docs/README.md](docs/README.md) |
| Understand how it works | [docs/HOW-IT-WORKS.md](docs/HOW-IT-WORKS.md) |
| Look up a term | [docs/GLOSSARY.md](docs/GLOSSARY.md) |
| Fix something that is not working | [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) |
| Change the code | [docs/DEVELOPING.md](docs/DEVELOPING.md) |
| Know why it is built this way | [docs/DECISIONS.md](docs/DECISIONS.md) |

## Running it locally

```bash
cd test-site && ./setup.sh
```

Needs [DDEV](https://ddev.readthedocs.io/) and a running Docker engine. Everything else is in the
containers. Details in [test-site/README.md](test-site/README.md).

## Two things to know before pointing this at real content

- **PDFs over 25 pages or 50 MB are not converted.** Those are Equalify Iris's limits. The plugin
  detects them before uploading, says so in the dashboard, and does not pretend otherwise.
- **Equalify Iris reports conversion problems as public GitHub issues**, which can include extracts
  of the document. This cannot be turned off. Do not point this plugin at confidential documents.
