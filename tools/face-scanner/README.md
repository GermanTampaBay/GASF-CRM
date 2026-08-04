# GASF-CRM face scanner

The home half of the club's face-suggestion feature. It runs on **one private
machine** — not the web host — polls the CRM over HTTPS for library photos,
works out who is in them, and posts back a name, a box, and a confidence.

**A suggestion is never a tag.** The server stores what this sends apart from
the real people tags; it only ever surfaces as a chip a volunteer may click,
and only that click writes a name. This script cannot tag anyone, read mail, or
approve a photo. See the header of [`scan.py`](scan.py) and the server side in
[`../../includes/email-crm/photos-faces.php`](../../includes/email-crm/photos-faces.php)
for the full design and why it is shaped this way.

**Why here and not on the server:** face embeddings are biometric data. Keeping
them off a shared host means there is no biometric database to breach or
subpoena — the vectors live only in `faces.db` on this machine, and the home
network opens no inbound port because this script only ever reaches *out*.

---

## Setup (Windows, Python 3.13/3.14)

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
  -CaptionModel "llava:7b"
```

That script installs Ollama (if missing), starts/verifies the local API, pulls
the vision model, installs scanner Python dependencies, writes `config.json`,
and runs `scan.py --check`.

The **scanner key** comes from **wp-admin → Email CRM → Photos → Face
suggestions → Issue a scanner key**. It is shown once; if you lose it, issue a
new one (the old one stops working immediately).

The first real run downloads the `buffalo_l` model pack (~280 MB) into
`~/.insightface/` and takes a few seconds to warm up; every run after is fast.

---

## Everyday use

| Command | What it does |
|---|---|
| `python scan.py` | Learn if the reference set is empty, then scan whatever is waiting, then stop. |
| `python scan.py --learn` | Refresh the reference set from newly tagged photos first (incremental — cheap). |
| `python scan.py --label` | Open a local browser UI to label face boxes with Next/Previous, plus name autocomplete from the library people list, and save explicit box→name mappings for learning. |
| `python scan.py --watch 900` | Keep going: learn, scan, sleep 15 min, repeat. |
| `python scan.py --status` | Who it knows, how many examples each, what is waiting. No ML or server needed. |
| `python scan.py --check` | Preflight: backend, config, database, and that the server accepts the key. |
| `python scan.py --selftest` | Exercise the non-ML plumbing. Needs no backend, no config, no network. |

`--engine insightface|face_recognition|auto` overrides the backend for one run.

### Optional: local AI summaries (captions)

If you run a local vision model in Ollama, the scanner can submit a short
caption suggestion with each scanned photo.

1. In `config.json`, set `"caption_model"` (for example `llava:7b`).  
2. Leave `caption_url` at `http://127.0.0.1:11434/api/generate` unless your
   Ollama endpoint is elsewhere.
3. Run `python scan.py --check` to confirm the model is configured.

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
relearns into its own vectors and never misreads the other's. `--status` shows
the counts per engine and marks the active one.

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
| `requirements.txt`, `config.example.json` | Committed. |
| `config.json` | Your URL + key. **Gitignored** — never commit it. |
| `faces.db` | The biometric vectors. **Gitignored** — never commit it, never copy it off this machine. |
| `scan.log` | Run log from the task. Gitignored. |

Deleting somebody's face data is deleting their rows in `faces.db`. Deleting
everything is deleting the file; the next `--learn` rebuilds only from photos
volunteers have tagged.
