# GASF-CRM face scanner

The home half of the club's face-suggestion feature. It runs on **one private
machine** — not the web host — polls the CRM over HTTPS for library photos,
works out who is in them, and posts back a name, a box, and a confidence.

Face matches are sent with a confidence score. Below the administrator-set
auto-accept threshold they remain suggestions stored apart from real people
tags; at or above it, the server may apply the name automatically. The local
script never writes WordPress taxonomy terms directly, reads mail, or approves
a photo. See the header of [`scan.py`](scan.py) and the server side in
[`../../includes/email-crm/photos-faces.php`](../../includes/email-crm/photos-faces.php)
for the full design and why it is shaped this way.

**Why here and not on the server:** face embeddings are biometric data. Keeping
them off a shared host means there is no biometric database to breach or
subpoena — the vectors live only in `faces.db` on this machine, and the home
network opens no inbound port because this script only ever reaches *out*.

---

## Setup (Windows, Python 3.13/3.14)

### Install on another PC (recommended)

Build the source-free transfer ZIP once from this folder:

```powershell
powershell -ExecutionPolicy Bypass -File build-installer.ps1
```

Copy the resulting `dist\GASF-Face-Scanner-<version>.zip` to the laptop,
extract it, and double-click `Install-GASFFaceScanner.cmd`. The installer:

1. Installs 64-bit Python 3.13 and Ollama through `winget` when missing.
2. Copies only the runtime scripts to `%LOCALAPPDATA%\GASF Face Scanner`.
3. Creates a private `.venv` and installs InsightFace, ONNX Runtime, and the
   scanner's core packages.
4. Downloads `qwen3-vl:8b`, writes `config.json` after a hidden scanner-key
   prompt, and runs the full preflight.
5. Adds **GASF Face Scanner** to the Desktop and Start Menu.

The laptop does not need Git or this repository. Re-running a newer installer
updates scripts and dependencies while preserving its scanner key,
engine/tolerance/discovery tuning, and local `faces.db`. Use
`-InstallScheduledTask -TaskIntervalMinutes 30` when unattended polling is
wanted; it is deliberately not enabled by default.

The ZIP excludes credentials, face vectors, logs, caches, and Git metadata.
See `INSTALL-LAPTOP.txt` inside it for the short transfer instructions.

### Move an existing face corpus securely

`faces.db` contains biometric vectors, so the installer deliberately never
places it in the transfer ZIP. A new laptop can rebuild those vectors from
confirmed WordPress labels, but an encrypted direct transfer avoids that
recomputation and preserves the incremental-learning watermarks.

1. Close ScanGUI and the browser labeler on both computers. If the optional
   task is installed, stop it before copying:

   ```powershell
   Get-ScheduledTask -TaskName "GASF Face Scanner" -ErrorAction SilentlyContinue |
     Stop-ScheduledTask
   ```

2. On the old computer, locate `faces.db` beside `scan.py`, record its hash, and
   copy it only to a BitLocker-encrypted USB drive or an AES-256-encrypted
   archive. Do not email it, put the raw file in cloud storage, or include
   `config.json`.

   ```powershell
   $old = "C:\path\to\old\face-scanner\faces.db"
   Get-FileHash -Algorithm SHA256 $old
   Copy-Item -LiteralPath $old -Destination "E:\faces.db"
   ```

3. Install the scanner normally on the laptop, close it, preserve any database
   it created, and copy the transferred database into the installed folder:

   ```powershell
   $app = Join-Path $env:LOCALAPPDATA "GASF Face Scanner"
   $dest = Join-Path $app "faces.db"
   if (Test-Path $dest) {
     Move-Item -LiteralPath $dest -Destination (Join-Path $app "faces.db.before-migration")
   }
   Copy-Item -LiteralPath "E:\faces.db" -Destination $dest
   Get-FileHash -Algorithm SHA256 $dest
   ```

4. Confirm the destination hash matches the old computer, then launch ScanGUI
   and run `--status`. Keep the rollback file until the corpus counts look
   correct. The database stores each vector's engine, so engine isolation
   remains intact after transfer.

5. Remove the transfer copy from the encrypted media. Once the laptop is
   proven and the old scanner is retired, remove the old `faces.db` too; avoid
   keeping unnecessary biometric replicas.

The scanner credential is separate. Let the installer write `config.json` from
its hidden key prompt rather than moving the old credential file.

### Manual/developer setup

```powershell
# 1. core libraries
pip install -r requirements.txt

# 2. the recognition backend — the production choice, installs as plain wheels
#    (no compiler) on current Python, which dlib does not:
pip install insightface onnxruntime

# 3. tell it where the site is and give it the scanner key
copy config.example.json config.json
notepad config.json
#    …or use environment variables instead of the file:
#    setx GASF_URL https://germantampabay.com
#    setx GASF_FACE_KEY gasf_face_xxxxxxxxxxxx

# 4. confirm everything lines up
python scan.py --check
```

### One-command setup (Ollama + model + scanner config)

If you want a full bootstrap on Windows, use:

```powershell
powershell -ExecutionPolicy Bypass -File install-ollama.ps1 `
  -ScannerKey "gasf_face_xxxxx" `
  -SiteUrl "https://germantampabay.com" `
  -CaptionModel "qwen3-vl:8b"
```

That script installs Ollama (if missing), starts/verifies the local API, pulls
the vision model, installs scanner Python dependencies, writes `config.json`,
and runs `scan.py --check`.

The **scanner key** comes from **wp-admin → Email CRM → Photos → Face
suggestions → Issue a scanner key**. It is shown once; if you lose it, issue a
new one (the old one stops working immediately).

The first real run downloads the `buffalo_l` model pack (~280 MB) into
`~/.insightface/` and takes a few seconds to warm up; every run after is fast.
Older JPEGs that still carry EXIF rotation are normalized before detection, so
face rectangles use the same orientation Edge displays.

---

## Everyday use

| Command | What it does |
|---|---|
| `python scan.py` | Learn if the reference set is empty, then scan whatever is waiting, then stop. |
| `python scan.py --learn` | Refresh the reference set from newly tagged photos first. It also advances a bounded, resumable legacy-quality backfill. |
| `python scan.py --label` | Open a local browser app: gallery first, click a photo to open it, navigate one-photo-at-a-time with Back/Next, Exit back to gallery, autocomplete names from library people, and save explicit box→name mappings for learning. |
| `python scan.py --label --label-flow` | Mature refinement pass: learn corrections, face-scan new photos, label only unresolved work, relearn those labels, then run the full face/caption scan. |
| `python scan.py --discover` | Refresh unresolved observations, cluster them locally, and open the loopback-only People Discovery contact-sheet board. |
| `python scan.py --watch 900` | Keep going: learn, scan, sleep 15 min, repeat. |
| `python scan.py --uploaded-after 2026-08-01 --uploaded-before 2026-08-14` | Only process photos uploaded in that date window (inclusive) for scanning, `--label`, and `--discover`. Useful for "new uploads only" runs. |
| `python scan.py --status` | Active/retained corpus counts and quality, what is waiting, and the latest conservative calibration recommendation. No ML is loaded. |
| `python scan.py --check` | Preflight: backend, config, database, and that the server accepts the key. |
| `python scan.py --selftest` | Exercise the non-ML plumbing. Needs no backend, no config, no network. |
| `python scan-gui.py` | Checkbox launcher UI for `scan.py` (blocks unsupported option combos, shows inline output). |

`--engine insightface|face_recognition|auto` overrides the backend for one run.

### Simple launcher UI

If you prefer not to remember CLI flags:

```powershell
python scan-gui.py
```

- Tick options, click **Start**, and it runs `scan.py`.
- It blocks unsupported combinations (for example `--learn` + `--label`).
- In label mode, leave **Refinement flow: Learn → Scan → Label → Learn → Scan** on. Familiar high-confidence faces are resolved before the browser opens, so the default gallery concentrates on unknown and uncertain faces.
- The label gallery keeps its existing filters and adds optional **Active learning**. It ranks a bounded top subset using unresolved-cluster size, corpus weakness, boundary uncertainty, appearance novelty, crop quality, and underrepresented dates when known. The score and every embedding stay local.
- In discovery mode, each contact sheet is a conservative, engine-isolated cluster from the current date/limit preparation scope. Open it, deselect mistakes, type one known or new person name, and confirm. That one name applies to every selected face. Unselected faces remain unknown and are reclustered; **Dismiss selected locally** suppresses the same local face using its rectangle and embedding, even if detector order changes, without changing or deleting the WordPress photo.
- Optional upload-date bounds (`Uploaded after`, `Uploaded before`) let you skip old uploads (`YYYY-MM-DD`).
- The labeler has live outline/opacity settings plus zoom, fit, center, and pan controls. You can also drag the image to pan, double-click to fit, and use Ctrl+wheel to zoom.
- In the WordPress photo editor, **Not in photo** removes one wrong person suggestion and remembers that photo/person rejection. Later scans may suggest other people, but cannot resurrect that rejected person or auto-accept them on that photo.
- The launcher finds `scan.py` beside itself, so the folder can move without editing code.

Optional single-file EXE (Windows):

```powershell
pip install pyinstaller
pyinstaller --onefile --noconsole scan-gui.py
```

Then run `dist\scan-gui.exe`.

### Optional: local AI summaries (captions)

If you run a local vision model in Ollama, the scanner can submit a short
caption suggestion with each scanned photo.

1. In `config.json`, set `"caption_model"` (`qwen3-vl:8b` is recommended for
   the scanner machine's 16 GB GPU).
2. Leave `caption_url` at `http://127.0.0.1:11434/api/generate` unless your
   local Ollama installation uses another loopback address. Remote endpoints
   are refused so club photos cannot be sent to an external model by mistake.
3. Run `python scan.py --check` to confirm the model is configured.

The captioner uses trusted catalogue metadata already attached to the photo:
event, date taken, place, confirmed people, and groups. It keeps that context
separate from visual evidence so the model may say *Nikolaustag at the
German-American Society* when those tags are known, but may not invent a name,
location, date, or activity.

Caption generation is deliberately slower and more conservative than face
matching:

1. A structured first pass drafts a caption and lists visible evidence,
   readable text, and uncertainties.
2. A second pass checks the draft against the same image and trusted metadata,
   removing unsupported claims.
3. Low-temperature, bounded generation keeps the result factual and concise.

Set `"caption_passes": 1` to disable verification, or leave the recommended
default of `2`. `"caption_num_ctx": 8192` prevents image tokens from crowding
out the prompt.

Caption work has its own endpoint, completion key, and failure counter; a
caption-only result cannot alter face boxes or suggestions. If Ollama is
unavailable, face matching can still finish while the caption remains queued
for a later run. Repeatedly invalid output is quarantined after three runs.
Changing the caption model does not silently reprocess the whole archive;
**Rescan everything** explicitly refreshes existing captions.

When enabled, the CRM shows a **Use suggestion** button in photo editing.
Applying it writes the caption with ` (AI Summary)` appended, so provenance is
explicit in the archive.

On Windows, `scan.py` now auto-adds pip-installed NVIDIA runtime DLL folders
(`site-packages\nvidia\...\bin`) before loading ONNX/InsightFace, so CUDA
runs do not depend on manual PATH edits in every shell.

### How it learns

It learns from two safe sources:

1. **Unambiguous photos** (one face, one name), and
2. **Explicit face-box labels** from the local scanner tool (`python scan.py --label`),
   which detects faces locally and stores exact box→name mappings through the
   scanner API.

That second path means group photos can now teach the model without guessing
which name belongs to which face. A person is not offered as a suggestion until
it has **at least three** examples.

Each reference is scored locally from face pixel size, crop sharpness, and
detector-box edge clipping. The database retains every current confirmed
reference, but matching uses at most 12 quality-first, diverse references per
person and engine. Near-duplicates are inactive rather than deleted, historical
date/appearance diversity receives a selection bonus, and a person with usable
vectors is never selected below the three-reference floor. Relearning replaces
one photo's truth and recomputes affected selections in one SQLite transaction.
No pose score is invented: neither supported backend currently exposes one
reliably through the shared abstraction.

References also carry the WordPress person term id and canonical display name.
Alias spellings therefore share one reference floor, a rename updates the
display name without splitting vectors, and a merge moves later reconciliation
onto the surviving identity. Older databases retain their exact-name rows until
the confirmed photo is reprocessed.

Databases created before objective quality metadata are backfilled incrementally
in batches of 25 photos during ordinary learn/watch/refinement passes. A failed
image leaves its old reference untouched for the next pass. Each engine has its
own resumable completion marker, which is written only after no synthetic
quality rows remain.

### People Discovery stays local

`--discover` stores unresolved observations and their clusters only in
`faces.db`. Each row includes the local embedding plus review context such as
the photo id, detector face index, displayed-pixel rectangle and dimensions,
date taken/uploaded when available, and trusted event/place context. Clusters
never mix recognition engines.

The board listens only on a random `127.0.0.1` port and uses a per-run random
token, restrictive browser headers, request limits, bounded image caching, and
stale-view guards. Representative crops are generated locally after the board
fetches a protected library image. No embedding, centroid, cluster template, or
distance is sent to WordPress.

Naming sends only the selected photo ids, displayed-pixel boxes and dimensions,
and the one human-entered name. WordPress adds those reviewed box-to-name facts
without replacing unrelated labels on the photo. The next `--learn` pass picks
them up as explicit training examples.

`"discovery_tolerance"` is a separate, deliberately conservative distance
threshold; lower values create smaller, stricter clusters. The default is
engine-specific. `"discovery_limit"` caps preparation at the latest 1,000
approved library photos. The CLI `--discovery-limit N` and the existing upload
date flags can narrow a pass further. A narrowed board clusters and displays
only observations refreshed in that pass; older local rows remain available
for a later, broader run but cannot influence the current contact sheets.

---

## Running it on a schedule

Two small PowerShell helpers register a Windows Scheduled Task so this runs by
itself whenever the machine is on and you are logged in.

```powershell
# every 30 minutes while you are logged in
powershell -ExecutionPolicy Bypass -File install-task.ps1

# a different cadence, or a dry run, or remove it
powershell -ExecutionPolicy Bypass -File install-task.ps1 -IntervalMinutes 15
powershell -ExecutionPolicy Bypass -File install-task.ps1 -WhatIf
powershell -ExecutionPolicy Bypass -File install-task.ps1 -Uninstall
```

- The task calls [`run.ps1`](run.ps1), which does an incremental learn plus one
  scan pass and appends output to `scan.log` in this folder. Run `run.ps1` by
  hand to see exactly what the task will do.
- It runs **only while you are logged on** — no stored password, no admin mode.
  That matches the design: if this machine is off, the CRM just gets no new
  suggestions and everything else carries on.
- If `Register-ScheduledTask` reports **Access is denied**, run the same command
  from a PowerShell started with **Run as administrator** (some machines require
  elevation to create a task).
- Do **not** install this on the web host or any shared box — the whole point is
  that the vectors stay on a machine the club controls.

---

## Backends, and why the engine is recorded

Recognition can run on either engine, and their numbers are **not**
interchangeable:

| Engine | Vectors | Distance | Install |
|---|---|---|---|
| `insightface` (buffalo_l / ArcFace) | 512-d | cosine | `pip install insightface onnxruntime` — plain wheels, **the one to use** |
| `face_recognition` (dlib) | 128-d | euclidean | `pip install face_recognition` — needs dlib + a compiler; no wheel on new Python |

Every reference face is stored in `faces.db` **stamped with the engine that
produced it**, and the scanner only ever compares a face against references from
the same engine. Switching engines is therefore safe: the new one simply
relearns into its own vectors and never misreads the other's. Unknown
observations, clusters, and confidence evidence carry the same engine boundary.
Legacy evidence without an engine is retained but cannot influence a current
recommendation. `--status` shows active/retained counts, measured-quality
backfill progress, and average objective quality per engine.

## Confidence calibration

WordPress retains a bounded, non-biometric history of offered name, confidence,
photo, rectangle, and recognition-engine facts after a suggestion disappears. A
matching face-box label explicitly saved by a human is positive; **Not in
photo** is negative. Machine auto-accept, no-action, and unrelated person tags
do not count, and negative outcomes never become face vectors.

The scanner filters evidence to its currently resolved backend, groups explicit
outcomes into five-point confidence bands, and tests candidate thresholds with a
99% Wilson confidence lower bound. It recommends a threshold only after the
configured minimum sample count and only when that conservative floor meets the
target precision (99% by default). `--status`, normal scan completion, and the
WordPress face admin panel show the concise engine-specific result. A
recommendation is advice: it never changes the saved auto-accept setting.
`calibration_target_precision` may only be 0.99 or higher, and
`calibration_min_samples` has a hard floor of 20.

## Tuning the tolerance

The match threshold is deliberately strict by default (a wrong suggestion is
worse than a missed one). The right value depends on real club faces, so it is
left as a knob rather than guessed: set `"tolerance"` in `config.json` (or
`GASF_FACE_TOLERANCE`). Lower = stricter, fewer but surer suggestions. Watch a
batch of `--status` and the chips in the library before loosening it.

---

## Files

| File | |
|---|---|
| `scan.py` | The scanner. Committed. |
| `run.ps1`, `install-task.ps1` | Scheduled-task helpers. Committed. ASCII-only on purpose (PS 5.1). |
| `build-installer.ps1`, `Install-GASFFaceScanner.ps1` | Build and run the source-free Windows laptop installer. |
| `requirements.txt`, `config.example.json` | Committed. |
| `config.json` | Your URL + key. **Gitignored** — never commit it. |
| `faces.db` | The biometric vectors. **Gitignored** — never commit it, never copy it off this machine. |
| `scan.log` | Run log from the task. Gitignored. |

Deleting somebody's face data is deleting their rows in `faces.db`. Deleting
everything is deleting the file; the next `--learn` rebuilds only from photos
volunteers have tagged.
