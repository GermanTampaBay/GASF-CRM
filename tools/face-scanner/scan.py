#!/usr/bin/env python3
"""
GASF-CRM face scanner — runs on a private machine, never on the web host.

    python scan.py                 # scan whatever is waiting, then stop
    python scan.py --learn         # refresh the reference set first
    python scan.py --watch 900     # keep going, learning then scanning, every 15 min
    python scan.py --uploaded-after 2026-08-01   # only scan newer uploads
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
A guess sent with its confidence and face rectangle. Below the server's
administrator-set auto-accept threshold it stays separate from real tags and
is shown as a chip a volunteer may click. At or above that threshold the server
may accept it automatically. The local script never writes WordPress taxonomy
terms directly, and the biometric vectors never leave this machine.

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
import base64
from collections import OrderedDict
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import io
import json
import os
import secrets
import sqlite3
import subprocess
import sys
import sysconfig
import tempfile
import threading
import time
from urllib.parse import parse_qs, urlparse
import webbrowser
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
_DLL_DIR_HANDLES = []


def ensure_windows_cuda_dll_dirs():
    """On Windows, add pip-installed NVIDIA runtime DLL folders for this process.

    onnxruntime-gpu depends on CUDA/cuDNN DLLs that may live under
    site-packages\\nvidia\\*\\bin and not be on PATH. add_dll_directory keeps
    loading deterministic without requiring machine-wide PATH edits."""
    if os.name != "nt" or not hasattr(os, "add_dll_directory"):
        return
    pure = sysconfig.get_paths().get("purelib", "")
    if not pure:
        return
    base = Path(pure) / "nvidia"
    dirs = []
    cu13 = base / "cu13" / "bin" / "x86_64"
    cudnn = base / "cudnn" / "bin"

    # New NVIDIA wheel layout (CUDA 13): keep this pair together to avoid
    # mixing CUDA 13 provider DLLs with legacy CUDA 12 runtime DLL names.
    if cu13.is_dir():
        dirs.append(cu13)
        if cudnn.is_dir():
            dirs.append(cudnn)
    else:
        # Legacy wheel layout (CUDA 12 style folders).
        for leaf in ("cudnn", "cublas", "cuda_runtime", "cuda_nvrtc"):
            p = base / leaf / "bin"
            if p.is_dir():
                dirs.append(p)

    for p in dirs:
        try:
            # Keep handles alive for process lifetime; dropping them removes the dir.
            _DLL_DIR_HANDLES.append(os.add_dll_directory(str(p)))
        except OSError:
            pass

    # Some native loaders still rely on PATH-based resolution. Keep the same
    # order there so transitive CUDA/cuDNN DLL loads stay on one stack.
    current = os.environ.get("PATH", "")
    parts = [x for x in current.split(";") if x]
    for p in reversed([str(d) for d in dirs]):
        if p in parts:
            parts.remove(p)
        parts.insert(0, p)
    os.environ["PATH"] = ";".join(parts)


# --------------------------------------------------------------------------- config


def load_config(required=True):
    """(url, key, cfg). With required=False, url/key may be blank — for --status
    and --selftest, which do not need to reach the server."""
    cfg = {}
    path = HERE / "config.json"
    if path.exists():
        cfg = json.loads(path.read_text(encoding="utf-8-sig"))
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


def cfg_caption_model(cfg):
    return os.environ.get("GASF_FACE_CAPTION_MODEL", cfg.get("caption_model", "")).strip()


def cfg_caption_prompt(cfg):
    return (
        os.environ.get(
            "GASF_FACE_CAPTION_PROMPT",
            cfg.get(
                "caption_prompt",
                "Write one short, neutral description of this photo for a club archive. "
                "Describe visible people, activity, setting, and notable objects. "
                "Do not guess names or identities.",
            ),
        ).strip()
    )


def cfg_caption_url(cfg):
    raw = os.environ.get(
        "GASF_FACE_CAPTION_URL",
        cfg.get("caption_url", "http://127.0.0.1:11434/api/generate"),
    ).strip()
    parts = urlsplit(raw)
    if parts.scheme not in ("http", "https") or parts.hostname not in ("127.0.0.1", "localhost", "::1"):
        sys.exit(
            "caption_url must use a loopback Ollama endpoint "
            "(127.0.0.1, localhost, or ::1); refusing to send photos elsewhere"
        )
    return raw


def cfg_caption_timeout(cfg):
    raw = os.environ.get("GASF_FACE_CAPTION_TIMEOUT", cfg.get("caption_timeout", "120"))
    try:
        n = int(raw)
    except (TypeError, ValueError):
        sys.exit(f"caption_timeout must be an integer number of seconds, got {raw!r}")
    return max(15, min(300, n))


def cfg_caption_passes(cfg):
    raw = os.environ.get("GASF_FACE_CAPTION_PASSES", cfg.get("caption_passes", "2"))
    try:
        n = int(raw)
    except (TypeError, ValueError):
        sys.exit(f"caption_passes must be 1 or 2, got {raw!r}")
    if n not in (1, 2):
        sys.exit(f"caption_passes must be 1 or 2, got {raw!r}")
    return n


def cfg_caption_num_ctx(cfg):
    raw = os.environ.get("GASF_FACE_CAPTION_NUM_CTX", cfg.get("caption_num_ctx", "8192"))
    try:
        n = int(raw)
    except (TypeError, ValueError):
        sys.exit(f"caption_num_ctx must be an integer, got {raw!r}")
    return max(4096, min(32768, n))


def parse_ymd(raw, flag_name):
    raw = (raw or "").strip()
    if not raw:
        return ""
    try:
        time.strptime(raw, "%Y-%m-%d")
    except ValueError:
        sys.exit(f"{flag_name} must be YYYY-MM-DD, got {raw!r}")
    return raw


class Api:
    """The CRM, reached the only way this machine talks to anything: outward."""

    def __init__(self, base, key):
        parts = urlsplit(base.rstrip("/"))
        self.origin = (parts.scheme + "://" + parts.netloc).rstrip("/")
        self.base = base + "/wp-json/gasf/v1/crm/photos/faces"
        self.key = key
        self.s = requests.Session()
        self.s.headers["Authorization"] = "Bearer " + key
        self.s.headers["User-Agent"] = USER_AGENT
        self.s.headers["Accept"] = "application/json"
    def _needs_key_fallback(self, response):
        return response.status_code in (401, 403, 406)

    def get(self, path, **params):
        r = self.s.get(self.base + path, params=params, timeout=60)
        if self._needs_key_fallback(r):
            p2 = dict(params)
            p2["key"] = self.key
            r = self.s.get(self.base + path, params=p2, timeout=60)
        if r.status_code == 403:
            sys.exit("The server refused the key. Issue a new one in wp-admin and update the config.")
        r.raise_for_status()
        return r.json()

    def post(self, path, payload):
        r = self.s.post(self.base + path, json=payload, timeout=120)
        if self._needs_key_fallback(r):
            r = self.s.post(self.base + path, params={"key": self.key}, json=payload, timeout=120)
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
        attempts = 4
        for attempt in range(1, attempts + 1):
            try:
                r = self.s.get(url, timeout=120)
                if self._needs_key_fallback(r):
                    r = self.s.get(url, params={"key": self.key}, timeout=120)
                if r.status_code == 403:
                    sys.exit("The server refused the key.")
                if r.status_code in RETRYABLE_HTTP:
                    raise requests.HTTPError(
                        f"{r.status_code} {r.reason}",
                        response=r,
                    )
                r.raise_for_status()
                return r.content
            except requests.RequestException:
                if attempt >= attempts:
                    raise
                # Brief backoff for host throttling/transient 5xx.
                time.sleep(0.6 * attempt)


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

    @staticmethod
    def _is_cuda_runtime_error(err):
        msg = str(err or "").lower()
        return (
            "cudnn" in msg
            or "cudaexecutionprovider" in msg
            or "loadlibrary failed with error 126" in msg
            or "onnxruntimeerror" in msg and "cuda" in msg
        )

    def __init__(self):
        from insightface.app import FaceAnalysis  # heavy; imported on demand
        from PIL import Image
        import onnxruntime as ort

        self._Image = Image
        providers = [p for p in ("CUDAExecutionProvider", "CPUExecutionProvider") if p in ort.get_available_providers()]
        use_cuda = "CUDAExecutionProvider" in providers
        self._app = FaceAnalysis(name="buffalo_l", providers=providers or ["CPUExecutionProvider"])
        self._app.prepare(ctx_id=0 if use_cuda else -1, det_size=(640, 640))
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
    if engine != "insightface":
        return _BACKENDS[engine]()

    ensure_windows_cuda_dll_dirs()

    # On some Windows setups, forcing pip NVIDIA DLL directories breaks an
    # otherwise working CUDA stack; on others, those dirs are required. So try
    # native resolution first, then retry with pip DLL dirs only if CUDA load
    # errors indicate missing/mismatched runtime symbols.
    try:
        return InsightFaceBackend()
    except Exception as first:
        if not InsightFaceBackend._is_cuda_runtime_error(first):
            raise
        ensure_windows_cuda_dll_dirs()
        try:
            return InsightFaceBackend()
        except Exception:
            raise first


CAPTION_PIPELINE_VERSION = 2
CAPTION_SYSTEM = (
    "You write factual archival descriptions for the German-American Society of Tampa Bay. "
    "Treat the supplied catalogue context as trusted metadata, but treat every other claim as "
    "valid only when it is clearly visible in the image. Never infer an unknown person's name, "
    "relationship, age, ethnicity, nationality, intent, or private information. Do not invent "
    "an event, location, date, activity, object, or readable text. Prefer omission to guessing."
)
CAPTION_DRAFT_SCHEMA = {
    "type": "object",
    "properties": {
        "caption": {"type": "string"},
        "visible_details": {"type": "array", "items": {"type": "string"}},
        "visible_text": {"type": "array", "items": {"type": "string"}},
        "uncertainties": {"type": "array", "items": {"type": "string"}},
    },
    "required": ["caption", "visible_details", "visible_text", "uncertainties"],
}
CAPTION_FINAL_SCHEMA = {
    "type": "object",
    "properties": {"caption": {"type": "string"}},
    "required": ["caption"],
}


def caption_context(raw):
    """Keep only bounded, trusted catalogue fields in a stable prompt shape."""
    raw = raw if isinstance(raw, dict) else {}
    out = {}
    taken = " ".join(str(raw.get("taken_at") or "").split())[:40]
    if taken:
        out["date_taken"] = taken
    for source, target in (
        ("events", "events"),
        ("places", "places"),
        ("groups", "groups"),
        ("people", "confirmed_people"),
    ):
        values = []
        for value in raw.get(source, []) if isinstance(raw.get(source), list) else []:
            clean = " ".join(str(value or "").split())[:120]
            if clean and clean not in values:
                values.append(clean)
        if values:
            out[target] = values[:20]
    return out


def caption_scan_key(cfg):
    model = cfg_caption_model(cfg)
    if not model:
        return ""
    spec = {
        "pipeline": CAPTION_PIPELINE_VERSION,
        "model": model,
        "prompt": cfg_caption_prompt(cfg),
        "passes": cfg_caption_passes(cfg),
        "num_ctx": cfg_caption_num_ctx(cfg),
        "temperature": 0.2,
        "top_p": 0.8,
        "top_k": 20,
        "num_predict": 220,
    }
    raw = json.dumps(spec, ensure_ascii=True, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()[:32]


def _ollama_caption_call(image_b64, cfg, prompt, schema, temperature):
    payload = {
        "model": cfg_caption_model(cfg),
        "system": CAPTION_SYSTEM,
        "prompt": prompt,
        "stream": False,
        "think": False,
        "format": schema,
        "images": [image_b64],
        "keep_alive": "10m",
        "options": {
            "temperature": float(temperature),
            "top_p": 0.8,
            "top_k": 20,
            "seed": 42,
            "num_ctx": cfg_caption_num_ctx(cfg),
            "num_predict": 220,
        },
    }
    r = requests.post(cfg_caption_url(cfg), json=payload, timeout=cfg_caption_timeout(cfg))
    r.raise_for_status()
    out = r.json()
    raw = (out.get("response") or "").strip()
    if not raw:
        # Qwen3-VL's Ollama thinking parser may classify a schema-constrained
        # JSON object as "thinking". Accept it only if the whole field is valid
        # JSON; never turn free-form reasoning into archive text.
        candidate = (out.get("thinking") or "").strip()
        if candidate:
            try:
                structured = json.loads(candidate)
                if isinstance(structured, dict):
                    raw = candidate
            except json.JSONDecodeError:
                pass
    if not raw:
        raise ValueError(
            "Ollama returned no caption text "
            f"(done_reason={out.get('done_reason')!r}, "
            f"thinking_chars={len(out.get('thinking') or '')})"
        )
    parsed = json.loads(raw)
    if not isinstance(parsed, dict):
        raise ValueError("Ollama caption response was not a JSON object")
    return parsed


def _clean_caption(raw):
    text = " ".join(str(raw or "").split()).strip().strip('"')
    if len(text) < 8:
        raise ValueError("Ollama returned an empty or unusable caption")
    if len(text) > 420:
        text = text[:420].rsplit(" ", 1)[0].rstrip(" ,;:-")
    return text


def local_caption(image_bytes, cfg, metadata=None):
    """Draft and optionally verify a caption against the image and trusted metadata."""
    model = cfg_caption_model(cfg)
    if not model:
        return "", ""

    context = caption_context(metadata)
    context_json = json.dumps(context, ensure_ascii=False, sort_keys=True)
    focus = cfg_caption_prompt(cfg)
    draft_prompt = (
        f"{focus}\n\n"
        "Trusted catalogue context (may be empty):\n"
        f"{context_json}\n\n"
        "Write a useful archive caption of one or two sentences, ideally 20-55 words. "
        "Use the trusted event, place, and date when supplied. Confirmed people may be named "
        "collectively, but do not assign a specific action or position to a named person unless "
        "that association is explicitly supported by the context. Describe the main activity, "
        "setting, clothing, decorations, and notable objects only when clearly visible. Copy "
        "visible signage only when legible. Return the requested JSON evidence fields as well."
    )
    image_b64 = base64.b64encode(image_bytes).decode("ascii")
    draft = _ollama_caption_call(image_b64, cfg, draft_prompt, CAPTION_DRAFT_SCHEMA, 0.2)
    caption = _clean_caption(draft.get("caption"))

    if cfg_caption_passes(cfg) >= 2:
        verify_prompt = (
            "Verify this draft against the same image and trusted catalogue context. Remove or "
            "rewrite every detail that is not directly visible or explicitly supplied by the "
            "trusted context. Keep useful event, place, and date context. Do not add new facts. "
            "Return only a polished one- or two-sentence caption in the requested JSON shape.\n\n"
            f"Trusted context:\n{context_json}\n\n"
            f"Draft analysis:\n{json.dumps(draft, ensure_ascii=False, sort_keys=True)}"
        )
        verified = _ollama_caption_call(
            image_b64,
            cfg,
            verify_prompt,
            CAPTION_FINAL_SCHEMA,
            0.1,
        )
        caption = _clean_caption(verified.get("caption"))

    return caption, f"ollama:{model};pipeline={CAPTION_PIPELINE_VERSION};passes={cfg_caption_passes(cfg)}"


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
               face_key TEXT NOT NULL DEFAULT '0',
               vector BLOB NOT NULL,
               UNIQUE(photo_id, engine, face_key)
           )"""
    )
    conn.execute("CREATE TABLE IF NOT EXISTS state (k TEXT PRIMARY KEY, v TEXT)")

    cols = {row[1] for row in conn.execute("PRAGMA table_info(refs)")}
    if "engine" not in cols:  # a database written before backends existed
        conn.execute("ALTER TABLE refs ADD COLUMN engine TEXT NOT NULL DEFAULT ''")
        conn.execute("UPDATE refs SET engine = 'face_recognition:dlib-hog' WHERE engine = ''")
    if "face_key" not in cols:
        conn.execute("ALTER TABLE refs ADD COLUMN face_key TEXT NOT NULL DEFAULT '0'")
        conn.execute("UPDATE refs SET face_key = '0' WHERE face_key IS NULL OR face_key = ''")

    if not _has_unique_photo_engine_face(conn):
        conn.execute("DROP TABLE IF EXISTS refs_new")
        conn.execute(
            """CREATE TABLE refs_new (
                  id INTEGER PRIMARY KEY,
                  person TEXT NOT NULL,
                  photo_id INTEGER NOT NULL,
                  engine TEXT NOT NULL DEFAULT '',
                  face_key TEXT NOT NULL DEFAULT '0',
                  vector BLOB NOT NULL,
                  UNIQUE(photo_id, engine, face_key)
               )"""
        )
        conn.execute(
            """INSERT INTO refs_new (id, person, photo_id, engine, face_key, vector)
               SELECT r.id, r.person, r.photo_id, r.engine, COALESCE(NULLIF(r.face_key, ''), '0'), r.vector
               FROM refs r
               INNER JOIN (
                   SELECT photo_id, engine, COALESCE(NULLIF(face_key, ''), '0') AS face_key, MAX(id) AS keep_id
                   FROM refs
                   GROUP BY photo_id, engine, COALESCE(NULLIF(face_key, ''), '0')
               ) k ON k.keep_id = r.id"""
        )
        conn.execute("DROP TABLE refs")
        conn.execute("ALTER TABLE refs_new RENAME TO refs")

    conn.execute("CREATE INDEX IF NOT EXISTS refs_person ON refs(engine, person)")
    conn.commit()


def _has_unique_photo_engine_face(conn):
    for _, index_name, is_unique, *_ in conn.execute("PRAGMA index_list(refs)"):
        if not is_unique:
            continue
        cols = [row[2] for row in conn.execute(f"PRAGMA index_info({index_name!r})")]
        if cols == ["photo_id", "engine", "face_key"]:
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


def replace_photo_references(conn, photo_id, engine, references):
    """Make one photo's vectors exactly match its current confirmed truth."""
    rows = list(references)
    old_count = int(
        conn.execute(
            "SELECT COUNT(*) FROM refs WHERE photo_id = ? AND engine = ?",
            (photo_id, engine),
        ).fetchone()[0]
    )
    with conn:
        conn.execute(
            "DELETE FROM refs WHERE photo_id = ? AND engine = ?",
            (photo_id, engine),
        )
        conn.executemany(
            """INSERT INTO refs (person, photo_id, engine, face_key, vector)
               VALUES (?, ?, ?, ?, ?)""",
            [
                (person, photo_id, engine, face_key, vector.astype(np.float32).tobytes())
                for person, face_key, vector in rows
            ],
        )
    return old_count, len(rows)


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


def css_box_to_xywh(box_css):
    top, right, bottom, left = box_css
    return [int(left), int(top), int(right - left), int(bottom - top)]


def box_iou_xywh(a, b):
    ax1, ay1, aw, ah = a
    bx1, by1, bw, bh = b
    ax2, ay2 = ax1 + max(0, aw), ay1 + max(0, ah)
    bx2, by2 = bx1 + max(0, bw), by1 + max(0, bh)
    ix1, iy1 = max(ax1, bx1), max(ay1, by1)
    ix2, iy2 = min(ax2, bx2), min(ay2, by2)
    iw, ih = max(0, ix2 - ix1), max(0, iy2 - iy1)
    inter = float(iw * ih)
    if inter <= 0:
        return 0.0
    area_a = float(max(0, aw) * max(0, ah))
    area_b = float(max(0, bw) * max(0, bh))
    union = area_a + area_b - inter
    return inter / union if union > 0 else 0.0


# --------------------------------------------------------------------------- local labeling


def _open_preview_html(path_or_url):
    """Open preview in Edge on Windows; fall back to default browser elsewhere."""
    target = str(path_or_url)
    is_url = target.startswith("http://") or target.startswith("https://")
    p = None if is_url else Path(target).resolve()
    if os.name == "nt":
        candidates = [
            "msedge.exe",
            str(Path(os.environ.get("ProgramFiles(x86)", "")) / "Microsoft" / "Edge" / "Application" / "msedge.exe"),
            str(Path(os.environ.get("ProgramFiles", "")) / "Microsoft" / "Edge" / "Application" / "msedge.exe"),
            str(Path(os.environ.get("LOCALAPPDATA", "")) / "Microsoft" / "Edge" / "Application" / "msedge.exe"),
        ]
        for edge in candidates:
            if not edge or edge.endswith("\\.exe"):
                continue
            try:
                if edge.lower() == "msedge.exe" or Path(edge).is_file():
                    subprocess.Popen([edge, target if is_url else str(p)])
                    return
            except OSError:
                continue
    webbrowser.open_new_tab(target if is_url else p.as_uri())


def _mime_for_image(image_bytes):
    if image_bytes.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if image_bytes.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    if image_bytes[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    return "application/octet-stream"


def _thumb_data_uri(image_bytes, max_px=240, quality=70):
    """Small JPEG thumbnail data URI for gallery cards; empty on any failure."""
    try:
        from PIL import Image
        img = Image.open(io.BytesIO(image_bytes)).convert("RGB")
        img.thumbnail((max_px, max_px))
        out = io.BytesIO()
        img.save(out, format="JPEG", quality=quality, optimize=True)
        payload = base64.b64encode(out.getvalue()).decode("ascii")
        return f"data:image/jpeg;base64,{payload}"
    except Exception:
        return ""


class _HeartbeatTicker:
    """Periodic "still alive" line while a long loop is running."""

    def __init__(self, enabled, interval_s, line_fn):
        self.enabled = bool(enabled)
        self.interval = max(1, int(interval_s))
        self.line_fn = line_fn
        self._stop = threading.Event()
        self._thread = None

    def start(self):
        if not self.enabled:
            return

        def run():
            while not self._stop.wait(self.interval):
                try:
                    line = self.line_fn()
                except Exception:
                    line = ""
                if line:
                    print(line)

        self._thread = threading.Thread(target=run, daemon=True)
        self._thread.start()

    def stop(self):
        if self._thread is None:
            return
        self._stop.set()
        self._thread.join(timeout=1.0)


def _collect_label_items(api, conn, backend, tolerance, limit, uploaded_after="", uploaded_before=""):
    limit = max(1, min(1000, int(limit)))
    q = {"limit": limit}
    if uploaded_after:
        q["after"] = uploaded_after
    if uploaded_before:
        q["before"] = uploaded_before
    try:
        data = api.get("/label-queue", **q)
    except requests.HTTPError as e:
        code = e.response.status_code if e.response is not None else 0
        if code != 404:
            raise
        data = api.get("/confirmed", **q)

    photos = list(data.get("photos", []) or [])
    people_names = []
    try:
        pd = api.get("/people")
        raw_people = pd.get("people", []) if isinstance(pd, dict) else []
        for n in raw_people:
            if isinstance(n, dict):
                name = str(n.get("label") or n.get("value") or "").strip()
            else:
                name = str(n or "").strip()
            if name:
                people_names.append(name)
    except Exception:
        people_names = []
    refs = load_references(conn, backend.name)
    items = []
    total = len(photos)
    current = 0
    beat = _HeartbeatTicker(
        total > 0,
        15,
        lambda: f"label prep heartbeat: working on {current}/{total}, {len(items)} ready",
    )
    beat.start()
    try:
        for n, p in enumerate(photos, start=1):
            current = n
            photo_id = int(p["id"])
            people = [str(n).strip() for n in (p.get("people") or []) if str(n).strip()]
            try:
                image_bytes = api.image(p["url"])
                found = backend.embed(image_bytes)
            except Exception as e:
                print(f"#{photo_id}: skipped ({e})")
                continue
            if not found:
                continue

            boxes = [css_box_to_xywh(box_css) for (box_css, _) in found]
            hints = []
            for i, (_, vec) in enumerate(found):
                name, conf = identify(vec, refs, backend, tolerance)
                hints.append({"index": i, "name": name or "", "confidence": int(round(conf * 100)) if name else 0})

            # Pre-fill from previously saved labels on matching rectangles.
            prefill = {}
            labels = [l for l in (p.get("labels") or []) if isinstance(l, dict)]
            used = set()
            for lbl in labels:
                name = str(lbl.get("name") or "").strip()
                box = lbl.get("box") or []
                if not name or not isinstance(box, (list, tuple)) or len(box) != 4:
                    continue
                target = [int(box[0]), int(box[1]), int(box[2]), int(box[3])]
                best_i, best_iou = -1, 0.0
                for i, db in enumerate(boxes):
                    if i in used:
                        continue
                    iou = box_iou_xywh(target, db)
                    if iou > best_iou:
                        best_i, best_iou = i, iou
                if best_i >= 0 and best_iou >= 0.15:
                    used.add(best_i)
                    prefill[str(best_i)] = name

            labeled = len(prefill)
            # A one-face photo that already has exactly one person tag is already
            # learnable without box labels; keep label mode focused on ambiguous work.
            if labeled == 0 and len(boxes) == 1 and len(people) == 1:
                continue
            if labeled <= 0:
                status = "untagged"
            elif labeled < len(boxes):
                status = "partial"
            else:
                status = "full"

            items.append(
                {
                    "id": photo_id,
                    "url": p["url"],
                    "people": people,
                    "boxes": boxes,
                    "hints": hints,
                    "prefill": prefill,
                    "status": status,
                    # Lightweight preview only. Full image is loaded on demand per photo.
                    "thumb": _thumb_data_uri(image_bytes),
                }
            )
            people_names.extend(people)
            if n % 25 == 0 or n == total:
                print(f"label prep: {n}/{total} photos checked, {len(items)} ready")
    finally:
        beat.stop()
    dedup = []
    seen = set()
    for n in people_names:
        k = n.casefold()
        if k in seen:
            continue
        seen.add(k)
        dedup.append(n)
    return items, dedup


def _label_ui_html(label_flow=False, session_token=""):
    followup = "true" if label_flow else "false"
    html = """<!doctype html>
<html><head><meta charset="utf-8"><title>GASF Face Labeler</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}
:root{--face-border-width:1px;--face-border-color:rgba(96,165,250,.65);--face-fill-color:rgba(37,99,235,.04)}
.top{position:sticky;top:0;z-index:20;padding:12px 16px;border-bottom:1px solid #26324a;background:#0f172a;
display:flex;gap:12px;align-items:center;justify-content:space-between}
.topactions{display:flex;gap:12px;align-items:center}
.finish{padding:8px 14px;border:1px solid #2563eb;border-radius:6px;background:#2563eb;color:white;cursor:pointer;font-weight:600}
.main{padding:14px;overflow:auto}
.view{display:none}
.view.on{display:block}
.gallery{border:1px solid #2d3748;border-radius:10px;background:#111827;padding:12px}
.ghead{display:flex;gap:10px;justify-content:space-between;align-items:center;margin:0 0 10px 0}
.gallery h3{margin:0;font-size:15px}
.ghead select{background:#0b1220;color:#e5e7eb;border:1px solid #334155;border-radius:6px;padding:5px 8px}
.glist{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.gbtn{border:1px solid #334155;background:#0b1220;color:#cbd5e1;border-radius:8px;padding:6px;cursor:pointer;text-align:left}
.gbtn.on{border-color:#60a5fa;box-shadow:0 0 0 1px #60a5fa inset}
.gbtn img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:6px;display:block;background:#0b1220}
.gph{display:block;width:100%;aspect-ratio:1/1;border-radius:6px;background:#0b1220;border:1px dashed #334155}
.gmeta{display:block;font-size:12px;padding:6px 2px 2px 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.detail{display:grid;grid-template-columns:minmax(0,1fr) clamp(300px,34vw,430px);gap:14px;align-items:start}
.detail.on{display:grid}
.stage{position:relative;min-width:0;background:#111827;border:1px solid #2d3748;border-radius:8px;overflow:hidden;min-height:360px;text-align:center}
.frame{position:relative;display:inline-block;max-width:100%;line-height:0}
#photo{display:block;max-width:100%;max-height:calc(100vh - 130px);width:auto;height:auto;position:relative;z-index:1}
#ov{position:absolute;inset:0;pointer-events:none;z-index:2}
.fb{position:absolute;box-sizing:border-box;border:var(--face-border-width) solid var(--face-border-color);
background:var(--face-fill-color);border-radius:5px}
.fb span{position:absolute;left:0;top:0;padding:1px 4px;background:#1d4ed8;border:1px solid #93c5fd;
border-radius:0 0 5px 0;font-weight:800;font-size:12px;line-height:1.15;min-width:1.2em;text-align:center}
.fb-ext span{border-radius:999px;padding:1px 6px;font-size:12px;line-height:1.2;min-width:1.2em}
.fb-ext-right-up span{left:100%;top:0;transform:translate(6px,-100%)}
.fb-ext-right-down span{left:100%;top:0;transform:translate(6px,6px)}
.fb-ext-left-up span{left:auto;right:100%;top:0;transform:translate(-6px,-100%)}
.fb-ext-left-down span{left:auto;right:100%;top:0;transform:translate(-6px,6px)}
.side{border:1px solid #2d3748;border-radius:8px;padding:12px;background:#111827;
display:flex;flex-direction:column;max-height:calc(100vh - 130px)}
.muted{color:#94a3b8}
.people{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 12px}
.pchip{background:#1f2937;color:#e5e7eb;border:1px solid #374151;border-radius:999px;padding:3px 10px;font-size:12px}
.boxprefs{display:grid;grid-template-columns:auto minmax(90px,1fr) 48px;gap:5px 8px;align-items:center;
margin:0 0 10px;padding:8px;border:1px solid #26324a;border-radius:6px;background:#0b1220}
.boxprefs strong{grid-column:1/-1;font-size:12px}
.boxprefs label{font-size:12px;color:#cbd5e1}
.boxprefs input{width:100%}
.boxprefs output{font-size:12px;color:#94a3b8;text-align:right}
.rows{display:grid;gap:8px;flex:1 1 auto;min-height:0;overflow:auto}
.row{display:grid;grid-template-columns:64px 1fr auto;gap:6px;align-items:center}
.row label{font-size:12px;color:#cbd5e1}
.row input{background:#0b1220;color:#e5e7eb;border:1px solid #334155;border-radius:6px;padding:6px 8px}
.row button{background:#1d4ed8;color:white;border:0;border-radius:6px;padding:6px 8px;cursor:pointer}
.acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;padding-top:8px}
.acts button{padding:8px 12px;border:1px solid #334155;border-radius:6px;background:#0b1220;color:#e5e7eb;cursor:pointer}
.acts .pri{background:#2563eb;border-color:#2563eb;color:white}
.acts button:disabled,.finish:disabled{opacity:.55;cursor:wait}
.finishview{max-width:620px;margin:12vh auto 0;padding:28px;border:1px solid #334155;border-radius:10px;background:#111827;text-align:center}
.finishview h2{margin:0 0 10px}
.finishview button{margin-top:14px;padding:8px 14px;border:1px solid #334155;border-radius:6px;background:#0b1220;color:#e5e7eb;cursor:pointer}
.spinner{width:30px;height:30px;margin:18px auto;border:4px solid #334155;border-top-color:#60a5fa;border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
</style></head>
<body>
<div class="top">
  <div><strong id="title">Loading...</strong><div class="muted" id="sub"></div></div>
  <div class="topactions"><div id="stat" class="muted"></div><button id="finish" class="finish">Finish labeling</button></div>
</div>
<div class="main">
  <section class="view gallery on" id="galleryView">
    <div class="ghead"><h3>Photo gallery</h3><label class="muted">Show
      <select id="gfilter">
        <option value="all">All photos</option>
        <option value="partial">Partially tagged</option>
        <option value="untagged">Untagged</option>
      </select>
    </label></div>
    <div class="glist" id="glist"></div>
  </section>
  <section class="view detail" id="detailView">
    <div class="stage"><div class="frame" id="frame"><img id="photo" alt=""><div id="ov"></div></div></div>
    <div class="side">
      <div class="muted">People already on this photo</div><div class="people" id="people"></div>
        <datalist id="peopleListLocal"></datalist>
        <datalist id="peopleListGlobal"></datalist>
      <div class="boxprefs">
        <strong>Face box visibility</strong>
        <label for="boxWidth">Outline</label><input id="boxWidth" type="range" min="0" max="5" step="1" value="1"><output id="boxWidthValue">1 px</output>
        <label for="boxOpacity">Opacity</label><input id="boxOpacity" type="range" min="20" max="100" step="5" value="65"><output id="boxOpacityValue">65%</output>
      </div>
      <div class="rows" id="rows"></div>
      <div class="muted">Names save automatically when you move to another photo.</div>
      <div class="acts">
        <button id="back">Back</button>
        <button id="next">Next</button>
        <button id="save" class="pri">Save & Next</button>
        <button id="exit">Exit to gallery</button>
      </div>
    </div>
  </section>
  <section class="view finishview" id="finishView" aria-live="polite">
    <h2 id="finishTitle">Finishing labeling...</h2>
    <div class="spinner" id="finishSpinner"></div>
    <div id="finishMessage" class="muted">Saving any names on the current photo.</div>
    <button id="finishBack" type="button" hidden>Back to labeling</button>
  </section>
</div>
<script>
const labelFlow=__LABEL_FLOW__;
const sessionToken=__LABEL_TOKEN__;
let count=0, pos=0, current=null, activeGallery=[], allGallery=[], loadSeq=0, photoAbort=null;
let saving=false, finishing=false, dirty=false, finishPollFailures=0, finishReturnView='galleryView';
const nameSet = new Map();
async function j(url,opt){
  const next=Object.assign({},opt||{});
  const headers=new Headers(next.headers||{});
  headers.set('X-GASF-Label-Token',sessionToken);
  next.headers=headers;
  const r=await fetch(url,next);
  if(!r.ok){throw new Error(await r.text()||r.statusText);}
  return r.json();
}
function setText(id,t){document.getElementById(id).textContent=t;}
function esc(s){return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
function applyBoxVisibility(){
  const width=Number(document.getElementById('boxWidth').value||1);
  const opacity=Number(document.getElementById('boxOpacity').value||65);
  const alpha=Math.max(.2,Math.min(1,opacity/100));
  const root=document.documentElement.style;
  root.setProperty('--face-border-width',`${width}px`);
  root.setProperty('--face-border-color',`rgba(96,165,250,${alpha})`);
  root.setProperty('--face-fill-color',`rgba(37,99,235,${(alpha*.06).toFixed(3)})`);
  setText('boxWidthValue',`${width} px`);
  setText('boxOpacityValue',`${opacity}%`);
}
function showOnly(id){
  document.querySelectorAll('.main > .view').forEach(v=>v.classList.toggle('on',v.id===id));
}
function showGallery(){ showOnly('galleryView'); }
function showDetail(){ showOnly('detailView'); }
function showFinish(){ showOnly('finishView'); }
function gallerySub(){
  const f = (document.getElementById('gfilter')||{}).value || 'all';
  const nouns = {all:'photo', partial:'partially tagged photo', untagged:'untagged photo'};
  const noun = nouns[f] || 'photo';
  if(!count){ return `No ${noun}s in this batch.`; }
  return `Click a photo to open it (${count} ${noun}${count===1?'':'s'} in this view).`;
}
function foldName(s, expand){
  let v=(s||'').toLocaleLowerCase();
  if(expand){ v=v.replace(/ä/g,'ae').replace(/ö/g,'oe').replace(/ü/g,'ue').replace(/ß/g,'ss'); }
  if(v.normalize){ v=v.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
  return v.replace(/[^a-z0-9 ]/g, ' ').replace(/\\s+/g, ' ').trim();
}
function addName(n){
  const name=(n||'').trim();
  if(!name){return;}
  const k=foldName(name,true)||foldName(name,false)||name.toLocaleLowerCase();
  if(!nameSet.has(k)){ nameSet.set(k,name); }
}
function refreshNameList(){
  const dl=document.getElementById('peopleListGlobal');
  const vals=Array.from(nameSet.values()).sort((a,b)=>a.localeCompare(b));
  dl.innerHTML=vals.map(n=>`<option value="${esc(n)}"></option>`).join('');
}
function setupLocalNames(p){
  const local=(p.people||[]).map(n=>(n||'').trim()).filter(Boolean);
  const dlocal=document.getElementById('peopleListLocal');
  dlocal.innerHTML=local.map(n=>`<option value="${esc(n)}"></option>`).join('');
  return local;
}
function drawBoxes(p){
  const ov=document.getElementById('ov'); ov.innerHTML='';
  if(!p||!p.boxes||!p.boxes.length){return;}
  const img=document.getElementById('photo'), w=img.naturalWidth||1, h=img.naturalHeight||1;
  const ow=ov.clientWidth||img.clientWidth||1, oh=ov.clientHeight||img.clientHeight||1;
  const minW=Math.max(0.35, (10*100)/ow), minH=Math.max(0.35, (10*100)/oh);
  p.boxes.forEach((b,i)=>{
    const left=Math.max(0,Math.min(100,b[0]*100/w));
    const top=Math.max(0,Math.min(100,b[1]*100/h));
    const ww=Math.max(minW,Math.min(100-left,b[2]*100/w));
    const hh=Math.max(minH,Math.min(100-top,b[3]*100/h));
    const pxW=(ww*ow)/100, pxH=(hh*oh)/100;
    const tiny = pxW < 70 || pxH < 70;
    const side = (left + ww > 84) ? 'left' : 'right';
    const vert = top < 8 ? 'down' : 'up';
    const d=document.createElement('div');
    d.className = tiny ? `fb fb-ext fb-ext-${side}-${vert}` : 'fb';
    d.style.cssText=`left:${left}%;top:${top}%;width:${ww}%;height:${hh}%`;
    d.innerHTML=`<span>${i+1}</span>`; ov.appendChild(d);
  });
}
function drawCurrent(){
  const img=document.getElementById('photo');
  if(!current||!img||!img.complete||(img.naturalWidth||0)<1||(img.naturalHeight||0)<1){ return; }
  drawBoxes(current);
}
function render(p){
  current=p;
  dirty=false;
  showDetail();
  setText('title', `Photo #${p.id}`);
  setText('sub', `${p.boxes.length} face box(es)`);
  setText('stat', `${pos+1} / ${count}`);
  const img=document.getElementById('photo');
  img.onload=()=>drawCurrent(); img.src=p.image;
  if(img.complete && (img.naturalWidth||0)>0){ requestAnimationFrame(drawCurrent); }
  else { setTimeout(drawCurrent, 80); }
  const ppl=document.getElementById('people');
  ppl.innerHTML=(p.people||[]).map(n=>`<span class="pchip">${esc(n)}</span>`).join('');
  (p.people||[]).forEach(addName);
  const localNames = setupLocalNames(p);
  const localFoldA = localNames.map(n=>foldName(n,true));
  const localFoldB = localNames.map(n=>foldName(n,false));
  const rows=document.getElementById('rows'); rows.innerHTML='';
  (p.boxes||[]).forEach((b,i)=>{
    const hint=(p.hints||[]).find(h=>h.index===i) || {name:'',confidence:0};
    const val=(p.prefill && p.prefill[String(i)]) || '';
    const row=document.createElement('div'); row.className='row';
    const listId = localNames.length ? "peopleListLocal" : "peopleListGlobal";
    row.innerHTML=`<label>Face ${i+1}</label><input list="${listId}" data-i="${i}" value="${esc(val)}" placeholder="Name">${hint.name?`<button data-fill="${i}">Use ${esc(hint.name)} (${hint.confidence}%)</button>`:'<span></span>'}`;
    rows.appendChild(row);
    if(val){ addName(val); }
  });
  rows.querySelectorAll('button[data-fill]').forEach(b=>b.onclick=()=>{
    const i=b.getAttribute('data-fill');
    const hint=(p.hints||[]).find(h=>String(h.index)===String(i));
    const inp=rows.querySelector(`input[data-i="${i}"]`);
    if(inp && hint && hint.name){inp.value=hint.name; dirty=true; inp.focus();}
  });
  rows.querySelectorAll('input[data-i]').forEach(inp=>{
    inp.addEventListener('input', ()=>{
      dirty=true;
      if(!localNames.length){ inp.setAttribute('list','peopleListGlobal'); return; }
      const vA=foldName(inp.value||'', true);
      const vB=foldName(inp.value||'', false);
      if(!vA && !vB){ inp.setAttribute('list','peopleListLocal'); return; }
      const matchesLocal = localFoldA.some((n,i)=>
        n.startsWith(vA) || vA.startsWith(n) ||
        localFoldB[i].startsWith(vB) || vB.startsWith(localFoldB[i]));
      inp.setAttribute('list', matchesLocal ? 'peopleListLocal' : 'peopleListGlobal');
    });
  });
  document.getElementById('back').disabled = pos <= 0;
  document.getElementById('next').disabled = pos >= count - 1;
  document.querySelectorAll('#glist .gbtn').forEach((b,bi)=>b.classList.toggle('on', bi===pos));
  refreshNameList();
}
async function load(globalIndex){
  const seq = ++loadSeq;
  if(photoAbort){ photoAbort.abort(); }
  photoAbort = new AbortController();
  const r = await fetch(`/api/photo?i=${globalIndex}`, {
    signal:photoAbort.signal,
    headers:{'X-GASF-Label-Token':sessionToken}
  });
  if (seq !== loadSeq) { return; }
  if(!r.ok){ throw new Error(await r.text()||r.statusText); }
  const p = await r.json();
  if (seq !== loadSeq) { return; }
  render(p);
}
async function openByPos(newPos){
  if(finishing || newPos < 0 || newPos >= count || !activeGallery[newPos]){ return; }
  pos = newPos;
  setText('sub', 'Loading photo...');
  try{
    await load(activeGallery[pos].global_i);
  } catch (e){
    if(e && e.name !== 'AbortError'){
      setText('sub', e.message ? e.message : String(e));
    }
  }
}
function paintGallery(){
  const gl=document.getElementById('glist');
  if(!activeGallery.length){
    gl.innerHTML='<div class="muted">No photos in this filter.</div>';
    return;
  }
  gl.innerHTML=activeGallery.map((g,i)=>`<button class="gbtn" data-i="${i}" title="Photo #${g.id}">${
    g.thumb ? `<img src="${g.thumb}" alt="">` : `<span class="gph" aria-hidden="true"></span>`
  }<span class="gmeta">#${g.id}</span></button>`).join('');
  gl.querySelectorAll('.gbtn').forEach(b=>b.onclick=async()=>{
    await openByPos(parseInt(b.getAttribute('data-i'),10)||0);
  });
}
function applyFilter(){
  const f = (document.getElementById('gfilter')||{}).value || 'all';
  activeGallery = allGallery.filter(g => f === 'all' ? true : g.status === f);
  count = activeGallery.length;
  pos = 0;
  paintGallery();
  showGallery();
  setText('title','Photo gallery');
  setText('sub', gallerySub());
  setText('stat','');
}
async function init(){
  document.getElementById('boxWidth').addEventListener('input',applyBoxVisibility);
  document.getElementById('boxOpacity').addEventListener('input',applyBoxVisibility);
  applyBoxVisibility();
  const m=await j('/api/meta');
  allGallery = (m.gallery||[]);
  (m.people||[]).forEach(addName);
  const gf=document.getElementById('gfilter');
  if (gf) {
    gf.onchange = applyFilter;
    const has = s => allGallery.some(g => g.status === s);
    gf.value = has('untagged') ? 'untagged' : (has('partial') ? 'partial' : 'all');
  }
  refreshNameList();
  applyFilter();
  if(!count){ setText('title','Nothing to label'); return; }
}
function collectLabels(){
  if(!current){return [];}
  const labels=[];
  document.querySelectorAll('#rows input').forEach(inp=>{
    const name=inp.value.trim(); if(!name){return;}
    addName(name);
    const i=parseInt(inp.getAttribute('data-i'),10); labels.push({name, box: current.boxes[i]});
  });
  refreshNameList();
  return labels;
}
function updateGalleryStatus(photoId, labels){
  const status = !labels.length ? 'untagged' : (current && labels.length >= current.boxes.length ? 'full' : 'partial');
  const item = allGallery.find(g=>Number(g.id)===Number(photoId));
  if(item){ item.status=status; }
}
async function saveCurrentOnly(){
  if(!current || !dirty || saving){return 0;}
  const labels=collectLabels();
  saving=true;
  const saveBtn=document.getElementById('save');
  const finishBtn=document.getElementById('finish');
  saveBtn.disabled=true;
  finishBtn.disabled=true;
  saveBtn.textContent='Saving...';
  try{
    const out = await j('/api/save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({photo:current.id,labels})});
    updateGalleryStatus(current.id,labels);
    dirty=false;
    return Number((out && out.stored) || 0);
  } finally {
    saving=false;
    saveBtn.disabled=false;
    finishBtn.disabled=false;
    saveBtn.textContent='Save & Next';
  }
}
async function saveAndNext(){
  if(!current || saving){return;}
  try{
    await saveCurrentOnly();
    if(pos+1 < count){ await openByPos(pos + 1); }
    else { setText('sub','Saved. End of batch.'); }
  } catch(e){
    setText('sub', e && e.message ? e.message : String(e));
  }
}
async function saveAndOpen(newPos){
  if(saving || finishing){return;}
  try{
    await saveCurrentOnly();
    await openByPos(newPos);
  } catch(e){
    setText('sub',e && e.message ? e.message : String(e));
  }
}
async function pollFinish(){
  if(!finishing){return;}
  try{
    const s=await j('/api/finish-status');
    finishPollFailures=0;
    if(s.status==='done'){
      setText('finishTitle','Labeling finished');
      setText('finishMessage',labelFlow
        ? 'Your labels are saved. Learning and scanning are continuing in ScanGUI.'
        : 'Your labels are saved. You can close this tab.');
      document.getElementById('finishSpinner').hidden=true;
      document.getElementById('finish').disabled=true;
      return;
    }
    if(s.status==='error'){
      finishing=false;
      setText('finishTitle','Could not finish');
      setText('finishMessage',s.message||'The current labels could not be saved.');
      document.getElementById('finishSpinner').hidden=true;
      document.getElementById('finishBack').hidden=false;
      document.getElementById('finish').disabled=false;
      document.getElementById('finish').textContent='Finish labeling';
      return;
    }
    setText('finishMessage',s.message||'Saving any names on the current photo.');
    setTimeout(pollFinish,400);
  } catch(e){
    finishPollFailures += 1;
    if(finishPollFailures < 5){
      setText('finishMessage','Still waiting for ScanGUI to confirm the save...');
      setTimeout(pollFinish,800);
      return;
    }
    setText('finishTitle','Finish sent to ScanGUI');
    setText('finishMessage','This page could not confirm the final status. Check the ScanGUI output before closing this tab.');
    document.getElementById('finishSpinner').hidden=true;
  }
}
async function beginFinish(){
  if(finishing || saving){return;}
  finishing=true;
  finishPollFailures=0;
  finishReturnView=document.getElementById('detailView').classList.contains('on')?'detailView':'galleryView';
  if(photoAbort){photoAbort.abort();}
  const finishBtn=document.getElementById('finish');
  finishBtn.disabled=true;
  finishBtn.textContent='Finishing...';
  document.getElementById('finishBack').hidden=true;
  document.getElementById('finishSpinner').hidden=false;
  setText('finishTitle','Finishing labeling...');
  setText('finishMessage','Saving any names on the current photo.');
  showFinish();
  const save=dirty;
  const labels=save ? collectLabels() : [];
  try{
    await j('/api/finish',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({photo:current?current.id:0,labels,save})
    });
    pollFinish();
  } catch(e){
    finishing=false;
    setText('finishTitle','Could not finish');
    setText('finishMessage',e && e.message ? e.message : String(e));
    document.getElementById('finishSpinner').hidden=true;
    document.getElementById('finishBack').hidden=false;
    finishBtn.disabled=false;
    finishBtn.textContent='Finish labeling';
  }
}
document.getElementById('save').onclick=saveAndNext;
document.getElementById('back').onclick=async()=>{ await saveAndOpen(pos - 1); };
document.getElementById('next').onclick=async()=>{ await saveAndOpen(pos + 1); };
document.getElementById('exit').onclick=async()=>{
  if(saving || finishing){return;}
  try{
    await saveCurrentOnly();
    applyFilter();
  } catch(e){
    setText('sub',e && e.message ? e.message : String(e));
  }
};
document.getElementById('finish').onclick=beginFinish;
document.getElementById('finishBack').onclick=()=>{
  showOnly(finishReturnView);
  document.getElementById('finishBack').hidden=true;
};
window.addEventListener('resize', ()=>drawCurrent());
document.addEventListener('visibilitychange', ()=>{ if(!document.hidden){ setTimeout(drawCurrent, 60); } });
init().catch(e=>{ setText('title','Error'); setText('sub', e.message||String(e)); });
</script></body></html>"""
    return (
        html.replace("__LABEL_FLOW__", followup)
        .replace("__LABEL_TOKEN__", json.dumps(session_token))
    )


def local_label(
    api,
    conn,
    backend,
    tolerance,
    limit=500,
    uploaded_after="",
    uploaded_before="",
    label_flow=False,
):
    """Interactive local browser UI: tag faces and step next in one page."""
    items, people_names = _collect_label_items(
        api,
        conn,
        backend,
        tolerance,
        limit,
        uploaded_after,
        uploaded_before,
    )
    if not items:
        print("No confirmed photos with detectable faces are available for local labeling.")
        return 0

    state = {
        "items": items,
        "saved": 0,
        "done": threading.Event(),
        "people": people_names,
        "lock": threading.Lock(),
        "finish": {"status": "idle", "message": ""},
        "token": secrets.token_urlsafe(32),
        "image_cache": OrderedDict(),
        "finish_thread": None,
    }

    class LabelHandler(BaseHTTPRequestHandler):
        def _write(self, code, payload, ctype="application/json; charset=utf-8"):
            body = payload if isinstance(payload, (bytes, bytearray)) else payload.encode("utf-8")
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Cache-Control", "no-store")
            self.send_header("Referrer-Policy", "no-referrer")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("X-Frame-Options", "DENY")
            if ctype.startswith("text/html"):
                self.send_header(
                    "Content-Security-Policy",
                    "default-src 'self'; img-src 'self' data:; "
                    "style-src 'unsafe-inline'; script-src 'unsafe-inline'; "
                    "connect-src 'self'; frame-ancestors 'none'",
                )
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            try:
                self.wfile.write(body)
            except (BrokenPipeError, ConnectionResetError):
                # Browser tab closed or navigation interrupted mid-response.
                return

        def _authorized(self):
            supplied = self.headers.get("X-GASF-Label-Token", "")
            return bool(supplied) and secrets.compare_digest(supplied, state["token"])

        def do_GET(self):
            u = urlparse(self.path)
            if u.path == "/":
                supplied = (parse_qs(u.query or "").get("token") or [""])[0]
                if not supplied or not secrets.compare_digest(supplied, state["token"]):
                    return self._write(403, "Forbidden", "text/plain; charset=utf-8")
                return self._write(
                    200,
                    _label_ui_html(label_flow, state["token"]),
                    "text/html; charset=utf-8",
                )
            if not self._authorized():
                return self._write(403, json.dumps({"error": "Forbidden"}))
            if u.path == "/api/meta":
                gallery = [
                    {"id": it["id"], "thumb": it.get("thumb", ""), "status": it.get("status", "untagged"), "global_i": i}
                    for i, it in enumerate(state["items"])
                ]
                return self._write(200, json.dumps({
                    "count": len(state["items"]),
                    "saved": state["saved"],
                    "people": state["people"],
                    "gallery": gallery,
                }))
            if u.path == "/api/finish-status":
                with state["lock"]:
                    status = dict(state["finish"])
                    status["saved_total"] = state["saved"]
                return self._write(200, json.dumps(status))
            if u.path == "/api/photo":
                q = parse_qs(u.query or "")
                try:
                    i = int((q.get("i") or ["0"])[0] or 0)
                except (TypeError, ValueError):
                    return self._write(400, json.dumps({"error": "Invalid photo index"}))
                if i < 0 or i >= len(state["items"]):
                    return self._write(404, json.dumps({"error": "No such photo index"}))
                item = dict(state["items"][i])
                with state["lock"]:
                    image_bytes = state["image_cache"].get(i)
                    if image_bytes is not None:
                        state["image_cache"].move_to_end(i)
                if image_bytes is None:
                    try:
                        image_bytes = api.image(item["url"])
                    except (requests.RequestException, RuntimeError, ValueError, SystemExit) as e:
                        return self._write(502, json.dumps({"error": f"Could not load photo #{item.get('id')}: {e}"}))
                    with state["lock"]:
                        state["image_cache"][i] = image_bytes
                        state["image_cache"].move_to_end(i)
                        while len(state["image_cache"]) > 4:
                            state["image_cache"].popitem(last=False)
                mime = _mime_for_image(image_bytes)
                item["image"] = f"data:{mime};base64,{base64.b64encode(image_bytes).decode('ascii')}"
                return self._write(200, json.dumps(item))
            return self._write(404, json.dumps({"error": "Not found"}))

        def do_POST(self):
            u = urlparse(self.path)
            if not self._authorized():
                return self._write(403, json.dumps({"error": "Forbidden"}))
            try:
                n = int(self.headers.get("Content-Length", "0") or 0)
            except (TypeError, ValueError):
                return self._write(400, json.dumps({"error": "Invalid content length"}))
            if n > 1024 * 1024:
                return self._write(413, json.dumps({"error": "Request too large"}))
            raw = self.rfile.read(n) if n > 0 else b"{}"
            try:
                data = json.loads(raw.decode("utf-8") or "{}")
            except Exception:
                return self._write(400, json.dumps({"error": "Invalid JSON"}))

            if u.path == "/api/save":
                try:
                    photo = int(data.get("photo") or 0)
                except (TypeError, ValueError):
                    return self._write(400, json.dumps({"error": "Invalid photo id"}))
                labels = [l for l in (data.get("labels") or []) if isinstance(l, dict)]
                if photo < 1:
                    return self._write(400, json.dumps({"error": "Missing photo id"}))
                try:
                    out = api.post("/label", {"photo": photo, "labels": labels})
                except (requests.RequestException, RuntimeError, ValueError, SystemExit) as e:
                    return self._write(502, json.dumps({"error": f"Could not save labels: {e}"}))
                kept = int(out.get("stored") or 0)
                with state["lock"]:
                    state["saved"] += kept
                    saved_total = state["saved"]
                return self._write(200, json.dumps({"ok": True, "stored": kept, "saved_total": saved_total}))

            if u.path == "/api/finish":
                try:
                    photo = int(data.get("photo") or 0)
                except (TypeError, ValueError):
                    return self._write(400, json.dumps({"error": "Invalid photo id"}))
                labels = [l for l in (data.get("labels") or []) if isinstance(l, dict)]
                save = bool(data.get("save"))
                if save and photo < 1:
                    return self._write(400, json.dumps({"error": "Missing photo id"}))
                with state["lock"]:
                    if state["finish"]["status"] == "saving":
                        return self._write(409, json.dumps({"error": "Finish is already in progress"}))
                    state["finish"] = {
                        "status": "saving",
                        "message": "Saving the current photo to WordPress..." if save else "Closing the labeling session...",
                    }

                def finish():
                    try:
                        kept = 0
                        if save:
                            out = api.post("/label", {"photo": photo, "labels": labels})
                            kept = int(out.get("stored") or 0)
                        with state["lock"]:
                            state["saved"] += kept
                            state["finish"] = {
                                "status": "done",
                                "message": "Labels saved. Labeling is complete.",
                            }
                        # Leave the status endpoint alive long enough for the page to
                        # render success before the CLI advances to learn/scan.
                        if not state["done"].wait(2.0):
                            state["done"].set()
                    except (requests.RequestException, RuntimeError, ValueError, SystemExit) as e:
                        with state["lock"]:
                            state["finish"] = {
                                "status": "error",
                                "message": str(e) or e.__class__.__name__,
                            }

                finish_thread = threading.Thread(target=finish, daemon=False)
                with state["lock"]:
                    state["finish_thread"] = finish_thread
                finish_thread.start()
                return self._write(202, json.dumps({"ok": True, "status": "saving"}))

            return self._write(404, json.dumps({"error": "Not found"}))

        def log_message(self, format, *args):
            return

    server = ThreadingHTTPServer(("127.0.0.1", 0), LabelHandler)
    # A cancelled browser navigation may leave an upstream image request alive.
    # Those stale request threads must never keep Finish from closing the UI.
    server.daemon_threads = True
    server.block_on_close = False
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    label_url = f"http://127.0.0.1:{port}/?token={state['token']}"
    print(f"label UI: http://127.0.0.1:{port}/")
    _open_preview_html(label_url)

    try:
        while not state["done"].wait(0.25):
            pass
    except KeyboardInterrupt:
        pass
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2.0)
        with state["lock"]:
            finish_thread = state["finish_thread"]
        if finish_thread is not None and finish_thread.is_alive():
            print("Waiting for the current label save to finish...")
            finish_thread.join(timeout=125.0)

    return state["saved"]


# --------------------------------------------------------------------------- learn


def learn(api, conn, backend, verbose=True):
    """
    Grow the reference set from photos volunteers have actually tagged.

    Two learning paths are allowed:
      - one-face/one-name photos (the original unambiguous path), and
      - explicit face-box labels from the CRM editor (box + chosen name).

    Group photos without explicit face boxes are still skipped: guessing which
    name belongs to which face would poison the reference set with confident
    nonsense — the failure mode that makes a system like this worse than nothing.

    The watermark is per engine, so switching backends relearns from scratch
    into that engine's own vectors rather than trusting the other's homework.
    """
    wk_mod = state_key(backend.name, "learned_modified")
    wk_id = state_key(backend.name, "learned_id")
    since_mod = state_get(conn, wk_mod, "")
    since_id = int(state_get(conn, wk_id, "0") or 0)
    include_empty = bool(since_mod)
    added = removed = skipped = processed = 0
    learned_ids = set()
    beat = _HeartbeatTicker(
        verbose,
        15,
        lambda: (
            f"learn heartbeat: {processed} photo(s) checked, "
            f"{added} added, {skipped} skipped"
        ),
    )
    beat.start()
    try:
        while True:
            params = {"limit": 100}
            if include_empty:
                params["include_empty"] = 1
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

                try:
                    people = [n for n in p.get("people", []) if n.strip()]
                    labels = [l for l in (p.get("labels") or []) if isinstance(l, dict)]
                    if labels:
                        try:
                            found = backend.embed(api.image(p["url"]))
                        except Exception as e:  # a missing file must not stop the run
                            if verbose:
                                print(f"  #{p['id']}: {e}")
                            continue
                        if not found:
                            old_count, _ = replace_photo_references(
                                conn, photo_id, backend.name, []
                            )
                            removed += old_count
                            learned_ids.add(photo_id)
                            skipped += 1
                            continue

                        det_boxes = [css_box_to_xywh(b) for (b, _) in found]
                        used = set()
                        replacements = []
                        for li, lbl in enumerate(labels):
                            name = str(lbl.get("name") or "").strip()
                            box = lbl.get("box") or []
                            if not name or not isinstance(box, (list, tuple)) or len(box) != 4:
                                continue
                            target = [int(box[0]), int(box[1]), int(box[2]), int(box[3])]
                            best_i, best_iou = -1, 0.0
                            for j, db in enumerate(det_boxes):
                                if j in used:
                                    continue
                                iou = box_iou_xywh(target, db)
                                if iou > best_iou:
                                    best_i, best_iou = j, iou
                            if best_i < 0 or best_iou < 0.15:
                                continue
                            used.add(best_i)
                            _, vector = found[best_i]
                            face_key = f"b:{target[0]},{target[1]},{target[2]},{target[3]}:{li}"
                            replacements.append((name, face_key, vector))
                        old_count, new_count = replace_photo_references(
                            conn, photo_id, backend.name, replacements
                        )
                        removed += old_count
                        added += new_count
                        learned_ids.add(photo_id)
                        if new_count == 0:
                            skipped += 1
                        continue

                    if len(people) != 1:
                        old_count, _ = replace_photo_references(
                            conn, photo_id, backend.name, []
                        )
                        removed += old_count
                        learned_ids.add(photo_id)
                        skipped += 1
                        continue
                    try:
                        found = backend.embed(api.image(p["url"]))
                    except Exception as e:  # a missing file must not stop the run
                        if verbose:
                            print(f"  #{p['id']}: {e}")
                        continue
                    if len(found) != 1:
                        old_count, _ = replace_photo_references(
                            conn, photo_id, backend.name, []
                        )
                        removed += old_count
                        learned_ids.add(photo_id)
                        skipped += 1
                        continue

                    _, vector = found[0]
                    old_count, new_count = replace_photo_references(
                        conn,
                        photo_id,
                        backend.name,
                        [(people[0].strip(), "0", vector)],
                    )
                    removed += old_count
                    added += new_count
                    learned_ids.add(photo_id)
                finally:
                    processed += 1
                    if verbose and processed % 20 == 0:
                        print(
                            f"learn progress: {processed} photo(s) checked, "
                            f"{added} reference face(s) added, {skipped} skipped"
                        )

            conn.commit()
            if since_mod:
                state_set(conn, wk_mod, since_mod)
            state_set(conn, wk_id, since_id)
            state_set(conn, state_key(backend.name, "learned_to"), since_id)
    finally:
        beat.stop()

    if verbose:
        print(
            f"learned: {added} current reference face(s), {removed} prior row(s) reconciled; "
            f"{skipped} photo(s) too ambiguous to learn from"
        )
    if learned_ids:
        try:
            api.post("/learned", {"photos": sorted(learned_ids), "engine": backend.name})
        except Exception as e:
            if verbose:
                print(f"learned marker push skipped: {e}")
    return added


# --------------------------------------------------------------------------- scan


def scan(api, conn, backend, tolerance, cfg, verbose=True, uploaded_after="", uploaded_before=""):
    references = load_references(conn, backend.name)
    caption_key = caption_scan_key(cfg)
    if verbose:
        print(f"reference set: {len(references)} person(s) with {MIN_REFERENCES}+ examples [{backend.name}]")
        if caption_key:
            print(
                f"caption pipeline: {cfg_caption_model(cfg)}, "
                f"{cfg_caption_passes(cfg)} pass(es), key {caption_key[:8]}"
            )
        if uploaded_after or uploaded_before:
            print(
                "scan window: "
                f"{uploaded_after or 'start'} .. {uploaded_before or 'now'}"
            )

    total_seen = 0
    total_kept = 0
    total_captions = 0
    deferred_ids = set()

    while True:
        qp = {}
        if uploaded_after:
            qp["after"] = uploaded_after
        if uploaded_before:
            qp["before"] = uploaded_before
        if caption_key:
            qp["caption_key"] = caption_key
        if deferred_ids:
            qp["exclude"] = ",".join(str(n) for n in sorted(deferred_ids))
        data = api.get("/queue", **qp)
        caption_endpoint = bool(data.get("caption_endpoint"))
        photos = data.get("photos", [])
        if not photos:
            remaining = int(data.get("remaining", 0))
            if verbose and deferred_ids and remaining:
                print(f"{remaining} photo(s) remain pending after temporary failures")
            if verbose and total_seen == 0:
                print("nothing waiting")
            if verbose and total_seen > 0:
                print(
                    f"sent {total_seen} photo(s), {total_kept} face suggestion(s) kept, "
                    f"{total_captions} caption suggestion(s) stored"
                )
                print(f"{data.get('remaining', 0)} still waiting")
            return total_seen

        face_results = []
        caption_results = []
        batch_now = 0
        batch_total = len(photos)
        beat = _HeartbeatTicker(
            verbose and batch_total > 0,
            15,
            lambda: (
                f"scan heartbeat: working on {batch_now}/{batch_total} "
                f"in this batch, {total_seen} sent, {total_kept} kept"
            ),
        )
        beat.start()
        try:
            for idx, p in enumerate(photos, start=1):
                batch_now = idx
                photo_id = int(p["id"])
                needs_faces = bool(p.get("needs_faces", True)) if caption_endpoint else True
                needs_caption = bool(caption_key) and bool(p.get("needs_caption", True))
                found = None
                image_bytes = None
                err = None
                for attempt in range(MAX_SCAN_RETRIES):
                    try:
                        image_bytes = api.image(p["url"])
                        found = backend.embed(image_bytes) if needs_faces else []
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
                    if needs_faces and _is_deterministic_error(err):
                        n = _bump_failure(conn, backend.name, photo_id)
                        if verbose:
                            print(f"  #{photo_id}: deterministic failure ({n}/{QUARANTINE_FAILS}) — {err}")
                        if n >= QUARANTINE_FAILS:
                            face_results.append({"id": photo_id, "found": 0, "faces": []})
                            _clear_failure(conn, backend.name, photo_id)
                            if verbose:
                                print(f"  #{photo_id}: quarantined after repeated deterministic failures")
                    elif needs_caption and _is_deterministic_error(err):
                        n = _bump_caption_failure(conn, caption_key, photo_id)
                        if n >= QUARANTINE_FAILS:
                            caption_results.append({
                                "id": photo_id,
                                "caption_key": caption_key,
                                "caption_model": f"ollama:{cfg_caption_model(cfg)}",
                            })
                            _clear_caption_failure(conn, caption_key, photo_id)
                            if verbose:
                                print(f"  #{photo_id}: caption quarantined after repeated deterministic failures")
                        else:
                            deferred_ids.add(photo_id)
                            if verbose:
                                print(f"  #{photo_id}: deterministic caption failure ({n}/{QUARANTINE_FAILS}) — {err}")
                    else:
                        deferred_ids.add(photo_id)
                        if verbose:
                            print(f"  #{photo_id}: temporary failure left in queue — {err}")
                    continue

                _clear_failure(conn, backend.name, photo_id)

                face_item = {"id": photo_id, "faces_scanned": needs_faces}
                faces = []
                if needs_faces:
                    already = {n.strip().lower() for n in p.get("people", [])}
                    boxes = []
                    for (top, right, bottom, left), vector in found:
                        box = [int(left), int(top), int(right - left), int(bottom - top)]
                        boxes.append({"box": box})
                        name, conf = identify(vector, references, backend, tolerance)
                        if not name or name.lower() in already:
                            continue
                        faces.append(
                            {
                                "name": name,
                                "confidence": conf,
                                "box": box,
                            }
                        )
                    face_item.update({
                        "found": len(found),
                        "faces": faces,
                        "boxes": boxes,
                        "engine": backend.name,
                    })

                caption_done = False
                caption_item = None
                if needs_caption and image_bytes is not None:
                    try:
                        cap, model = local_caption(
                            image_bytes,
                            cfg,
                            p.get("caption_context"),
                        )
                        if cap:
                            caption_item = {
                                "id": photo_id,
                                "caption": cap,
                                "caption_model": model,
                                "caption_key": caption_key,
                            }
                            _clear_caption_failure(conn, caption_key, photo_id)
                            caption_done = True
                    except Exception as e:
                        deterministic = _is_deterministic_error(e) or isinstance(e, (ValueError, json.JSONDecodeError))
                        if deterministic:
                            n = _bump_caption_failure(conn, caption_key, photo_id)
                            if n >= QUARANTINE_FAILS:
                                caption_item = {
                                    "id": photo_id,
                                    "caption_key": caption_key,
                                    "caption_model": f"ollama:{cfg_caption_model(cfg)}",
                                }
                                _clear_caption_failure(conn, caption_key, photo_id)
                                caption_done = True
                                if verbose:
                                    print(f"  #{photo_id}: caption quarantined after {n} deterministic failures")
                            else:
                                deferred_ids.add(photo_id)
                                if verbose:
                                    print(f"  #{photo_id}: deterministic caption failure ({n}/{QUARANTINE_FAILS}) — {e}")
                        else:
                            deferred_ids.add(photo_id)
                            if verbose:
                                print(f"  #{photo_id}: caption left pending — {e}")

                if not needs_faces and not caption_done:
                    continue

                if needs_faces:
                    if caption_item is not None and not caption_endpoint:
                        face_item.update(caption_item)
                    face_results.append(face_item)
                if caption_item is not None and caption_endpoint:
                    caption_results.append(caption_item)
                if verbose:
                    parts = []
                    if needs_faces:
                        names = ", ".join(f["name"] for f in faces) or "no one recognised"
                        parts.append(f"{len(found)} face(s) — {names}")
                    if caption_done:
                        parts.append("caption drafted and verified")
                    print(f"  #{photo_id}: " + "; ".join(parts))
        finally:
            beat.stop()

        if not face_results and not caption_results:
            if verbose:
                print("no scan results were safe to commit; leaving this batch in queue for retry")
            return total_seen

        processed = set()
        fallback_seen = 0
        if face_results:
            out = api.post("/suggest", {"photos": face_results})
            acknowledged = out.get("processed_ids")
            if isinstance(acknowledged, list):
                processed.update(int(photo_id) for photo_id in acknowledged)
            else:
                fallback_seen += int(out.get("photos", 0))
            deferred_ids.update(int(photo_id) for photo_id in (out.get("busy_ids") or []))
            total_kept += int(out.get("suggestions", 0))
            if not caption_endpoint:
                total_captions += int(out.get("captions", 0))
        if caption_results:
            out = api.post("/caption", {"photos": caption_results})
            acknowledged = out.get("processed_ids")
            if isinstance(acknowledged, list):
                processed.update(int(photo_id) for photo_id in acknowledged)
            else:
                fallback_seen += int(out.get("photos", 0))
            deferred_ids.update(int(photo_id) for photo_id in (out.get("busy_ids") or []))
            total_captions += int(out.get("captions", 0))
        total_seen += len(processed) + fallback_seen
        if len(deferred_ids) >= 100:
            if verbose:
                print("100 temporary failures deferred; stopping this pass")
            return total_seen


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


def _caption_fail_key(caption_key, photo_id):
    return f"caption_fail:{caption_key}:{int(photo_id)}"


def _bump_caption_failure(conn, caption_key, photo_id):
    key = _caption_fail_key(caption_key, photo_id)
    count = int(state_get(conn, key, "0") or 0) + 1
    state_set(conn, key, count)
    return count


def _clear_caption_failure(conn, caption_key, photo_id):
    conn.execute(
        "DELETE FROM state WHERE k = ?",
        (_caption_fail_key(caption_key, photo_id),),
    )
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
    cap_model = cfg_caption_model(cfg)
    if cap_model:
        print(f"local caption model: {cap_model} ({cfg_caption_url(cfg)})")
    else:
        print("local caption model: off")

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
            queue_args = {"limit": 1}
            key = caption_scan_key(cfg)
            if key:
                queue_args["caption_key"] = key
            print(f"\nwaiting for face/caption work: {api.get('/queue', **queue_args).get('remaining', 0)}")
        except Exception as e:
            print(f"\nwaiting for face/caption work: (could not ask the server: {e})")
        try:
            m = api.get("/metrics", sample=25)
            states = m.get("states", {}) if isinstance(m, dict) else {}
            if states:
                print("\nserver face pipeline:")
                for k in sorted(states.keys()):
                    print(f"  {k}: {int(states[k])}")
                print(f"  eligible: {int(m.get('eligible', 0))}")
                print(f"  learned:  {int(m.get('learned', 0))}")
        except Exception as e:
            print(f"\nserver face pipeline: (could not ask the server: {e})")
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

    cap_model = cfg_caption_model(cfg)
    if cap_model:
        try:
            parts = urlsplit(cfg_caption_url(cfg))
            tags_url = f"{parts.scheme}://{parts.netloc}/api/tags"
            tags = requests.get(tags_url, timeout=10)
            tags.raise_for_status()
            installed = {
                str(m.get("name") or "")
                for m in (tags.json().get("models") or [])
                if isinstance(m, dict)
            }
            present = cap_model in installed or (
                ":" not in cap_model and f"{cap_model}:latest" in installed
            )
            line(
                present,
                "caption model available",
                cap_model if present else f"{cap_model} is not installed in Ollama",
            )
            line(
                True,
                "caption pipeline",
                f"{cfg_caption_passes(cfg)} pass(es), context {cfg_caption_num_ctx(cfg)}, key {caption_scan_key(cfg)[:8]}",
            )
        except (requests.RequestException, ValueError) as e:
            line(False, "caption model available", f"Ollama is not reachable: {e}")
    else:
        line(True, "caption model configured", "off (set caption_model to enable local summaries)")

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
    line(bool(key), "config: scanner key", "configured" if key else "missing (GASF_FACE_KEY / config.json)")

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
            queue_args = {"limit": 1}
            cap_key = caption_scan_key(cfg)
            if cap_key:
                queue_args["caption_key"] = cap_key
            waiting = Api(url, key).get("/queue", **queue_args).get("remaining", 0)
            line(True, "server accepts the key", f"{waiting} photo(s) waiting")
        except requests.HTTPError as e:
            code = e.response.status_code if e.response is not None else 0
            line(False, "server accepts the key", f"HTTP {code}")
        except SystemExit as e:
            line(False, "server accepts the key", str(e))
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

        def embed(self, image_bytes):
            return [
                (
                    (10, 30, 30, 10),
                    np.array([1.0, 2.0, 3.0], dtype=np.float32),
                )
            ]

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
        check_that(
            _bump_caption_failure(conn, "pipeline-a", 301) == 1
            and _bump_caption_failure(conn, "pipeline-a", 301) == 2
            and _bump_caption_failure(conn, "pipeline-b", 301) == 1,
            "db: caption failure counters are isolated by pipeline",
        )
        _clear_caption_failure(conn, "pipeline-a", 301)
        check_that(
            state_get(conn, _caption_fail_key("pipeline-a", 301), "0") == "0",
            "db: successful captions clear their failure counter",
        )

        class LearnApi:
            def __init__(self, photo):
                self.photo = photo
                self.served = False
                self.get_params = []

            def get(self, path, **params):
                if path != "/confirmed":
                    raise RuntimeError(f"unexpected learn path {path}")
                self.get_params.append(params)
                if self.served:
                    return {"photos": []}
                self.served = True
                return {"photos": [self.photo]}

            def image(self, _url):
                return b"face"

            def post(self, path, _payload):
                if path != "/learned":
                    raise RuntimeError(f"unexpected learn path {path}")
                return {"ok": True}

        first = LearnApi({
            "id": 501,
            "modified": "2026-08-08 01:00:00",
            "url": "selftest://501",
            "people": [],
            "labels": [{"name": "Anna", "box": [10, 10, 20, 20]}],
        })
        learn(first, conn, backend, verbose=False)
        corrected = LearnApi({
            "id": 501,
            "modified": "2026-08-08 01:00:01",
            "url": "selftest://501",
            "people": [],
            "labels": [{"name": "Berta", "box": [10, 10, 20, 20]}],
        })
        learn(corrected, conn, backend, verbose=False)
        rows = conn.execute(
            "SELECT person FROM refs WHERE photo_id = 501 AND engine = ?",
            (backend.name,),
        ).fetchall()
        check_that(
            rows == [("Berta",)] and corrected.get_params[0].get("include_empty") == 1,
            "learn: correcting a face replaces its old reference and requests removals",
        )
        removed = LearnApi({
            "id": 501,
            "modified": "2026-08-08 01:00:02",
            "url": "selftest://501",
            "people": [],
            "labels": [],
        })
        learn(removed, conn, backend, verbose=False)
        left = conn.execute(
            "SELECT COUNT(*) FROM refs WHERE photo_id = 501 AND engine = ?",
            (backend.name,),
        ).fetchone()[0]
        check_that(left == 0, "learn: removing confirmed truth deletes the photo's stale references")
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

    # Caption pipeline: trusted context is bounded and configuration changes
    # produce a new queue key without depending on face-scan watermarks.
    cap_cfg = {
        "caption_model": "vision:test",
        "caption_prompt": "Describe the archive photo.",
        "caption_passes": 2,
        "caption_num_ctx": 8192,
    }
    clean_context = caption_context({
        "taken_at": " 2026-12-06 14:30:00 ",
        "events": ["Nikolaustag", "Nikolaustag"],
        "places": ["German-American Society"],
        "people": ["Anna"],
        "ignored": ["not trusted"],
    })
    check_that(
        clean_context == {
            "date_taken": "2026-12-06 14:30:00",
            "events": ["Nikolaustag"],
            "places": ["German-American Society"],
            "confirmed_people": ["Anna"],
        },
        "caption: trusted metadata is normalized and allow-listed",
    )
    cap_key = caption_scan_key(cap_cfg)
    changed_key = caption_scan_key({**cap_cfg, "caption_passes": 1})
    check_that(
        len(cap_key) == 32 and cap_key != changed_key,
        "caption: pipeline key changes with generation settings",
    )
    try:
        cfg_caption_url({"caption_url": "https://example.com/api/generate"})
        remote_caption_allowed = True
    except SystemExit:
        remote_caption_allowed = False
    check_that(
        not remote_caption_allowed,
        "caption: remote model endpoints are refused",
    )
    original_post = requests.post
    caption_calls = []
    jsonlib = json

    class StubCaptionResponse:
        def __init__(self, payload):
            self.payload = payload

        def raise_for_status(self):
            return None

        def json(self):
            return self.payload

    def stub_caption_post(url, json=None, timeout=None):
        caption_calls.append({"url": url, "json": json, "timeout": timeout})
        if len(caption_calls) == 1:
            response = {
                "caption": "Guests gather beneath holiday decorations.",
                "visible_details": ["holiday decorations", "group of guests"],
                "visible_text": [],
                "uncertainties": [],
            }
        else:
            response = {
                "caption": "Guests gather for Nikolaustag at the German-American Society."
            }
        return StubCaptionResponse({"response": jsonlib.dumps(response)})

    try:
        requests.post = stub_caption_post
        cap, provenance = local_caption(
            b"image",
            cap_cfg,
            {
                "taken_at": "2026-12-06",
                "events": ["Nikolaustag"],
                "places": ["German-American Society"],
            },
        )
        check_that(
            cap == "Guests gather for Nikolaustag at the German-American Society."
            and len(caption_calls) == 2,
            "caption: two-pass draft and verification returns final text",
        )
        check_that(
            "Nikolaustag" in caption_calls[0]["json"]["prompt"]
            and caption_calls[0]["json"]["format"] == CAPTION_DRAFT_SCHEMA
            and "pipeline=2" in provenance,
            "caption: trusted metadata and structured schema reach Ollama",
        )
    finally:
        requests.post = original_post

    # Local labeler lifecycle: token guard, asynchronous save, and clean finish.
    original_collect = globals()["_collect_label_items"]
    original_open = globals()["_open_preview_html"]
    opened = {}
    opened_event = threading.Event()
    label_result = {}
    label_posts = []

    class StubApi:
        def post(self, path, payload):
            if path != "/label":
                raise RuntimeError(f"unexpected stub path {path}")
            label_posts.append(payload)
            return {"stored": len(payload.get("labels") or [])}

    def stub_collect(*args, **kwargs):
        return (
            [{
                "id": 7,
                "url": "https://example.invalid/photo.jpg",
                "people": [],
                "boxes": [[10, 10, 20, 20]],
                "hints": [],
                "prefill": {},
                "status": "untagged",
                "thumb": "",
            }],
            ["Anna"],
        )

    def stub_open(url):
        opened["url"] = url
        opened_event.set()

    label_thread = None
    try:
        globals()["_collect_label_items"] = stub_collect
        globals()["_open_preview_html"] = stub_open

        def run_labeler():
            label_result["saved"] = local_label(
                StubApi(),
                None,
                None,
                0.5,
                label_flow=True,
            )

        label_thread = threading.Thread(target=run_labeler, daemon=True)
        label_thread.start()
        ready = opened_event.wait(3.0)
        check_that(ready, "label UI: local server starts")
        if ready:
            label_url = opened["url"]
            token = (parse_qs(urlparse(label_url).query).get("token") or [""])[0]
            base = label_url.split("/?token=", 1)[0]
            denied = requests.get(base + "/api/meta", timeout=3)
            check_that(denied.status_code == 403, "label UI: API rejects requests without its session token")
            headers = {"X-GASF-Label-Token": token}
            page = requests.get(label_url, timeout=3)
            check_that(
                page.status_code == 200
                and "Finish labeling" in page.text
                and ".detail.on{display:grid}" in page.text
                and 'id="boxWidth"' in page.text
                and 'id="boxOpacity"' in page.text,
                "label UI: detail keeps side controls and exposes box visibility settings",
            )
            cleared = requests.post(
                base + "/api/save",
                headers=headers,
                json={"photo": 7, "labels": []},
                timeout=3,
            )
            check_that(
                cleared.status_code == 200
                and label_posts
                and label_posts[-1].get("labels") == [],
                "label UI: clearing every name persists an empty replacement",
            )
            accepted = requests.post(
                base + "/api/finish",
                headers=headers,
                json={
                    "photo": 7,
                    "labels": [{"name": "Anna", "box": [10, 10, 20, 20]}],
                    "save": True,
                },
                timeout=3,
            )
            check_that(accepted.status_code == 202, "label UI: finish request is accepted immediately")
            deadline = time.monotonic() + 3.0
            finish_status = {}
            while time.monotonic() < deadline:
                finish_status = requests.get(
                    base + "/api/finish-status",
                    headers=headers,
                    timeout=3,
                ).json()
                if finish_status.get("status") == "done":
                    break
                time.sleep(0.05)
            check_that(
                finish_status.get("status") == "done",
                "label UI: finish reports saved completion",
            )
            label_thread.join(timeout=4.0)
            check_that(
                not label_thread.is_alive() and label_result.get("saved") == 1,
                "label UI: finish closes server after persisting labels",
            )
    except requests.RequestException as e:
        check_that(False, f"label UI: localhost lifecycle ({e})")
    finally:
        globals()["_collect_label_items"] = original_collect
        globals()["_open_preview_html"] = original_open

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
    ap.add_argument("--label", action="store_true", help="interactive local browser UI for box->name labeling")
    ap.add_argument("--label-flow", action="store_true",
                    help="with --label: after closing the UI, run learn then one scan pass")
    ap.add_argument("--label-limit", type=int, default=500, metavar="N",
                    help="how many recent confirmed photos to load in --label mode (default: 500)")
    ap.add_argument("--watch", type=int, metavar="SECONDS", help="keep running, pausing this long between passes")
    ap.add_argument("--uploaded-after", metavar="YYYY-MM-DD",
                    help="only process photos uploaded on/after this date (scan and --label)")
    ap.add_argument("--uploaded-before", metavar="YYYY-MM-DD",
                    help="only process photos uploaded on/before this date (scan and --label)")
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
    uploaded_after = parse_ymd(args.uploaded_after, "--uploaded-after")
    uploaded_before = parse_ymd(args.uploaded_before, "--uploaded-before")
    if uploaded_after and uploaded_before and uploaded_after > uploaded_before:
        sys.exit("--uploaded-after must be on or before --uploaded-before")

    if args.label:
        if verbose and (uploaded_after or uploaded_before):
            print(
                "label window: "
                f"{uploaded_after or 'start'} .. {uploaded_before or 'now'}"
            )
        stored = local_label(
            api,
            conn,
            backend,
            tolerance,
            args.label_limit,
            uploaded_after,
            uploaded_before,
            args.label_flow,
        )
        if verbose:
            print(f"stored {stored} explicit face label(s)")
        if args.label_flow:
            if verbose:
                print("label flow: learning from confirmed labels")
            learn(api, conn, backend, verbose)
            if verbose:
                print("label flow: scanning queue with refreshed references")
            scan(api, conn, backend, tolerance, cfg, verbose, uploaded_after, uploaded_before)
        return

    while True:
        # In watch mode learn every pass — it is incremental past the watermark,
        # so it costs nothing when no new photos have been tagged and it lets the
        # reference set grow as volunteers work. One-shot runs only learn when
        # asked, or when there is nothing to compare against yet.
        if args.learn or args.watch or not load_references(conn, backend.name):
            learn(api, conn, backend, verbose)
        scan(api, conn, backend, tolerance, cfg, verbose, uploaded_after, uploaded_before)
        if not args.watch:
            break
        if verbose:
            print(f"— sleeping {args.watch}s —")
        time.sleep(args.watch)


if __name__ == "__main__":
    main()
