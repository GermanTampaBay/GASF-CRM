# GASF-CRM — working manifest

The German-American Society of Tampa Bay's shared-inbox CRM and photo
catalogue. A WordPress plugin serving **germantampabay.com**.

This file loads automatically for any session started in this directory. If you
are reading it, you are in the right place — everything below is relative to
this repo and nothing else.

---

## Where things are

| | |
|---|---|
| **Repo** | `github.com/GermanTampaBay/GASF-CRM` (branch `main`) |
| **Local working copy** | `C:\OneDrive - Microsoft\Technical\GASF-Websites\GASF-CRM` |
| **Live site** | https://germantampabay.com — volunteer UI at `/email` |
| **Server** | Bluehost shared. SSH `germanta@162.241.253.39:2222`, key `C:\mcp-keys\gasf_bluehost` (MCP connector `gasf-SFTP-Linux-Server`) |
| **Server checkout** | `/home4/germanta/gasf-crm` ← the git repo; deploys happen here |
| **WordPress root** | `/home4/germanta/public_html` ← run `wp` from here |
| **Log** | `/home4/germanta/gasf-crm.log` |

**How WordPress loads it.** `wp-content/plugins/gasf-crm/gasf-crm.php` is a
915-byte **loader shim** that `require`s the real plugin from the git checkout.
So `git pull` in `/home4/germanta/gasf-crm` *is* the deploy — nothing is copied
into `wp-content`. `wp plugin list` reports the shim's version (1.0.0), not the
real one; the real version is the header in `gasf-crm.php`.

**Never edit files on the server.** Commit → push → pull. Direct edits create
drift the next pull silently destroys.

---

## The deploy ritual

```bash
# 1. locally: both static checks must pass before committing
node tools/check-js.js && node tools/static-checks.js

# 2. lint on the server WITHOUT deploying — push a scratch ref first
git push origin main:refs/heads/lint-check --force
#    then on the server:
#    cd /home4/germanta/gasf-crm && git fetch -q origin lint-check
#    git show origin/lint-check:<file> > /tmp/l.php && php -l /tmp/l.php

# 3. only then
git push origin main
git push origin --delete lint-check

# 4. on the server
cd /home4/germanta/gasf-crm && git pull --ff-only origin main

# 5. prove it still works
cd /home4/germanta/public_html && wp eval-file /home4/germanta/gasf-crm/tests/runtime.php
```

Step 2 exists because a PHP parse error in this plugin white-screens the club's
whole website — the shim `require`s it on every page load. `php -l` on a scratch
ref costs ten seconds and has caught real breakage.

---

## Tests

- **`tests/runtime.php`** — 69 assertions, run on the server against live
  WordPress (there is no second environment). Safe by construction: synthetic
  fixtures only, a shutdown reaper that survives fatals, options snapshotted,
  mail disabled. **Never point a test at a real photo** — a drill once did, died
  before its restore line, and left drill data in a member's consent record.
- **`tools/check-js.js`** — parses inline JS, balances inline CSS.
- **`tools/static-checks.js`** — patch-script residue, the serial-comma house
  rule, REST routes without permission callbacks, unprepared SQL.
- **CI** — `.github/workflows/ci.yml` runs both static checkers on every push.

---

## House rules

- **Oxford comma is mandatory** in all user-facing copy. `static-checks.js`
  enforces it.
- **Verify outcomes, don't trust returns.** This codebase has been bitten
  repeatedly by steps that reported success having done nothing. Check the
  database, not the green tick.
- **Drill anonymous paths anonymously.** Browser drills carry a volunteer
  session cookie, which silently passes permission gates a real guest would
  fail. Use `curl` without cookies for anything public-facing.
- **Patch scripts:** write Python to a file with the Write tool, then run it.
  Shell heredocs have mangled escapes repeatedly — one `\b` became a literal
  BACKSPACE byte and invisibly disabled the check it sat in.
- **Never commit** `config.json` or `faces.db` under `tools/face-scanner/`
  (gitignored), and never create or read the server's secrets in git.

---

## What exists (v2.15.x)

**Email CRM** — Microsoft Graph app-only against a shared mailbox, volunteers
sign in at `/email` with Google or Microsoft, Claude-drafted replies.

**Photo catalogue** — email intake → volunteer review → EXIF/GPS scrub →
publish → library with people/place/event tagging, bulk tag, crop editor.

**Public photo doors** (`photos-public.php`) — one URL, two postures:
- *Year-round* — always open, full tagging, **held for volunteer approval**.
- *Party* — window-gated, **auto-accepts** onto the library. Guarded by
  possession of a QR code during the event window; exempt from the bot layers
  on purpose.
- Bot defence on the year-round door: honeypot, signed speed floor, Cloudflare
  Turnstile. Fails **open** if Cloudflare is unreachable — the volunteer queue
  behind it is the real gate.

**Consent** — `gasf_crm_photo_may( $id, 'web'|'export'|'kiosk'|'backup' )` is
the single policy function. `limited` = kiosk and backup only; `refused` = the
club may keep it but never show it. **Enforced in the zip export.**

**Backup** — mirrors every library photo plus a JSON sidecar to the Teams
"Photo Archive" (SharePoint) via Graph. Failed deletions go to a retry queue;
orphans stuck past a day raise their own admin banner. Health alerts email the
WP admins after 24h without a clean pass.

**Face suggestions** (`photos-faces.php`, v2.15.0) — see below.

---

## In flight: the face scanner's home side

**Built and live (server):** four key-guarded endpoints — `queue`, `image`,
`suggest`, `confirmed` — plus suggestion chips in the library editor and an
admin panel at *Email CRM → Photos → Face suggestions*.

**The design constraint that matters:** face embeddings are biometric data and
must never reach the web host. Recognition runs on a private machine that
**polls outward** (no inbound ports); the vectors live only in a local SQLite
file. What crosses the wire back is a photo id, a rectangle, a name and a
confidence — all things a volunteer could have typed. **A suggestion is never a
tag:** it is stored outside `gasf_photo_person`, so it is structurally incapable
of reaching the grid, search, sidecars or zip filenames. Only a volunteer's
click writes a name. Two tests pin that negative.

**Built (client, on the target Windows machine):** the original
`tools/face-scanner/scan.py` assumed `face_recognition`/dlib, which has **no
wheel for Python 3.14**. Resolved by proving `insightface` installs as a plain
binary wheel on 3.14 (buffalo_l / ArcFace, 512-d, CPU) — verified end to end on
the box. The client now has:

1. A **backend abstraction** — `insightface` (production) or `face_recognition`
   (dlib, kept for machines that have it) behind one interface. Every reference
   vector in `faces.db` is stamped with its engine and only ever compared within
   that engine; watermarks are per-engine; a pre-engine DB migrates to the dlib
   stamp. Switching engines just relearns — it cannot misread the other's
   numbers. ML is lazily imported so `--status`/`--selftest` need no backend.
2. A `--check` **doctor**: Python, backend actually loads, config, DB writable,
   server accepts the key.
3. A `--selftest` (12 assertions over confidence/identify/box-packing/DB, no ML,
   no network — CI-able).
4. `README.md`, `requirements.txt`, updated `config.example.json` (documents
   `engine`/`tolerance`), and `run.ps1` + `install-task.ps1` (logon-only Windows
   Scheduled Task, logs to gitignored `scan.log`). PS files are **ASCII-only** —
   PS 5.1 reads `.ps1` as cp1252 and a Unicode dash breaks parsing.

**Still open on the client:**
- **Tolerance tuning against real club faces** — now a config knob
  (`tolerance` / `GASF_FACE_TOLERANCE`), left strict by default: a missed
  suggestion costs one typed name; a confident wrong one puts a member's name on
  a stranger's face in the archive.
- The `face_recognition`/dlib path is written but **unverified** (dlib not
  installed on 3.14); insightface is the path that is proven.
- Registering the Scheduled Task needs the user's own session (may need
  elevation); it could not be exercised in the build environment.

**Not settled, and not an engineering decision:** whether the club wants a face
matcher pointed at its members, including the children at Nikolaustag. The
architecture keeps that answer cheap to reverse. Board call.

---

## Open items, roughly ranked

1. **Limited-scope photos are still physically web-served.** The policy
   function returns `false` for `web`, but a published file is reachable by URL
   regardless. True enforcement means gated storage behind the handler.
2. **Party mode has no panic switch** — "disable this door and hide everything
   from the last N minutes" as one admin button.
3. **Device/door counters are non-atomic** read-modify-write. Low stakes,
   genuinely racy.
4. **The held quick-lane duplicates publish logic** rather than routing through
   `gasf_crm_photo_confirm()`. Works, drifts.
5. **Eight public videos still carry GPS** — the blanker is proven against
   copies; awaiting a go-ahead to clean the live files.
6. **The club calendar starts 15 July 2023.** Nothing older survived the
   MEC→GASF Events migration, so date-based event suggestions find nothing for
   older photos. Not a bug; missing data.
7. **No QR generator** — party links are copy-paste into any QR tool.

---

## Version note

`gasf-crm.php` currently reads `2.15.0` while the newest commit is tagged
`v2.15.1` in its message — the last commit changed behaviour without bumping
the header. Bump it on the next change.
