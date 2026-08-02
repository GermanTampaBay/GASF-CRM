#!/usr/bin/env python3
"""
GASF-CRM face scanner — runs on a private machine, never on the web host.

    python scan.py                 # scan whatever is waiting, then stop
    python scan.py --learn         # refresh the reference set first
    python scan.py --watch 900     # keep going, learning then scanning, every 15 min
    python scan.py --status        # what do I know, and what is waiting
    python scan.py --check         # is everything wired up? (needs the ML backend)
    python scan.py --selftest      # exercise the plumbing with no ML at all

WHY IT LIVES HERE AND NOT ON THE SERVER
---------------------------------------
Face embeddings are biometric data. Keeping them on a shared web host would
mean the club maintaining a biometric database of its own members — including
the children at Nikolaustag — on a machine it does not control, backed up
nightly to somebody else's cloud, one credential away from anyone.

So the vectors never leave this machine. They live in faces.db beside this
script. The server is told a photo id, a rectangle, a name and a confidence:
all things a volunteer could have typed. Deleting a person's biometric data is
deleting rows in a local SQLite file.

It also means nothing has to be opened on the home firewall. This script
POLLS OUT over HTTPS. There is no inbound port, no tunnel, no exposed service —
if this machine is off, the CRM simply gets no suggestions and every other part
of it carries on exactly as before.

WHAT A SUGGESTION IS
--------------------
A guess, shown to a volunteer as a chip they may click. The server stores it
apart from the real tags and no code path turns one into a name by itself.
This script cannot tag anybody. That is deliberate and it should stay true.

TWO BACKENDS, NEVER MIXED
-------------------------
Recognition can run on either engine:

  * insightface  — the production choice. Installs as a plain wheel (no
    compiler), including on Python 3.14; ArcFace embeddings, 512 dimensions,
    compared by cosine distance. This is what the home machine uses.
  * face_recognition — dlib, 128 dimensions, euclidean distance. Kept as an
    option for machines that already have it; dlib has no wheel for newer
    Pythons and needs a compiler, which is the whole reason insightface won.

Their vectors are NOT interchangeable — a dlib number and an ArcFace number
that happen to be close mean nothing to each other. So every reference face is
stored with the engine that produced it, and the scanner only ever compares a
face against references from the same engine. Switch engines and the new one
simply relearns; it never reads the other's vectors by mistake.

SETUP
-----
    pip install -r requirements.txt          # requests, numpy
    pip install insightface onnxruntime      # the production backend

    Then either set environment variables:
        set GASF_URL=https://germantampabay.com
        set GASF_FACE_KEY=gasf_face_xxxxxxxx
    or copy config.example.json to config.json and fill it in.

    python scan.py --check                    # confirm it all lines up

The key comes from wp-admin -> Email CRM -> Photos -> Face suggestions.
It is shown once. If you lose it, issue a new one.
"""

import argparse
import io
import json
import os
import sqlite3
import sys
import tempfile
import time
from pathlib import Path
from urllib.parse import urlsplit

try:
    import numpy as np
except ImportError:
    sys.exit("pip install numpy")

try:
    import requests
except ImportError:
    sys.exit("pip install requests")


HERE = Path(__file__).resolve().parent
DB_PATH = HERE / "faces.db"

# A browser-shaped User-Agent on purpose. The host (Bluehost) runs mod_security,
# which answers the default python-requests agent — and anything with "scanner"
# in it — with a 406 before WordPress ever sees the request. This UA gets
# through, and it is what --check must send too, or the doctor reports a healthy
# server as broken (or a broken one as fine).
USER_AGENT = "Mozilla/5.0 (compatible; GASF-CRM-FaceClient/1.1; +https://germantampabay.com)"

# How close two faces must be to count as the same person, measured as a
# distance where LOWER is more alike. The number lives on the backend because
# the two engines do not share a scale: dlib's euclidean 0.5 and insightface's
# cosine 0.5 are different cutoffs on different rulers. Both defaults are
# deliberately strict — a missed suggestion costs a volunteer one typed name,
# while a confident wrong one puts a member's name on a stranger's face in the
# club archive. Those are not symmetric mistakes and the threshold must not
# pretend they are. Override per machine in config.json -> "tolerance" once
# there are real club faces to tune against.
DEFAULT_TOLERANCE = {
    "insightface": 0.50,        # cosine distance (1 - similarity); sim >= 0.50
    "face_recognition": 0.50,   # euclidean; the library's own default is 0.60
}

# Below this many reference faces, a person is not offered at all. One photo of
# somebody is an accident waiting to happen — a bad angle becomes "the system
# thinks everyone is Hans".
MIN_REFERENCES = 3
MAX_SCAN_RETRIES = 3
QUARANTINE_FAILS = 3
RETRYABLE_HTTP = {408, 425, 429, 500, 502, 503, 504}
DETERMINISTIC_HTTP = {400, 401, 403, 404, 410, 415, 422}


# --------------------------------------------------------------------------- config


def load_config(required=True):
    """(url, key, cfg). With required=False, url/key may be blank — for --status
    and --selftest, which do not need to reach the server."""
    cfg = {}
    path = HERE / "config.json"
    if path.exists():
        cfg = json.loads(path.read_text(encoding="utf-8"))
    url = os.environ.get("GASF_URL", cfg.get("url", "")).rstrip("/")
    key = os.environ.get("GASF_FACE_KEY", cfg.get("key", ""))
    if required and (not url or not key):
        sys.exit(
            "Missing configuration.\n"
            "  Set GASF_URL and GASF_FACE_KEY, or create config.json next to this script.\n"
            "  The key comes from wp-admin -> Email CRM -> Photos -> Face suggestions."
        )
    return url, key, cfg


def cfg_engine(cfg):
    return os.environ.get("GASF_FACE_ENGINE", cfg.get("engine", "auto")).strip().lower() or "auto"


def cfg_tolerance(cfg, engine):
    raw = os.environ.get("GASF_FACE_TOLERANCE", cfg.get("tolerance", ""))
    if raw not in ("", None):
        try:
            return float(raw)
        except (TypeError, ValueError):
            sys.exit(f"tolerance must be a number, got {raw!r}")
    return DEFAULT_TOLERANCE.get(engine, 0.50)


class Api:
    """The CRM, reached the only way this machine talks to anything: outward."""

    def __init__(self, base, key):
        parts = urlsplit(base.rstrip("/"))
        self.origin = (parts.scheme + "://" + parts.netloc).rstrip("/")
        self.base = base + "/wp-json/gasf/v1/crm/photos/faces"
        self.s = requests.Session()
        self.s.headers["Authorization"] = "Bearer " + key
        self.s.headers["User-Agent"] = USER_AGENT
        self.s.headers["Accept"] = "application/json"

    def get(self, path, **params):
        r = self.s.get(self.base + path, params=params, timeout=60)
        if r.status_code == 403:
            sys.exit("The server refused the key. Issue a new one in wp-admin and update the config.")
        r.raise_for_status()
        return r.json()

    def post(self, path, payload):
        r = self.s.post(self.base + path, json=payload, timeout=120)
        if r.status_code == 403:
            sys.exit("The server refused the key.")
        r.raise_for_status()
        return r.json()

    def image(self, url):
        parts = urlsplit(url)
        if not parts.scheme or not parts.netloc:
            raise RuntimeError(f"refusing non-absolute image URL: {url!r}")
        origin = (parts.scheme + "://" + parts.netloc).rstrip("/")
        if origin != self.origin:
            raise RuntimeError(f"refusing cross-origin image URL: {origin}")
        r = self.s.get(url, timeout=120)
        r.raise_for_status()
        return r.content


# --------------------------------------------------------------------------- backends
#
# A backend turns image bytes into faces and measures how alike two faces are.
# Everything above it — the queue, the reference set, the confidence chip — is
# engine-agnostic and speaks in one shared vocabulary:
#
#   * a box in CSS order (top, right, bottom, left), the order face_recognition
#     already uses and the single-event template's rectangles expect;
#   * a vector, stored as float32 bytes and never compared across engines;
#   * a distance where LOWER means more alike, so one confidence formula fits.
#
# The heavy import lives inside build(); nothing at module scope pulls in ML, so
# --selftest and --status run on a machine that has neither engine installed.


class Backend:
    name = "abstract"
    dim = 0

    def embed(self, image_bytes):
        """[(box_css, vector_float32), ...] for every face found."""
        raise NotImplementedError

    def distances(self, matrix, vector):
        """Distance from `vector` to each row of `matrix`; lower is more alike."""
        raise NotImplementedError


class InsightFaceBackend(Backend):
    """ArcFace via insightface's buffalo_l pack. Embeddings are L2-normalised,
    so cosine similarity is a dot product and cosine distance is 1 minus it."""

    name = "insightface:buffalo_l"
    dim = 512

    def __init__(self):
        from insightface.app import FaceAnalysis  # heavy; imported on demand
        from PIL import Image
        self._Image = Image
        self._app = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
        # ctx_id=-1 is CPU. det_size is the detector's working resolution; 640
        # is insightface's own default and a fair speed/recall trade on a home PC.
        self._app.prepare(ctx_id=-1, det_size=(640, 640))

    def embed(self, image_bytes):
        # insightface expects an OpenCV-style BGR uint8 array. PIL gives us RGB;
        # reverse the channels and make it contiguous for the C++ detector.
        img = self._Image.open(io.BytesIO(image_bytes)).convert("RGB")
        bgr = np.ascontiguousarray(np.asarray(img)[:, :, ::-1])
        out = []
        for f in self._app.get(bgr):
            x1, y1, x2, y2 = (int(v) for v in f.bbox)
            box = (max(0, y1), max(0, x2), max(0, y2), max(0, x1))  # css order
            out.append((box, np.asarray(f.normed_embedding, dtype=np.float32)))
        return out

    def distances(self, matrix, vector):
        # Rows and vector are unit-length, so the dot product is cosine
        # similarity in [-1, 1]; 1 - it is a distance in [0, 2], lower alike.
        return 1.0 - (matrix @ vector)


class FaceRecognitionBackend(Backend):
    """dlib's 128-d encodings, compared by plain euclidean distance."""

    name = "face_recognition:dlib-hog"
    dim = 128

    def __init__(self):
        import face_recognition  # heavy; imported on demand
        self._fr = face_recognition

    def embed(self, image_bytes):
        img = self._fr.load_image_file(io.BytesIO(image_bytes))
        boxes = self._fr.face_locations(img, model="hog")  # already css order
        if not boxes:
            return []
        vectors = self._fr.face_encodings(img, boxes)
        return [(b, np.asarray(v, dtype=np.float32)) for b, v in zip(boxes, vectors)]

    def distances(self, matrix, vector):
        return np.linalg.norm(matrix - vector, axis=1)


# Which engines this file knows how to build, in preference order for "auto".
_BACKENDS = {
    "insightface": InsightFaceBackend,
    "face_recognition": FaceRecognitionBackend,
}
_AUTO_ORDER = ["insightface", "face_recognition"]


def available_engine(pref="auto"):
    """The engine name that would be used, or None — WITHOUT importing any ML.
    Lets --status and the doctor name the backend before paying to load it."""
    import importlib.util

    def installed(engine):
        mod = {"insightface": "insightface", "face_recognition": "face_recognition"}[engine]
        return importlib.util.find_spec(mod) is not None

    if pref == "auto":
        return next((e for e in _AUTO_ORDER if installed(e)), None)
    if pref in _BACKENDS:
        return pref if installed(pref) else None
    sys.exit(f"Unknown engine {pref!r}. Choose auto, insightface, or face_recognition.")


def build_backend(pref="auto"):
    """Construct the chosen backend, importing ML now. Exits with guidance if
    nothing usable is installed."""
    engine = available_engine(pref)
    if engine is None:
        if pref == "auto":
            sys.exit(
                "No recognition backend installed.\n"
                "  Install the production one:  pip install insightface onnxruntime\n"
                "  (or the dlib one, if you have it:  pip install face_recognition)"
            )
        sys.exit(f"Engine {pref!r} is selected but not installed. Try: pip install {pref}")
    return _BACKENDS[engine]()


# --------------------------------------------------------------------------- store


def db():
    conn = sqlite3.connect(DB_PATH)
    _migrate(conn)
    return conn


def _migrate(conn):
    """Create the schema, or add the engine column to a pre-1.1 faces.db.

    The engine column is what keeps two backends' vectors from ever being
    compared. An older database (from when there was only one engine) is
    stamped with the engine it must have been — dlib, the only one that
    existed then — so its references are not silently reinterpreted as ArcFace."""
    conn.execute(
        """CREATE TABLE IF NOT EXISTS refs (
               id INTEGER PRIMARY KEY,
               person TEXT NOT NULL,
               photo_id INTEGER NOT NULL,
               engine TEXT NOT NULL DEFAULT '',
               vector BLOB NOT NULL,
               UNIQUE(photo_id, engine)
           )"""
    )
    conn.execute("CREATE TABLE IF NOT EXISTS state (k TEXT PRIMARY KEY, v TEXT)")

    cols = {row[1] for row in conn.execute("PRAGMA table_info(refs)")}
    if "engine" not in cols:  # a database written before backends existed
        conn.execute("ALTER TABLE refs ADD COLUMN engine TEXT NOT NULL DEFAULT ''")
        conn.execute("UPDATE refs SET engine = 'face_recognition:dlib-hog' WHERE engine = ''")

    if not _has_unique_photo_engine(conn):
        conn.execute("DROP TABLE IF EXISTS refs_new")
        conn.execute(
            """CREATE TABLE refs_new (
                   id INTEGER PRIMARY KEY,
                   person TEXT NOT NULL,
                   photo_id INTEGER NOT NULL,
                   engine TEXT NOT NULL DEFAULT '',
                   vector BLOB NOT NULL,
                   UNIQUE(photo_id, engine)
               )"""
        )
        conn.execute(
            """INSERT INTO refs_new (id, person, photo_id, engine, vector)
               SELECT r.id, r.person, r.photo_id, r.engine, r.vector
               FROM refs r
               INNER JOIN (
                   SELECT photo_id, engine, MAX(id) AS keep_id
                   FROM refs
                   GROUP BY photo_id, engine
               ) k ON k.keep_id = r.id"""
        )
        conn.execute("DROP TABLE refs")
        conn.execute("ALTER TABLE refs_new RENAME TO refs")

    conn.execute("CREATE INDEX IF NOT EXISTS refs_person ON refs(engine, person)")
    conn.commit()


def _has_unique_photo_engine(conn):
    for _, index_name, is_unique, *_ in conn.execute("PRAGMA index_list(refs)"):
        if not is_unique:
            continue
        cols = [row[2] for row in conn.execute(f"PRAGMA index_info({index_name!r})")]
        if cols == ["photo_id", "engine"]:
            return True
    return False


def state_key(engine, base):
    """Watermarks are per engine: a fresh backend must relearn from photo 0, not
    inherit the other engine's 'already learned up to here'."""
    return f"{base}:{engine}"


def state_get(conn, k, default=""):
    row = conn.execute("SELECT v FROM state WHERE k = ?", (k,)).fetchone()
    return row[0] if row else default


def state_set(conn, k, v):
    conn.execute("INSERT INTO state (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", (k, str(v)))
    conn.commit()


def load_references(conn, engine):
    """person -> stacked vectors for this engine, for people with enough
    examples to trust. Vectors from other engines are invisible here."""
    vectors = {}
    for person, blob in conn.execute("SELECT person, vector FROM refs WHERE engine = ?", (engine,)):
        vectors.setdefault(person, []).append(np.frombuffer(blob, dtype=np.float32))
    return {p: np.vstack(vs) for p, vs in vectors.items() if len(vs) >= MIN_REFERENCES}


# --------------------------------------------------------------------------- identify


def confidence(distance, tolerance):
    """A readable rendering of distance, not a probability. The volunteer sees a
    percentage and it should move the way intuition expects: ~1 at a perfect
    match, ~0.5 right at the tolerance, and nothing past it is offered at all.
    Returned as a 0..1 float; the server rounds it to a whole percent on store."""
    return round(max(0.0, 1.0 - (distance / tolerance)) * 0.5 + 0.5, 3)


def identify(vector, references, backend, tolerance):
    """Closest person within tolerance, as (name, confidence) or (None, 0)."""
    best_name, best_dist = None, float("inf")
    for name, vs in references.items():
        d = float(np.min(backend.distances(vs, vector)))
        if d < best_dist:
            best_name, best_dist = name, d
    if best_name is None or best_dist > tolerance:
        return None, 0.0
    return best_name, confidence(best_dist, tolerance)


# --------------------------------------------------------------------------- learn


def learn(api, conn, backend, verbose=True):
    """
    Grow the reference set from photos volunteers have actually tagged.

    Only unambiguous pairs are learned: one face in the photo, one name on it.
    A crowd shot with six names cannot say which face is which, and guessing
    would poison the reference set with confident nonsense — the failure mode
    that makes a system like this worse than nothing.

    The watermark is per engine, so switching backends relearns from scratch
    into that engine's own vectors rather than trusting the other's homework.
    """
    wk_mod = state_key(backend.name, "learned_modified")
    wk_id = state_key(backend.name, "learned_id")
    since_mod = state_get(conn, wk_mod, "")
    since_id = int(state_get(conn, wk_id, "0") or 0)
    added = skipped = 0

    while True:
        params = {"limit": 100}
        if since_mod:
            params.update({"after": since_mod, "after_id": since_id})
        elif since_id > 0:
            params.update({"since": since_id})
        data = api.get("/confirmed", **params)
        photos = data.get("photos", [])
        if not photos:
            break

        for p in photos:
            photo_id = int(p["id"])
            modified = str(p.get("modified") or "")
            if modified:
                if modified > since_mod or (modified == since_mod and photo_id > since_id):
                    since_mod, since_id = modified, photo_id
            else:
                since_id = max(since_id, photo_id)

            people = [n for n in p.get("people", []) if n.strip()]
            if len(people) != 1:
                skipped += 1
                continue
            try:
                found = backend.embed(api.image(p["url"]))
            except Exception as e:  # a missing file must not stop the run
                if verbose:
                    print(f"  #{p['id']}: {e}")
                continue
            if len(found) != 1:
                skipped += 1
                continue

            _, vector = found[0]
            conn.execute(
                """INSERT INTO refs (person, photo_id, engine, vector)
                   VALUES (?, ?, ?, ?)
                   ON CONFLICT(photo_id, engine) DO UPDATE SET
                       person = excluded.person,
                       vector = excluded.vector""",
                (people[0].strip(), photo_id, backend.name, vector.astype(np.float32).tobytes()),
            )
            added += 1

        conn.commit()
        if since_mod:
            state_set(conn, wk_mod, since_mod)
        state_set(conn, wk_id, since_id)
        state_set(conn, state_key(backend.name, "learned_to"), since_id)

    if verbose:
        print(f"learned: {added} new reference face(s); {skipped} photo(s) too ambiguous to learn from")
    return added


# --------------------------------------------------------------------------- scan


def scan(api, conn, backend, tolerance, verbose=True):
    references = load_references(conn, backend.name)
    if verbose:
        print(f"reference set: {len(references)} person(s) with {MIN_REFERENCES}+ examples [{backend.name}]")

    total_seen = 0
    total_kept = 0

    while True:
        data = api.get("/queue")
        photos = data.get("photos", [])
        if not photos:
            if verbose and total_seen == 0:
                print("nothing waiting")
            if verbose and total_seen > 0:
                print(f"sent {total_seen} photo(s), {total_kept} suggestion(s) kept")
                print(f"{data.get('remaining', 0)} still waiting")
            return total_seen

        results = []
        for p in photos:
            photo_id = int(p["id"])
            found = None
            err = None
            for attempt in range(MAX_SCAN_RETRIES):
                try:
                    found = backend.embed(api.image(p["url"]))
                    err = None
                    break
                except Exception as e:
                    err = e
                    if not _is_retryable_error(e) or attempt == MAX_SCAN_RETRIES - 1:
                        break
                    wait = 2 ** attempt
                    if verbose:
                        print(f"  #{photo_id}: temporary fetch error, retrying in {wait}s")
                    time.sleep(wait)

            if err is not None or found is None:
                if _is_deterministic_error(err):
                    n = _bump_failure(conn, backend.name, photo_id)
                    if verbose:
                        print(f"  #{photo_id}: deterministic failure ({n}/{QUARANTINE_FAILS}) — {err}")
                    if n >= QUARANTINE_FAILS:
                        results.append({"id": photo_id, "found": 0, "faces": []})
                        _clear_failure(conn, backend.name, photo_id)
                        if verbose:
                            print(f"  #{photo_id}: quarantined after repeated deterministic failures")
                else:
                    if verbose:
                        print(f"  #{photo_id}: temporary failure left in queue — {err}")
                continue

            _clear_failure(conn, backend.name, photo_id)

            already = {n.strip().lower() for n in p.get("people", [])}
            faces = []
            for (top, right, bottom, left), vector in found:
                name, conf = identify(vector, references, backend, tolerance)
                if not name or name.lower() in already:
                    continue
                faces.append(
                    {
                        "name": name,
                        "confidence": conf,
                        "box": [int(left), int(top), int(right - left), int(bottom - top)],
                    }
                )

            results.append({"id": photo_id, "found": len(found), "faces": faces})
            if verbose:
                names = ", ".join(f["name"] for f in faces) or "no one recognised"
                print(f"  #{photo_id}: {len(found)} face(s) — {names}")

        if not results:
            if verbose:
                print("no scan results were safe to commit; leaving this batch in queue for retry")
            return total_seen

        out = api.post("/suggest", {"photos": results})
        total_seen += int(out.get("photos", 0))
        total_kept += int(out.get("suggestions", 0))


def _fail_key(engine, photo_id):
    return f"scan_fail:{engine}:{int(photo_id)}"


def _bump_failure(conn, engine, photo_id):
    k = _fail_key(engine, photo_id)
    now = int(state_get(conn, k, "0") or 0) + 1
    state_set(conn, k, now)
    return now


def _clear_failure(conn, engine, photo_id):
    conn.execute("DELETE FROM state WHERE k = ?", (_fail_key(engine, photo_id),))
    conn.commit()


def _is_retryable_error(err):
    if isinstance(err, (requests.Timeout, requests.ConnectionError)):
        return True
    if isinstance(err, requests.HTTPError):
        code = err.response.status_code if err.response is not None else 0
        return code in RETRYABLE_HTTP
    return False


def _is_deterministic_error(err):
    if isinstance(err, requests.HTTPError):
        code = err.response.status_code if err.response is not None else 0
        return code in DETERMINISTIC_HTTP
    msg = str(err or "").lower()
    return "cannot identify image file" in msg or "unsupported image" in msg


# --------------------------------------------------------------------------- status


def status(api, conn, cfg):
    engine = cfg_engine(cfg)
    resolved = available_engine(engine)
    print(f"engine: {engine}" + (f" -> {resolved}" if resolved else " -> none installed"))

    rows = conn.execute(
        "SELECT engine, person, COUNT(*) FROM refs GROUP BY engine, person ORDER BY engine, COUNT(*) DESC"
    ).fetchall()
    by_engine = {}
    for eng, person, n in rows:
        by_engine.setdefault(eng or "(unstamped)", []).append((person, n))

    if not by_engine:
        print("reference set: empty — run with --learn once photos are tagged")
    for eng, people in by_engine.items():
        total = sum(n for _, n in people)
        mark = " (active)" if resolved and eng == _resolved_name(resolved) else ""
        print(f"\nreference set [{eng}]{mark}: {len(people)} person(s), {total} face(s) total")
        for person, n in people[:20]:
            need = "" if n >= MIN_REFERENCES else f"  (needs {MIN_REFERENCES - n} more to be offered)"
            print(f"  {n:3d}  {person}{need}")
        if len(people) > 20:
            print(f"  ... and {len(people) - 20} more")
        print(f"  learned up to photo #{state_get(conn, state_key(eng, 'learned_to'), '0')}")

    if api is not None:
        try:
            print(f"\nwaiting to be scanned: {api.get('/queue', limit=1).get('remaining', 0)}")
        except Exception as e:
            print(f"\nwaiting to be scanned: (could not ask the server: {e})")
    print(f"\nvectors live in {DB_PATH} and nowhere else.")


def _resolved_name(engine):
    """The stored engine name for a resolvable engine, without loading ML."""
    return {"insightface": InsightFaceBackend.name, "face_recognition": FaceRecognitionBackend.name}[engine]


# --------------------------------------------------------------------------- doctor


def check(cfg):
    """Answer 'is this machine ready to scan?' one line at a time. Returns an
    exit code: 0 if every hard requirement passed."""
    ok = True

    def line(good, label, detail=""):
        nonlocal ok
        ok = ok and good
        print(f"  [{'PASS' if good else 'FAIL'}] {label}" + (f" — {detail}" if detail else ""))

    print("GASF face scanner — preflight\n")

    py = sys.version_info
    line(py >= (3, 8), "Python", f"{py.major}.{py.minor}.{py.micro}")
    line(True, "numpy", np.__version__)
    line(True, "requests", requests.__version__)

    engine_pref = cfg_engine(cfg)
    resolved = available_engine(engine_pref)
    line(resolved is not None, f"recognition backend (engine={engine_pref})",
         resolved or "none installed — pip install insightface onnxruntime")

    # Load it for real: a spec can exist yet fail to import or download models.
    backend = None
    if resolved is not None:
        try:
            t = time.time()
            backend = build_backend(engine_pref)
            line(True, "backend loads", f"{backend.name}, dim {backend.dim}, {time.time() - t:.1f}s")
        except SystemExit:
            raise
        except Exception as e:
            line(False, "backend loads", str(e))

    url, key, _ = load_config(required=False)
    line(bool(url), "config: site URL", url or "missing (GASF_URL / config.json)")
    line(bool(key), "config: scanner key", (key[:14] + "…") if key else "missing (GASF_FACE_KEY / config.json)")

    # Database is writable and this machine can round-trip through it.
    try:
        conn = db()
        state_set(conn, "check:probe", str(int(time.time())))
        got = state_get(conn, "check:probe")
        conn.execute("DELETE FROM state WHERE k = 'check:probe'")
        conn.commit()
        line(bool(got), "faces.db writable", str(DB_PATH))
    except Exception as e:
        line(False, "faces.db writable", str(e))

    # Reach the server with the key — the one check only the real deployment can pass.
    if url and key:
        try:
            r = requests.get(
                url + "/wp-json/gasf/v1/crm/photos/faces/queue",
                headers={
                    "Authorization": "Bearer " + key,
                    "User-Agent": USER_AGENT,
                    "Accept": "application/json",
                },
                params={"limit": 1}, timeout=30,
            )
            if r.status_code == 403:
                line(False, "server accepts the key", "403 — issue a new key in wp-admin and update the config")
            elif r.status_code == 200:
                line(True, "server accepts the key", f"{r.json().get('remaining', 0)} photo(s) waiting")
            elif r.status_code == 406:
                line(False, "server accepts the key",
                     "406 — the host's WAF (Bluehost mod_security) blocked the request before WordPress saw it; "
                     "a User-Agent problem, not the key")
            else:
                line(False, "server accepts the key", f"HTTP {r.status_code}")
        except Exception as e:
            line(False, "server reachable", str(e))
    else:
        line(False, "server accepts the key", "skipped — configure URL and key first")

    print("\n" + ("Ready." if ok else "Not ready — fix the FAIL lines above."))
    return 0 if ok else 1


# --------------------------------------------------------------------------- selftest


def selftest():
    """Exercise the plumbing that has nothing to do with ML — config parsing,
    the database, the identify math, box packing — with a stub backend, so it
    runs on any machine and can guard the logic in CI. Returns an exit code."""
    failures = []

    def check_that(cond, label):
        print(f"  [{'ok' if cond else 'XX'}] {label}")
        if not cond:
            failures.append(label)

    class StubBackend(Backend):
        """Euclidean, like dlib, so distances are easy to reason about by hand."""
        name = "stub:selftest"
        dim = 3

        def distances(self, matrix, vector):
            return np.linalg.norm(matrix - vector, axis=1)

    print("GASF face scanner — selftest (no ML)\n")

    # confidence: 1.0 at a perfect match, 0.5 at the tolerance, monotone between.
    check_that(confidence(0.0, 0.5) == 1.0, "confidence: perfect match reads 1.0")
    check_that(confidence(0.5, 0.5) == 0.5, "confidence: at tolerance reads 0.5")
    check_that(confidence(0.25, 0.5) > confidence(0.4, 0.5), "confidence: nearer beats farther")

    # identify: nearest within tolerance wins; nothing past it is offered.
    backend = StubBackend()
    refs = {
        "Hans": np.array([[0.0, 0.0, 0.0]], dtype=np.float32),
        "Greta": np.array([[10.0, 10.0, 10.0]], dtype=np.float32),
    }
    name, conf = identify(np.array([0.1, 0.0, 0.0], dtype=np.float32), refs, backend, 0.5)
    check_that(name == "Hans" and conf > 0.5, "identify: picks the nearer person")
    name, _ = identify(np.array([5.0, 5.0, 5.0], dtype=np.float32), refs, backend, 0.5)
    check_that(name is None, "identify: refuses when nobody is within tolerance")

    # box packing: css (top,right,bottom,left) -> [x, y, w, h] for the server.
    top, right, bottom, left = 20, 90, 60, 30
    box = [int(left), int(top), int(right - left), int(bottom - top)]
    check_that(box == [30, 20, 60, 40], "box: css corners pack to [x, y, w, h]")

    # database: schema, per-engine isolation, the MIN_REFERENCES floor, watermarks.
    prev = globals()["DB_PATH"]
    tmp = Path(tempfile.mkdtemp()) / "faces.db"
    globals()["DB_PATH"] = tmp
    try:
        conn = db()
        vec = np.array([1.0, 2.0, 3.0], dtype=np.float32).tobytes()
        for pid in (101, 102):  # two of Hans under engine A — below the floor of 3
            conn.execute("INSERT INTO refs (person, photo_id, engine, vector) VALUES (?,?,?,?)",
                         ("Hans", pid, "engineA", vec))
        conn.execute("INSERT INTO refs (person, photo_id, engine, vector) VALUES (?,?,?,?)",
                     ("Hans", 201, "engineB", vec))  # a different engine entirely
        conn.commit()
        check_that(load_references(conn, "engineA") == {}, "db: two examples is below the offer floor")
        conn.execute("INSERT INTO refs (person, photo_id, engine, vector) VALUES (?,?,?,?)",
                     ("Hans", 103, "engineA", vec))
        conn.commit()
        a = load_references(conn, "engineA")
        check_that(list(a) == ["Hans"] and a["Hans"].shape == (3, 3), "db: three examples clears the floor")
        check_that(load_references(conn, "engineB") == {}, "db: engines cannot see each other's vectors")
        state_set(conn, state_key("engineA", "learned_to"), 103)
        check_that(state_get(conn, state_key("engineB", "learned_to"), "0") == "0",
                   "db: learn watermarks are per engine")
        conn.close()

        # migration: a pre-engine database is stamped as dlib, not reinterpreted.
        legacy = Path(tempfile.mkdtemp()) / "faces.db"
        raw = sqlite3.connect(legacy)
        raw.execute("CREATE TABLE refs (id INTEGER PRIMARY KEY, person TEXT, photo_id INTEGER, vector BLOB)")
        raw.execute("INSERT INTO refs (person, photo_id, vector) VALUES ('Old', 1, ?)", (vec,))
        raw.commit()
        raw.close()
        globals()["DB_PATH"] = legacy
        conn = db()
        stamped = conn.execute("SELECT DISTINCT engine FROM refs").fetchone()[0]
        check_that(stamped == "face_recognition:dlib-hog", "db: legacy rows migrate to the dlib engine")
        conn.close()
    finally:
        globals()["DB_PATH"] = prev

    # engine resolution rejects nonsense before anything expensive happens.
    try:
        available_engine("banana")
        resolved_bad = True
    except SystemExit:
        resolved_bad = False
    check_that(not resolved_bad, "engine: an unknown engine name is refused")

    print("\n" + ("selftest passed." if not failures else f"selftest FAILED: {len(failures)} problem(s)."))
    return 0 if not failures else 1


# --------------------------------------------------------------------------- main


def main():
    # This prose has em-dashes and accented names in it, and it may be printing
    # into a Scheduled Task's redirected log under a legacy Windows code page
    # where those bytes do not exist. Force UTF-8 so a stray character can never
    # crash a headless run; replace anything truly unencodable rather than raise.
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError):
            pass

    ap = argparse.ArgumentParser(description="Suggest who is in the club's photos. Suggestions only — never tags.")
    ap.add_argument("--learn", action="store_true", help="refresh the reference set from confirmed tags first")
    ap.add_argument("--watch", type=int, metavar="SECONDS", help="keep running, pausing this long between passes")
    ap.add_argument("--status", action="store_true", help="show what is known and what is waiting")
    ap.add_argument("--check", action="store_true", help="preflight: backend, config, database, and server")
    ap.add_argument("--selftest", action="store_true", help="exercise the non-ML plumbing; needs no backend or server")
    ap.add_argument("--engine", choices=["auto", "insightface", "face_recognition"],
                    help="override the recognition backend for this run")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    # These three never touch the ML backend or the network unnecessarily.
    if args.selftest:
        sys.exit(selftest())

    _, _, cfg = load_config(required=False)
    if args.engine:
        cfg = {**cfg, "engine": args.engine}

    if args.check:
        sys.exit(check(cfg))

    if args.status:
        url, key, _ = load_config(required=False)
        api = Api(url, key) if url and key else None
        sys.exit(status(api, db(), cfg) or 0)

    # From here on we actually scan, so we need the config, the backend and the DB.
    url, key, cfg = load_config(required=True)
    if args.engine:
        cfg = {**cfg, "engine": args.engine}
    engine = cfg_engine(cfg)
    backend = build_backend(engine)
    tolerance = cfg_tolerance(cfg, engine)
    api = Api(url, key)
    conn = db()
    verbose = not args.quiet

    while True:
        # In watch mode learn every pass — it is incremental past the watermark,
        # so it costs nothing when no new photos have been tagged and it lets the
        # reference set grow as volunteers work. One-shot runs only learn when
        # asked, or when there is nothing to compare against yet.
        if args.learn or args.watch or not load_references(conn, backend.name):
            learn(api, conn, backend, verbose)
        scan(api, conn, backend, tolerance, verbose)
        if not args.watch:
            break
        if verbose:
            print(f"— sleeping {args.watch}s —")
        time.sleep(args.watch)


if __name__ == "__main__":
    main()
