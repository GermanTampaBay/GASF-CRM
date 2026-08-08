#!/usr/bin/env python3
"""
GASF-CRM face scanner — runs on a private machine, never on the web host.

    python scan.py                 # scan whatever is waiting, then stop
    python scan.py --learn         # refresh the reference set first
    python scan.py --label --label-flow  # mature learn/scan/label refinement loop
    python scan.py --discover      # cluster unresolved faces and open the local board
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
import html
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import io
import json
import os
import re
import secrets
import sqlite3
import subprocess
import sys
import sysconfig
import tempfile
import threading
import time
import unicodedata
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
DEFAULT_DISCOVERY_TOLERANCE = {
    "insightface": 0.32,
    "face_recognition": 0.42,
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


def cfg_discovery_tolerance(cfg, engine):
    raw = os.environ.get(
        "GASF_FACE_DISCOVERY_TOLERANCE",
        cfg.get("discovery_tolerance", ""),
    )
    if raw not in ("", None):
        try:
            value = float(raw)
        except (TypeError, ValueError):
            sys.exit(f"discovery_tolerance must be a number, got {raw!r}")
        if value <= 0:
            sys.exit("discovery_tolerance must be greater than zero")
        return value
    short = str(engine or "").split(":", 1)[0]
    return DEFAULT_DISCOVERY_TOLERANCE.get(short, 0.35)


def cfg_discovery_limit(cfg):
    raw = os.environ.get("GASF_FACE_DISCOVERY_LIMIT", cfg.get("discovery_limit", "1000"))
    try:
        value = int(raw)
    except (TypeError, ValueError):
        sys.exit(f"discovery_limit must be an integer, got {raw!r}")
    return max(1, min(1000, value))


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
        return self.embed_rgb(display_rgb_array(image_bytes))

    def embed_rgb(self, rgb):
        """Embed an already browser-oriented RGB uint8 array."""
        raise NotImplementedError

    def distances(self, matrix, vector):
        """Distance from `vector` to each row of `matrix`; lower is more alike."""
        raise NotImplementedError


def display_rgb_array(image_bytes):
    """Decode pixels in the same EXIF orientation modern browsers display."""
    from PIL import Image, ImageOps

    with Image.open(io.BytesIO(image_bytes)) as source:
        return np.array(ImageOps.exif_transpose(source).convert("RGB"), copy=True)


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
        import onnxruntime as ort

        providers = [p for p in ("CUDAExecutionProvider", "CPUExecutionProvider") if p in ort.get_available_providers()]
        use_cuda = "CUDAExecutionProvider" in providers
        self._app = FaceAnalysis(name="buffalo_l", providers=providers or ["CPUExecutionProvider"])
        self._app.prepare(ctx_id=0 if use_cuda else -1, det_size=(640, 640))
    def embed_rgb(self, rgb):
        # InsightFace expects BGR. Apply EXIF orientation first: browsers do so
        # automatically, and boxes from unrotated source pixels land elsewhere.
        bgr = np.ascontiguousarray(rgb[:, :, ::-1])
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

    def embed_rgb(self, rgb):
        boxes = self._fr.face_locations(rgb, model="hog")  # already css order
        if not boxes:
            return []
        vectors = self._fr.face_encodings(rgb, boxes)
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
    conn = sqlite3.connect(DB_PATH, check_same_thread=False)
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
    conn.execute(
        """CREATE TABLE IF NOT EXISTS unknown_faces (
               id INTEGER PRIMARY KEY,
               engine TEXT NOT NULL,
               photo_id INTEGER NOT NULL,
               face_key TEXT NOT NULL,
               box_x INTEGER NOT NULL,
               box_y INTEGER NOT NULL,
               box_w INTEGER NOT NULL,
               box_h INTEGER NOT NULL,
               image_width INTEGER NOT NULL,
               image_height INTEGER NOT NULL,
               image_url TEXT NOT NULL DEFAULT '',
               taken_at TEXT NOT NULL DEFAULT '',
               uploaded_at TEXT NOT NULL DEFAULT '',
               context_json TEXT NOT NULL DEFAULT '{}',
               vector BLOB NOT NULL,
               cluster_id TEXT NOT NULL DEFAULT '',
               updated_at INTEGER NOT NULL,
               UNIQUE(engine, photo_id, face_key)
           )"""
    )
    conn.execute(
        """CREATE TABLE IF NOT EXISTS unknown_clusters (
               cluster_id TEXT PRIMARY KEY,
               engine TEXT NOT NULL,
               anchor_unknown_id INTEGER NOT NULL,
               member_count INTEGER NOT NULL DEFAULT 0,
               updated_at INTEGER NOT NULL
           )"""
    )
    conn.execute(
        """CREATE TABLE IF NOT EXISTS unknown_dismissals (
               engine TEXT NOT NULL,
               photo_id INTEGER NOT NULL,
               face_key TEXT NOT NULL,
               dismissed_at INTEGER NOT NULL,
               PRIMARY KEY(engine, photo_id, face_key)
           )"""
    )

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
    conn.execute(
        "CREATE INDEX IF NOT EXISTS unknown_faces_engine_cluster "
        "ON unknown_faces(engine, cluster_id, id)"
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS unknown_faces_photo "
        "ON unknown_faces(engine, photo_id)"
    )
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


def _unknown_face_key(index):
    return f"i:{int(index)}"


def _photo_context(photo):
    raw = photo.get("caption_context") if isinstance(photo, dict) else {}
    raw = raw if isinstance(raw, dict) else {}
    clean = caption_context(raw)
    return {
        "taken_at": str(raw.get("taken_at") or clean.get("date_taken") or "")[:40],
        "uploaded_at": str(photo.get("uploaded_at") or "")[:40],
        "context_json": json.dumps(clean, ensure_ascii=True, sort_keys=True),
        "image_url": str(photo.get("url") or ""),
    }


def reconcile_unknown_photo(conn, engine, photo, observations):
    """Make one photo's unresolved local observations match the latest detection."""
    photo_id = int(photo["id"])
    metadata = _photo_context(photo)
    dismissed = {
        row[0]
        for row in conn.execute(
            """SELECT face_key FROM unknown_dismissals
               WHERE engine = ? AND photo_id = ?""",
            (engine, photo_id),
        )
    }
    rows = []
    for observation in observations:
        if str(observation["face_key"]) in dismissed:
            continue
        box = observation["box"]
        vector = np.asarray(observation["vector"], dtype=np.float32)
        rows.append(
            (
                engine,
                photo_id,
                str(observation["face_key"]),
                int(box[0]),
                int(box[1]),
                int(box[2]),
                int(box[3]),
                int(observation["image_width"]),
                int(observation["image_height"]),
                metadata["image_url"],
                metadata["taken_at"],
                metadata["uploaded_at"],
                metadata["context_json"],
                vector.tobytes(),
                int(time.time()),
            )
        )

    keep = {row[2] for row in rows}
    with conn:
        if keep:
            marks = ",".join("?" for _ in keep)
            conn.execute(
                f"""DELETE FROM unknown_faces
                    WHERE engine = ? AND photo_id = ? AND face_key NOT IN ({marks})""",
                (engine, photo_id, *sorted(keep)),
            )
        else:
            conn.execute(
                "DELETE FROM unknown_faces WHERE engine = ? AND photo_id = ?",
                (engine, photo_id),
            )
        conn.executemany(
            """INSERT INTO unknown_faces (
                   engine, photo_id, face_key,
                   box_x, box_y, box_w, box_h,
                   image_width, image_height, image_url,
                   taken_at, uploaded_at, context_json, vector, updated_at
               ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(engine, photo_id, face_key) DO UPDATE SET
                   box_x = excluded.box_x,
                   box_y = excluded.box_y,
                   box_w = excluded.box_w,
                   box_h = excluded.box_h,
                   image_width = excluded.image_width,
                   image_height = excluded.image_height,
                   image_url = excluded.image_url,
                   taken_at = excluded.taken_at,
                   uploaded_at = excluded.uploaded_at,
                   context_json = excluded.context_json,
                   vector = excluded.vector,
                   updated_at = excluded.updated_at""",
            rows,
        )
    return len(rows)


def unresolved_observations(photo, found, references, backend, tolerance, image_width, image_height):
    """Return only faces not resolved by explicit truth or a non-rejected match."""
    boxes = []
    vectors = []
    for box_css, vector in found:
        box = clamp_box_xywh(css_box_to_xywh(box_css), image_width, image_height)
        if box is None:
            continue
        boxes.append(box)
        vectors.append(vector)

    explicit = set()
    labels = [item for item in (photo.get("labels") or []) if isinstance(item, dict)]
    used = set()
    for label in labels:
        target = label.get("box") or []
        name = str(label.get("name") or "").strip()
        if not name or not isinstance(target, (list, tuple)) or len(target) != 4:
            continue
        target = [int(value) for value in target]
        best_i, best_iou = -1, 0.0
        for index, box in enumerate(boxes):
            if index in used:
                continue
            overlap = box_iou_xywh(target, box)
            if overlap > best_iou:
                best_i, best_iou = index, overlap
        if best_i >= 0 and best_iou >= 0.15:
            explicit.add(best_i)
            used.add(best_i)

    one_face_truth = len(boxes) == 1 and len(
        [name for name in (photo.get("people") or []) if str(name).strip()]
    ) == 1
    rejected = photo.get("rejected") or []
    unknown = []
    for index, (box, vector) in enumerate(zip(boxes, vectors)):
        resolved = index in explicit or (index == 0 and one_face_truth)
        if not resolved:
            name, _ = identify(vector, references, backend, tolerance)
            resolved = bool(name) and not _face_name_rejected(name, rejected)
        if resolved:
            continue
        unknown.append(
            {
                "face_key": _unknown_face_key(index),
                "box": box,
                "image_width": image_width,
                "image_height": image_height,
                "vector": vector,
            }
        )
    return unknown


def cluster_unknown_faces(conn, backend, threshold):
    """Deterministic complete-link clustering, isolated to one recognition engine."""
    raw = conn.execute(
        """SELECT id, vector FROM unknown_faces
           WHERE engine = ? ORDER BY id ASC""",
        (backend.name,),
    ).fetchall()
    observations = [
        {"id": int(row[0]), "vector": np.frombuffer(row[1], dtype=np.float32)}
        for row in raw
    ]
    clusters = []
    for observation in observations:
        candidates = []
        for cluster in clusters:
            matrix = np.vstack([member["vector"] for member in cluster])
            distances = backend.distances(matrix, observation["vector"])
            farthest = float(np.max(distances))
            if farthest <= threshold:
                candidates.append((farthest, cluster[0]["id"], cluster))
        if candidates:
            min(candidates, key=lambda item: (item[0], item[1]))[2].append(observation)
        else:
            clusters.append([observation])

    now = int(time.time())
    assigned = []
    with conn:
        conn.execute(
            "UPDATE unknown_faces SET cluster_id = '' WHERE engine = ?",
            (backend.name,),
        )
        conn.execute(
            "DELETE FROM unknown_clusters WHERE engine = ?",
            (backend.name,),
        )
        for cluster in clusters:
            anchor = int(cluster[0]["id"])
            digest = hashlib.sha256(f"{backend.name}:{anchor}".encode("utf-8")).hexdigest()[:16]
            cluster_id = f"unknown-{digest}"
            ids = [int(member["id"]) for member in cluster]
            conn.execute(
                """INSERT INTO unknown_clusters (
                       cluster_id, engine, anchor_unknown_id, member_count, updated_at
                   ) VALUES (?, ?, ?, ?, ?)""",
                (cluster_id, backend.name, anchor, len(ids), now),
            )
            marks = ",".join("?" for _ in ids)
            conn.execute(
                f"UPDATE unknown_faces SET cluster_id = ? WHERE id IN ({marks})",
                (cluster_id, *ids),
            )
            assigned.append((cluster_id, ids))
    return assigned


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


# --------------------------------------------------------------------------- people discovery


def prepare_unknown_faces(
    api,
    conn,
    backend,
    tolerance,
    limit=1000,
    uploaded_after="",
    uploaded_before="",
    verbose=True,
):
    """Refresh local unknown observations from approved library photos."""
    params = {"limit": max(1, min(1000, int(limit)))}
    if uploaded_after:
        params["after"] = uploaded_after
    if uploaded_before:
        params["before"] = uploaded_before
    data = api.get("/label-queue", **params)
    photos = list(data.get("photos") or [])
    references = load_references(conn, backend.name)
    people = []
    try:
        people_data = api.get("/people")
        people = [
            str(name).strip()
            for name in (people_data.get("people") or [])
            if str(name).strip()
        ]
    except requests.RequestException as e:
        if verbose:
            print(f"people list unavailable: {e}")

    processed = unknown_count = failed = 0
    for index, photo in enumerate(photos, start=1):
        photo_id = int(photo["id"])
        try:
            image_bytes = api.image(photo["url"])
            pixels = display_rgb_array(image_bytes)
            found = backend.embed_rgb(pixels)
            image_height, image_width = pixels.shape[:2]
            unknown = unresolved_observations(
                photo,
                found,
                references,
                backend,
                tolerance,
                image_width,
                image_height,
            )
            unknown_count += reconcile_unknown_photo(
                conn,
                backend.name,
                photo,
                unknown,
            )
            processed += 1
        except (requests.RequestException, RuntimeError, ValueError, OSError) as e:
            failed += 1
            if verbose:
                print(f"  #{photo_id}: discovery preparation skipped ({e})")
        if verbose and (index % 25 == 0 or index == len(photos)):
            print(
                f"discovery prep: {index}/{len(photos)} photos checked, "
                f"{unknown_count} unresolved occurrence(s)"
            )
    return {
        "photos": processed,
        "unknown": unknown_count,
        "failed": failed,
        "people": people,
    }


def discovery_metadata(conn, engine):
    rows = conn.execute(
        """SELECT u.id, u.cluster_id, u.photo_id, u.face_key,
                  u.box_x, u.box_y, u.box_w, u.box_h,
                  u.image_width, u.image_height,
                  u.taken_at, u.uploaded_at, u.context_json
           FROM unknown_faces u
           WHERE u.engine = ? AND u.cluster_id <> ''
           ORDER BY u.cluster_id, u.id""",
        (engine,),
    ).fetchall()
    grouped = {}
    for row in rows:
        context = {}
        try:
            context = json.loads(row[12] or "{}")
        except json.JSONDecodeError:
            context = {}
        occurrence = {
            "id": int(row[0]),
            "photo": int(row[2]),
            "face_key": row[3],
            "box": [int(row[4]), int(row[5]), int(row[6]), int(row[7])],
            "image_width": int(row[8]),
            "image_height": int(row[9]),
            "taken_at": row[10] or "",
            "uploaded_at": row[11] or "",
            "context": context,
        }
        grouped.setdefault(row[1], []).append(occurrence)

    clusters = []
    for cluster_id, occurrences in grouped.items():
        dates = sorted(
            value[:10]
            for item in occurrences
            for value in (item["taken_at"] or item["uploaded_at"],)
            if value
        )
        clusters.append(
            {
                "id": cluster_id,
                "count": len(occurrences),
                "first_date": dates[0] if dates else "",
                "last_date": dates[-1] if dates else "",
                "occurrences": occurrences,
            }
        )
    clusters.sort(key=lambda item: (-item["count"], item["id"]))
    return clusters


def _discovery_crop(image_bytes, box, max_px=280):
    from PIL import Image, ImageOps

    with Image.open(io.BytesIO(image_bytes)) as source:
        image = ImageOps.exif_transpose(source).convert("RGB")
    x, y, width, height = [int(value) for value in box]
    padding = max(8, int(max(width, height) * 0.22))
    left = max(0, x - padding)
    top = max(0, y - padding)
    right = min(image.width, x + width + padding)
    bottom = min(image.height, y + height + padding)
    if right <= left or bottom <= top:
        raise ValueError("The stored face rectangle is outside the photo.")
    crop = image.crop((left, top, right, bottom))
    crop.thumbnail((max_px, max_px))
    output = io.BytesIO()
    crop.save(output, format="JPEG", quality=82, optimize=True)
    return output.getvalue()


def _discovery_ui_html(session_token):
    page = r"""<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GASF People Discovery</title>
<style>
:root{color-scheme:light;--blue:#135e96;--ink:#1d2327;--muted:#646970;--line:#dcdcde;--bg:#f6f7f7}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 system-ui,sans-serif}
header{position:sticky;top:0;z-index:3;display:flex;gap:16px;align-items:center;padding:14px 22px;background:#fff;border-bottom:1px solid var(--line)}
h1{font-size:21px;margin:0}header p{margin:0;color:var(--muted);flex:1}.button,button{border:1px solid #2271b1;border-radius:4px;background:#2271b1;color:#fff;padding:8px 13px;font-weight:600;cursor:pointer}
button.secondary{background:#fff;color:#2271b1}button.danger{background:#fff;color:#b32d2e;border-color:#b32d2e}
main{padding:20px}.notice{max-width:900px;margin:0 0 18px;padding:12px 14px;background:#fff;border-left:4px solid var(--blue)}
#clusters{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.cluster{background:#fff;border:1px solid var(--line);border-radius:6px;overflow:hidden;cursor:pointer}.cluster:focus{outline:3px solid #72aee6}
.sheet{display:grid;grid-template-columns:repeat(3,1fr);height:220px;background:#dcdcde;gap:2px}.sheet img{width:100%;height:100%;object-fit:cover;background:#eee}
.cluster .meta{padding:11px 13px}.cluster strong{font-size:17px}.range{color:var(--muted);font-size:13px}
#empty{padding:30px;background:#fff;border:1px solid var(--line)}
.modal{position:fixed;inset:0;z-index:5;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:22px}.modal.on{display:flex}
.dialog{background:#fff;width:min(1050px,100%);max-height:94vh;overflow:auto;border-radius:7px}.dialog header{position:sticky;padding:13px 16px}
.dialog .body{padding:16px}.instruction{padding:10px 12px;background:#fcf0c3;border-left:4px solid #dba617;font-weight:600}
.occurrences{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin:16px 0}.occ{border:2px solid #2271b1;border-radius:5px;background:#fff;overflow:hidden}.occ.off{border-color:#c3c4c7;opacity:.55}
.occ img{display:block;width:100%;height:150px;object-fit:cover;background:#eee}.occ label{display:block;padding:8px}.occ small{display:block;color:var(--muted)}
.actions{position:sticky;bottom:0;display:flex;gap:9px;align-items:end;padding:13px 0;background:#fff;border-top:1px solid var(--line)}
.actions .name{flex:1}.actions label{display:block;font-weight:600}.actions input[type=text]{width:100%;padding:8px;border:1px solid #8c8f94;border-radius:4px}
#message{min-height:22px;color:#b32d2e;font-weight:600}.busy{opacity:.6;pointer-events:none}
</style></head><body>
<header><h1>People Discovery</h1><p>Biometric vectors stay in this PC's local faces.db. WordPress receives only reviewed photo, box, and name facts.</p><button class="secondary" id="closeBoard">Close board</button></header>
<main><div class="notice"><strong>Review before naming.</strong> Each card is a conservative local cluster. Open one, deselect any wrong faces, and type one name. That one name applies to every selected face.</div>
<div id="clusters"></div><div id="empty" hidden>No unresolved faces are waiting. Run discovery again after new photos are scanned.</div></main>
<div class="modal" id="modal"><section class="dialog"><header><h1 id="clusterTitle">Review cluster</h1><button class="secondary" id="closeModal">Back</button></header><div class="body">
<div class="instruction">One name applies to all selected faces. Deselect mistakes before applying.</div>
<div id="occurrences" class="occurrences"></div><div id="message"></div>
<div class="actions"><div class="name"><label for="personName">Known or new person name</label><input id="personName" type="text" maxlength="120" list="people"></div>
<datalist id="people"></datalist><button class="danger" id="dismissSelected">Dismiss selected locally</button><button id="applyName">Apply name to selected faces</button></div>
</div></section></div>
<script>
const TOKEN=__DISCOVERY_TOKEN__;
let meta={clusters:[],people:[]}, current=null, viewRevision=0, busy=false;
const byId=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function call(path,options={}){options.headers={...(options.headers||{}),'X-GASF-Discovery-Token':TOKEN};if(options.body)options.headers['Content-Type']='application/json';const r=await fetch(path,options);let data=null;try{data=await r.json()}catch(e){}if(!r.ok)throw new Error(data?.error||`Request failed (${r.status})`);return data}
async function putCrop(img,id,revision){try{const r=await fetch(`/api/crop?id=${encodeURIComponent(id)}`,{headers:{'X-GASF-Discovery-Token':TOKEN}});if(!r.ok)throw new Error();const blob=await r.blob();if(revision!==viewRevision)return;img.src=URL.createObjectURL(blob)}catch(e){if(revision===viewRevision)img.alt='Crop unavailable'}}
function contextText(o){const c=o.context||{};return [...(c.events||[]),...(c.places||[])].slice(0,2).join(' / ')}
function render(next){meta=next;const revision=++viewRevision;const root=byId('clusters');root.innerHTML='';byId('empty').hidden=meta.clusters.length>0;byId('people').innerHTML=(meta.people||[]).map(n=>`<option value="${esc(n)}">`).join('');
meta.clusters.forEach((cluster,index)=>{const card=document.createElement('article');card.className='cluster';card.tabIndex=0;card.innerHTML=`<div class="sheet"></div><div class="meta"><strong>${cluster.count} occurrence${cluster.count===1?'':'s'}</strong><div class="range">${esc(cluster.first_date&&cluster.last_date?(cluster.first_date===cluster.last_date?cluster.first_date:`${cluster.first_date} to ${cluster.last_date}`):'Date unavailable')}</div></div>`;card.onclick=()=>openCluster(index);card.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();openCluster(index)}};const sheet=card.querySelector('.sheet');cluster.occurrences.slice(0,6).forEach(o=>{const img=document.createElement('img');img.alt=`Face from photo ${o.photo}`;sheet.appendChild(img);putCrop(img,o.id,revision)});root.appendChild(card)})}
function openCluster(index){current=meta.clusters[index];if(!current)return;const revision=++viewRevision;byId('clusterTitle').textContent=`Review ${current.count} occurrence${current.count===1?'':'s'}`;byId('personName').value='';byId('message').textContent='';const root=byId('occurrences');root.innerHTML='';current.occurrences.forEach(o=>{const card=document.createElement('article');card.className='occ';card.innerHTML=`<img alt="Face crop from photo ${o.photo}"><label><input type="checkbox" value="${o.id}" checked> Photo #${o.photo}<small>${esc(o.taken_at||o.uploaded_at||'Date unavailable')}</small><small>${esc(contextText(o))}</small></label>`;const cb=card.querySelector('input');cb.onchange=()=>card.classList.toggle('off',!cb.checked);root.appendChild(card);putCrop(card.querySelector('img'),o.id,revision)});byId('modal').classList.add('on')}
function selected(){return [...byId('occurrences').querySelectorAll('input:checked')].map(el=>Number(el.value))}
function setBusy(value){busy=value;document.body.classList.toggle('busy',value)}
async function applyName(){if(busy)return;const ids=selected(),name=byId('personName').value.trim();if(!ids.length){byId('message').textContent='Select at least one face.';return}if(!name){byId('message').textContent='Type the one name to apply to all selected faces.';return}if(!confirm(`Apply "${name}" to ${ids.length} selected face${ids.length===1?'':'s'}?`))return;setBusy(true);try{const out=await call('/api/name',{method:'POST',body:JSON.stringify({cluster:current.id,name,selected:ids})});byId('modal').classList.remove('on');render(out.meta);if(out.pending)alert(`${out.applied} face(s) saved. ${out.pending} stayed pending because WordPress reported them busy.`)}catch(e){byId('message').textContent=e.message}finally{setBusy(false)}}
async function dismissSelected(){if(busy)return;const ids=selected();if(!ids.length){byId('message').textContent='Select at least one face to dismiss.';return}if(!confirm(`Dismiss ${ids.length} selected occurrence${ids.length===1?'':'s'} locally? The WordPress photos are not deleted or changed.`))return;setBusy(true);try{const out=await call('/api/dismiss',{method:'POST',body:JSON.stringify({cluster:current.id,selected:ids})});byId('modal').classList.remove('on');render(out.meta)}catch(e){byId('message').textContent=e.message}finally{setBusy(false)}}
async function closeBoard(){try{await call('/api/close',{method:'POST',body:'{}'});document.body.innerHTML='<main><div class="notice"><strong>People Discovery closed.</strong> You may close this tab.</div></main>'}catch(e){alert(e.message)}}
byId('closeModal').onclick=()=>{byId('modal').classList.remove('on');++viewRevision};byId('applyName').onclick=applyName;byId('dismissSelected').onclick=dismissSelected;byId('closeBoard').onclick=closeBoard;
call('/api/meta').then(render).catch(e=>{byId('clusters').innerHTML=`<div class="notice">${esc(e.message)}</div>`});
</script></body></html>"""
    return page.replace("__DISCOVERY_TOKEN__", json.dumps(session_token))


def local_discovery_board(api, conn, backend, threshold, people):
    state = {
        "token": secrets.token_urlsafe(32),
        "done": threading.Event(),
        "lock": threading.Lock(),
        "db_lock": threading.RLock(),
        "image_cache": OrderedDict(),
        "people": list(people),
    }

    def current_meta():
        with state["db_lock"]:
            return {
                "engine": backend.name,
                "threshold": threshold,
                "people": state["people"],
                "clusters": discovery_metadata(conn, backend.name),
            }

    class DiscoveryHandler(BaseHTTPRequestHandler):
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
                    "default-src 'self'; img-src 'self' blob: data:; "
                    "style-src 'unsafe-inline'; script-src 'unsafe-inline'; "
                    "connect-src 'self'; frame-ancestors 'none'",
                )
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            try:
                self.wfile.write(body)
            except (BrokenPipeError, ConnectionResetError):
                return

        def _json(self, code, payload):
            return self._write(code, json.dumps(payload, ensure_ascii=True))

        def _authorized(self):
            supplied = self.headers.get("X-GASF-Discovery-Token", "")
            return bool(supplied) and secrets.compare_digest(supplied, state["token"])

        def _selected_rows(self, data):
            cluster_id = str(data.get("cluster") or "")
            raw_ids = data.get("selected") or []
            if not isinstance(raw_ids, list) or not raw_ids or len(raw_ids) > 100:
                raise ValueError("Select between 1 and 100 occurrences.")
            try:
                ids = sorted({int(value) for value in raw_ids if int(value) > 0})
            except (TypeError, ValueError) as e:
                raise ValueError("Selected occurrence ids must be integers.") from e
            if not ids:
                raise ValueError("Select at least one occurrence.")
            marks = ",".join("?" for _ in ids)
            with state["db_lock"]:
                rows = conn.execute(
                    f"""SELECT id, photo_id, face_key, box_x, box_y, box_w, box_h,
                               image_width, image_height
                        FROM unknown_faces
                        WHERE engine = ? AND cluster_id = ? AND id IN ({marks})
                        ORDER BY id""",
                    (backend.name, cluster_id, *ids),
                ).fetchall()
            if len(rows) != len(ids):
                raise ValueError("That cluster changed. Refresh the board and review it again.")
            return rows

        def do_GET(self):
            parsed = urlparse(self.path)
            if parsed.path == "/":
                supplied = (parse_qs(parsed.query or "").get("token") or [""])[0]
                if not supplied or not secrets.compare_digest(supplied, state["token"]):
                    return self._write(403, "Forbidden", "text/plain; charset=utf-8")
                return self._write(
                    200,
                    _discovery_ui_html(state["token"]),
                    "text/html; charset=utf-8",
                )
            if not self._authorized():
                return self._json(403, {"error": "Forbidden"})
            if parsed.path == "/api/meta":
                return self._json(200, current_meta())
            if parsed.path == "/api/crop":
                try:
                    occurrence_id = int((parse_qs(parsed.query).get("id") or ["0"])[0])
                except (TypeError, ValueError):
                    return self._json(400, {"error": "Invalid occurrence id."})
                with state["db_lock"]:
                    row = conn.execute(
                        """SELECT photo_id, image_url, box_x, box_y, box_w, box_h
                           FROM unknown_faces WHERE id = ? AND engine = ?""",
                        (occurrence_id, backend.name),
                    ).fetchone()
                if row is None:
                    return self._json(404, {"error": "No such occurrence."})
                photo_id, image_url = int(row[0]), row[1]
                with state["lock"]:
                    image_bytes = state["image_cache"].get(photo_id)
                    if image_bytes is not None:
                        state["image_cache"].move_to_end(photo_id)
                if image_bytes is None:
                    try:
                        image_bytes = api.image(image_url)
                    except (requests.RequestException, RuntimeError, ValueError, SystemExit) as e:
                        return self._json(502, {"error": f"Could not load photo #{photo_id}: {e}"})
                    with state["lock"]:
                        state["image_cache"][photo_id] = image_bytes
                        state["image_cache"].move_to_end(photo_id)
                        while len(state["image_cache"]) > 6:
                            state["image_cache"].popitem(last=False)
                try:
                    crop = _discovery_crop(image_bytes, row[2:6])
                except (OSError, ValueError) as e:
                    return self._json(422, {"error": str(e)})
                return self._write(200, crop, "image/jpeg")
            return self._json(404, {"error": "Not found"})

        def do_POST(self):
            parsed = urlparse(self.path)
            if not self._authorized():
                return self._json(403, {"error": "Forbidden"})
            try:
                length = int(self.headers.get("Content-Length", "0") or 0)
            except (TypeError, ValueError):
                return self._json(400, {"error": "Invalid content length."})
            if length < 0 or length > 64 * 1024:
                return self._json(413, {"error": "Request too large."})
            try:
                data = json.loads((self.rfile.read(length) if length else b"{}").decode("utf-8"))
            except (UnicodeDecodeError, json.JSONDecodeError):
                return self._json(400, {"error": "Invalid JSON."})

            if parsed.path == "/api/close":
                state["done"].set()
                return self._json(200, {"ok": True})

            if parsed.path == "/api/dismiss":
                try:
                    rows = self._selected_rows(data)
                except ValueError as e:
                    return self._json(409, {"error": str(e)})
                now = int(time.time())
                ids = [int(row[0]) for row in rows]
                with state["db_lock"]:
                    with conn:
                        conn.executemany(
                            """INSERT INTO unknown_dismissals (
                                   engine, photo_id, face_key, dismissed_at
                               ) VALUES (?, ?, ?, ?)
                               ON CONFLICT(engine, photo_id, face_key) DO UPDATE SET
                                   dismissed_at = excluded.dismissed_at""",
                            [(backend.name, int(row[1]), str(row[2]), now) for row in rows],
                        )
                        marks = ",".join("?" for _ in ids)
                        conn.execute(
                            f"DELETE FROM unknown_faces WHERE id IN ({marks})",
                            ids,
                        )
                    cluster_unknown_faces(conn, backend, threshold)
                return self._json(
                    200,
                    {"ok": True, "dismissed": len(ids), "meta": current_meta()},
                )

            if parsed.path == "/api/name":
                name = " ".join(str(data.get("name") or "").split())
                if not name or len(name) > 120:
                    return self._json(400, {"error": "Type a person name of 1 to 120 characters."})
                try:
                    rows = self._selected_rows(data)
                except ValueError as e:
                    return self._json(409, {"error": str(e)})
                payload = []
                for row in rows:
                    payload.append(
                        {
                            "client_key": str(row[0]),
                            "photo": int(row[1]),
                            "box": [int(value) for value in row[3:7]],
                            "image_width": int(row[7]),
                            "image_height": int(row[8]),
                        }
                    )
                try:
                    result = api.post(
                        "/discover-label",
                        {"name": name, "occurrences": payload},
                    )
                except (requests.RequestException, RuntimeError, ValueError, SystemExit) as e:
                    return self._json(502, {"error": f"WordPress did not save the labels: {e}"})
                applied_keys = {
                    str(value) for value in (result.get("applied") or [])
                }
                applied_ids = [
                    int(row[0]) for row in rows if str(row[0]) in applied_keys
                ]
                if applied_ids:
                    marks = ",".join("?" for _ in applied_ids)
                    with state["db_lock"]:
                        with conn:
                            conn.execute(
                                f"DELETE FROM unknown_faces WHERE id IN ({marks})",
                                applied_ids,
                            )
                        cluster_unknown_faces(conn, backend, threshold)
                return self._json(
                    200,
                    {
                        "ok": len(applied_ids) == len(rows),
                        "applied": len(applied_ids),
                        "pending": len(rows) - len(applied_ids),
                        "meta": current_meta(),
                    },
                )
            return self._json(404, {"error": "Not found"})

        def log_message(self, format, *args):
            return

    server = ThreadingHTTPServer(("127.0.0.1", 0), DiscoveryHandler)
    server.daemon_threads = True
    server.block_on_close = False
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    url = f"http://127.0.0.1:{port}/?token={state['token']}"
    print(f"People Discovery board: http://127.0.0.1:{port}/")
    _open_preview_html(url)
    try:
        while not state["done"].wait(0.25):
            pass
    except KeyboardInterrupt:
        pass
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2.0)


def run_discovery(
    api,
    conn,
    backend,
    tolerance,
    threshold,
    limit,
    uploaded_after="",
    uploaded_before="",
    verbose=True,
):
    prepared = prepare_unknown_faces(
        api,
        conn,
        backend,
        tolerance,
        limit,
        uploaded_after,
        uploaded_before,
        verbose,
    )
    clusters = cluster_unknown_faces(conn, backend, threshold)
    if verbose:
        print(
            f"discovery: {prepared['unknown']} unresolved occurrence(s) in "
            f"{len(clusters)} conservative cluster(s) [{backend.name}, threshold {threshold:g}]"
        )
    if not clusters:
        print("No unresolved faces are available for People Discovery.")
        return 0
    local_discovery_board(
        api,
        conn,
        backend,
        threshold,
        prepared["people"],
    )
    return len(clusters)


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
        from PIL import Image, ImageOps
        with Image.open(io.BytesIO(image_bytes)) as source:
            img = ImageOps.exif_transpose(source).convert("RGB")
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
    known_threshold = max(0, min(100, int(data.get("auto_accept_threshold") or 0)))
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
                display_pixels = display_rgb_array(image_bytes)
                found = backend.embed_rgb(display_pixels)
            except Exception as e:
                print(f"#{photo_id}: skipped ({e})")
                continue
            if not found:
                continue

            image_height, image_width = display_pixels.shape[:2]
            boxes = []
            kept_found = []
            for box_css, vector in found:
                box = clamp_box_xywh(css_box_to_xywh(box_css), image_width, image_height)
                if box is None:
                    continue
                boxes.append(box)
                kept_found.append((box_css, vector))
            found = kept_found
            if not found:
                continue
            hints = []
            rejected = p.get("rejected") or []
            for i, (_, vec) in enumerate(found):
                name, conf = identify(vec, refs, backend, tolerance)
                if _face_name_rejected(name, rejected):
                    name, conf = None, 0
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
                if _face_name_rejected(name, rejected):
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

            status = _label_item_status(len(boxes), prefill, hints, known_threshold)
            # A one-face photo that already has exactly one person tag is already
            # learnable without box labels; keep label mode focused on ambiguous work.
            if status == "untagged" and len(boxes) == 1 and len(people) == 1:
                continue

            items.append(
                {
                    "id": photo_id,
                    "url": p["url"],
                    "people": people,
                    "boxes": boxes,
                    "image_width": image_width,
                    "image_height": image_height,
                    "hints": hints,
                    "prefill": prefill,
                    "status": status,
                    "known_threshold": known_threshold,
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


def _label_item_status(face_count, prefill, hints, known_threshold):
    """Classify corpus work, counting strict known matches as already resolved."""
    resolved = set()
    for raw in (prefill or {}).keys():
        try:
            resolved.add(int(raw))
        except (TypeError, ValueError):
            continue
    if known_threshold > 0:
        for hint in hints or []:
            try:
                if hint.get("name") and int(hint.get("confidence") or 0) >= known_threshold:
                    resolved.add(int(hint["index"]))
            except (KeyError, TypeError, ValueError):
                continue
    if not resolved:
        return "untagged"
    if len(resolved) < max(0, int(face_count)):
        return "partial"
    return "full"


def _face_name_rejected(name, rejected_names):
    """Match the server's per-photo negative names without weakening other hints."""
    keys = _face_name_keys(name)
    return bool(keys) and any(keys.intersection(_face_name_keys(rejected)) for rejected in (rejected_names or []))


def _face_name_keys(name):
    """Mirror WordPress's expanded and plain German person-name keys."""
    clean = " ".join(html.unescape(str(name or "")).split())
    if not clean:
        return set()
    expanded = clean.translate(str.maketrans({
        "ä": "ae", "ö": "oe", "ü": "ue", "ß": "ss",
        "Ä": "ae", "Ö": "oe", "Ü": "ue",
    }))
    keys = set()
    for value in (expanded, clean):
        ascii_value = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode("ascii").lower()
        key = " ".join(re.sub(r"[^a-z0-9 ]+", " ", ascii_value).split())
        if key:
            keys.add(key)
    return keys


def clamp_box_xywh(box, image_width, image_height):
    """Clip a detector box to the exact oriented pixels sent to the browser."""
    if len(box) != 4 or image_width < 1 or image_height < 1:
        return None
    x, y, width, height = (int(v) for v in box)
    x1 = max(0, min(image_width, x))
    y1 = max(0, min(image_height, y))
    x2 = max(0, min(image_width, x + max(0, width)))
    y2 = max(0, min(image_height, y + max(0, height)))
    if x2 <= x1 or y2 <= y1:
        return None
    return [x1, y1, x2 - x1, y2 - y1]


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
.frame{position:relative;display:inline-block;max-width:100%;line-height:0;transform-origin:center center;cursor:grab;touch-action:none}
.frame.panning{cursor:grabbing}
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
.viewprefs{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:0 0 10px;padding:8px;
border:1px solid #26324a;border-radius:6px;background:#0b1220}
.viewprefs strong{grid-column:1/-1;font-size:12px}
.viewprefs button{padding:5px 7px;border:1px solid #334155;border-radius:5px;background:#111827;color:#e5e7eb;cursor:pointer}
.viewprefs button:hover{border-color:#60a5fa}
.viewprefs .zoomread{grid-column:1/-1;text-align:center;font-size:12px;color:#94a3b8}
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
        <option value="needs">Needs labeling</option>
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
      <div class="viewprefs">
        <strong>View</strong>
        <button id="zoomOut" type="button" title="Zoom out">-</button>
        <button id="viewFit" type="button" title="Fit image">Fit</button>
        <button id="zoomIn" type="button" title="Zoom in">+</button>
        <button id="panUp" type="button" title="Pan up">Up</button>
        <button id="panLeft" type="button" title="Pan left">Left</button>
        <button id="panDown" type="button" title="Pan down">Down</button>
        <button id="panRight" type="button" title="Pan right">Right</button>
        <button id="panCenter" type="button" title="Center without changing zoom">Center</button>
        <div class="zoomread"><span id="zoomValue">100%</span> - drag image to pan; Ctrl+wheel to zoom</div>
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
let count=0, pos=0, current=null, activeGallery=[], allGallery=[], loadSeq=0, imageRenderSeq=0, photoAbort=null;
let saving=false, finishing=false, dirty=false, finishPollFailures=0, finishReturnView='galleryView';
let viewZoom=1, viewPanX=0, viewPanY=0, panPointer=null, panStartX=0, panStartY=0, panOriginX=0, panOriginY=0;
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
function applyView(){
  const frame=document.getElementById('frame');
  frame.style.transform=`translate(${viewPanX}px,${viewPanY}px) scale(${viewZoom})`;
  setText('zoomValue',`${Math.round(viewZoom*100)}%`);
}
function setZoom(next){
  viewZoom=Math.max(.5,Math.min(4,Number(next)||1));
  applyView();
}
function resetView(){
  viewZoom=1; viewPanX=0; viewPanY=0; applyView();
}
function panView(dx,dy){
  viewPanX+=dx; viewPanY+=dy; applyView();
}
function setupViewControls(){
  const frame=document.getElementById('frame');
  document.getElementById('zoomOut').onclick=()=>setZoom(viewZoom-.25);
  document.getElementById('zoomIn').onclick=()=>setZoom(viewZoom+.25);
  document.getElementById('viewFit').onclick=resetView;
  document.getElementById('panCenter').onclick=()=>{viewPanX=0;viewPanY=0;applyView();};
  document.getElementById('panUp').onclick=()=>panView(0,60);
  document.getElementById('panDown').onclick=()=>panView(0,-60);
  document.getElementById('panLeft').onclick=()=>panView(60,0);
  document.getElementById('panRight').onclick=()=>panView(-60,0);
  frame.addEventListener('pointerdown',e=>{
    if(e.button!==0){return;}
    panPointer=e.pointerId; panStartX=e.clientX; panStartY=e.clientY;
    panOriginX=viewPanX; panOriginY=viewPanY;
    frame.setPointerCapture(e.pointerId); frame.classList.add('panning');
    e.preventDefault();
  });
  frame.addEventListener('pointermove',e=>{
    if(panPointer!==e.pointerId){return;}
    viewPanX=panOriginX+(e.clientX-panStartX);
    viewPanY=panOriginY+(e.clientY-panStartY);
    applyView();
  });
  const stopPan=e=>{
    if(panPointer!==e.pointerId){return;}
    panPointer=null; frame.classList.remove('panning');
  };
  frame.addEventListener('pointerup',stopPan);
  frame.addEventListener('pointercancel',stopPan);
  frame.addEventListener('dblclick',resetView);
  frame.addEventListener('wheel',e=>{
    if(!e.ctrlKey){return;}
    e.preventDefault();
    setZoom(viewZoom+(e.deltaY<0?.25:-.25));
  },{passive:false});
}
function showOnly(id){
  document.querySelectorAll('.main > .view').forEach(v=>v.classList.toggle('on',v.id===id));
}
function showGallery(){ showOnly('galleryView'); }
function showDetail(){ showOnly('detailView'); }
function showFinish(){ showOnly('finishView'); }
function gallerySub(){
  const f = (document.getElementById('gfilter')||{}).value || 'all';
  const nouns = {all:'photo', needs:'photo needing labels', partial:'partially tagged photo', untagged:'untagged photo'};
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
  const img=document.getElementById('photo');
  const w=Number(p.image_width)||img.naturalWidth||1;
  const h=Number(p.image_height)||img.naturalHeight||1;
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
  const renderSeq=++imageRenderSeq;
  current=p;
  dirty=false;
  resetView();
  showDetail();
  setText('title', `Photo #${p.id}`);
  setText('sub', `${p.boxes.length} face box(es)`);
  setText('stat', `${pos+1} / ${count}`);
  const img=document.getElementById('photo');
  document.getElementById('ov').innerHTML='';
  img.onload=()=>{
    if(renderSeq!==imageRenderSeq){return;}
    drawCurrent();
  };
  img.onerror=()=>{
    if(renderSeq===imageRenderSeq){setText('sub','Could not decode this image.');}
  };
  img.src=p.image;
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
  activeGallery = allGallery.filter(g =>
    f === 'all' ? true : (f === 'needs' ? g.status !== 'full' : g.status === f));
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
  setupViewControls();
  const m=await j('/api/meta');
  allGallery = (m.gallery||[]);
  (m.people||[]).forEach(addName);
  const gf=document.getElementById('gfilter');
  if (gf) {
    gf.onchange = applyFilter;
    gf.value = 'needs';
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
function currentCorpusStatus(){
  if(!current){return 'untagged';}
  const resolved=new Set();
  document.querySelectorAll('#rows input[data-i]').forEach(inp=>{
    if(inp.value.trim()){resolved.add(Number(inp.getAttribute('data-i')));}
  });
  const threshold=Number(current.known_threshold||0);
  if(threshold>0){
    (current.hints||[]).forEach(h=>{
      if(h.name && Number(h.confidence||0)>=threshold){resolved.add(Number(h.index));}
    });
  }
  if(!resolved.size){return 'untagged';}
  return resolved.size >= (current.boxes||[]).length ? 'full' : 'partial';
}
function updateGalleryStatus(photoId){
  const status=currentCorpusStatus();
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
    updateGalleryStatus(current.id);
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
        ? 'Your labels are saved. References are refreshing, then one final scan will run in ScanGUI.'
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


def scan(
    api,
    conn,
    backend,
    tolerance,
    cfg,
    verbose=True,
    uploaded_after="",
    uploaded_before="",
    include_captions=True,
):
    references = load_references(conn, backend.name)
    caption_key = caption_scan_key(cfg) if include_captions else ""
    if verbose:
        print(f"reference set: {len(references)} person(s) with {MIN_REFERENCES}+ examples [{backend.name}]")
        if not include_captions:
            print("caption pipeline: deferred until after labeling")
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
                display_pixels = None
                err = None
                for attempt in range(MAX_SCAN_RETRIES):
                    try:
                        image_bytes = api.image(p["url"])
                        if needs_faces:
                            display_pixels = display_rgb_array(image_bytes)
                            found = backend.embed_rgb(display_pixels)
                        else:
                            found = []
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
                    image_height, image_width = display_pixels.shape[:2]
                    unknown = unresolved_observations(
                        p,
                        found,
                        references,
                        backend,
                        tolerance,
                        image_width,
                        image_height,
                    )
                    reconcile_unknown_photo(conn, backend.name, p, unknown)
                    for box_css, vector in found:
                        box = clamp_box_xywh(
                            css_box_to_xywh(box_css),
                            image_width,
                            image_height,
                        )
                        if box is None:
                            continue
                        boxes.append({"box": box})
                        name, conf = identify(vector, references, backend, tolerance)
                        if (
                            not name
                            or name.lower() in already
                            or _face_name_rejected(name, p.get("rejected") or [])
                        ):
                            continue
                        faces.append(
                            {
                                "name": name,
                                "confidence": conf,
                                "box": box,
                            }
                        )
                    face_item.update({
                        "found": len(boxes),
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

    unknown_rows = conn.execute(
        """SELECT engine, COUNT(*), COUNT(DISTINCT NULLIF(cluster_id, ''))
           FROM unknown_faces GROUP BY engine ORDER BY engine"""
    ).fetchall()
    for eng, occurrences, clusters in unknown_rows:
        print(
            f"\nPeople Discovery [{eng}]: {int(occurrences)} unresolved occurrence(s), "
            f"{int(clusters)} cluster(s)"
        )

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
    known_hints = [
        {"index": 0, "name": "Hans", "confidence": 98},
        {"index": 1, "name": "Greta", "confidence": 97},
    ]
    check_that(
        _label_item_status(2, {}, known_hints, 95) == "full",
        "label queue: high-confidence known faces do not require more corpus labels",
    )
    check_that(
        _label_item_status(2, {}, known_hints[:1], 95) == "partial"
        and _label_item_status(2, {}, known_hints, 0) == "untagged",
        "label queue: unresolved faces remain visible and disabled auto-accept resolves nothing",
    )
    check_that(
        _face_name_rejected("Debbie Example", ["debbie example"])
        and _face_name_rejected("Jürgen Example", ["Juergen Example"])
        and not _face_name_rejected("Other Candidate", ["Debbie Example"]),
        "label queue: one rejected person is hidden without suppressing other candidates",
    )
    check_that(
        clamp_box_xywh([95, 75, 20, 20], 100, 80) == [95, 75, 5, 5]
        and clamp_box_xywh([110, 90, 20, 20], 100, 80) is None,
        "label queue: boxes are clipped to their detector image geometry",
    )

    # box packing: css (top,right,bottom,left) -> [x, y, w, h] for the server.
    top, right, bottom, left = 20, 90, 60, 30
    box = [int(left), int(top), int(right - left), int(bottom - top)]
    check_that(box == [30, 20, 60, 40], "box: css corners pack to [x, y, w, h]")

    # Browsers honor EXIF orientation. Detection must use those same displayed
    # pixels or rectangles from older phone photos are mirrored away from faces.
    try:
        from PIL import Image
        oriented = Image.new("RGB", (6, 4), (255, 0, 0))
        for x in range(3, 6):
            for y in range(2, 4):
                oriented.putpixel((x, y), (0, 0, 255))
        exif = Image.Exif()
        exif[274] = 3  # rotate 180 degrees for display
        encoded = io.BytesIO()
        oriented.save(encoded, format="JPEG", quality=100, subsampling=0, exif=exif)
        displayed = display_rgb_array(encoded.getvalue())
        check_that(
            displayed.shape[:2] == (4, 6)
            and int(displayed[0, 0, 2]) > int(displayed[0, 0, 0]),
            "image orientation: detector pixels match browser EXIF rotation",
        )
    except ImportError:
        check_that(False, "image orientation: Pillow is available")

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

        def unknown_photo(photo_id):
            return {
                "id": photo_id,
                "url": f"selftest://{photo_id}",
                "uploaded_at": "2026-08-08 01:00:00",
                "caption_context": {
                    "taken_at": "2026-07-04",
                    "events": ["Selftest Event"],
                },
            }

        def unknown_observation(value):
            return {
                "face_key": "i:0",
                "box": [10, 10, 20, 20],
                "image_width": 100,
                "image_height": 80,
                "vector": np.array([value, 0.0, 0.0], dtype=np.float32),
            }

        reconcile_unknown_photo(
            conn,
            backend.name,
            unknown_photo(601),
            [unknown_observation(0.0)],
        )
        reconcile_unknown_photo(
            conn,
            backend.name,
            unknown_photo(601),
            [unknown_observation(0.02)],
        )
        check_that(
            conn.execute(
                """SELECT COUNT(*) FROM unknown_faces
                   WHERE engine = ? AND photo_id = 601""",
                (backend.name,),
            ).fetchone()[0] == 1,
            "discovery db: reprocessing one engine/photo/face is idempotent",
        )
        reconcile_unknown_photo(
            conn,
            backend.name,
            unknown_photo(602),
            [unknown_observation(0.10)],
        )
        reconcile_unknown_photo(
            conn,
            backend.name,
            unknown_photo(603),
            [unknown_observation(0.20)],
        )
        first_clusters = cluster_unknown_faces(conn, backend, 0.15)
        second_clusters = cluster_unknown_faces(conn, backend, 0.15)
        sizes = sorted(len(ids) for _, ids in first_clusters)
        check_that(
            sizes == [1, 2],
            "discovery clustering: complete-link blocks transitive chain over-merging",
        )
        check_that(
            first_clusters == second_clusters,
            "discovery clustering: unchanged reruns keep stable cluster ids and members",
        )
        reconcile_unknown_photo(
            conn,
            "other-engine",
            unknown_photo(604),
            [unknown_observation(0.02)],
        )
        cluster_unknown_faces(conn, backend, 0.15)
        check_that(
            conn.execute(
                "SELECT cluster_id FROM unknown_faces WHERE engine = 'other-engine'"
            ).fetchone()[0] == "",
            "discovery clustering: another engine is never assigned into this engine's clusters",
        )
        rejected_unknown = unresolved_observations(
            {"rejected": ["Rejected Person"]},
            [((10, 30, 30, 10), np.zeros(3, dtype=np.float32))],
            {"Rejected Person": np.zeros((3, 3), dtype=np.float32)},
            backend,
            0.5,
            100,
            80,
        )
        check_that(
            len(rejected_unknown) == 1,
            "discovery capture: rejecting one known person leaves the face available as unknown",
        )
        corrected_known = unresolved_observations(
            {"labels": [{"name": "Corrected", "box": [10, 10, 20, 20]}]},
            [((10, 30, 30, 10), np.zeros(3, dtype=np.float32))],
            {},
            backend,
            0.5,
            100,
            80,
        )
        reconcile_unknown_photo(
            conn,
            backend.name,
            unknown_photo(601),
            corrected_known,
        )
        check_that(
            conn.execute(
                """SELECT COUNT(*) FROM unknown_faces
                   WHERE engine = ? AND photo_id = 601""",
                (backend.name,),
            ).fetchone()[0] == 0,
            "discovery capture: corrected explicit truth removes stale unknown observations",
        )
        with conn:
            conn.execute(
                """INSERT INTO unknown_dismissals (
                       engine, photo_id, face_key, dismissed_at
                   ) VALUES (?, 605, 'i:0', 1)""",
                (backend.name,),
            )
        reconcile_unknown_photo(
            conn,
            backend.name,
            unknown_photo(605),
            [unknown_observation(0.3)],
        )
        check_that(
            conn.execute(
                """SELECT COUNT(*) FROM unknown_faces
                   WHERE engine = ? AND photo_id = 605""",
                (backend.name,),
            ).fetchone()[0] == 0,
            "discovery db: locally dismissed detector mistakes stay dismissed",
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
        migrated_tables = {
            row[0]
            for row in conn.execute(
                "SELECT name FROM sqlite_master WHERE type = 'table'"
            )
        }
        check_that(
            {"unknown_faces", "unknown_clusters", "unknown_dismissals"}.issubset(migrated_tables),
            "db: legacy databases gain discovery tables without losing references",
        )
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
                "image_width": 100,
                "image_height": 80,
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
                and 'id="boxOpacity"' in page.text
                and 'id="zoomIn"' in page.text
                and 'id="panCenter"' in page.text
                and "setupViewControls()" in page.text,
                "label UI: side controls include box visibility, zoom, and pan",
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

    # People Discovery lifecycle: protected loopback board, local crop, reviewed
    # non-biometric write contract, and local resolution after acknowledgement.
    discovery_prev_db = globals()["DB_PATH"]
    discovery_open = globals()["_open_preview_html"]
    discovery_tmp = Path(tempfile.mkdtemp()) / "faces.db"
    discovery_conn = None
    discovery_thread = None
    discovery_opened = {}
    discovery_ready = threading.Event()
    discovery_posts = []

    class DiscoveryApi:
        def __init__(self, image_bytes):
            self.image_bytes = image_bytes

        def image(self, _url):
            return self.image_bytes

        def post(self, path, payload):
            if path != "/discover-label":
                raise RuntimeError(f"unexpected discovery path {path}")
            discovery_posts.append(payload)
            return {
                "ok": True,
                "applied": [
                    item["client_key"]
                    for item in payload.get("occurrences", [])
                ],
            }

    def capture_discovery_url(url):
        discovery_opened["url"] = url
        discovery_ready.set()

    try:
        from PIL import Image

        image = Image.new("RGB", (100, 80), (80, 120, 160))
        encoded = io.BytesIO()
        image.save(encoded, format="JPEG")
        globals()["DB_PATH"] = discovery_tmp
        discovery_conn = db()
        reconcile_unknown_photo(
            discovery_conn,
            backend.name,
            {
                "id": 701,
                "url": "selftest://701",
                "uploaded_at": "2026-08-08 01:00:00",
                "caption_context": {"taken_at": "2026-07-04"},
            },
            [{
                "face_key": "i:0",
                "box": [20, 15, 30, 30],
                "image_width": 100,
                "image_height": 80,
                "vector": np.array([0.0, 0.0, 0.0], dtype=np.float32),
            }],
        )
        cluster_unknown_faces(discovery_conn, backend, 0.15)
        globals()["_open_preview_html"] = capture_discovery_url

        def run_discovery_board():
            local_discovery_board(
                DiscoveryApi(encoded.getvalue()),
                discovery_conn,
                backend,
                0.15,
                ["Anna"],
            )

        discovery_thread = threading.Thread(target=run_discovery_board, daemon=True)
        discovery_thread.start()
        ready = discovery_ready.wait(3.0)
        check_that(ready, "discovery UI: loopback board starts")
        if ready:
            board_url = discovery_opened["url"]
            token = (parse_qs(urlparse(board_url).query).get("token") or [""])[0]
            base = board_url.split("/?token=", 1)[0]
            headers = {"X-GASF-Discovery-Token": token}
            denied = requests.get(base + "/api/meta", timeout=3)
            page = requests.get(board_url, timeout=3)
            check_that(
                denied.status_code == 403
                and page.status_code == 200
                and "One name applies to all selected faces" in page.text
                and "default-src 'self'" in page.headers.get("Content-Security-Policy", ""),
                "discovery UI: token guard, review warning, and restrictive CSP are active",
            )
            board_meta = requests.get(
                base + "/api/meta",
                headers=headers,
                timeout=3,
            ).json()
            occurrence_id = board_meta["clusters"][0]["occurrences"][0]["id"]
            crop = requests.get(
                base + f"/api/crop?id={occurrence_id}",
                headers=headers,
                timeout=3,
            )
            check_that(
                crop.status_code == 200
                and crop.headers.get("Content-Type") == "image/jpeg",
                "discovery UI: representative crops are generated locally",
            )
            named = requests.post(
                base + "/api/name",
                headers=headers,
                json={
                    "cluster": board_meta["clusters"][0]["id"],
                    "name": "Anna",
                    "selected": [occurrence_id],
                },
                timeout=3,
            ).json()
            sent_occurrence = discovery_posts[0]["occurrences"][0]
            check_that(
                named.get("applied") == 1
                and set(sent_occurrence) == {
                    "client_key",
                    "photo",
                    "box",
                    "image_width",
                    "image_height",
                }
                and "vector" not in json.dumps(discovery_posts[0]).lower(),
                "discovery UI: WordPress receives only reviewed non-biometric facts",
            )
            check_that(
                discovery_conn.execute(
                    "SELECT COUNT(*) FROM unknown_faces WHERE engine = ?",
                    (backend.name,),
                ).fetchone()[0] == 0,
                "discovery UI: acknowledged selections leave the local unknown queue",
            )
            requests.post(
                base + "/api/close",
                headers=headers,
                json={},
                timeout=3,
            )
            discovery_thread.join(timeout=4.0)
            check_that(
                not discovery_thread.is_alive(),
                "discovery UI: close stops the loopback server",
            )
    except (ImportError, requests.RequestException, KeyError, IndexError) as e:
        check_that(False, f"discovery UI: localhost lifecycle ({e})")
    finally:
        globals()["_open_preview_html"] = discovery_open
        globals()["DB_PATH"] = discovery_prev_db
        if discovery_conn is not None:
            discovery_conn.close()

    # Mature label flow resolves known faces before asking for human work, then
    # incorporates the new labels before the full face/caption scan.
    original_learn = globals()["learn"]
    original_scan = globals()["scan"]
    original_label = globals()["local_label"]
    flow_events = []
    try:
        globals()["learn"] = lambda *args, **kwargs: flow_events.append("learn")
        globals()["scan"] = lambda *args, **kwargs: flow_events.append(
            "scan-full" if kwargs.get("include_captions", True) else "scan-faces"
        )
        globals()["local_label"] = lambda *args, **kwargs: flow_events.append("label") or 2
        flow_stored = run_label_refinement(
            None, None, None, 0.5, {}, 500, "", "", verbose=False
        )
        check_that(
            flow_events == ["learn", "scan-faces", "label", "learn", "scan-full"]
            and flow_stored == 2,
            "label flow: learn and face-scan before labeling, then relearn and fully scan",
        )
    finally:
        globals()["learn"] = original_learn
        globals()["scan"] = original_scan
        globals()["local_label"] = original_label

    print("\n" + ("selftest passed." if not failures else f"selftest FAILED: {len(failures)} problem(s)."))
    return 0 if not failures else 1


# --------------------------------------------------------------------------- main


def run_label_refinement(
    api,
    conn,
    backend,
    tolerance,
    cfg,
    label_limit,
    uploaded_after="",
    uploaded_before="",
    verbose=True,
):
    """Resolve mature-corpus work first, then learn from the human remainder."""
    if verbose:
        print("label flow 1/5: learning new and corrected confirmed labels")
    learn(api, conn, backend, verbose)
    if verbose:
        print("label flow 2/5: face-scanning new photos before opening the labeler")
    scan(
        api,
        conn,
        backend,
        tolerance,
        cfg,
        verbose,
        uploaded_after,
        uploaded_before,
        include_captions=False,
    )
    if verbose:
        print("label flow 3/5: opening unresolved faces for human labeling")
    stored = local_label(
        api,
        conn,
        backend,
        tolerance,
        label_limit,
        uploaded_after,
        uploaded_before,
        label_flow=True,
    )
    if verbose:
        print(f"label flow 4/5: learning {stored} explicit label change(s)")
    learn(api, conn, backend, verbose)
    if verbose:
        print("label flow 5/5: final face and caption scan with refreshed references")
    scan(
        api,
        conn,
        backend,
        tolerance,
        cfg,
        verbose,
        uploaded_after,
        uploaded_before,
        include_captions=True,
    )
    return stored


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
                    help="with --label: learn/scan first, label unresolved faces, then relearn/rescan")
    ap.add_argument("--label-limit", type=int, default=500, metavar="N",
                    help="how many recent confirmed photos to load in --label mode (default: 500)")
    ap.add_argument("--discover", action="store_true",
                    help="prepare unknown faces and open the local People Discovery board")
    ap.add_argument("--discovery-limit", type=int, metavar="N",
                    help="how many recent library photos to prepare for --discover (default: config or 1000)")
    ap.add_argument("--watch", type=int, metavar="SECONDS", help="keep running, pausing this long between passes")
    ap.add_argument("--uploaded-after", metavar="YYYY-MM-DD",
                    help="only process photos uploaded on/after this date (scan, --label, and --discover)")
    ap.add_argument("--uploaded-before", metavar="YYYY-MM-DD",
                    help="only process photos uploaded on/before this date (scan, --label, and --discover)")
    ap.add_argument("--status", action="store_true", help="show what is known and what is waiting")
    ap.add_argument("--check", action="store_true", help="preflight: backend, config, database, and server")
    ap.add_argument("--selftest", action="store_true", help="exercise the non-ML plumbing; needs no backend or server")
    ap.add_argument("--engine", choices=["auto", "insightface", "face_recognition"],
                    help="override the recognition backend for this run")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()
    if args.discover and (args.label or args.label_flow or args.learn or args.watch):
        ap.error("--discover cannot be combined with --label, --label-flow, --learn, or --watch")

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

    if args.discover:
        discovery_limit = args.discovery_limit or cfg_discovery_limit(cfg)
        if discovery_limit < 1 or discovery_limit > 1000:
            sys.exit("--discovery-limit must be between 1 and 1000")
        threshold = cfg_discovery_tolerance(cfg, engine)
        run_discovery(
            api,
            conn,
            backend,
            tolerance,
            threshold,
            discovery_limit,
            uploaded_after,
            uploaded_before,
            verbose,
        )
        return

    if args.label:
        if verbose and (uploaded_after or uploaded_before):
            print(
                "label window: "
                f"{uploaded_after or 'start'} .. {uploaded_before or 'now'}"
            )
        if args.label_flow:
            stored = run_label_refinement(
                api,
                conn,
                backend,
                tolerance,
                cfg,
                args.label_limit,
                uploaded_after,
                uploaded_before,
                verbose,
            )
        else:
            stored = local_label(
                api,
                conn,
                backend,
                tolerance,
                args.label_limit,
                uploaded_after,
                uploaded_before,
                False,
            )
        if verbose:
            print(f"stored {stored} explicit face label(s)")
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
