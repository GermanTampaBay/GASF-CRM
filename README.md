# GASF Email CRM & Photo Catalogue

The German-American Society of Tampa Bay's shared-inbox CRM and photo
catalogue, as a standalone WordPress plugin.

Approved volunteers sign in at `/email` with Google or Microsoft, read and
answer the club's mail, take in photo submissions, record permissions, tag
people and places, and pull finished photos back out for newsletters and
posters. The full design contract is in
[docs/EMAIL-CRM-SPEC.md](docs/EMAIL-CRM-SPEC.md).

## History

This lived inside
[GASF-Utilities](https://github.com/GermanTampaBay/GASF-Utilities) as modules
42/43 plus `modules/email-crm/` through utilities v1.90.0, by which point it
was ~18,000 lines wearing a module's clothes. This repo is that code moved,
not rewritten; the 1.x history is in the utilities repo.

## Layout

| Path | What |
|---|---|
| `gasf-crm.php` | Plugin bootstrap: double-load guard, fallbacks for what the utilities plugin used to provide, includes. |
| `includes/loader.php` | Gate, constants, config, streams, schema/rewrite upgrades. |
| `includes/email-crm/` | The CRM proper — auth, Graph sync, REST, UI, photos intake/library/upload. |
| `includes/photo-catalog.php` | Taxonomies, EXIF, places, the shared people-matcher JS. |
| `tools/check-js.js` | Pre-commit guard: parses the inline JS and brace-checks the inline CSS that PHP emits. PHP lint sees neither. |

## Working on it

Run this before every commit that touches a file emitting `<script>` or
`<style>`:

```bash
node tools/check-js.js
```

Deploy is git-based: commit → push → `git pull` in the plugin directory on the
server. Never edit files on the server directly.

## Secrets

None in this tree, deliberately. Graph client secrets, the Anthropic key and
mailbox config live in `wp_options`, written from the admin screen and read at
runtime — see the note above `gasf_crm_cfg()` in `includes/loader.php`.

## Coexistence with GASF-Utilities

Runs beside it happily; the utilities plugin's log, settings and admin screen
are used when present, with guarded fallbacks when not. If a GASF-Utilities
build that still carries the old modules is loaded, this plugin detects the
copy already in memory and steps back with an admin notice instead of
fataling the site.
