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

# 5. prove it still works — NOT OPTIONAL, every single deploy
cd /home4/germanta/public_html && wp eval-file /home4/germanta/gasf-crm/tests/runtime.php
```

Step 2 exists because a PHP parse error in this plugin white-screens the club's
whole website — the shim `require`s it on every page load. `php -l` on a scratch
ref costs ten seconds and has caught real breakage.

**Step 5 is not optional, and is not a formality.** Run it after every deploy,
including the ones that are "obviously safe", and read the number. Two hours of
this project's history are the argument:

- **v2.21.0** shipped a revision helper that read "no `_gasf_photo_rev` row" as
  "somebody else won". 1,335 of 3,654 photos predate that meta, so approve,
  edit, tag and delete broke for all of them in production. `runtime.php` caught
  it on the run immediately after the deploy.
- **v2.20.1** shipped a fix that was *wrong*, and its own new test failed on the
  first server run and said so. The fix was incomplete; the test refused it.
- **v2.26.1** — a misfiled-email bug that no test covered ran for four inbound
  messages without anybody noticing, because an empty thread looks exactly like
  a thread nobody has replied to yet. Nothing failed. That is the failure mode
  this suite exists to convert into a number that goes down.

The run takes about two and a half minutes. It has never once been the slow part
of anything, and it has repeatedly been the only thing standing between a bad
deploy and a volunteer discovering it.

The suite is single-threaded and runs against live WordPress, so it cannot stage
a real race — where a bug is concurrent, pin the PRIMITIVE the fix relies on
instead (see `test_revision_bump`) rather than a scenario that passes either way.

---

## Tests

- **`tests/runtime.php`** — 181 assertions, run on the server against live
  WordPress (there is no second environment) after **every** deploy, no
  exceptions. Safe by construction: synthetic fixtures only, a shutdown reaper
  that survives fatals, options snapshotted, mail disabled. **Never point a test
  at a real photo** — a drill once did, died before its restore line, and left
  drill data in a member's consent record.
  - Add an assertion with every behavioural change. A fix with no test is a fix
    that will be "simplified" back out in six months by somebody reasonable.
  - Prefer pinning the primitive over the scenario when the bug is a race, a
    default, or a silent omission — those are exactly the ones a passing test
    can otherwise sail straight past.
  - The count going UP is part of the evidence a change landed. Note it.
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

## What exists (v2.26.x)

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

**Built and live (server):** twelve key-guarded endpoints — `queue`, `image`,
`suggest`, `caption`, `label`, `discover-label`, `learned`, `label-queue`,
`people`, `metrics`, `calibration`, and `confirmed` (plus `reject`, which takes
a volunteer session rather than the key) — with suggestion chips in the library
editor and an admin panel at *Email CRM → Photos → Face suggestions*. The
scanner key rides in headers only (`Authorization: Bearer` or
`X-GASF-Faces-Key`); it is never accepted in a query string, because a key in a
URL lands in the shared host's access log.

**The design constraint that matters:** face embeddings are biometric data and
must never reach the web host. Recognition runs on a private machine that
**polls outward** (no inbound ports); the vectors live only in a local SQLite
file. What crosses the wire back is a photo id, a rectangle, a name and a
confidence — all things a volunteer could have typed. **A raw suggestion is
stored apart from the taxonomy:** it lives outside `gasf_photo_person`, so a
suggestion as such never reaches the grid, search, sidecars or zip filenames.
But **auto-accept ships on** — the threshold is a WordPress option defaulting to
95 — and above it the scanner's match is promoted into a real `gasf_photo_person`
term, and so into the public title, the grid, search, and the exports, with no
volunteer click. That is the club's deliberate, accepted trade for an
org-internal tool; the earlier claim that "only a volunteer's click writes a
name" no longer holds. What stays true: the confidence and box are recorded so a
machine-made tag is inspectable, and a rejection removes it and blocks its
return.

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

1. **Anything already public stays fetchable by direct URL.** Consent is now
   enforced by STORAGE on every write path — `gasf_crm_photo_enforce_web_boundary()`
   physically moves a not-for-web photo back to the private root at 0600 — but a
   file that was public before being restricted is still reachable at its old
   uploads path, and published photos are ordinary files with no per-request
   check. True enforcement means gated delivery for everything, which is a real
   piece of work rather than a guard.
2. **Party mode has no panic switch** — "disable this door and hide everything
   from the last N minutes" as one admin button.
3. **Eight public videos still carry GPS** — the blanker is proven and wired
   into publish/upload, but nothing walks already-published files. Needs a
   one-off backfill and a go-ahead.
4. **Face auto-accept has no bulk undo.** Revoking the scanner key stops new
   writes but leaves every machine-written tag; there is no "purge what the
   matcher wrote" action, and machine-written names are not marked as such.
   Auto-accept is ON by default at 95 — the club's accepted trade, recorded
   deliberately rather than left implicit.
5. **An edited photo's `-gasf-original` sidecar outlives its photo.** It is in
   no attachment metadata, so neither delete nor unpublish removes it: the
   full-quality pre-edit copy stays in public uploads after a photo is deleted
   or withdrawn.
6. **The `_gasf_photo_confirmed` shape is split** — an array `{from,by,at}` from
   `confirm()`, a bare timestamp string from the quick lanes. Existence checks
   work; anything reading `['by']` silently gets nothing for quick-lane photos.
7. **The club calendar starts 15 July 2023.** Nothing older survived the
   MEC→GASF Events migration, so date-based event suggestions find nothing for
   older photos. Not a bug; missing data.
8. **No QR generator** — party links are copy-paste into any QR tool.
9. **Two people cannot share a name.** Identity is a term to the photo library
   but a NAME STRING to everything face-related, and WordPress refuses duplicate
   term names — so a second "Bob Schmidt" needs a disambiguator in the name.
   `(II)` works on both sides (verified: it normalises to `bob schmidt ii`), but
   a real-world one — middle initial, genuine Sr./Jr. — reads better now that
   names appear in public titles. The principled fix is re-keying refs, labels,
   and rejections off term ids; not worth it at 127 people and zero collisions.
10. **No near-duplicate detection** — dedup is exact-bytes only, so the same
    photo re-encoded by WhatsApp arrives as new. And **no trash/undo**: every
    delete is a force-delete.

**Closed since this list was written** (v2.20.0–2.26.1): HEIC on every intake
route plus the EXIF-date loss during conversion; door counters made atomic under
`GET_LOCK`; the revision compare-and-swap, which was never comparing; the scanner
key out of URLs and out of query strings entirely; the door device budget off a
spoofable header; the permanent door's 600-photo brick, now with an admin reset;
video-by-crafted-POST on the doors; the missing disk-free floor on upload;
people's names out of download filenames; **the held quick-lane and party/bulk
branch now gating publication on `may('web')`**; the public-name opt-out reaching
every surface outside the club and rewriting what is already published;
`YYYY-MM` dates and partial dates in range filters; face records following a
rename or merge; "not a person" ending a face for good; and **inbound email
being filed under a thread id that belonged to another table**.

---

## Four traps worth remembering

All four are the same shape: **state that is correct when you read it and wrong
by the time you use it**, failing silently, looking exactly like the normal
empty case. None produced an error. Three reached production.

**`update_post_meta`'s previous-value guard is void at 0.** It only adds the
expected value to its WHERE clause `if ( ! empty( $prev_value ) )`, and PHP's
`empty(0)` is true — so `update_post_meta( id, 1, 0 )`, i.e. every FIRST
decision, silently updates unconditionally and every racer wins. All revision
guards now go through `gasf_crm_photo_rev_bump()`, which does it in SQL where 0
is an ordinary value. `test_revision_bump` demonstrates the trap deliberately so
a "simplification" back to `update_post_meta` cannot pass unnoticed.

**A missing meta row is not a lost race.** The first version of that helper read
"no `_gasf_photo_rev` row" as "somebody else won" — and 1,335 of this library's
3,654 photos predate the seed, so approve/edit/tag/delete broke for all of them
in production. `gasf_crm_photo_revision()` reports 0 for an absent row, so a
caller holding 0 is current and must be allowed to create it. Both the bug and
the fix were caught by `tests/runtime.php` on the run right after deploy, which
is the argument for running it every time.

**`$wpdb->insert_id` is whatever the LAST insert did, not yours.**
`gasf_crm_upsert_thread()` inserted the thread, then created a case and logged a
case event — both inserts — and only then returned
`array( 'id' => (int) $wpdb->insert_id )`. By then it held the case EVENT's id.
Every inbound email since the case workflow shipped was filed under a thread
number belonging to another table: the row landed, nothing errored, and the mail
was simply never seen again. Four messages went that way before anybody noticed,
because **an empty thread looks exactly like a thread nobody has replied to
yet**. Capture the id into a local the instant the insert returns, before
anything else can insert. The other three `insert_id` reads in the plugin were
audited and each reads immediately after its own insert.

**Rejecting a face NAME made the face permanently unresolved.** The scanner
counts a face as resolved only if it has an explicit label, is the lone face on
a single-named photo, or matches a reference whose name is not rejected. So
rejecting the name flipped that last condition false and the face returned for
review on every later scan — a volunteer doing the right thing made the prompt
permanent. "Wrong person" and "not a person to tag" are different answers and
need different verbs: `_gasf_face_ignored` is the second one. Watch for this
shape generally — a negative signal wired into a positive test can invert it.

---

## Version note

The header in `gasf-crm.php` is the real version — `wp plugin list` reports the
loader shim's 1.0.0, not this. It currently reads **2.26.1** and matches the
newest commit. Bump it with every behavioural change: that header is the only
way to tell from the server what is actually deployed.
