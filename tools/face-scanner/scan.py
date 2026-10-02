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
import math
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

# The scanner's version. Bump it with every change to this folder, the same way
# the plugin header is bumped: it is the only way to tell from the scanning PC
# which copy is running. The launcher reads it from this line with a regex
# (without importing this file), so keep it a plain string literal on one line.
# Numbering starts at 1.4.0 so it never looks older than the "1.3" in
# USER_AGENT below -- which is NOT a version, just a string mod_security allows.
SCANNER_VERSION = "1.6.5"

# A browser-shaped User-Agent on purpose. The host (Bluehost) runs mod_security,
# which answers the default python-requests agent — and anything with "scanner"
# in it — with a 406 before WordPress ever sees the request. This UA gets
# through, and it is what --check must send too, or the doctor reports a healthy
# server as broken (or a broken one as fine).
USER_AGENT = "Mozilla/5.0 (compatible; GASF-CRM-FaceClient/1.3; +https://germantampabay.com)"

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

# Smallest group the discovery board will offer for naming.
#
# Tied to MIN_REFERENCES rather than picked, because it is the same number for
# the same reason: load_references() only trusts a person once they have this
# many examples, so naming a group smaller than it produces a person the matcher
# still cannot use. One click, no recognition. The board exists to grow the
# reference set, and below this it is not doing that.
MIN_DISCOVERY_CLUSTER = MIN_REFERENCES
# Crops the discovery board keeps in memory for the life of one board: at
# ~15 KB each, 1,200 is under 20 MB and covers a full 1,000-photo preparation.
DISCOVERY_CROP_CACHE = 1200
MAX_ACTIVE_REFERENCES = 12
MIN_ACTIVE_QUALITY = 0.28
ACTIVE_LEARNING_THRESHOLD = 0.45
ACTIVE_LEARNING_MAX = 100
CALIBRATION_TARGET_PRECISION = 0.99
CALIBRATION_MIN_SAMPLES = 30
CALIBRATION_WILSON_Z = 2.576
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


def cfg_calibration_target(cfg):
    raw = os.environ.get(
        "GASF_FACE_CALIBRATION_TARGET",
        cfg.get("calibration_target_precision", CALIBRATION_TARGET_PRECISION),
    )
    try:
        value = float(raw)
    except (TypeError, ValueError):
        sys.exit(f"calibration_target_precision must be a number, got {raw!r}")
    if value < 0.99 or value >= 1:
        sys.exit("calibration_target_precision must be at least 0.99 and less than 1")
    return value


def cfg_calibration_min_samples(cfg):
    raw = os.environ.get(
        "GASF_FACE_CALIBRATION_MIN_SAMPLES",
        cfg.get("calibration_min_samples", CALIBRATION_MIN_SAMPLES),
    )
    try:
        value = int(raw)
    except (TypeError, ValueError):
        sys.exit(f"calibration_min_samples must be an integer, got {raw!r}")
    return max(20, min(10000, value))


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
        # The key rides in headers only, never the query string — a key in the URL
        # is written into the server's access log. Two headers because shared
        # hosting often strips Authorization before PHP sees it; X-GASF-Faces-Key
        # is the reliable one and Authorization is kept for standard tooling. The
        # server accepts either (gasf_crm_faces_authed).
        self.s.headers["Authorization"] = "Bearer " + key
        self.s.headers["X-GASF-Faces-Key"] = key
        self.s.headers["User-Agent"] = USER_AGENT
        self.s.headers["Accept"] = "application/json"
        # Whether this key has EVER been accepted by this server on this run.
        # It is the difference between "you pasted the wrong key" and "something
        # changed underneath a working session", which are the same HTTP status
        # and want opposite answers.
        self._authed_once = False

    # How many times a refusal that looks like the HOST rather than the CRM is
    # tried again before giving up, and how long to wait between attempts.
    AUTH_RETRIES = 5
    AUTH_BACKOFF = 2.0

    # A transient status (RETRYABLE_HTTP) or a dropped connection: the shared
    # host having a bad minute, which every route rides out the same way.
    SOFT_RETRIES = 4
    SOFT_BACKOFF = 1.5

    def _auth_verdict(self, r, url):
        """
        Tell a wrong key from a working key that stopped working for a moment.

        The first version of this asked WHO was refusing: the CRM answering with
        its own JSON meant the key was not accepted and there was no point
        trying again, while an HTML page from the shared host meant mod_security
        or a rate limiter and was worth retrying.

        That was half the answer, and the missing half killed a labelling
        session seventy-seven photos in. The CRM really did refuse, with its own
        JSON, because the runtime suite had briefly revoked the live key to test
        that revoking works. The key was fine; it was fine before and fine two
        seconds later. Refusing to retry on the grounds that WordPress was the
        one saying no threw away a session over a gap measured in milliseconds -
        and told the volunteer to issue a new key, which would have broken the
        config that was working.

        So the question is not only who refused, but WHEN. A key that has never
        been accepted on this run is a key somebody typed wrongly: stop, and say
        so. A key that has been accepted and is now refused is something
        changing underneath a working session, and the right answer to that is
        to wait a moment and ask again.

        Returns "fatal", or "retry".
        """
        body = (r.text or "").strip()
        looks_ours = body.startswith("{") and ("gasf" in body or "rest_" in body)
        if looks_ours and not self._authed_once:
            return "fatal"
        return "retry"

    def _send(self, method, url, **kw):
        """
        One request, retried whenever the answer is not the caller's problem.

        Two kinds of "not yet", counted separately because they want different
        patience. A refusal (401/403/406) may be the key or may be a doorman,
        and _auth_verdict decides. A 503, a 500, a 429 or a dropped connection
        is the shared host being unwell, and the answer is always to wait a
        moment and ask again.

        The transient half used to live in image() alone. So fetching a photo
        rode out a 503, and posting the captions it produced did not: get() and
        post() went straight to raise_for_status, and a single 503 on the
        caption POST ended a run with a Python traceback after a hundred and
        ninety-seven photos had been scanned and sent. Same host, same weather,
        opposite handling - because the retry had been written where the bug
        was noticed rather than where it belonged.
        """
        last = ""
        auth_tries = 0
        soft_tries = 0
        while True:
            try:
                r = self.s.request(method, url, **kw)
            except requests.RequestException as e:
                # The connection itself, not an answer. Same patience.
                soft_tries += 1
                if soft_tries >= self.SOFT_RETRIES:
                    raise
                time.sleep(self.SOFT_BACKOFF * soft_tries)
                continue

            if r.status_code in RETRYABLE_HTTP:
                soft_tries += 1
                if soft_tries >= self.SOFT_RETRIES:
                    # Out of patience: hand back the real response so the caller
                    # raises with the server's own status and body rather than
                    # something this function invented.
                    return r
                time.sleep(self.SOFT_BACKOFF * soft_tries)
                continue

            if r.status_code not in (401, 403, 406):
                # Only a real answer counts as proof the key works. A 404 is the
                # route not matching, which happens before any key is looked at.
                if 200 <= r.status_code < 300:
                    self._authed_once = True
                return r

            auth_tries += 1
            attempt = auth_tries
            verdict = self._auth_verdict(r, url)
            snippet = " ".join((r.text or "").split())[:220]
            if verdict == "fatal":
                sys.exit(
                    "The server refused the key (HTTP %d).\n  %s\n"
                    "Issue a new key in wp-admin -> Email CRM -> Photos -> Face suggestions "
                    "and put it in config.json." % (r.status_code, snippet or "no message")
                )

            last = "HTTP %d from %s: %s" % (r.status_code, url, snippet or "no body")
            if attempt < self.AUTH_RETRIES:
                # Longer each time. A rate limiter wants to be left alone, and
                # hammering it is how a pause becomes a ban.
                time.sleep(self.AUTH_BACKOFF * attempt)
                continue
            break

        if self._authed_once:
            sys.exit(
                "The server stopped accepting the key part-way through this run: %s\n"
                "The key is almost certainly fine - it was accepted earlier in this "
                "same run. Do NOT issue a new one; that would break a config that "
                "works. This is what a key being reissued, or the runtime test suite "
                "running against the live site, looks like from here. Wait a moment "
                "and run again - work already saved is kept." % last
            )
        sys.exit(
            "The server kept refusing requests, and not because of the key: %s\n"
            "That is the host (mod_security or a rate limit), not WordPress. "
            "Wait a few minutes and run again - work already saved is kept." % last
        )

    def get(self, path, **params):
        r = self._send("GET", self.base + path, params=params, timeout=60)
        r.raise_for_status()
        return r.json()

    def post(self, path, payload):
        r = self._send("POST", self.base + path, json=payload, timeout=120)
        r.raise_for_status()
        return r.json()

    def image(self, url):
        parts = urlsplit(url)
        if not parts.scheme or not parts.netloc:
            raise RuntimeError(f"refusing non-absolute image URL: {url!r}")
        origin = (parts.scheme + "://" + parts.netloc).rstrip("/")
        if origin != self.origin:
            raise RuntimeError(f"refusing cross-origin image URL: {origin}")
        # No retry loop of its own any more: _send rides out transient statuses
        # and dropped connections for every route, which is what this loop used
        # to do here and nowhere else.
        r = self._send("GET", url, timeout=120)
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

        import contextlib

        providers = [p for p in ("CUDAExecutionProvider", "CPUExecutionProvider") if p in ort.get_available_providers()]
        use_cuda = "CUDAExecutionProvider" in providers
        self.device = "GPU" if use_cuda else "CPU"
        chatter = io.StringIO()
        try:
            with contextlib.redirect_stdout(chatter):
                self._app = FaceAnalysis(name="buffalo_l", providers=providers or ["CPUExecutionProvider"])
                self._app.prepare(ctx_id=0 if use_cuda else -1, det_size=(640, 640))
        except Exception:
            sys.stderr.write(chatter.getvalue())
            raise
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
# The club's names. Informally "the German-American Society"; formally "German-
# American Society Friendship of Pinellas County". "German-American Society of
# Tampa Bay" is NOT a name the club has ever had -- it came from this prompt,
# which put it in the model's mouth, and it then turned up in captions.
CLUB_NAME = "German-American Society"
CLUB_FORMAL_NAME = "German-American Society Friendship of Pinellas County"

CAPTION_SYSTEM = (
    f"You write factual archival descriptions for the {CLUB_NAME}. When a caption names "
    f"the club, call it \"the {CLUB_NAME}\". Never add a place to that name and never "
    "invent another name for the club. "
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


# Wrong names for the club that a model may still produce: "German-American
# Society of (the) Tampa Bay (Area)" and "Tampa Bay('s) German-American Society".
# The prompt says not to; this makes sure, because a small model sometimes
# repeats a plausible name from its training data regardless.
_WRONG_CLUB_NAMES = (
    re.compile(r"German[\s-]*American\s+Society\s+of\s+(?:the\s+)?(?:Greater\s+)?Tampa\s+Bay(?:\s+Area)?", re.I),
    re.compile(r"(?:Greater\s+)?Tampa\s+Bay(?:'s|\s+Area(?:'s)?)?\s+German[\s-]*American\s+Society", re.I),
)


def fix_club_name(text):
    """Replace any invented 'Tampa Bay' form of the club's name with the real one."""
    for pattern in _WRONG_CLUB_NAMES:
        text = pattern.sub(CLUB_NAME, text)
    return text


def _clean_caption(raw):
    text = fix_club_name(" ".join(str(raw or "").split()).strip().strip('"'))
    if len(text) < 8:
        raise ValueError("Ollama returned an empty or unusable caption")
    if len(text) > 420:
        text = text[:420].rsplit(" ", 1)[0].rstrip(" ,;:-")
    return text


def _caption_ready_bytes(image_bytes):
    """
    Hand Ollama only formats it can read: JPEG and PNG pass through, anything
    else Pillow can open is re-encoded as JPEG.

    Ollama 0.32 cannot decode WebP and answers 400 "Failed to load image or
    audio file". The library serves each photo's "large" size in whatever
    format it was uploaded, and phones and messaging apps increasingly upload
    WebP -- so every WebP photo failed captioning deterministically and was
    quarantined after three runs, while its faces (decoded by Pillow) were
    found perfectly well. Bytes Pillow cannot open go through unchanged, so
    Ollama's own error still reports a genuinely broken file.
    """
    if image_bytes[:3] == b"\xff\xd8\xff" or image_bytes[:8] == b"\x89PNG\r\n\x1a\n":
        return image_bytes
    try:
        from PIL import Image

        with Image.open(io.BytesIO(image_bytes)) as im:
            im.load()
            if im.mode in ("RGBA", "LA") or (im.mode == "P" and "transparency" in im.info):
                rgba = im.convert("RGBA")
                flat = Image.new("RGB", rgba.size, (255, 255, 255))
                flat.paste(rgba, mask=rgba.getchannel("A"))
            else:
                flat = im.convert("RGB")
            out = io.BytesIO()
            flat.save(out, "JPEG", quality=92)
            return out.getvalue()
    except Exception:
        return image_bytes


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
    image_b64 = base64.b64encode(_caption_ready_bytes(image_bytes)).decode("ascii")
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
               face_width INTEGER NOT NULL DEFAULT 0,
               face_height INTEGER NOT NULL DEFAULT 0,
               sharpness REAL NOT NULL DEFAULT 0,
               clipping REAL NOT NULL DEFAULT 0,
               quality REAL NOT NULL DEFAULT 0.5,
               redundancy REAL NOT NULL DEFAULT 0,
               active INTEGER NOT NULL DEFAULT 1,
               captured_at TEXT NOT NULL DEFAULT '',
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
    # What was detected on each photo, so the naming page and People Discovery
    # do not re-download and re-detect every photo on every run. Keyed by the
    # server's image_rev as well as the photo, so a cropped or rotated photo is
    # a miss rather than old boxes on a new picture. Local only, like every
    # other vector in this file.
    conn.execute(
        """CREATE TABLE IF NOT EXISTS face_detections (
               photo_id INTEGER NOT NULL,
               engine TEXT NOT NULL,
               image_rev TEXT NOT NULL,
               width INTEGER NOT NULL,
               height INTEGER NOT NULL,
               faces TEXT NOT NULL,
               thumb TEXT NOT NULL DEFAULT '',
               stored_at INTEGER NOT NULL,
               PRIMARY KEY (photo_id, engine)
           )"""
    )
    conn.execute(
        """CREATE TABLE IF NOT EXISTS unknown_dismissals (
               id INTEGER PRIMARY KEY,
               engine TEXT NOT NULL,
               photo_id INTEGER NOT NULL,
               box_x INTEGER NOT NULL,
               box_y INTEGER NOT NULL,
               box_w INTEGER NOT NULL,
               box_h INTEGER NOT NULL,
               vector BLOB NOT NULL,
               dismissed_at INTEGER NOT NULL,
               UNIQUE(engine, photo_id, box_x, box_y, box_w, box_h)
           )"""
    )
    dismissal_cols = {
        row[1] for row in conn.execute("PRAGMA table_info(unknown_dismissals)")
    }
    dismissal_required = {
        "id", "engine", "photo_id", "box_x", "box_y",
        "box_w", "box_h", "vector", "dismissed_at",
    }
    if not dismissal_required.issubset(dismissal_cols):
        # Ordinal-only dismissals cannot be mapped safely after detector order
        # changes. Forget them rather than risk suppressing a different face.
        conn.execute("DROP TABLE unknown_dismissals")
        conn.execute(
            """CREATE TABLE unknown_dismissals (
                   id INTEGER PRIMARY KEY,
                   engine TEXT NOT NULL,
                   photo_id INTEGER NOT NULL,
                   box_x INTEGER NOT NULL,
                   box_y INTEGER NOT NULL,
                   box_w INTEGER NOT NULL,
                   box_h INTEGER NOT NULL,
                   vector BLOB NOT NULL,
                   dismissed_at INTEGER NOT NULL,
                   UNIQUE(engine, photo_id, box_x, box_y, box_w, box_h)
               )"""
        )

    cols = {row[1] for row in conn.execute("PRAGMA table_info(refs)")}
    if "engine" not in cols:  # a database written before backends existed
        conn.execute("ALTER TABLE refs ADD COLUMN engine TEXT NOT NULL DEFAULT ''")
        conn.execute("UPDATE refs SET engine = 'face_recognition:dlib-hog' WHERE engine = ''")
    if "face_key" not in cols:
        conn.execute("ALTER TABLE refs ADD COLUMN face_key TEXT NOT NULL DEFAULT '0'")
        conn.execute("UPDATE refs SET face_key = '0' WHERE face_key IS NULL OR face_key = ''")
    ref_additions = {
        "face_width": "INTEGER NOT NULL DEFAULT 0",
        "face_height": "INTEGER NOT NULL DEFAULT 0",
        "sharpness": "REAL NOT NULL DEFAULT 0",
        "clipping": "REAL NOT NULL DEFAULT 0",
        "quality": "REAL NOT NULL DEFAULT 0.5",
        "redundancy": "REAL NOT NULL DEFAULT 0",
        "active": "INTEGER NOT NULL DEFAULT 1",
        "captured_at": "TEXT NOT NULL DEFAULT ''",
        # A small JPEG crop of this reference face, so the labeler can show WHO
        # it is suggesting rather than only spelling the name. Local-only, like
        # the vector beside it: a face crop is more recognisable than a vector,
        # not less, so it lives in exactly the same place and travels no further.
        # Empty on rows written before this existed; those fill in as they relearn.
        "thumb": "BLOB",
        # 1 once quality was measured, 2 once measuring was tried and could not
        # be done (photo gone or changed), 0 for rows still at the 0.5 default
        # the quality column was added with -- see backfill_reference_quality.
        "quality_measured": "INTEGER NOT NULL DEFAULT 0",
    }
    cols = {row[1] for row in conn.execute("PRAGMA table_info(refs)")}
    for column, declaration in ref_additions.items():
        if column not in cols:
            conn.execute(f"ALTER TABLE refs ADD COLUMN {column} {declaration}")

    if not _has_unique_photo_engine_face(conn):
        conn.execute("DROP TABLE IF EXISTS refs_new")
        conn.execute(
            """CREATE TABLE refs_new (
                  id INTEGER PRIMARY KEY,
                  person TEXT NOT NULL,
                  photo_id INTEGER NOT NULL,
                  engine TEXT NOT NULL DEFAULT '',
                  face_key TEXT NOT NULL DEFAULT '0',
                  face_width INTEGER NOT NULL DEFAULT 0,
                  face_height INTEGER NOT NULL DEFAULT 0,
                  sharpness REAL NOT NULL DEFAULT 0,
                  clipping REAL NOT NULL DEFAULT 0,
                  quality REAL NOT NULL DEFAULT 0.5,
                  redundancy REAL NOT NULL DEFAULT 0,
                  active INTEGER NOT NULL DEFAULT 1,
                  captured_at TEXT NOT NULL DEFAULT '',
                  thumb BLOB,
                  quality_measured INTEGER NOT NULL DEFAULT 0,
                  vector BLOB NOT NULL,
                  UNIQUE(photo_id, engine, face_key)
               )"""
        )
        # Every column the ALTERs above just added has to be declared and
        # copied here too. thumb and quality_measured were not: the rebuild
        # dropped them moments after they were created, and the first write of
        # that run failed on "no such column: thumb". Add a column to
        # ref_additions and it belongs in this statement as well - the selftest
        # migrates a database of this shape and then uses it, to say so.
        conn.execute(
            """INSERT INTO refs_new (
                   id, person, photo_id, engine, face_key,
                   face_width, face_height, sharpness, clipping,
                   quality, redundancy, active, captured_at,
                   thumb, quality_measured, vector
               )
               SELECT r.id, r.person, r.photo_id, r.engine,
                      COALESCE(NULLIF(r.face_key, ''), '0'),
                      r.face_width, r.face_height, r.sharpness, r.clipping,
                      r.quality, r.redundancy, r.active, r.captured_at,
                      r.thumb, r.quality_measured, r.vector
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
        "CREATE INDEX IF NOT EXISTS refs_active_person "
        "ON refs(engine, person, active, quality DESC, id)"
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS unknown_faces_engine_cluster "
        "ON unknown_faces(engine, cluster_id, id)"
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS unknown_faces_photo "
        "ON unknown_faces(engine, photo_id)"
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS unknown_dismissals_photo "
        "ON unknown_dismissals(engine, photo_id)"
    )
    selection_version = "2"
    current_selection_version = conn.execute(
        "SELECT v FROM state WHERE k = 'reference_selection_version'"
    ).fetchone()
    if not current_selection_version or current_selection_version[0] != selection_version:
        refresh_reference_selection(conn)
        conn.execute(
            """INSERT INTO state (k, v) VALUES ('reference_selection_version', ?)
               ON CONFLICT(k) DO UPDATE SET v = excluded.v""",
            (selection_version,),
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


# How big a stored reference thumbnail is, and how much room to leave around
# the detector's box. A box cropped tight to the jaw is oddly hard to recognise
# - the hair and chin are most of what a person recognises somebody by - so the
# crop is widened before it is scaled down.
THUMB_PX = 112
THUMB_PAD = 0.35


def reference_thumb(display_pixels, box_css, size=THUMB_PX):
    """
    A small JPEG of one reference face, so the labeler can show WHO it means.

    Never leaves this machine. It is written beside the vector in faces.db and
    served only to the loopback labelling board; the wire back to WordPress
    still carries nothing but an id, a rectangle, a name, and a confidence.

    Returns None rather than raising: a missing thumbnail costs a picture next
    to a name, and is not worth failing a learn pass over.
    """
    try:
        from PIL import Image

        height, width = display_pixels.shape[:2]
        raw = css_box_to_xywh(box_css)
        box = clamp_box_xywh(raw, width, height)
        if box is None:
            return None
        x, y, bw, bh = box
        pad_x = int(round(bw * THUMB_PAD))
        pad_y = int(round(bh * THUMB_PAD))
        x0 = max(0, x - pad_x)
        y0 = max(0, y - pad_y)
        x1 = min(width, x + bw + pad_x)
        y1 = min(height, y + bh + pad_y)
        if x1 - x0 < 8 or y1 - y0 < 8:
            return None

        crop = np.asarray(display_pixels[y0:y1, x0:x1], dtype=np.uint8)
        if crop.ndim == 2:
            image = Image.fromarray(crop, mode="L").convert("RGB")
        else:
            image = Image.fromarray(crop[..., :3], mode="RGB")
        image.thumbnail((int(size), int(size)), Image.LANCZOS)
        buffer = io.BytesIO()
        image.save(buffer, format="JPEG", quality=82, optimize=True)
        return buffer.getvalue()
    except Exception:
        return None


def reference_quality(display_pixels, box_css):
    """Objective local crop quality from size, sharpness, and edge clipping."""
    height, width = display_pixels.shape[:2]
    raw = css_box_to_xywh(box_css)
    box = clamp_box_xywh(raw, width, height)
    if box is None:
        return {
            "face_width": 0,
            "face_height": 0,
            "sharpness": 0.0,
            "clipping": 1.0,
            "quality": 0.0,
        }
    x, y, face_width, face_height = box
    crop = np.asarray(
        display_pixels[y:y + face_height, x:x + face_width],
        dtype=np.float32,
    )
    if crop.ndim == 3:
        gray = np.dot(crop[..., :3], np.array([0.299, 0.587, 0.114], dtype=np.float32))
    else:
        gray = crop
    if gray.shape[0] >= 3 and gray.shape[1] >= 3:
        laplacian = (
            -4.0 * gray[1:-1, 1:-1]
            + gray[:-2, 1:-1]
            + gray[2:, 1:-1]
            + gray[1:-1, :-2]
            + gray[1:-1, 2:]
        )
        lap_variance = float(np.var(laplacian))
    else:
        lap_variance = 0.0
    sharpness = 1.0 - math.exp(-max(0.0, lap_variance) / 400.0)
    size_score = max(0.0, min(1.0, (min(face_width, face_height) - 24.0) / 136.0))
    edge_margin = max(2, int(round(min(width, height) * 0.003)))
    contacts = sum(
        (
            raw[0] <= edge_margin,
            raw[1] <= edge_margin,
            raw[0] + raw[2] >= width - edge_margin,
            raw[1] + raw[3] >= height - edge_margin,
        )
    )
    clipping = contacts / 4.0
    quality = 0.45 * size_score + 0.35 * sharpness + 0.20 * (1.0 - clipping)
    return {
        "face_width": int(face_width),
        "face_height": int(face_height),
        "sharpness": round(sharpness, 6),
        "clipping": round(clipping, 6),
        "quality": round(max(0.0, min(1.0, quality)), 6),
    }


def _reference_distances(engine, matrix, vector):
    matrix = np.asarray(matrix, dtype=np.float32)
    vector = np.asarray(vector, dtype=np.float32)
    if str(engine).startswith("insightface"):
        matrix_norms = np.linalg.norm(matrix, axis=1)
        vector_norm = float(np.linalg.norm(vector))
        denom = matrix_norms * vector_norm
        similarities = np.divide(
            matrix @ vector,
            denom,
            out=np.zeros(matrix.shape[0], dtype=np.float32),
            where=denom > 0,
        )
        return 1.0 - similarities
    return np.linalg.norm(matrix - vector, axis=1)


def _reference_distance_scale(engine):
    return 0.50 if str(engine).startswith("insightface") else 0.60


def _reference_duplicate_threshold(engine):
    return 0.035 if str(engine).startswith("insightface") else 0.08


def _captured_year(raw):
    match = re.match(r"^\s*(\d{4})", str(raw or ""))
    return int(match.group(1)) if match else 0


# How many photos' worth of old references to re-measure per learning run.
# Each is one download and one detection; 20 keeps a run's extra time small.
QUALITY_BACKFILL_PHOTOS = 20
# Same photo, same engine: the stored vector and a fresh one are near-identical.
# Anything further apart means the picture changed, and the row is left alone.
QUALITY_BACKFILL_MATCH = 0.15


def apply_retired_names(api, conn, verbose=True):
    """
    Refile local examples learned under a name that has since been merged or
    renamed, and drop the ones whose name was removed.

    The website records every retired name (v2.58.0) and sends the list with
    the people. Without this the scanner kept 20 examples of a merged-away typo
    and went on suggesting it: only photos the site flags as changed are ever
    relearned, and most of those examples came from photos nobody touched.
    """
    try:
        data = api.get("/people")
    except Exception:
        return 0
    retired = data.get("retired") if isinstance(data, dict) else None
    if not retired:
        return 0
    moved = dropped = 0
    touched = set()
    for row in retired:
        old = str((row or {}).get("from") or "").strip()
        new = str((row or {}).get("to") or "").strip()
        if not old or old.casefold() == new.casefold():
            continue
        if new:
            n = conn.execute(
                "UPDATE refs SET person = ? WHERE person = ? COLLATE NOCASE", (new, old)
            ).rowcount
            moved += n
            if n:
                touched.add(new)
        else:
            dropped += conn.execute(
                "DELETE FROM refs WHERE person = ? COLLATE NOCASE", (old,)
            ).rowcount
    conn.commit()
    if touched or dropped:
        refresh_reference_selection(conn, None, touched or None)
        # The reselection writes and does not commit. Left to whatever committed
        # next, a run that stopped here kept the refiled names and lost which of
        # each person's examples the matcher should use.
        conn.commit()
    if verbose and (moved or dropped):
        print(
            f"retired names: {moved} example(s) refiled under the current spelling"
            + (f", {dropped} dropped for removed names" if dropped else ""),
            flush=True,
        )
    return moved + dropped


def _http_gone(exc):
    """Did the server say this thing is not there, as opposed to not answering?"""
    response = getattr(exc, "response", None)
    return getattr(response, "status_code", None) in (404, 410)


def backfill_reference_quality(api, conn, backend, limit=QUALITY_BACKFILL_PHOTOS, verbose=True):
    """
    Re-measure reference faces still carrying the 0.5 placeholder quality.

    Quality (size, sharpness, clipping) decides which of a person's examples
    the matcher actually uses. Rows learned before quality existed were given a
    flat 0.5 and only get a real score if their photo happens to be relearned,
    so the good and the poor ones among them compete as equals. A few photos
    per run fixes that gradually. The face is found again by its own vector,
    not by box, so a crop or rotate since cannot mismatch it: no close match,
    and the row is marked 2 (tried, could not) instead of guessed at.

    Returns (rows measured, rows that could not be, rows still waiting).
    """
    # Rows written with a real score since quality existed are measured already.
    conn.execute(
        "UPDATE refs SET quality_measured = 1 WHERE quality_measured = 0 AND ABS(quality - 0.5) > 1e-9"
    )
    conn.commit()
    photo_ids = [
        int(r[0]) for r in conn.execute(
            """SELECT DISTINCT photo_id FROM refs
                WHERE engine = ? AND quality_measured = 0
                ORDER BY photo_id LIMIT ?""",
            (backend.name, int(limit)),
        )
    ]
    measured = failed = skipped = 0
    touched = set()
    for photo_id in photo_ids:
        rows = conn.execute(
            "SELECT id, person, vector FROM refs WHERE engine = ? AND photo_id = ? AND quality_measured = 0",
            (backend.name, photo_id),
        ).fetchall()
        # Fetching and measuring fail for different reasons and mean different
        # things. A timeout or a 503 says nothing about the photo, and marking
        # its faces "tried, could not" on the strength of one - which is what a
        # single except around both used to do - took them out of the queue for
        # good, because only rows still at 0 are ever looked at again. Those
        # rows are left alone and come round next run. A photo the server says
        # is gone, or one that will not decode, really cannot be measured.
        try:
            raw = api.image(f"{api.base}/image?photo={photo_id}")
        except requests.RequestException as e:
            if not _http_gone(e):
                skipped += 1
                continue
            raw = None
        except Exception:
            raw = None
        try:
            pixels = display_rgb_array(raw) if raw is not None else None
            found = backend.embed_rgb(pixels) if pixels is not None else []
        except Exception:
            found, pixels = [], None
        for ref_id, person, blob in rows:
            vec = np.frombuffer(blob, dtype=np.float32)
            best = None
            if found and pixels is not None and len(vec) == backend.dim:
                matrix = np.vstack([np.asarray(v, dtype=np.float32) for _, v in found])
                dists = backend.distances(matrix, vec)
                i = int(np.argmin(dists))
                if float(dists[i]) <= QUALITY_BACKFILL_MATCH:
                    best = found[i][0]
            if best is None:
                conn.execute("UPDATE refs SET quality_measured = 2 WHERE id = ?", (ref_id,))
                failed += 1
                continue
            m = reference_quality(pixels, best)
            conn.execute(
                """UPDATE refs SET face_width = ?, face_height = ?, sharpness = ?, clipping = ?,
                                   quality = ?, quality_measured = 1
                    WHERE id = ?""",
                (int(m["face_width"]), int(m["face_height"]), float(m["sharpness"]),
                 float(m["clipping"]), float(m["quality"]), ref_id),
            )
            measured += 1
            touched.add(person)
        conn.commit()
    if touched:
        refresh_reference_selection(conn, backend.name, touched)
        conn.commit()  # with the measurements it was made from - see apply_retired_names
    waiting = int(conn.execute(
        "SELECT COUNT(*) FROM refs WHERE engine = ? AND quality_measured = 0", (backend.name,)
    ).fetchone()[0])
    if verbose and (measured or failed or skipped):
        print(
            f"re-measured quality for {measured} older reference face(s)"
            + (f", {failed} could not be (photo gone or changed)" if failed else "")
            + (f", {skipped} photo(s) could not be fetched and will be tried again" if skipped else "")
            + f"; {waiting} still waiting",
            flush=True,
        )
    return measured, failed, waiting


def refresh_reference_selection(conn, engine=None, people=None):
    """Select a deterministic, bounded, quality-first diverse subset per person."""
    conditions = []
    params = []
    if engine:
        conditions.append("engine = ?")
        params.append(engine)
    if people is not None:
        clean_people = sorted({str(person) for person in people if str(person)})
        if not clean_people:
            return
        conditions.append("person IN (" + ",".join("?" for _ in clean_people) + ")")
        params.extend(clean_people)
    where = (" WHERE " + " AND ".join(conditions)) if conditions else ""
    groups = conn.execute(
        f"SELECT DISTINCT engine, person FROM refs{where} ORDER BY engine, person",
        params,
    ).fetchall()
    for group_engine, person in groups:
        rows = conn.execute(
            """SELECT id, photo_id, quality, captured_at, vector
               FROM refs WHERE engine = ? AND person = ? ORDER BY id""",
            (group_engine, person),
        ).fetchall()
        usable = []
        for row_id, photo_id, quality, captured_at, blob in rows:
            vector = np.frombuffer(blob, dtype=np.float32)
            if vector.size < 1 or not np.all(np.isfinite(vector)):
                continue
            usable.append(
                {
                    "id": int(row_id),
                    "photo_id": int(photo_id),
                    "quality": max(0.0, min(1.0, float(quality))),
                    "captured_at": str(captured_at or ""),
                    "vector": vector,
                }
            )
        dimension_counts = {}
        for candidate in usable:
            dimension = int(candidate["vector"].size)
            dimension_counts[dimension] = dimension_counts.get(dimension, 0) + 1
        if dimension_counts:
            selected_dimension = max(
                dimension_counts,
                key=lambda dimension: (dimension_counts[dimension], dimension),
            )
            usable = [
                candidate
                for candidate in usable
                if int(candidate["vector"].size) == selected_dimension
            ]
        redundancy = {}
        for candidate in usable:
            others = [row["vector"] for row in usable if row["id"] != candidate["id"]]
            redundancy[candidate["id"]] = (
                float(np.min(_reference_distances(group_engine, np.vstack(others), candidate["vector"])))
                if others
                else 1.0
            )
        selected = []
        remaining = list(usable)
        duplicate_threshold = _reference_duplicate_threshold(group_engine)
        distance_scale = _reference_distance_scale(group_engine)
        while remaining and len(selected) < MAX_ACTIVE_REFERENCES:
            candidates = []
            selected_matrix = (
                np.vstack([row["vector"] for row in selected]) if selected else None
            )
            selected_years = {_captured_year(row["captured_at"]) for row in selected}
            for candidate in remaining:
                nearest = (
                    float(np.min(_reference_distances(
                        group_engine,
                        selected_matrix,
                        candidate["vector"],
                    )))
                    if selected_matrix is not None
                    else distance_scale
                )
                year = _captured_year(candidate["captured_at"])
                era_bonus = 1.0 if year and year not in selected_years else 0.0
                diversity = min(1.0, nearest / distance_scale)
                score = 0.65 * candidate["quality"] + 0.30 * diversity + 0.05 * era_bonus
                candidates.append(
                    (
                        score,
                        candidate["quality"],
                        diversity,
                        -candidate["photo_id"],
                        -candidate["id"],
                        nearest,
                        candidate,
                    )
                )
            best = max(candidates)
            remaining.remove(best[-1])
            nearest = best[-2]
            candidate = best[-1]
            enough = len(selected) >= min(MIN_REFERENCES, len(usable))
            duplicate = nearest <= duplicate_threshold
            represented_era = (
                _captured_year(candidate["captured_at"]) in
                {_captured_year(row["captured_at"]) for row in selected}
            )
            if enough and (
                candidate["quality"] < MIN_ACTIVE_QUALITY
                or (duplicate and represented_era)
            ):
                continue
            selected.append(candidate)
        active_ids = {row["id"] for row in selected}
        conn.execute(
            "UPDATE refs SET active = 0 WHERE engine = ? AND person = ?",
            (group_engine, person),
        )
        for candidate in usable:
            conn.execute(
                "UPDATE refs SET active = ?, redundancy = ? WHERE id = ?",
                (
                    1 if candidate["id"] in active_ids else 0,
                    round(redundancy[candidate["id"]], 6),
                    candidate["id"],
                ),
            )


def replace_photo_references(conn, photo_id, engine, references):
    """Make one photo's vectors exactly match its current confirmed truth."""
    rows = list(references)
    old_count = int(
        conn.execute(
            "SELECT COUNT(*) FROM refs WHERE photo_id = ? AND engine = ?",
            (photo_id, engine),
        ).fetchone()[0]
    )
    old_people = {
        row[0]
        for row in conn.execute(
            "SELECT DISTINCT person FROM refs WHERE photo_id = ? AND engine = ?",
            (photo_id, engine),
        )
    }
    prepared = []
    for row in rows:
        person, face_key, vector = row[:3]
        metrics = row[3] if len(row) > 3 and isinstance(row[3], dict) else {}
        prepared.append(
            (
                person,
                photo_id,
                engine,
                face_key,
                max(0, int(metrics.get("face_width", 0))),
                max(0, int(metrics.get("face_height", 0))),
                max(0.0, min(1.0, float(metrics.get("sharpness", 0)))),
                max(0.0, min(1.0, float(metrics.get("clipping", 0)))),
                max(0.0, min(1.0, float(metrics.get("quality", 0.5)))),
                str(metrics.get("captured_at") or "")[:40],
                np.asarray(vector, dtype=np.float32).tobytes(),
                # Optional: rows learned before thumbnails existed simply carry
                # None and fill in the next time that photo is relearned.
                metrics.get("thumb") if isinstance(metrics.get("thumb"), (bytes, bytearray)) else None,
            )
        )
    with conn:
        conn.execute(
            "DELETE FROM refs WHERE photo_id = ? AND engine = ?",
            (photo_id, engine),
        )
        conn.executemany(
            """INSERT INTO refs (
                   person, photo_id, engine, face_key,
                   face_width, face_height, sharpness, clipping,
                   quality, captured_at, vector, thumb
               ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            prepared,
        )
        refresh_reference_selection(
            conn,
            engine,
            old_people.union({row[0] for row in prepared}),
        )
    return old_count, len(rows)


def best_face_thumb(conn, engine, person):
    """
    The best stored picture of one person, for showing beside their name.

    "Best" is the quality the learner already scored every reference on — size,
    sharpness, and how much of the face ran off the edge — restricted to the
    references actually in use for this engine. That is the same ranking the
    matcher trusts, so the face a volunteer is shown is the face the machine is
    reasoning from, which is the honest thing to put next to a percentage.

    Returns None when the person has no thumbnail yet: references learned
    before thumbnails existed carry none until that photo is relearned, and a
    name with no picture beside it is the old behaviour, not a failure.
    """
    row = conn.execute(
        """SELECT thumb FROM refs
           WHERE engine = ? AND person = ? AND active = 1 AND thumb IS NOT NULL
           ORDER BY quality DESC, face_width DESC, id
           LIMIT 1""",
        (engine, person),
    ).fetchone()
    if row and row[0]:
        return bytes(row[0])
    return None


def load_references(conn, engine):
    """person -> stacked vectors for this engine, for people with enough
    examples to trust. Vectors from other engines are invisible here."""
    vectors = {}
    for person, blob in conn.execute(
        """SELECT person, vector FROM refs
           WHERE engine = ? AND active = 1
           ORDER BY person, quality DESC, id""",
        (engine,),
    ):
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


def _observation_is_dismissed(conn, backend, photo_id, observation, threshold):
    rows = conn.execute(
        """SELECT box_x, box_y, box_w, box_h, vector
           FROM unknown_dismissals
           WHERE engine = ? AND photo_id = ?""",
        (backend.name, photo_id),
    ).fetchall()
    if not rows:
        return False
    vector = np.asarray(observation["vector"], dtype=np.float32)
    for x, y, width, height, blob in rows:
        if box_iou_xywh(observation["box"], [x, y, width, height]) < 0.35:
            continue
        dismissed = np.frombuffer(blob, dtype=np.float32)
        if dismissed.shape != vector.shape:
            continue
        distance = float(backend.distances(dismissed.reshape(1, -1), vector)[0])
        if distance <= threshold:
            return True
    return False


def reconcile_unknown_photo(conn, backend, photo, observations, dismissal_threshold):
    """Make one photo's unresolved local observations match the latest detection."""
    photo_id = int(photo["id"])
    engine = backend.name
    metadata = _photo_context(photo)
    rows = []
    for observation in observations:
        if _observation_is_dismissed(
            conn,
            backend,
            photo_id,
            observation,
            dismissal_threshold,
        ):
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

    explicit = {
        box_index
        for _, box_index, _, _ in match_labels_to_boxes(photo.get("labels") or [], boxes)
    }

    one_face_truth = len(boxes) == 1 and len(
        [name for name in (photo.get("people") or []) if str(name).strip()]
    ) == 1
    rejected = photo.get("rejected") or []
    ignored = photo.get("ignored") or []
    unknown = []
    for index, (box, vector) in enumerate(zip(boxes, vectors)):
        # Put down for good by a volunteer: a poster, a reflection, a stranger
        # in the background, or something that was never a face. Checked before
        # anything else, because this is the one answer that ends the question -
        # and note that rejecting a NAME does the opposite, sending the face
        # back into this pile every scan, which is why this exists.
        if _face_box_ignored(box, image_width, image_height, ignored):
            continue
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


def cluster_unknown_faces(conn, backend, threshold, observation_ids=None):
    """Deterministic complete-link clustering, isolated to one recognition engine."""
    scope_ids = sorted({int(value) for value in (observation_ids or []) if int(value) > 0})
    if observation_ids is not None and not scope_ids:
        return []
    scope_sql = ""
    params = [backend.name]
    if observation_ids is not None:
        scope_sql = " AND id IN (" + ",".join("?" for _ in scope_ids) + ")"
        params.extend(scope_ids)
    raw = conn.execute(
        f"""SELECT id, vector FROM unknown_faces
            WHERE engine = ?{scope_sql} ORDER BY id ASC""",
        params,
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
        if observation_ids is None:
            conn.execute(
                "UPDATE unknown_faces SET cluster_id = '' WHERE engine = ?",
                (backend.name,),
            )
            conn.execute(
                "DELETE FROM unknown_clusters WHERE engine = ?",
                (backend.name,),
            )
        else:
            marks = ",".join("?" for _ in scope_ids)
            old_cluster_ids = [
                row[0]
                for row in conn.execute(
                    f"""SELECT DISTINCT cluster_id FROM unknown_faces
                        WHERE engine = ? AND id IN ({marks}) AND cluster_id <> ''""",
                    (backend.name, *scope_ids),
                )
            ]
            if old_cluster_ids:
                old_marks = ",".join("?" for _ in old_cluster_ids)
                conn.execute(
                    f"""UPDATE unknown_faces SET cluster_id = ''
                        WHERE engine = ? AND cluster_id IN ({old_marks})""",
                    (backend.name, *old_cluster_ids),
                )
                conn.execute(
                    f"DELETE FROM unknown_clusters WHERE cluster_id IN ({old_marks})",
                    old_cluster_ids,
                )
            conn.execute(
                f"UPDATE unknown_faces SET cluster_id = '' WHERE id IN ({marks})",
                scope_ids,
            )
        for cluster in clusters:
            anchor = int(cluster[0]["id"])
            digest = hashlib.sha256(f"{backend.name}:{anchor}".encode("utf-8")).hexdigest()[:16]
            cluster_id = f"unknown-{digest}"
            member_ids = [int(member["id"]) for member in cluster]
            conn.execute(
                """INSERT INTO unknown_clusters (
                       cluster_id, engine, anchor_unknown_id, member_count, updated_at
                   ) VALUES (?, ?, ?, ?, ?)
                   ON CONFLICT(cluster_id) DO UPDATE SET
                       engine = excluded.engine,
                       anchor_unknown_id = excluded.anchor_unknown_id,
                       member_count = excluded.member_count,
                       updated_at = excluded.updated_at""",
                (cluster_id, backend.name, anchor, len(member_ids), now),
            )
            marks = ",".join("?" for _ in member_ids)
            conn.execute(
                f"UPDATE unknown_faces SET cluster_id = ? WHERE id IN ({marks})",
                (cluster_id, *member_ids),
            )
            assigned.append((cluster_id, member_ids))
    return assigned


def delete_unknown_observations(conn, observation_ids):
    ids = sorted({int(value) for value in observation_ids if int(value) > 0})
    if not ids:
        return 0
    marks = ",".join("?" for _ in ids)
    cluster_ids = [
        row[0]
        for row in conn.execute(
            f"""SELECT DISTINCT cluster_id FROM unknown_faces
                WHERE id IN ({marks}) AND cluster_id <> ''""",
            ids,
        )
    ]
    with conn:
        conn.execute(
            f"DELETE FROM unknown_faces WHERE id IN ({marks})",
            ids,
        )
        if cluster_ids:
            cluster_marks = ",".join("?" for _ in cluster_ids)
            conn.execute(
                f"UPDATE unknown_faces SET cluster_id = '' WHERE cluster_id IN ({cluster_marks})",
                cluster_ids,
            )
            conn.execute(
                f"DELETE FROM unknown_clusters WHERE cluster_id IN ({cluster_marks})",
                cluster_ids,
            )
    return len(ids)


# --------------------------------------------------------------------------- identify


def confidence(distance, tolerance):
    """A readable rendering of distance, not a probability. The volunteer sees a
    percentage and it should move the way intuition expects: ~1 at a perfect
    match, ~0.5 right at the tolerance, and nothing past it is offered at all.
    Returned as a 0..1 float; the server rounds it to a whole percent on store."""
    return round(max(0.0, 1.0 - (distance / tolerance)) * 0.5 + 0.5, 3)


def identify(vector, references, backend, tolerance):
    """Closest person within tolerance, as (name, confidence) or (None, 0)."""
    best_name, best_dist = nearest_reference(vector, references, backend)
    if best_name is None or best_dist > tolerance:
        return None, 0.0
    return best_name, confidence(best_dist, tolerance)


def nearest_reference(vector, references, backend):
    """Closest active reference identity and raw distance, even outside tolerance."""
    best_name, best_dist = None, float("inf")
    for name, vs in references.items():
        d = float(np.min(backend.distances(vs, vector)))
        if d < best_dist:
            best_name, best_dist = name, d
    return best_name, best_dist


def wilson_lower_bound(positive, total, z=CALIBRATION_WILSON_Z):
    """One-sided conservative floor for a binomial precision estimate."""
    positive = max(0, int(positive))
    total = max(0, int(total))
    if total < 1 or positive > total:
        return 0.0
    rate = positive / total
    z2 = z * z
    center = rate + z2 / (2.0 * total)
    margin = z * math.sqrt(
        (rate * (1.0 - rate) + z2 / (4.0 * total)) / total
    )
    return max(0.0, (center - margin) / (1.0 + z2 / total))


def calibration_report(samples, target=CALIBRATION_TARGET_PRECISION, minimum=CALIBRATION_MIN_SAMPLES):
    """Empirical bands and a threshold whose 99% Wilson floor meets the target."""
    clean = []
    for sample in samples or []:
        if not isinstance(sample, dict):
            continue
        outcome = str(sample.get("outcome") or "").lower()
        if outcome not in ("positive", "negative"):
            continue
        try:
            confidence_pct = max(0, min(100, int(sample.get("confidence"))))
        except (TypeError, ValueError):
            continue
        clean.append((confidence_pct, outcome == "positive"))

    bands = []
    for lower in range(0, 100, 5):
        upper = lower + 4
        members = [positive for pct, positive in clean if lower <= pct <= upper]
        if not members:
            continue
        wins = sum(1 for positive in members if positive)
        bands.append(
            {
                "band": f"{lower}-{upper}%",
                "total": len(members),
                "positive": wins,
                "negative": len(members) - wins,
            }
        )
    hundred = [positive for pct, positive in clean if pct == 100]
    if hundred:
        wins = sum(1 for positive in hundred if positive)
        bands.append(
            {
                "band": "100%",
                "total": len(hundred),
                "positive": wins,
                "negative": len(hundred) - wins,
            }
        )

    recommendation = None
    recommendation_samples = 0
    recommendation_floor = 0.0
    for threshold in sorted({pct for pct, _ in clean if pct >= 50}):
        members = [positive for pct, positive in clean if pct >= threshold]
        if len(members) < minimum:
            continue
        wins = sum(1 for positive in members if positive)
        floor = wilson_lower_bound(wins, len(members))
        if floor >= target:
            recommendation = threshold
            recommendation_samples = len(members)
            recommendation_floor = floor
            break

    positives = sum(1 for _, positive in clean if positive)
    return {
        "version": 1,
        "evaluated": len(clean),
        "positive": positives,
        "negative": len(clean) - positives,
        "target_precision": float(target),
        "minimum_samples": int(minimum),
        "recommended_threshold": recommendation,
        "recommendation_samples": recommendation_samples,
        "lower_bound": round(recommendation_floor, 8),
        "bands": bands,
    }


def sync_calibration(api, cfg, publish=True):
    data = api.get("/calibration", limit=5000)
    report = calibration_report(
        data.get("samples", []) if isinstance(data, dict) else [],
        cfg_calibration_target(cfg),
        cfg_calibration_min_samples(cfg),
    )
    if publish:
        api.post("/calibration", report)
    report["current_threshold"] = int(
        data.get("current_threshold", 0) if isinstance(data, dict) else 0
    )
    return report


def calibration_summary(report):
    recommendation = report.get("recommended_threshold")
    if recommendation is None:
        return (
            f"{int(report.get('evaluated', 0))} explicit outcome(s); "
            f"insufficient evidence for a {100 * float(report.get('target_precision', 0.99)):.2f}% precision recommendation"
        )
    return (
        f"recommend {int(recommendation)}%+ from "
        f"{int(report.get('recommendation_samples', 0))} outcome(s), "
        f"{100 * float(report.get('lower_bound', 0)):.2f}% conservative lower bound; "
        f"saved setting remains {int(report.get('current_threshold', 0))}%"
    )


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


# How much a saved label's rectangle must overlap a detected face to be that
# face. Deliberately loose: the label was drawn on one detection run and is
# being matched against another, and a crop or a re-encode moves boxes about.
LABEL_MATCH_IOU = 0.15


def match_labels_to_boxes(labels, boxes, skip=None):
    """
    Pair saved face labels with detected faces. The one place this is decided.

    Each label, in the order given, takes the detected box that overlaps it
    most among those not already taken, provided the overlap reaches
    LABEL_MATCH_IOU. A label with no name or no four-number box is passed
    over, and so is one whose name `skip` answers true for - before it can
    take a box, so a rejected name does not use up a face.

    This was written out three times - in learning, in the labelling board's
    pre-fill and in the unknown-face pass - and the three had started to differ
    in what they did around it. The pairing itself is the part that must never
    differ: if learning and the board disagree about which face a label is on,
    a volunteer is shown one face and the matcher is taught another.

    `boxes` are [x, y, w, h], prepared however the caller needs. Returns
    (label_index, box_index, name, target) tuples, label_index counting every
    entry of `labels` so a caller can key on a label's position.
    """
    out = []
    used = set()
    for label_index, label in enumerate(labels or []):
        if not isinstance(label, dict):
            continue
        name = str(label.get("name") or "").strip()
        box = label.get("box") or []
        if not name or not isinstance(box, (list, tuple)) or len(box) != 4:
            continue
        if skip is not None and skip(name):
            continue
        target = [int(value) for value in box]
        best_i, best_iou = -1, 0.0
        for box_index, candidate in enumerate(boxes):
            if box_index in used:
                continue
            overlap = box_iou_xywh(target, candidate)
            if overlap > best_iou:
                best_i, best_iou = box_index, overlap
        if best_i < 0 or best_iou < LABEL_MATCH_IOU:
            continue
        used.add(best_i)
        out.append((label_index, best_i, name, target))
    return out


# --------------------------------------------------------------------------- people discovery


def prepare_unknown_faces(
    api,
    conn,
    backend,
    tolerance,
    dismissal_threshold,
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
    scope_ids = set()
    for index, photo in enumerate(photos, start=1):
        photo_id = int(photo["id"])
        try:
            det = detect_photo(api, conn, backend, photo)
            found = det.found
            image_height, image_width = det.height, det.width
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
                backend,
                photo,
                unknown,
                dismissal_threshold,
            )
            scope_ids.update(
                int(row[0])
                for row in conn.execute(
                    """SELECT id FROM unknown_faces
                       WHERE engine = ? AND photo_id = ?""",
                    (backend.name, photo_id),
                )
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
        "observation_ids": sorted(scope_ids),
    }


def discovery_metadata(conn, engine, observation_ids=None):
    ids = sorted({int(value) for value in (observation_ids or []) if int(value) > 0})
    if observation_ids is not None and not ids:
        return []
    scope_sql = ""
    params = [engine]
    if observation_ids is not None:
        scope_sql = " AND u.id IN (" + ",".join("?" for _ in ids) + ")"
        params.extend(ids)
    rows = conn.execute(
        f"""SELECT u.id, u.cluster_id, u.photo_id, u.face_key,
                   u.box_x, u.box_y, u.box_w, u.box_h,
                   u.image_width, u.image_height,
                   u.taken_at, u.uploaded_at, u.context_json
            FROM unknown_faces u
            WHERE u.engine = ? AND u.cluster_id <> ''{scope_sql}
            ORDER BY u.cluster_id, u.id""",
        params,
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
.smallnote{padding:10px 14px;margin:0 0 12px;background:#fff;border:1px solid var(--line);
  border-left:3px solid #8a6508;color:#4b5563;font-size:13px;line-height:1.5}
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
<div id="smallnote" class="smallnote" hidden></div>
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
const cropUrls=new Map(), cropPending=new Map();
const byId=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function call(path,options={}){options.headers={...(options.headers||{}),'X-GASF-Discovery-Token':TOKEN};if(options.body)options.headers['Content-Type']='application/json';const r=await fetch(path,options);let data=null;try{data=await r.json()}catch(e){}if(!r.ok)throw new Error(data?.error||`Request failed (${r.status})`);return data}
async function putCrop(img,id,revision){const known=cropUrls.get(id);if(known){if(revision===viewRevision)img.src=known;return}let pending=cropPending.get(id);if(!pending){pending=(async()=>{const r=await fetch(`/api/crop?id=${encodeURIComponent(id)}`,{headers:{'X-GASF-Discovery-Token':TOKEN}});if(!r.ok)throw new Error();const url=URL.createObjectURL(await r.blob());cropUrls.set(id,url);return url})().finally(()=>cropPending.delete(id));cropPending.set(id,pending)}try{const url=await pending;if(revision===viewRevision)img.src=url}catch(e){if(revision===viewRevision)img.alt='Crop unavailable'}}
function pruneCropCache(){const keep=new Set();meta.clusters.forEach(c=>c.occurrences.forEach(o=>keep.add(Number(o.id))));for(const [id,url] of cropUrls){if(keep.has(Number(id)))continue;URL.revokeObjectURL(url);cropUrls.delete(id)}}
function contextText(o){const c=o.context||{};return [...(c.events||[]),...(c.places||[])].slice(0,2).join(' / ')}
function render(next){meta=next;pruneCropCache();const revision=++viewRevision;const root=byId('clusters');root.innerHTML='';byId('empty').hidden=meta.clusters.length>0;byId('people').innerHTML=(meta.people||[]).map(n=>`<option value="${esc(n)}">`).join('');
/* Groups smaller than the reference floor are not offered: naming one gives a
   person the matcher still cannot use. Said out loud so the board never reads
   as "that is everything" when it is not. */
const note=byId('smallnote');const hidden=Number(meta.hidden_small||0);
note.hidden=hidden<1;
if(hidden>0){note.textContent=`${hidden} smaller group${hidden===1?'':'s'} hidden — a person needs ${meta.min_cluster||3} photos before the scanner can recognise them, so naming fewer would not teach it anything. They return here once more of the same face turns up.`;}
meta.clusters.forEach((cluster,index)=>{const card=document.createElement('article');card.className='cluster';card.tabIndex=0;card.innerHTML=`<div class="sheet"></div><div class="meta"><strong>${cluster.count} occurrence${cluster.count===1?'':'s'}</strong><div class="range">${esc(cluster.first_date&&cluster.last_date?(cluster.first_date===cluster.last_date?cluster.first_date:`${cluster.first_date} to ${cluster.last_date}`):'Date unavailable')}</div></div>`;card.onclick=()=>openCluster(index);card.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();openCluster(index)}};const sheet=card.querySelector('.sheet');cluster.occurrences.slice(0,6).forEach(o=>{const img=document.createElement('img');img.alt=`Face from photo ${o.photo}`;sheet.appendChild(img);putCrop(img,o.id,revision)});root.appendChild(card)})}
function openCluster(index){current=meta.clusters[index];if(!current)return;const revision=++viewRevision;byId('clusterTitle').textContent=`Review ${current.count} occurrence${current.count===1?'':'s'}`;byId('personName').value='';byId('message').textContent='';const root=byId('occurrences');root.innerHTML='';current.occurrences.forEach(o=>{const card=document.createElement('article');card.className='occ';card.innerHTML=`<img alt="Face crop from photo ${o.photo}"><label><input type="checkbox" value="${o.id}" checked> Photo #${o.photo}<small>${esc(o.taken_at||o.uploaded_at||'Date unavailable')}</small><small>${esc(contextText(o))}</small></label>`;const cb=card.querySelector('input');cb.onchange=()=>card.classList.toggle('off',!cb.checked);root.appendChild(card);putCrop(card.querySelector('img'),o.id,revision)});byId('modal').classList.add('on')}
function selected(){return [...byId('occurrences').querySelectorAll('input:checked')].map(el=>Number(el.value))}
function setBusy(value){busy=value;document.body.classList.toggle('busy',value)}
async function applyName(){if(busy)return;const ids=selected(),name=byId('personName').value.trim();if(!ids.length){byId('message').textContent='Select at least one face.';return}if(!name){byId('message').textContent='Type the one name to apply to all selected faces.';return}if(!confirm(`Apply "${name}" to ${ids.length} selected face${ids.length===1?'':'s'}?`))return;setBusy(true);try{const out=await call('/api/name',{method:'POST',body:JSON.stringify({cluster:current.id,name,selected:ids})});byId('modal').classList.remove('on');render(out.meta);if(out.pending)alert(`${out.applied} face(s) saved. ${out.pending} stayed pending because WordPress reported them busy.`)}catch(e){byId('message').textContent=e.message}finally{setBusy(false)}}
async function dismissSelected(){if(busy)return;const ids=selected();if(!ids.length){byId('message').textContent='Select at least one face to dismiss.';return}if(!confirm(`Dismiss ${ids.length} selected occurrence${ids.length===1?'':'s'} locally? The WordPress photos are not deleted or changed.`))return;setBusy(true);try{const out=await call('/api/dismiss',{method:'POST',body:JSON.stringify({cluster:current.id,selected:ids})});byId('modal').classList.remove('on');render(out.meta)}catch(e){byId('message').textContent=e.message}finally{setBusy(false)}}
async function closeBoard(){try{await call('/api/close',{method:'POST',body:'{}'});for(const [,url] of cropUrls)URL.revokeObjectURL(url);cropUrls.clear();document.body.innerHTML='<main><div class="notice"><strong>People Discovery closed.</strong> You may close this tab.</div></main>'}catch(e){alert(e.message)}}
byId('closeModal').onclick=()=>{byId('modal').classList.remove('on');++viewRevision};byId('applyName').onclick=applyName;byId('dismissSelected').onclick=dismissSelected;byId('closeBoard').onclick=closeBoard;
call('/api/meta').then(render).catch(e=>{byId('clusters').innerHTML=`<div class="notice">${esc(e.message)}</div>`});
</script></body></html>"""
    return page.replace("__DISCOVERY_TOKEN__", json.dumps(session_token))


def local_discovery_board(api, conn, backend, threshold, people, observation_ids):
    state = {
        "token": secrets.token_urlsafe(32),
        "done": threading.Event(),
        "lock": threading.Lock(),
        "db_lock": threading.RLock(),
        "image_cache": OrderedDict(),
        # Finished JPEG crops by occurrence id, so reopening a group or
        # re-rendering after a name does not re-download and re-crop. Bytes
        # only, in memory, gone when the board closes. ~15 KB each.
        "crop_cache": OrderedDict(),
        "people": list(people),
        "observation_ids": {int(value) for value in observation_ids},
    }

    def current_meta():
        with state["db_lock"]:
            everything = discovery_metadata(
                conn,
                backend.name,
                state["observation_ids"],
            )
        shown = [c for c in everything if c["count"] >= MIN_DISCOVERY_CLUSTER]
        hidden = len(everything) - len(shown)
        return {
            "engine": backend.name,
            "threshold": threshold,
            "people": state["people"],
            "clusters": shown,
            # Said, not swallowed. A board that silently dropped most of its
            # groups would read as "that is all of them" - the house rule
            # against silent caps exists because that is how work disappears.
            "hidden_small": hidden,
            "min_cluster": MIN_DISCOVERY_CLUSTER,
        }

    class DiscoveryHandler(BaseHTTPRequestHandler):
        def _write(self, code, payload, ctype="application/json; charset=utf-8", extra_headers=None):
            body = payload if isinstance(payload, (bytes, bytearray)) else payload.encode("utf-8")
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            for key, value in (extra_headers or {}).items():
                self.send_header(str(key), str(value))
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
            if not set(ids).issubset(state["observation_ids"]):
                raise ValueError("That discovery scope changed. Refresh the board and review it again.")
            marks = ",".join("?" for _ in ids)
            with state["db_lock"]:
                rows = conn.execute(
                    f"""SELECT id, photo_id, face_key, box_x, box_y, box_w, box_h,
                               image_width, image_height, vector
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
                if occurrence_id not in state["observation_ids"]:
                    return self._json(404, {"error": "No such occurrence in this discovery run."})
                with state["lock"]:
                    cached_crop = state["crop_cache"].get(occurrence_id)
                    if cached_crop is not None:
                        state["crop_cache"].move_to_end(occurrence_id)
                if cached_crop is not None:
                    return self._write(200, cached_crop, "image/jpeg", {"X-GASF-Crop-Cache": "hit"})
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
                with state["lock"]:
                    state["crop_cache"][occurrence_id] = crop
                    state["crop_cache"].move_to_end(occurrence_id)
                    while len(state["crop_cache"]) > DISCOVERY_CROP_CACHE:
                        state["crop_cache"].popitem(last=False)
                return self._write(200, crop, "image/jpeg", {"X-GASF-Crop-Cache": "miss"})
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
                                   engine, photo_id,
                                   box_x, box_y, box_w, box_h,
                                   vector, dismissed_at
                               ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                               ON CONFLICT(engine, photo_id, box_x, box_y, box_w, box_h)
                               DO UPDATE SET
                                   vector = excluded.vector,
                                   dismissed_at = excluded.dismissed_at""",
                            [
                                (
                                    backend.name,
                                    int(row[1]),
                                    int(row[3]),
                                    int(row[4]),
                                    int(row[5]),
                                    int(row[6]),
                                    row[9],
                                    now,
                                )
                                for row in rows
                            ],
                        )
                    delete_unknown_observations(conn, ids)
                    state["observation_ids"].difference_update(ids)
                    with state["lock"]:
                        for observation_id in ids:
                            state["crop_cache"].pop(int(observation_id), None)
                    cluster_unknown_faces(
                        conn,
                        backend,
                        threshold,
                        state["observation_ids"],
                    )
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
                    with state["db_lock"]:
                        delete_unknown_observations(conn, applied_ids)
                        state["observation_ids"].difference_update(applied_ids)
                        with state["lock"]:
                            for observation_id in applied_ids:
                                state["crop_cache"].pop(int(observation_id), None)
                        cluster_unknown_faces(
                            conn,
                            backend,
                            threshold,
                            state["observation_ids"],
                        )
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
        threshold,
        limit,
        uploaded_after,
        uploaded_before,
        verbose,
    )
    scope_ids = prepared["observation_ids"]
    clusters = cluster_unknown_faces(conn, backend, threshold, scope_ids)
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
        scope_ids,
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


class Detection:
    """Faces found on one photo: from the cache, or freshly detected."""

    __slots__ = ("found", "width", "height", "qualities", "thumb", "cached")

    def __init__(self, found, width, height, qualities, thumb, cached):
        self.found = found            # [(box_css, vector)], as backend.embed_rgb returns
        self.width = int(width)
        self.height = int(height)
        self.qualities = qualities    # one reference_quality()["quality"] per face
        self.thumb = thumb            # small data-URI preview for gallery cards
        self.cached = cached


def remember_detection(conn, backend, photo, image_bytes, pixels, found):
    """Store what was just detected on a photo, if the server stamped its image."""
    rev = str(photo.get("image_rev") or "")
    if not rev:
        return None
    height, width = pixels.shape[:2]
    faces = []
    qualities = []
    for box_css, vector in found:
        q = float(reference_quality(pixels, box_css)["quality"])
        qualities.append(q)
        faces.append({
            "box": [int(v) for v in box_css],
            "v": base64.b64encode(np.asarray(vector, dtype=np.float32).tobytes()).decode("ascii"),
            "q": q,
        })
    thumb = _thumb_data_uri(image_bytes) if image_bytes is not None else ""
    conn.execute(
        """INSERT OR REPLACE INTO face_detections
               (photo_id, engine, image_rev, width, height, faces, thumb, stored_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
        (int(photo["id"]), backend.name, rev, int(width), int(height),
         json.dumps(faces, separators=(",", ":")), thumb, int(time.time())),
    )
    conn.commit()
    return Detection(found, width, height, qualities, thumb, cached=False)


def detect_photo(api, conn, backend, photo):
    """
    Faces on one photo, from the local cache when its image is unchanged.

    A hit needs the same photo, the same engine, and the same image_rev the
    server reports now; anything else downloads and detects afresh and replaces
    the row. A server that sends no image_rev (older plugin) is never cached.
    """
    photo_id = int(photo["id"])
    rev = str(photo.get("image_rev") or "")
    if rev:
        row = conn.execute(
            """SELECT width, height, faces, thumb FROM face_detections
                WHERE photo_id = ? AND engine = ? AND image_rev = ?""",
            (photo_id, backend.name, rev),
        ).fetchone()
        if row is not None:
            try:
                faces = json.loads(row[2])
                found = [
                    (tuple(int(v) for v in f["box"]),
                     np.frombuffer(base64.b64decode(f["v"]), dtype=np.float32).copy())
                    for f in faces
                ]
                if all(len(v) == backend.dim for _, v in found):
                    return Detection(found, row[0], row[1], [float(f["q"]) for f in faces], row[3], cached=True)
            except (ValueError, KeyError, TypeError):
                pass  # unreadable row: fall through and rebuild it
    image_bytes = api.image(photo["url"])
    pixels = display_rgb_array(image_bytes)
    found = backend.embed_rgb(pixels)
    stored = remember_detection(conn, backend, photo, image_bytes, pixels, found)
    if stored is not None:
        return stored
    height, width = pixels.shape[:2]
    return Detection(
        found, width, height,
        [float(reference_quality(pixels, b)["quality"]) for b, _ in found],
        _thumb_data_uri(image_bytes), cached=False,
    )


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
    reused = 0
    if total:
        # The longest silence in the whole tool: every photo is downloaded and
        # face-detected before the page can open. Say so, once, up front.
        print(
            f"Getting the naming page ready: checking {total} photo(s) for faces. "
            "Your browser opens when this finishes; progress follows every 15 seconds.",
            flush=True,
        )
    beat = _HeartbeatTicker(
        total > 0,
        15,
        lambda: f"  preparing the naming page: photo {current} of {total}, {len(items)} with faces to review so far",
    )
    beat.start()
    try:
        for n, p in enumerate(photos, start=1):
            current = n
            photo_id = int(p["id"])
            people = [str(n).strip() for n in (p.get("people") or []) if str(n).strip()]
            try:
                det = detect_photo(api, conn, backend, p)
            except Exception as e:
                print(f"#{photo_id}: skipped ({e})")
                continue
            reused += int(det.cached)
            found = [(b, v, q) for (b, v), q in zip(det.found, det.qualities)]
            if not found:
                continue

            image_height, image_width = det.height, det.width
            boxes = []
            kept_found = []
            ignored_boxes = p.get("ignored") or []
            for box_css, vector, face_quality in found:
                box = clamp_box_xywh(css_box_to_xywh(box_css), image_width, image_height)
                if box is None:
                    continue
                # Faces a volunteer has put down are not offered for labelling
                # again - that is the whole point of putting them down.
                if _face_box_ignored(box, image_width, image_height, ignored_boxes):
                    continue
                boxes.append(box)
                kept_found.append((box_css, vector, face_quality))
            found = kept_found
            if not found:
                continue
            hints = []
            active_faces = []
            rejected = p.get("rejected") or []
            for i, (box_css, vec, face_quality) in enumerate(found):
                nearest_name, distance = nearest_reference(vec, refs, backend)
                name = nearest_name if nearest_name and distance <= tolerance else None
                conf = confidence(distance, tolerance) if name else 0
                if _face_name_rejected(name, rejected):
                    name, conf = None, 0
                hints.append({"index": i, "name": name or "", "confidence": int(round(conf * 100)) if name else 0})
                active_faces.append(
                    {
                        "index": i,
                        "vector": np.asarray(vec, dtype=np.float32),
                        "nearest_name": nearest_name or "",
                        "distance": float(distance),
                        "quality": float(face_quality),
                        "recognized": bool(name),
                    }
                )

            # Pre-fill from previously saved labels on matching rectangles.
            prefill = {
                str(box_index): name
                for _, box_index, name, _ in match_labels_to_boxes(
                    p.get("labels") or [],
                    boxes,
                    skip=lambda name: _face_name_rejected(name, rejected),
                )
            }

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
                    "_active_faces": active_faces,
                    "_captured_at": (
                        str((p.get("caption_context") or {}).get("taken_at") or "")
                        or str(p.get("uploaded_at") or "")
                    ),
                    # Lightweight preview only. Full image is loaded on demand per photo.
                    "thumb": det.thumb,
                }
            )
            people_names.extend(people)
            if n % 25 == 0 or n == total:
                print(f"label prep: {n}/{total} photos checked ({reused} from the local cache), {len(items)} ready")
    finally:
        beat.stop()
    prioritize_active_learning(items, conn, backend, tolerance)
    dedup = []
    seen = set()
    for n in people_names:
        k = n.casefold()
        if k in seen:
            continue
        seen.add(k)
        dedup.append(n)
    return items, dedup


def prioritize_active_learning(items, conn, backend, tolerance):
    """Rank unresolved local work by known, auditable information-value signals."""
    ref_counts = {
        person: int(count)
        for person, count in conn.execute(
            """SELECT person, COUNT(*) FROM refs
               WHERE engine = ? AND active = 1 GROUP BY person""",
            (backend.name,),
        )
    }
    ref_years = {}
    for person, captured_at in conn.execute(
        """SELECT person, captured_at FROM refs
           WHERE engine = ? AND active = 1 AND captured_at <> ''""",
        (backend.name,),
    ):
        year = _captured_year(captured_at)
        if year:
            ref_years.setdefault(person, set()).add(year)

    unknown = []
    for item_index, item in enumerate(items):
        resolved = {int(index) for index in (item.get("prefill") or {}).keys()}
        threshold = int(item.get("known_threshold") or 0)
        if threshold > 0:
            resolved.update(
                int(hint["index"])
                for hint in item.get("hints") or []
                if hint.get("name") and int(hint.get("confidence") or 0) >= threshold
            )
        for face in item.get("_active_faces") or []:
            face["resolved"] = int(face["index"]) in resolved
            if not face["resolved"] and not face["recognized"]:
                unknown.append((item_index, face))

    clusters = []
    cluster_limit = max(0.05, tolerance * 0.65)
    for entry in unknown:
        candidates = []
        for cluster in clusters:
            matrix = np.vstack([member[1]["vector"] for member in cluster])
            farthest = float(np.max(backend.distances(matrix, entry[1]["vector"])))
            if farthest <= cluster_limit:
                candidates.append(
                    (
                        farthest,
                        cluster[0][0],
                        int(cluster[0][1]["index"]),
                        cluster,
                    )
                )
        if candidates:
            min(candidates, key=lambda value: value[:3])[3].append(entry)
        else:
            clusters.append([entry])
    cluster_sizes = {
        (item_index, int(face["index"])): len(cluster)
        for cluster in clusters
        for item_index, face in cluster
    }

    ranked = []
    for item_index, item in enumerate(items):
        face_scores = []
        year = _captured_year(item.get("_captured_at"))
        for face in item.pop("_active_faces", []):
            if face["resolved"]:
                continue
            nearest_name = face["nearest_name"]
            distance = float(face["distance"])
            cluster_size = cluster_sizes.get((item_index, int(face["index"])), 1)
            cluster_signal = min(1.0, cluster_size / 5.0)
            count = ref_counts.get(nearest_name, 0)
            weak_corpus = 1.0 - min(1.0, count / float(MAX_ACTIVE_REFERENCES))
            if math.isfinite(distance) and tolerance > 0:
                uncertainty = max(
                    0.0,
                    1.0 - abs(distance - tolerance) / max(0.001, tolerance * 0.55),
                )
                novelty = min(1.0, distance / max(0.001, tolerance * 1.5))
            else:
                uncertainty = 0.0
                novelty = 1.0
            era_signal = (
                1.0
                if year and nearest_name and year not in ref_years.get(nearest_name, set())
                else 0.0
            )
            face_scores.append(
                0.25 * cluster_signal
                + 0.20 * weak_corpus
                + 0.20 * uncertainty
                + 0.15 * novelty
                + 0.10 * max(0.0, min(1.0, float(face["quality"])))
                + 0.10 * era_signal
            )
        item.pop("_captured_at", None)
        score = max(face_scores) if face_scores else 0.0
        if face_scores:
            score = min(1.0, score + 0.05 * (sum(face_scores) / len(face_scores)))
        item["active_score"] = round(score, 6)
        item["active_learning"] = False
        if item.get("status") != "full" and face_scores:
            ranked.append((score, -int(item["id"]), item))

    ranked.sort(reverse=True)
    offer_count = min(
        ACTIVE_LEARNING_MAX,
        max(1, int(math.ceil(len(ranked) * 0.30))) if ranked else 0,
    )
    offered = 0
    for score, _, item in ranked:
        if offered >= offer_count or score < ACTIVE_LEARNING_THRESHOLD:
            break
        item["active_learning"] = True
        offered += 1
    if ranked and offered == 0:
        ranked[0][2]["active_learning"] = True


def _label_item_apply_saved(item, labels):
    """Make a board item say what was just saved for it.

    The board builds each photo's names once, at start-up, and every save
    REPLACES the photo's labels on the server. Nothing used to write a save
    back into the item - so going Back to a photo showed its fields as they
    were at start-up, usually empty, and the next save from there replaced the
    names already saved with whatever was on screen. Save Anna, come back, add
    Berta, save: Anna was deleted, and nothing said so.

    The page sends each label's box exactly as the item gave it, so matching is
    equality, not overlap. Hints stay as they were - they are the matcher's
    opinion, and a save does not change it.
    """
    boxes = [[int(v) for v in b] for b in (item.get("boxes") or [])]
    prefill = {}
    for lbl in labels or []:
        if not isinstance(lbl, dict):
            continue
        name = str(lbl.get("name") or "").strip()
        try:
            box = [int(v) for v in (lbl.get("box") or [])]
        except (TypeError, ValueError):
            continue
        if not name or box not in boxes:
            continue
        prefill.setdefault(str(boxes.index(box)), name)
    item["prefill"] = prefill
    item["status"] = _label_item_status(
        len(boxes), prefill, item.get("hints"), int(item.get("known_threshold") or 0)
    )


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


def _caption_error_line(exc):
    """
    One readable line instead of a urllib3 stack of nouns.

    "Max retries exceeded with url: /api/generate (Caused by NewConnectionError(
    '<urllib3.connection.HTTPConnection object at 0x...>: [WinError 10061] No
    connection could be made because the target machine actively refused it'))"
    is thirty words that mean "Ollama is not running", printed once per photo.
    The distinction worth keeping is between a captioner that is switched off -
    ordinary, and nobody's fault - and one that answered with something wrong.
    """
    if isinstance(exc, requests.exceptions.ConnectionError):
        return "the local captioner is not running (start Ollama, or ignore this if you are not using captions)"
    if isinstance(exc, requests.exceptions.Timeout):
        return "the local captioner did not answer in time"
    text = " ".join(str(exc).split())
    return (text[:160] + "…") if len(text) > 160 else (text or exc.__class__.__name__)


def _face_name_rejected(name, rejected_names):
    """Match the server's per-photo negative names without weakening other hints."""
    keys = _face_name_keys(name)
    return bool(keys) and any(keys.intersection(_face_name_keys(rejected)) for rejected in (rejected_names or []))


# How much two rectangles must overlap to be the same face. Mirrors
# GASF_CRM_FACE_IGNORE_IOU on the server; both sides must agree or a face put
# down in the CRM would come back here.
FACE_IGNORE_IOU = 0.35


def _face_box_ignored(box, image_width, image_height, ignored):
    """
    Has a volunteer said this rectangle is not a person to tag?

    Normalised before comparing, because the same face measured on the original
    and on a smaller working copy is the same face with different numbers, and
    an ignore recorded at one size has to survive a rescan at another. Matched
    on overlap alone: the embedding that would make this sturdier never leaves
    this machine, so it cannot be part of an instruction the server sends back.
    """
    if not ignored or not box or len(box) != 4:
        return False
    iw, ih = float(image_width or 0), float(image_height or 0)
    if iw <= 0 or ih <= 0:
        return False
    bx, by, bw, bh = [float(v) for v in box]
    b = (bx / iw, by / ih, bw / iw, bh / ih)

    for row in ignored:
        if not isinstance(row, dict):
            continue
        rbox = row.get("box") or []
        rw = float(row.get("iw") or 0)
        rh = float(row.get("ih") or 0)
        if len(rbox) != 4 or rw <= 0 or rh <= 0:
            continue
        rx, ry, rww, rhh = [float(v) for v in rbox]
        a = (rx / rw, ry / rh, rww / rw, rhh / rh)

        ix = max(0.0, min(a[0] + a[2], b[0] + b[2]) - max(a[0], b[0]))
        iy = max(0.0, min(a[1] + a[3], b[1] + b[3]) - max(a[1], b[1]))
        inter = ix * iy
        union = (a[2] * a[3]) + (b[2] * b[3]) - inter
        if union > 0 and (inter / union) >= FACE_IGNORE_IOU:
            return True
    return False


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
/* Set apart from Back/Next/Save. These two decide something rather than move
   about, and neither is undoable from this page. Green keeps its work, amber
   throws it away; the pair should never be misread for each other at speed. */
.donephoto{border-color:#166534;color:#86efac}
.skipphoto{border-color:#7c2d12;color:#fdba74}
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
.row{display:grid;grid-template-columns:96px 1fr auto auto;gap:6px;align-items:center}
/* The tick beside "Face 3" shows or hides that rectangle, and nothing else. */
.facelab{display:flex;align-items:center;gap:5px;white-space:nowrap;cursor:pointer}
.facelab input{cursor:pointer;margin:0}
.rowshead{display:flex;align-items:center;gap:10px;margin:0 0 8px;flex-wrap:wrap}
.boxesall{padding:5px 10px;border:1px solid #334155;border-radius:6px;background:#0b1220;
  color:#cbd5e1;cursor:pointer;font:inherit;font-size:13px}
.boxesall:hover{border-color:#60a5fa}
.boxesall:disabled{opacity:.5;cursor:default}
.rowshead .muted{font-size:12px}
.notaperson{border:1px solid #475569;background:#0b1220;color:#94a3b8;border-radius:6px;padding:5px 9px;cursor:pointer;white-space:nowrap}
.notaperson:hover{border-color:#f87171;color:#fca5a5}
/* The suggested name, with the best stored picture of that person beside it -
   a percentage says how sure the machine is, a face says who it means. */
.usehint{display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
.hintface{width:34px;height:34px;border-radius:5px;object-fit:cover;flex:none;
  border:1px solid #334155;background:#0b1220}
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
        <option value="active">Active learning</option>
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
      <div class="rowshead">
        <button type="button" id="boxesall" class="boxesall">Hide all boxes</button>
        <span class="muted">Ticks only show or hide the rectangles. Names are saved either way.</span>
      </div>
      <div class="rows" id="rows"></div>
      <div class="muted">Names save automatically when you move to another photo.</div>
      <div class="acts">
        <button id="back">Back</button>
        <button id="next">Next</button>
        <button id="save" class="pri">Save & Next</button>
        <button id="done" class="donephoto" title="Save the names typed here, then close this photo for good. For when you have named everyone you can and the faces left over are strangers nobody will ever tag.">Done with this photo</button>
        <button id="skip" class="skipphoto" title="Nobody here is worth naming - a crowd of strangers, a hall from the back. This photo will not be offered for labelling again, on this machine or any other. Anything typed on it is discarded.">Skip this photo</button>
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
/* Counts edits, so a save can tell whether what it sent is still what is on
   screen. The name fields stay live while a save is in flight, and the save
   used to finish by declaring the photo clean whatever had been typed since:
   the next Save & Next then sent nothing, and the name typed during those two
   seconds was gone. */
let editSeq=0;
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
  const nouns = {all:'photo', needs:'photo needing labels', active:'high-value photo', partial:'partially tagged photo', untagged:'untagged photo'};
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
/* Which rectangles are hidden right now.
   Purely about seeing the photograph: on a group shot the boxes and their
   numbers cover the very faces they are pointing at, and there was no way to
   look underneath. Hiding one changes nothing that gets saved - the name typed
   beside it is still submitted - so this is a pair of spectacles, not a
   decision. Cleared whenever a different photo is opened. */
var hiddenBoxes = new Set();

/* One button for a crowded group shot, where ticking fifteen boxes off one at a
   time is its own chore. Reads as whichever action is useful next. */
function syncBoxesAll(){
  const btn=document.getElementById('boxesall');
  if(!btn||!current||!current.boxes){return;}
  const total=current.boxes.length;
  const allHidden = total>0 && hiddenBoxes.size>=total;
  btn.textContent = allHidden ? 'Show all boxes' : 'Hide all boxes';
  btn.disabled = total===0;
}

function setAllBoxes(show){
  if(!current||!current.boxes){return;}
  hiddenBoxes = new Set();
  if(!show){ current.boxes.forEach((b,i)=>hiddenBoxes.add(i)); }
  document.querySelectorAll('#rows input.boxtoggle').forEach(cb=>{ cb.checked = !!show; });
  drawBoxes(current);
  syncBoxesAll();
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
    if(hiddenBoxes.has(i)){return;}
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
  // A different photo starts with every rectangle showing: hiding one is about
  // reading THIS picture, and carrying it over would leave a face invisible on
  // a photo where nobody chose to hide anything.
  hiddenBoxes = new Set();
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
    row.innerHTML=`<label class="facelab"><input type="checkbox" class="boxtoggle" data-box="${i}"`
      + `${hiddenBoxes.has(i)?'':' checked'} title="Show this face's rectangle on the photo. Unticking only hides it - the name is still saved.">`
      + ` Face ${i+1}</label>`
      + `<input list="${listId}" data-i="${i}" value="${esc(val)}" placeholder="Name">${hint.name?`<button data-fill="${i}" class="usehint"><img class="hintface" alt="" src="/api/face-thumb?person=${encodeURIComponent(hint.name)}&token=${encodeURIComponent(sessionToken)}" onerror="this.remove()"><span>Use ${esc(hint.name)} (${hint.confidence}%)</span></button>`:'<span></span>'}`
      + `<button class="notaperson" data-ignore="${i}" title="This is not a person to tag - a poster, a reflection, or somebody in the background. It will not be offered again.">Not a person</button>`;
    rows.appendChild(row);
    if(val){ addName(val); }
  });
  rows.querySelectorAll('button[data-ignore]').forEach(b=>b.onclick=async()=>{
    const i=parseInt(b.getAttribute('data-ignore'),10);
    const box=(p.boxes||[])[i];
    if(!box){ return; }
    b.disabled=true; b.textContent='...';
    try{
      const r=await fetch('/api/ignore',{
        method:'POST',
        headers:{'Content-Type':'application/json','X-GASF-Label-Token':sessionToken},
        body:JSON.stringify({photo:p.id, box:box, iw:p.image_width||0, ih:p.image_height||0})
      });
      if(!r.ok){ throw new Error(await r.text()||r.statusText); }
      // Gone for good: drop the row so the remaining faces are what is left to
      // do, and clear any name typed into it so a save cannot re-add it.
      const inp=rows.querySelector(`input[data-i="${i}"]`);
      if(inp){ inp.value=''; }
      b.closest('.row').remove();
      setText('msg','That face will not be offered again.');
    }catch(e){
      b.disabled=false; b.textContent='Not a person';
      setText('msg','Could not save that: '+e.message);
    }
  });
  rows.querySelectorAll('input.boxtoggle').forEach(cb=>cb.onchange=()=>{
    const i=parseInt(cb.getAttribute('data-box'),10);
    if(cb.checked){ hiddenBoxes.delete(i); } else { hiddenBoxes.add(i); }
    drawBoxes(current);   // redraw only; nothing about the labels is touched
    syncBoxesAll();
  });
  syncBoxesAll();
  rows.querySelectorAll('button[data-fill]').forEach(b=>b.onclick=()=>{
    const i=b.getAttribute('data-fill');
    const hint=(p.hints||[]).find(h=>String(h.index)===String(i));
    const inp=rows.querySelector(`input[data-i="${i}"]`);
    if(inp && hint && hint.name){inp.value=hint.name; dirty=true; editSeq++; inp.focus();}
  });
  rows.querySelectorAll('input[data-i]').forEach(inp=>{
    inp.addEventListener('input', ()=>{
      dirty=true; editSeq++;
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
  // Through saveAndOpen, like Back and Next. A thumbnail used to open its photo
  // directly, so clicking one with names typed and unsaved threw them away.
  gl.querySelectorAll('.gbtn').forEach(b=>b.onclick=async()=>{
    await saveAndOpen(parseInt(b.getAttribute('data-i'),10)||0);
  });
}
function applyFilter(){
  const f = (document.getElementById('gfilter')||{}).value || 'all';
  activeGallery = allGallery.filter(g =>
    f === 'all' ? true : (f === 'needs' ? g.status !== 'full' : (f === 'active' ? g.active_learning : g.status === f)));
  if(f === 'active'){ activeGallery.sort((a,b)=>Number(b.active_score||0)-Number(a.active_score||0)||Number(b.id)-Number(a.id)); }
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
  // input[data-i] and nothing else. A bare '#rows input' would also collect the
  // show/hide checkboxes beside each face, whose value is the string "on" - so
  // ticking one off would have quietly saved a person called "on" against an
  // undefined box. The checkboxes are a way to see the picture and must be
  // invisible to saving.
  document.querySelectorAll('#rows input[data-i]').forEach(inp=>{
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
  const sentSeq=editSeq, sentId=current.id;
  saving=true;
  const saveBtn=document.getElementById('save');
  const finishBtn=document.getElementById('finish');
  saveBtn.disabled=true;
  finishBtn.disabled=true;
  saveBtn.textContent='Saving...';
  try{
    const out = await j('/api/save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({photo:sentId,labels})});
    // Only if this is still the photo that was saved: the status is read off
    // the fields on screen, and they may belong to another photo by now.
    if(current && current.id===sentId){ updateGalleryStatus(sentId); }
    // Clean only if nothing was typed while it was away. Otherwise it stays
    // dirty, and the next save sends what is actually there.
    if(editSeq===sentSeq){ dirty=false; }
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
/* Take a photo out of this batch and open whatever follows it.
   Shared by both ways of closing one, because the difference between them is
   entirely in what happens BEFORE this - whether the names were saved first. */
async function dropFromBatch(id, wasPos, note){
  allGallery = allGallery.filter(g=>Number(g.id)!==Number(id));
  const at = activeGallery.findIndex(g=>Number(g.id)===Number(id));
  if(at>=0){ activeGallery.splice(at,1); }
  count = activeGallery.length;
  paintGallery();
  if(!count){
    pos=0;
    showGallery();
    setText('title','Nothing left in this view');
    setText('sub','Every photo here has been labeled or closed.');
    setText('stat','');
    return;
  }
  // The next photo has slid into the position this one just left, so staying
  // put IS moving on. Clamped for the last photo in the batch.
  await openByPos(Math.min(at>=0?at:wasPos, count-1));
  setText('sub', note);
}

/* Finished with this photograph: keep the names, close the photo.
   The common ending, and the one that had no button. Two members named and a
   stranger three tables back who will never be named leaves a photo that is
   correctly tagged and permanently incomplete - so it came round again every
   run, looking exactly like work nobody had started.

   Names are saved FIRST and the photo is only closed once they are stored. The
   other order can close a photo whose labels never landed, and losing work
   somebody actually did is the one outcome here worth engineering against. */
async function donePhoto(){
  if(!current || saving || finishing){return;}
  const id=current.id;
  const btn=document.getElementById('done');
  const wasPos=pos;
  btn.disabled=true; btn.textContent='Saving...';
  try{
    await saveCurrentOnly();
    await j('/api/skip',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({photo:id, reason:'done'})});
    dirty=false;
    await dropFromBatch(id, wasPos, 'Saved, and finished with. It will not be offered again.');
  }catch(e){
    setText('sub', e && e.message ? e.message : String(e));
  } finally {
    btn.disabled=false; btn.textContent='Done with this photo';
  }
}

/* Pass this whole photograph over, for good.
   A different answer from "not a person": that one is for a poster on the wall
   or a reflection, and there is nothing to point at here. This is the crowd
   shot from the back of a hall where nobody at the club will ever name a single
   face, and where the cost is not the asking - it is that a queue of a few
   hundred photos spent its places on strangers, and the client downloaded and
   ran a detector over every one of them before this page even opened.

   Told to WordPress rather than kept here, so it survives a rebuilt faces.db, a
   reinstall, or a different machine. Undone in bulk from the admin panel, which
   is the only place that can reach a photo the queue no longer offers. */
async function skipPhoto(){
  if(!current || saving || finishing){return;}
  const id=current.id;
  const btn=document.getElementById('skip');
  const wasPos=pos;
  btn.disabled=true; btn.textContent='Passing over...';
  try{
    await j('/api/skip',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({photo:id})});
    // Anything typed on it goes with it. Skipping IS the answer to "who are
    // these people", so saving a half-typed name on the way out would be
    // filing the opposite of what was just decided.
    dirty=false;
    await dropFromBatch(id, wasPos, 'Passed over. It will not be offered again.');
  }catch(e){
    setText('sub', e && e.message ? e.message : String(e));
  } finally {
    btn.disabled=false; btn.textContent='Skip this photo';
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
/* The finish button, once there is nothing left to wait for.
   Disabled alone is not enough: the stylesheet gives a disabled finish button a
   wait cursor, so leaving it disabled and still reading "Finishing..." is how a
   completed session looked identical to a stuck one. */
function finishDone(label){
  const b=document.getElementById('finish');
  if(!b){return;}
  b.disabled=true;
  b.textContent=label;
  b.style.cursor='default';
}

async function pollFinish(){
  if(!finishing){return;}
  try{
    const s=await j('/api/finish-status');
    finishPollFailures=0;
    if(s.status==='done'){
      finishing=false;
      setText('finishTitle','Labeling finished');
      setText('finishMessage',labelFlow
        ? 'Your labels are saved. ScanGUI is now refreshing references and running a final scan — that can take several minutes, and its progress appears in the ScanGUI window, not here. You can close this tab.'
        : 'Your labels are saved. You can close this tab.');
      document.getElementById('finishSpinner').hidden=true;
      // The button said "Finishing..." and stayed disabled, which the stylesheet
      // draws with an hourglass cursor — so a finished, saved session looked
      // exactly like one still grinding away, for as long as the tab stayed
      // open. Say the true thing instead.
      finishDone('Labels saved');
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
    /* The board closes as soon as the session is handed back, so losing the
       connection here is the ORDINARY ending, not a fault: it means ScanGUI has
       taken over and moved on to learning and scanning. Said plainly, because
       the old wording read like something had gone wrong and left the button
       spinning underneath it. */
    finishing=false;
    setText('finishTitle','Handed back to ScanGUI');
    setText('finishMessage','This board has closed, which is what happens when the session ends. Any names you saved are already stored — ScanGUI is now refreshing references and running a final scan, and its progress appears in the ScanGUI window. You can close this tab.');
    document.getElementById('finishSpinner').hidden=true;
    finishDone('Handed back');
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
document.getElementById('boxesall').onclick=()=>{
  const total=(current&&current.boxes)?current.boxes.length:0;
  setAllBoxes(!(total>0 && hiddenBoxes.size>=total));
};
document.getElementById('done').onclick=donePhoto;
document.getElementById('skip').onclick=skipPhoto;
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
            if not supplied:
                # An <img src> cannot send a header, and the face thumbnails are
                # images. The token is per-run, loopback-only, and already rides
                # in the query on the page load that opened this board, so this
                # widens nothing - it just lets the browser fetch a picture.
                supplied = (parse_qs(urlparse(self.path).query or "").get("token") or [""])[0]
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
            if u.path == "/api/face-thumb":
                # The best stored picture of one person, so a suggested name
                # comes with a face. Read straight from faces.db and served only
                # on this loopback board - it is never uploaded anywhere.
                q = parse_qs(u.query or "")
                person = (q.get("person") or [""])[0].strip()
                if not person:
                    return self._write(400, json.dumps({"error": "Missing person"}))
                try:
                    blob = best_face_thumb(conn, backend.name, person)
                except Exception:
                    blob = None
                if not blob:
                    # 204: there is simply no picture of this person yet, which
                    # the page treats as "show the name alone" rather than an error.
                    return self._write(204, b"", "image/jpeg")
                return self._write(200, blob, "image/jpeg")
            if u.path == "/api/meta":
                gallery = [
                    {
                        "id": it["id"],
                        "thumb": it.get("thumb", ""),
                        "status": it.get("status", "untagged"),
                        "active_learning": bool(it.get("active_learning")),
                        "active_score": float(it.get("active_score", 0)),
                        "global_i": i,
                    }
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
                    # The server has these now, so the board must too - or the
                    # next save from this photo replaces them with a stale set.
                    for it in state["items"]:
                        if int(it.get("id") or 0) == photo:
                            _label_item_apply_saved(it, labels)
                return self._write(200, json.dumps({"ok": True, "stored": kept, "saved_total": saved_total}))

            if u.path == "/api/ignore":
                # "Not a person" - put one face down for good. Sent straight to
                # WordPress rather than kept locally, so it survives this
                # machine: a rebuilt faces.db, a reinstall, or a different PC
                # entirely still knows not to ask about this face again.
                try:
                    photo = int(data.get("photo") or 0)
                except (TypeError, ValueError):
                    return self._write(400, json.dumps({"error": "Invalid photo id"}))
                box = data.get("box") or []
                try:
                    box = [int(v) for v in box]
                except (TypeError, ValueError):
                    box = []
                iw = int(data.get("iw") or 0)
                ih = int(data.get("ih") or 0)
                if photo < 1 or len(box) != 4 or iw < 1 or ih < 1:
                    return self._write(400, json.dumps({"error": "A photo and a face rectangle are required"}))
                try:
                    api.post("/ignore", {
                        "photo": photo,
                        "box": box,
                        "iw": iw,
                        "ih": ih,
                        "clear": bool(data.get("clear")),
                    })
                except (requests.RequestException, RuntimeError, ValueError, SystemExit) as e:
                    return self._write(502, json.dumps({"error": f"Could not save that: {e}"}))
                return self._write(200, json.dumps({"ok": True}))

            if u.path == "/api/skip":
                # A whole photo closed for labelling, sent straight to WordPress
                # for the same reason an ignored face is: it has to survive this
                # machine. The queue that decides what gets downloaded next run
                # is built there, so this is also the only place it can matter.
                # The reason travels with it so the admin panel can tell a photo
                # that was worked properly from one that was thrown away.
                try:
                    photo = int(data.get("photo") or 0)
                except (TypeError, ValueError):
                    return self._write(400, json.dumps({"error": "Invalid photo id"}))
                if photo < 1:
                    return self._write(400, json.dumps({"error": "Missing photo id"}))
                reason = "done" if str(data.get("reason") or "") == "done" else "passed"
                try:
                    api.post("/skip", {
                        "photo": photo,
                        "clear": bool(data.get("clear")),
                        "reason": reason,
                    })
                except (requests.RequestException, RuntimeError, ValueError, SystemExit) as e:
                    return self._write(502, json.dumps({"error": f"Could not pass that photo over: {e}"}))
                return self._write(200, json.dumps({"ok": True}))

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


# How many runs in a row a photo may fail to download before learning stops
# waiting for it. Enough to ride out a bad evening; not so many that one photo
# the host will never serve makes every run redo the same work for ever.
LEARN_RETRY_RUNS = 3


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

    The watermark only moves past photos that were actually dealt with. It used
    to advance before each photo was fetched, so one timeout meant that photo
    was never looked at again - nothing re-queues it but a volunteer editing it
    a second time. Where the photo was a CORRECTION, the reference it was
    meant to replace stayed, and went on suggesting the wrong name. Now the
    first photo that could not be downloaded holds the saved watermark behind
    it: this run carries on past it, the next run starts from it again
    (relearning is idempotent), and after LEARN_RETRY_RUNS runs it is given up
    on out loud rather than waited for indefinitely.
    """
    if verbose:
        print("Studying photos volunteers have tagged since the last run...", flush=True)
    apply_retired_names(api, conn, verbose)
    wk_mod = state_key(backend.name, "learned_modified")
    wk_id = state_key(backend.name, "learned_id")
    since_mod = state_get(conn, wk_mod, "")
    since_id = int(state_get(conn, wk_id, "0") or 0)
    include_empty = bool(since_mod)
    added = removed = skipped = processed = 0
    learned_ids = set()
    retry_key = state_key(backend.name, "learn_retry")
    try:
        retry = json.loads(state_get(conn, retry_key, "") or "{}")
        retry = retry if isinstance(retry, dict) else {}
    except ValueError:
        retry = {}
    held = None  # the watermark as it stood before the first photo that must be retried

    def fetch_failed(exc, photo_id, before):
        """A photo could not be fetched or read. Decide whether to wait for it."""
        nonlocal held
        transient = isinstance(exc, requests.RequestException) and not _http_gone(exc)
        note = ""
        if transient:
            tries = int(retry.get(str(photo_id), 0)) + 1
            if tries <= LEARN_RETRY_RUNS:
                retry[str(photo_id)] = tries
                if held is None:
                    held = before
                note = " - will be tried again next run"
            else:
                retry.pop(str(photo_id), None)
                note = f" - given up on after {LEARN_RETRY_RUNS} runs; edit the photo's names to have it studied again"
        if verbose:
            print(f"  #{photo_id}: {exc}{note}")
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
                context = _photo_context(p)
                captured_at = context["taken_at"] or context["uploaded_at"]
                modified = str(p.get("modified") or "")
                before = (since_mod, since_id)
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
                            display_pixels = display_rgb_array(api.image(p["url"]))
                            found = backend.embed_rgb(display_pixels)
                        except Exception as e:  # a missing file must not stop the run
                            fetch_failed(e, photo_id, before)
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
                        replacements = []
                        for li, best_i, name, target in match_labels_to_boxes(labels, det_boxes):
                            box_css, vector = found[best_i]
                            face_key = f"b:{target[0]},{target[1]},{target[2]},{target[3]}:{li}"
                            metrics = reference_quality(display_pixels, box_css)
                            metrics["captured_at"] = captured_at
                            metrics["thumb"] = reference_thumb(display_pixels, box_css)
                            replacements.append((name, face_key, vector, metrics))
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
                        display_pixels = display_rgb_array(api.image(p["url"]))
                        found = backend.embed_rgb(display_pixels)
                    except Exception as e:  # a missing file must not stop the run
                        fetch_failed(e, photo_id, before)
                        continue
                    if len(found) != 1:
                        old_count, _ = replace_photo_references(
                            conn, photo_id, backend.name, []
                        )
                        removed += old_count
                        learned_ids.add(photo_id)
                        skipped += 1
                        continue

                    box_css, vector = found[0]
                    metrics = reference_quality(display_pixels, box_css)
                    metrics["captured_at"] = captured_at
                    metrics["thumb"] = reference_thumb(display_pixels, box_css)
                    old_count, new_count = replace_photo_references(
                        conn,
                        photo_id,
                        backend.name,
                        [(people[0].strip(), "0", vector, metrics)],
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
            for done_id in learned_ids:
                retry.pop(str(done_id), None)
            # What is SAVED stops behind the first photo still owed; what this
            # loop pages with (since_mod, since_id) carries on to the end.
            keep_mod, keep_id = held if held is not None else (since_mod, since_id)
            if keep_mod:
                state_set(conn, wk_mod, keep_mod)
            state_set(conn, wk_id, keep_id)
            state_set(conn, state_key(backend.name, "learned_to"), keep_id)
            state_set(conn, retry_key, json.dumps(retry))
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
    try:
        backfill_reference_quality(api, conn, backend, verbose=verbose)
    except Exception as e:
        if verbose:
            print(f"quality re-measure skipped this run: {e}")
    return added


# --------------------------------------------------------------------------- scan


# Send finished results at least this often, not only at the end of a batch.
# A face-only batch finishes in seconds, so it still goes up as one request and
# the host is not asked for more than before. Caption work takes a minute a
# photo with the large model, so a batch of 25 used to sit on the PC for half
# an hour -- invisible in the Gallery, and lost if the run was stopped.
SCAN_FLUSH_SECONDS = 60


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
    # Before loading references: a plain run that skips learning must not go on
    # suggesting a name that was merged away.
    apply_retired_names(api, conn, verbose)
    references = load_references(conn, backend.name)
    dismissal_threshold = cfg_discovery_tolerance(cfg, backend.name)
    caption_key = caption_scan_key(cfg) if include_captions else ""
    if verbose:
        print(f"reference set: {len(references)} person(s) with {MIN_REFERENCES}+ examples [{backend.name}]")
        if not include_captions:
            print("photo descriptions: not this run (add --describe to write them)")
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
    # The subset of the above that means the SERVER wants to be left alone.
    # A photo we skipped because the captioner is off is not that photo.
    backoff_ids = set()
    # Latched the first time the local captioner refuses a connection: it will
    # not come up mid-run, and asking once per photo costs a timeout each.
    captioner_down = False
    batch_no = 0
    run_started = time.time()
    photos_done = 0

    def post_results(face_results, caption_results, caption_endpoint):
        """Send what is ready and empty the lists. False if the host refused it."""
        nonlocal total_seen, total_kept, total_captions
        if not face_results and not caption_results:
            return True
        processed = set()
        fallback_seen = 0
        # A status that survived _send's backoff means the host is properly
        # unwell rather than merely busy. Say so in a sentence and stop: a
        # traceback after two hundred photos reads as though the run was wasted,
        # and it was not. Everything acknowledged is saved, and anything not
        # acknowledged is still pending server-side and comes round again.
        try:
            if face_results:
                out = api.post("/suggest", {"photos": face_results})
                acknowledged = out.get("processed_ids")
                if isinstance(acknowledged, list):
                    processed.update(int(photo_id) for photo_id in acknowledged)
                else:
                    fallback_seen += int(out.get("photos", 0))
                # The server naming rows it could not take IS the back-off signal.
                for busy in (out.get("busy_ids") or []):
                    _defer(deferred_ids, backoff_ids, int(busy))
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
                # The server naming rows it could not take IS the back-off signal.
                for busy in (out.get("busy_ids") or []):
                    _defer(deferred_ids, backoff_ids, int(busy))
                total_captions += int(out.get("captions", 0))
        except requests.RequestException as e:
            total_seen += len(processed) + fallback_seen
            print("the server could not take this batch: " + _http_error_line(e))
            print(
                "  It was asked %d times over several seconds first, so this is the host "
                "rather than a blip. Everything it acknowledged is saved; the rest is "
                "still pending and comes round again. Wait a few minutes and run again."
                % Api.SOFT_RETRIES
            )
            return False
        finally:
            face_results.clear()
            caption_results.clear()
        total_seen += len(processed) + fallback_seen
        return True

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
            try:
                report = sync_calibration(api, cfg)
                if verbose:
                    print("calibration: " + calibration_summary(report))
            except Exception as e:
                if verbose:
                    print(f"calibration: could not refresh report ({e})")
            return total_seen

        batch_no += 1
        # The whole run so far plus this batch plus what the site says is left.
        grand_total = photos_done + len(photos) + int(data.get("remaining", 0))
        if verbose:
            print(progress_line(photos_done, grand_total, time.time() - run_started), flush=True)
        face_results = []
        caption_results = []
        committed_any = False
        last_flush = time.time()
        batch_now = 0
        batch_total = len(photos)
        photo_started = time.time()
        beat = _HeartbeatTicker(
            verbose and batch_total > 0,
            15,
            lambda: (
                f"  still working: photo {photos_done + batch_now} of {grand_total}, "
                f"{time.time() - photo_started:.0f}s on it so far; {total_seen} sent, "
                f"{total_kept} face suggestion(s), {total_captions} caption(s) stored"
            ),
        )
        beat.start()
        try:
            for idx, p in enumerate(photos, start=1):
                batch_now = idx
                photo_started = time.time()
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
                            try:
                                remember_detection(conn, backend, p, image_bytes, display_pixels, found)
                            except Exception:
                                pass  # a cache that cannot be written is only a cache
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
                            _defer(deferred_ids, backoff_ids, photo_id, back_off=False)
                            if verbose:
                                print(f"  #{photo_id}: deterministic caption failure ({n}/{QUARANTINE_FAILS}) — {err}")
                    else:
                        # A real one: the photo itself, or the far end.
                        _defer(deferred_ids, backoff_ids, photo_id)
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
                    reconcile_unknown_photo(
                        conn,
                        backend,
                        p,
                        unknown,
                        dismissal_threshold,
                    )
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
                # Quarantine also sets caption_done (the photo is reported so it
                # stops coming round), but nothing was written. Without this the
                # summary line said "caption drafted and verified" straight after
                # "caption quarantined" -- 283 photos went that way unnoticed.
                caption_gave_up = False
                caption_item = None
                # Once the captioner has refused a connection, it is not going to
                # start mid-run. Asking again per photo bought nothing but a
                # timeout each and a screen of identical urllib3 text; the photos
                # stay pending either way and are captioned on a later pass.
                if needs_caption and captioner_down:
                    # Left for a later pass, but NOT counted against the stop:
                    # the captioner being off is not the server asking for room.
                    _defer(deferred_ids, backoff_ids, photo_id, back_off=False)
                    needs_caption = False

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
                                caption_gave_up = True
                                if verbose:
                                    print(
                                        f"  #{photo_id}: gave up on a description after {n} failed attempts; "
                                        "it will not be tried again automatically"
                                    )
                            else:
                                _defer(deferred_ids, backoff_ids, photo_id, back_off=False)
                                if verbose:
                                    print(f"  #{photo_id}: deterministic caption failure ({n}/{QUARANTINE_FAILS}) — {e}")
                        else:
                            _defer(deferred_ids, backoff_ids, photo_id, back_off=False)
                            if isinstance(e, requests.exceptions.ConnectionError) and not captioner_down:
                                captioner_down = True
                                print("  captions skipped for this run: " + _caption_error_line(e))
                            elif verbose and not captioner_down:
                                print(f"  #{photo_id}: caption left pending — {_caption_error_line(e)}")

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
                    if caption_gave_up:
                        parts.append("no description (gave up)")
                    elif caption_done:
                        parts.append("caption drafted and verified")
                    print(f"  {photos_done + idx}/{grand_total}  #{photo_id}: " + "; ".join(parts))
                if (face_results or caption_results) and time.time() - last_flush >= SCAN_FLUSH_SECONDS:
                    if not post_results(face_results, caption_results, caption_endpoint):
                        return total_seen
                    committed_any = True
                    last_flush = time.time()
        finally:
            beat.stop()

        if not committed_any and not face_results and not caption_results:
            if verbose:
                print("no scan results were safe to commit; leaving this batch in queue for retry")
            return total_seen

        if not post_results(face_results, caption_results, caption_endpoint):
            return total_seen
        photos_done += batch_total
        if len(backoff_ids) >= 100:
            if verbose:
                print(
                    f"{len(backoff_ids)} photo(s) the server could not take right now; "
                    "stopping this pass to let it settle. Everything already sent is saved."
                )
            return total_seen


def _defer(deferred, backoff, photo_id, back_off=True):
    """
    Leave a photo in the queue, and say whether that is also a reason to STOP.

    Two different things were being counted as one. A photo can be left for the
    next run because the SERVER could not take it - it is locked, or busy - and
    a hundred of those means the far end is struggling and the polite thing is
    to go away for a while. Or it can be left because the LOCAL CAPTIONER is not
    running, which says nothing about the server at all.

    Lumping them together meant switching Ollama off silently capped every run
    at a hundred photos: the face work was being done and sent perfectly well,
    and the pass stopped anyway, announcing "100 temporary failures" when
    nothing had failed. It read like a server problem and looked like one in the
    log - the one line that explained it scrolled past a thousand lines earlier.

    Both kinds still go in `deferred` so this run stops re-fetching them. Only
    the server's kind goes in `backoff`.
    """
    deferred.add(photo_id)
    if back_off:
        backoff.add(photo_id)


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


def _http_error_line(err):
    """A server refusal as one readable clause, never a traceback."""
    if isinstance(err, requests.HTTPError) and err.response is not None:
        body = " ".join((err.response.text or "").split())[:160]
        return "HTTP %d%s" % (err.response.status_code, (" - " + body) if body else "")
    if isinstance(err, requests.Timeout):
        return "it stopped answering"
    if isinstance(err, requests.ConnectionError):
        return "the connection dropped"
    return str(err) or err.__class__.__name__


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
        """SELECT engine, person, COUNT(*), SUM(active), AVG(quality)
           FROM refs GROUP BY engine, person ORDER BY engine, COUNT(*) DESC"""
    ).fetchall()
    by_engine = {}
    for eng, person, total, active, average_quality in rows:
        by_engine.setdefault(eng or "(unstamped)", []).append(
            (person, int(total), int(active or 0), float(average_quality or 0))
        )

    if not by_engine:
        print("reference set: empty — run with --learn once photos are tagged")
    for eng, people in by_engine.items():
        total = sum(n for _, n, _, _ in people)
        active_total = sum(active for _, _, active, _ in people)
        mark = " (active)" if resolved and eng == _resolved_name(resolved) else ""
        print(
            f"\nreference set [{eng}]{mark}: {len(people)} person(s), "
            f"{active_total}/{total} active/retained face(s)"
        )
        for person, n, active, average_quality in people[:20]:
            need = "" if active >= MIN_REFERENCES else f"  (needs {MIN_REFERENCES - active} more usable)"
            print(f"  {active:2d}/{n:<3d}  q={average_quality:.2f}  {person}{need}")
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
            report = sync_calibration(api, cfg)
            print("\nconfidence calibration:")
            print("  " + calibration_summary(report))
            print("  recommendations never change the saved WordPress threshold")
        except Exception as e:
            print(f"\nconfidence calibration: (could not ask the server: {e})")
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

    print(f"GASF face scanner {SCANNER_VERSION} — preflight\n")

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

        def embed_rgb(self, _rgb):
            return self.embed(b"")

        def distances(self, matrix, vector):
            return np.linalg.norm(matrix - vector, axis=1)

    print("GASF face scanner — selftest (no ML)\n")

    # confidence: 1.0 at a perfect match, 0.5 at the tolerance, monotone between.
    check_that(confidence(0.0, 0.5) == 1.0, "confidence: perfect match reads 1.0")
    check_that(confidence(0.5, 0.5) == 0.5, "confidence: at tolerance reads 0.5")
    check_that(confidence(0.25, 0.5) > confidence(0.4, 0.5), "confidence: nearer beats farther")

    # identify: nearest within tolerance wins; nothing past it is offered.
    backend = StubBackend()
    saved_discovery_env = os.environ.pop("GASF_FACE_DISCOVERY_TOLERANCE", None)
    try:
        check_that(
            cfg_discovery_tolerance({}, InsightFaceBackend.name) == 0.32
            and cfg_discovery_tolerance({}, FaceRecognitionBackend.name) == 0.42,
            "discovery config: resolved backend names select engine-specific defaults",
        )
        check_that(
            cfg_discovery_tolerance(
                {"discovery_tolerance": 0.27},
                InsightFaceBackend.name,
            ) == 0.27,
            "discovery config: an explicit configured threshold overrides the resolved default",
        )
    finally:
        if saved_discovery_env is not None:
            os.environ["GASF_FACE_DISCOVERY_TOLERANCE"] = saved_discovery_env
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
    _ignored_fixture = [{"box": [100, 120, 80, 80], "iw": 1000, "ih": 800}]
    check_that(
        _face_box_ignored([100, 120, 80, 80], 1000, 800, _ignored_fixture)
        # the same face on a half-size rescan: different numbers, same face
        and _face_box_ignored([50, 60, 40, 40], 500, 400, _ignored_fixture)
        # a different face on the same photo is still offered
        and not _face_box_ignored([700, 100, 80, 80], 1000, 800, _ignored_fixture)
        # and nothing is ignored when the server sent no list at all
        and not _face_box_ignored([100, 120, 80, 80], 1000, 800, []),
        "label queue: a face put down stays down across a rescan at another size",
    )
    with sqlite3.connect(":memory:") as thumb_db:
        _migrate(thumb_db)
        thumb_db.execute(
            """INSERT INTO refs (person, photo_id, engine, face_key, quality, face_width, vector, thumb)
               VALUES ('Bob', 1, 'engineA', '0', 0.40, 80, ?, ?)""",
            (np.zeros(3, dtype=np.float32).tobytes(), b"WORSE"),
        )
        thumb_db.execute(
            """INSERT INTO refs (person, photo_id, engine, face_key, quality, face_width, vector, thumb)
               VALUES ('Bob', 2, 'engineA', '0', 0.90, 200, ?, ?)""",
            (np.zeros(3, dtype=np.float32).tobytes(), b"BEST"),
        )
        # A better picture that is no longer in the active set must not win: the
        # face shown has to be one the matcher is actually reasoning from.
        thumb_db.execute(
            """INSERT INTO refs (person, photo_id, engine, face_key, quality, face_width, vector, thumb, active)
               VALUES ('Bob', 3, 'engineA', '0', 0.99, 300, ?, ?, 0)""",
            (np.zeros(3, dtype=np.float32).tobytes(), b"RETIRED"),
        )
        thumb_db.execute(
            """INSERT INTO refs (person, photo_id, engine, face_key, quality, vector)
               VALUES ('Nothumb', 4, 'engineA', '0', 0.95, ?)""",
            (np.zeros(3, dtype=np.float32).tobytes(),),
        )
        check_that(
            best_face_thumb(thumb_db, "engineA", "Bob") == b"BEST"
            and best_face_thumb(thumb_db, "engineA", "Nothumb") is None
            and best_face_thumb(thumb_db, "engineB", "Bob") is None
            and best_face_thumb(thumb_db, "engineA", "Nobody") is None,
            "labeler: the best ACTIVE face of a person is what gets shown beside their name",
        )
    # The show/hide ticks must be invisible to saving. collectLabels() reads
    # '#rows input[data-i]'; a bare '#rows input' would also match the checkboxes,
    # whose value is the string "on", so ticking one off would have saved a
    # person called "on" against an undefined box. Asserted against the HTML the
    # board is built from, because that selector IS the guarantee.
    _label_html = _label_ui_html()
    check_that(
        "'#rows input[data-i]'" in _label_html
        and "querySelectorAll('#rows input')" not in _label_html,
        "labeler: saving reads only the name inputs, never the show/hide ticks",
    )
    check_that(
        "hiddenBoxes" in _label_html and 'class="boxtoggle"' in _label_html,
        "labeler: every face has a tick that only shows or hides its rectangle",
    )
    # A finished session must stop looking like a working one. The button was
    # left disabled and still reading "Finishing...", which the stylesheet draws
    # with a wait cursor, so a saved session was indistinguishable from a hung
    # one for as long as the tab stayed open.
    check_that(
        "function finishDone(" in _label_html
        and _label_html.count("finishDone(") >= 3
        and "b.style.cursor='default'" in _label_html,
        "labeler: finishing clears the button's wait cursor instead of spinning forever",
    )
    # The discovery floor. Tied to MIN_REFERENCES because that is WHY it exists:
    # a person the matcher will not trust is a person not worth clicking for.
    _disc_all = [
        {"count": 5, "id": "a"}, {"count": 3, "id": "b"},
        {"count": 2, "id": "c"}, {"count": 1, "id": "d"}, {"count": 1, "id": "e"},
    ]
    _disc_shown = [c for c in _disc_all if c["count"] >= MIN_DISCOVERY_CLUSTER]
    check_that(
        MIN_DISCOVERY_CLUSTER == MIN_REFERENCES
        and [c["id"] for c in _disc_shown] == ["a", "b"]
        and (len(_disc_all) - len(_disc_shown)) == 3,
        "discovery: groups below the reference floor are withheld, and counted rather than dropped in silence",
    )
    check_that(
        "hidden_small" in _discovery_ui_html("selftest-token"),
        "discovery: the board says how many groups it is holding back",
    )
    # A wrong key and a doorman having a moment both arrive as 403. Telling them
    # apart is the difference between "stop, this cannot work" and "wait a
    # moment and carry on" - and getting it wrong killed a 500-photo run on the
    # 176th request, having already done the work for the first 175.
    class _FakeResp:
        def __init__(self, text):
            self.text = text
    # Switching the captioner off used to cap every run at a hundred photos:
    # the face work was done and sent, and the pass stopped anyway, reporting
    # "100 temporary failures" when nothing had failed.
    _def, _back = set(), set()
    _defer(_def, _back, 11, back_off=False)   # captioner is off
    _defer(_def, _back, 12, back_off=False)   # ditto
    _defer(_def, _back, 13)                   # the server said it was busy
    check_that(
        _def == {11, 12, 13} and _back == {13},
        "scanner: a downed captioner defers a photo without counting toward the stop",
    )

    # The detection cache: one download per photo while its image is unchanged,
    # a fresh one when the server's image_rev changes, and no caching at all
    # when the server sends no stamp.
    from PIL import Image as _DetImage

    _det_jpeg = io.BytesIO()
    _DetImage.new("RGB", (32, 24), (120, 110, 100)).save(_det_jpeg, "JPEG")

    class _DetApi:
        def __init__(self):
            self.downloads = 0

        def image(self, _url):
            self.downloads += 1
            return _det_jpeg.getvalue()

    with sqlite3.connect(":memory:") as _dconn:
        _migrate(_dconn)
        _dapi = _DetApi()
        _p = {"id": 77, "url": "u77", "image_rev": "rev-a"}
        _d1 = detect_photo(_dapi, _dconn, backend, _p)
        _d2 = detect_photo(_dapi, _dconn, backend, _p)
        _d3 = detect_photo(_dapi, _dconn, backend, {**_p, "image_rev": "rev-b"})
        _d4 = detect_photo(_dapi, _dconn, backend, {"id": 78, "url": "u78"})
        _d5 = detect_photo(_dapi, _dconn, backend, {"id": 78, "url": "u78"})
        check_that(
            not _d1.cached and _d2.cached and not _d3.cached and _dapi.downloads == 4
            and not _d4.cached and not _d5.cached,
            "detection cache: reused while the image is unchanged, redone when it changes, never without a stamp",
        )
        check_that(
            [b for b, _ in _d2.found] == [tuple(b) for b, _ in _d1.found]
            and all(np.array_equal(a, b) for (_, a), (_, b) in zip(_d1.found, _d2.found))
            and _d2.qualities == _d1.qualities and (_d2.width, _d2.height) == (32, 24)
            and _d2.thumb.startswith("data:image/"),
            "detection cache: a cached result is identical to the fresh one it replaced",
        )

    # Quality backfill: an old 0.5 row whose face is found again gets a real
    # score; one whose face is not there is marked tried-and-failed, not guessed.
    class _QApi:
        base = "https://example.invalid/faces"

        def image(self, _url):
            return _det_jpeg.getvalue()

    with sqlite3.connect(":memory:") as _qconn:
        _migrate(_qconn)
        _stub_vec = np.array([1.0, 2.0, 3.0], dtype=np.float32).tobytes()
        _far_vec = np.array([9.0, 9.0, 9.0], dtype=np.float32).tobytes()
        _qconn.execute(
            "INSERT INTO refs (person, photo_id, engine, face_key, vector) VALUES ('Anna', 5, ?, '0', ?)",
            (backend.name, _stub_vec),
        )
        _qconn.execute(
            "INSERT INTO refs (person, photo_id, engine, face_key, vector) VALUES ('Bert', 6, ?, '0', ?)",
            (backend.name, _far_vec),
        )
        _qconn.execute(
            "INSERT INTO refs (person, photo_id, engine, face_key, vector, quality) VALUES ('Cora', 7, ?, '0', ?, 0.83)",
            (backend.name, _stub_vec),
        )
        _qres = backfill_reference_quality(_QApi(), _qconn, backend, verbose=False)
        _qrows = dict(
            (r[0], r[1:]) for r in _qconn.execute("SELECT person, quality_measured, quality, face_width FROM refs")
        )
        check_that(
            _qres == (1, 1, 0)
            and _qrows["Anna"][0] == 1 and _qrows["Anna"][2] > 0
            and _qrows["Bert"][0] == 2 and abs(_qrows["Bert"][1] - 0.5) < 1e-9
            and _qrows["Cora"][0] == 1 and abs(_qrows["Cora"][1] - 0.83) < 1e-9,
            "quality backfill: an old default is re-measured, an unmatched face is marked not guessed, a real score is kept",
        )

    # A photo that could not be FETCHED says nothing about its faces: those rows
    # stay waiting. Only a photo the server says is gone is given up on.
    class _QDownApi:
        base = "https://example.invalid/faces"

        def __init__(self, status):
            self.status = status

        def image(self, _url):
            response = requests.Response()
            response.status_code = self.status
            raise requests.HTTPError(f"HTTP {self.status}", response=response)

    with sqlite3.connect(":memory:") as _qconn:
        _migrate(_qconn)
        _qconn.execute(
            "INSERT INTO refs (person, photo_id, engine, face_key, vector) VALUES ('Anna', 5, ?, '0', ?)",
            (backend.name, np.array([1.0, 2.0, 3.0], dtype=np.float32).tobytes()),
        )
        _down = backfill_reference_quality(_QDownApi(503), _qconn, backend, verbose=False)
        _after_down = _qconn.execute("SELECT quality_measured FROM refs").fetchone()[0]
        _gone = backfill_reference_quality(_QDownApi(404), _qconn, backend, verbose=False)
        _after_gone = _qconn.execute("SELECT quality_measured FROM refs").fetchone()[0]
        check_that(
            _down == (0, 0, 1) and _after_down == 0 and _gone == (0, 1, 0) and _after_gone == 2,
            "quality backfill: a photo that could not be fetched stays waiting; one that is gone is marked so",
        )

    # The choice of which examples to use is saved WITH the measurements it was
    # made from - on disk, through a second connection, with no later commit.
    with tempfile.TemporaryDirectory() as _sel_dir:
        _sel_path = os.path.join(_sel_dir, "faces.db")
        _sconn = sqlite3.connect(_sel_path)
        _migrate(_sconn)
        _sconn.execute(
            "INSERT INTO refs (person, photo_id, engine, face_key, vector, active) VALUES ('Anna', 5, ?, '0', ?, 0)",
            (backend.name, np.array([1.0, 2.0, 3.0], dtype=np.float32).tobytes()),
        )
        _sconn.commit()
        backfill_reference_quality(_QApi(), _sconn, backend, verbose=False)
        _sconn.close()  # closing rolls back anything left uncommitted
        _sconn = sqlite3.connect(_sel_path)
        _sel_row = _sconn.execute("SELECT quality_measured, active FROM refs").fetchone()
        _sconn.close()
        check_that(
            tuple(_sel_row) == (1, 1),
            "quality backfill: the reselection it triggers is committed with it, not left for a later commit",
        )

    # A database from before per-face keys is rebuilt, and the rebuild must keep
    # the columns added since - then be USED, which is what found it losing them.
    with sqlite3.connect(":memory:") as _lconn:
        _lconn.execute(
            """CREATE TABLE refs (
                   id INTEGER PRIMARY KEY, person TEXT NOT NULL, photo_id INTEGER NOT NULL,
                   engine TEXT NOT NULL DEFAULT '', vector BLOB NOT NULL, UNIQUE(photo_id, engine)
               )"""
        )
        _lconn.execute(
            "INSERT INTO refs (person, photo_id, engine, vector) VALUES ('Anna', 5, ?, ?)",
            (backend.name, np.array([1.0, 2.0, 3.0], dtype=np.float32).tobytes()),
        )
        _migrate(_lconn)
        _lcols = {row[1] for row in _lconn.execute("PRAGMA table_info(refs)")}
        try:
            _lconn.execute("UPDATE refs SET thumb = ?, quality_measured = 1 WHERE person = 'Anna'", (b"jpeg",))
            _lthumb = best_face_thumb(_lconn, backend.name, "Anna")
        except sqlite3.Error:
            _lthumb = None
        check_that(
            {"thumb", "quality_measured", "face_key"} <= _lcols
            and _lthumb == b"jpeg"
            and _lconn.execute("SELECT COUNT(*) FROM refs").fetchone()[0] == 1,
            "migration: an old database is rebuilt with every current column, and works straight away",
        )

    # Retired names: examples under a merged-away spelling are refiled under the
    # current one, and those under a removed name are dropped.
    class _RetiredApi:
        def get(self, path, **_kw):
            if path == "/people":
                return {"people": ["Susanne Kern"], "retired": [
                    {"from": "Susanne Kerm", "to": "Susanne Kern"},
                    {"from": "Nobody Really", "to": ""},
                ]}
            return {}

    with sqlite3.connect(":memory:") as _rconn:
        _migrate(_rconn)
        _v = np.array([1.0, 2.0, 3.0], dtype=np.float32).tobytes()
        for _i, _who in enumerate(["Susanne Kerm", "susanne kerm", "Susanne Kern", "Nobody Really", "Kept Person"]):
            _rconn.execute(
                "INSERT INTO refs (person, photo_id, engine, face_key, vector) VALUES (?, ?, ?, '0', ?)",
                (_who, 100 + _i, backend.name, _v),
            )
        apply_retired_names(_RetiredApi(), _rconn, verbose=False)
        _names = sorted(r[0] for r in _rconn.execute("SELECT person FROM refs"))
        check_that(
            _names == ["Kept Person", "Susanne Kern", "Susanne Kern", "Susanne Kern"],
            "retired names: local examples of a merged-away spelling are refiled, a removed name's are dropped",
        )

    # Results go up as they finish, not only when a batch ends. Driven through
    # the real scan() with a fake site: with the flush interval at zero, three
    # photos are three sends; with it at an hour, they are one, as before.
    from PIL import Image as _FlushImage

    _jpeg = io.BytesIO()
    _FlushImage.new("RGB", (16, 16), (90, 90, 90)).save(_jpeg, "JPEG")

    class _FlushApi:
        def __init__(self):
            self.posts = []
            self.served = False

        def get(self, path, **_kw):
            if path == "/queue":
                if self.served:
                    return {"photos": [], "remaining": 0}
                self.served = True
                return {
                    "photos": [{"id": i, "url": f"u{i}", "needs_faces": True} for i in (1, 2, 3)],
                    "remaining": 0,
                    "caption_endpoint": True,
                }
            return {}

        def post(self, path, payload):
            ids = [int(x["id"]) for x in payload.get("photos", [])]
            self.posts.append((path, ids))
            return {"processed_ids": ids, "suggestions": 0}

        def image(self, _url):
            return _jpeg.getvalue()

    _flush_saved = globals()["SCAN_FLUSH_SECONDS"]
    _flush_calib = globals()["sync_calibration"]
    try:
        globals()["sync_calibration"] = lambda *a, **k: {}
        _flush_counts = []
        for _interval in (0, 3600):
            globals()["SCAN_FLUSH_SECONDS"] = _interval
            _fapi = _FlushApi()
            with sqlite3.connect(":memory:") as _fconn:
                _migrate(_fconn)
                _seen = scan(_fapi, _fconn, backend, 1.0, {}, verbose=False, include_captions=False)
            _flush_counts.append(([p for p in _fapi.posts if p[0] == "/suggest"], _seen))
        check_that(
            [ids for _, ids in _flush_counts[0][0]] == [[1], [2], [3]] and _flush_counts[0][1] == 3
            and [ids for _, ids in _flush_counts[1][0]] == [[1, 2, 3]] and _flush_counts[1][1] == 3,
            "scan: finished results are sent as they go, and a fast batch is still one send",
        )
    finally:
        globals()["SCAN_FLUSH_SECONDS"] = _flush_saved
        globals()["sync_calibration"] = _flush_calib

    # 503 lives in RETRYABLE_HTTP, and used to be retried on the image route and
    # nowhere else - so a photo survived a bad minute and the captions made from
    # it did not.
    check_that(
        503 in RETRYABLE_HTTP and 500 in RETRYABLE_HTTP and 429 in RETRYABLE_HTTP
        and 404 not in RETRYABLE_HTTP and 401 not in RETRYABLE_HTTP,
        "scanner: a transient status is the host having a bad minute, not an answer",
    )
    check_that(
        _http_error_line(
            requests.HTTPError("boom", response=type("R", (), {"status_code": 503, "text": "busy"})())
        ) == "HTTP 503 - busy",
        "scanner: a server refusal reads as a sentence, not a traceback",
    )

    _api_probe = Api.__new__(Api)
    _api_probe._authed_once = False
    check_that(
        _api_probe._auth_verdict(_FakeResp('{"code":"gasf_crm_403","message":"no"}'), "u") == "fatal"
        and _api_probe._auth_verdict(_FakeResp("<html><body>Not Acceptable</body></html>"), "u") == "retry"
        and _api_probe._auth_verdict(_FakeResp(""), "u") == "retry",
        "scanner: a refusal before the key has ever worked stops the run",
    )
    # The half that was missing, and that cost a labeling session: the CRM
    # refusing a key it has already been accepting is not a bad key.
    _api_probe._authed_once = True
    check_that(
        _api_probe._auth_verdict(
            _FakeResp('{"code":"gasf_crm_auth","message":"Not signed in.","data":{"status":401}}'), "u"
        ) == "retry",
        "scanner: a refusal AFTER the key has been working is waited out, not fatal",
    )
    check_that(
        _caption_error_line(requests.exceptions.ConnectionError("boom")).startswith("the local captioner is not running")
        and "urllib3" not in _caption_error_line(requests.exceptions.ConnectionError("urllib3 nonsense")),
        "captions: a captioner that is switched off says so in one line",
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

    # Reference quality uses only local pixels and geometry.
    checker = np.indices((220, 220)).sum(axis=0) % 2
    checker = np.repeat((checker * 255).astype(np.uint8)[..., None], 3, axis=2)
    flat = np.full((220, 220, 3), 128, dtype=np.uint8)
    sharp_quality = reference_quality(checker, (30, 190, 190, 30))
    blur_quality = reference_quality(flat, (30, 190, 190, 30))
    small_quality = reference_quality(checker, (20, 50, 50, 20))
    clipped_quality = reference_quality(checker, (0, 160, 160, 0))
    check_that(
        sharp_quality["quality"] > blur_quality["quality"],
        "reference quality: sharp crops outrank blurred crops",
    )
    check_that(
        sharp_quality["quality"] > small_quality["quality"],
        "reference quality: sufficiently large faces outrank small faces",
    )
    check_that(
        sharp_quality["quality"] > clipped_quality["quality"]
        and clipped_quality["clipping"] > sharp_quality["clipping"],
        "reference quality: edge-clipped crops are penalized",
    )

    insufficient = calibration_report(
        [{"confidence": 99, "outcome": "positive"} for _ in range(29)],
        target=0.99,
        minimum=30,
    )
    sufficient = calibration_report(
        [{"confidence": 99, "outcome": "positive"} for _ in range(700)]
        + [{"confidence": 94, "outcome": "negative"} for _ in range(4)],
        target=0.99,
        minimum=30,
    )
    check_that(
        insufficient["recommended_threshold"] is None,
        "calibration: insufficient evidence cannot recommend a threshold",
    )
    check_that(
        sufficient["recommended_threshold"] == 99
        and sufficient["lower_bound"] >= 0.99
        and sufficient["recommendation_samples"] == 700,
        "calibration: recommendation uses a conservative Wilson lower bound",
    )
    bands = {band["band"]: band for band in sufficient["bands"]}
    check_that(
        bands["90-94%"]["total"] == 4
        and bands["95-99%"]["positive"] == 700,
        "calibration: confidence-band outcome counts are deterministic",
    )

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
        refresh_reference_selection(conn, "engineA", {"Hans"})
        conn.commit()
        a = load_references(conn, "engineA")
        check_that(list(a) == ["Hans"] and a["Hans"].shape == (3, 3), "db: three examples clears the floor")
        check_that(load_references(conn, "engineB") == {}, "db: engines cannot see each other's vectors")
        state_set(conn, state_key("engineA", "learned_to"), 103)
        check_that(state_get(conn, state_key("engineB", "learned_to"), "0") == "0",
                   "db: learn watermarks are per engine")
        for offset in range(18):
            person = "Diverse"
            vector = np.array(
                [float(offset // 3), float(offset % 3) / 10.0, 0.0],
                dtype=np.float32,
            )
            conn.execute(
                """INSERT INTO refs (
                       person, photo_id, engine, face_key, quality, captured_at, vector
                   ) VALUES (?, ?, ?, ?, ?, ?, ?)""",
                (
                    person,
                    700 + offset,
                    "engineA",
                    "0",
                    0.95 if offset < 15 else 0.20,
                    f"{2000 + offset:04d}-01-01",
                    vector.tobytes(),
                ),
            )
        conn.commit()
        refresh_reference_selection(conn, "engineA", {"Diverse"})
        conn.commit()
        diverse_rows = conn.execute(
            """SELECT COUNT(*), SUM(active), COUNT(DISTINCT CASE WHEN active = 1 THEN captured_at END)
               FROM refs WHERE engine = 'engineA' AND person = 'Diverse'"""
        ).fetchone()
        check_that(
            diverse_rows[0] == 18
            and MIN_REFERENCES <= diverse_rows[1] <= MAX_ACTIVE_REFERENCES,
            "reference selection: retained vectors survive while the matching subset stays bounded",
        )
        check_that(
            diverse_rows[2] >= MIN_REFERENCES,
            "reference selection: deterministic diversity preserves multiple eras and appearances",
        )
        floor_person = "Floor"
        for offset in range(MIN_REFERENCES + 3):
            conn.execute(
                """INSERT INTO refs (person, photo_id, engine, face_key, quality, vector)
                   VALUES (?, ?, ?, '0', 0.01, ?)""",
                (
                    floor_person,
                    800 + offset,
                    "engineA",
                    np.array([1.0, 1.0, 1.0], dtype=np.float32).tobytes(),
                ),
            )
        conn.commit()
        refresh_reference_selection(conn, "engineA", {floor_person})
        conn.commit()
        check_that(
            conn.execute(
                """SELECT SUM(active) FROM refs
                   WHERE engine = 'engineA' AND person = ?""",
                (floor_person,),
            ).fetchone()[0] == MIN_REFERENCES,
            "reference selection: low-quality duplicates never push a usable person below the minimum floor",
        )
        check_that(
            conn.execute(
                """SELECT COUNT(*) FROM refs
                   WHERE engine = 'engineB' AND active = 1"""
            ).fetchone()[0] == 1,
            "reference selection: reselection remains engine-isolated",
        )
        priority_items = []
        for photo_id, value in ((901, 9.0), (902, 9.04), (903, 20.0)):
            priority_items.append(
                {
                    "id": photo_id,
                    "status": "untagged",
                    "prefill": {},
                    "hints": [],
                    "known_threshold": 95,
                    "_captured_at": "2026-01-01",
                    "_active_faces": [
                        {
                            "index": 0,
                            "vector": np.array([value, 0.0, 0.0], dtype=np.float32),
                            "nearest_name": "",
                            "distance": float("inf"),
                            "quality": 0.8,
                            "recognized": False,
                        }
                    ],
                }
            )
        prioritize_active_learning(priority_items, conn, backend, 0.5)
        offered = [item for item in priority_items if item["active_learning"]]
        check_that(
            len(offered) == 1
            and offered[0]["id"] in (901, 902)
            and "_active_faces" not in offered[0],
            "active learning: a bounded filter prioritizes repeated unknown work without exposing vectors",
        )
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

        def unknown_observation(value, box=None, face_key="i:0"):
            return {
                "face_key": face_key,
                "box": list(box or [10, 10, 20, 20]),
                "image_width": 100,
                "image_height": 80,
                "vector": np.array([value, 0.0, 0.0], dtype=np.float32),
            }

        reconcile_unknown_photo(
            conn,
            backend,
            unknown_photo(601),
            [unknown_observation(0.0)],
            0.15,
        )
        reconcile_unknown_photo(
            conn,
            backend,
            unknown_photo(601),
            [unknown_observation(0.02)],
            0.15,
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
            backend,
            unknown_photo(602),
            [unknown_observation(0.10)],
            0.15,
        )
        reconcile_unknown_photo(
            conn,
            backend,
            unknown_photo(603),
            [unknown_observation(0.20)],
            0.15,
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
        other_backend = StubBackend()
        other_backend.name = "other-engine"
        reconcile_unknown_photo(
            conn,
            other_backend,
            unknown_photo(604),
            [unknown_observation(0.02)],
            0.15,
        )
        cluster_unknown_faces(conn, backend, 0.15)
        check_that(
            conn.execute(
                "SELECT cluster_id FROM unknown_faces WHERE engine = 'other-engine'"
            ).fetchone()[0] == "",
            "discovery clustering: another engine is never assigned into this engine's clusters",
        )
        reconcile_unknown_photo(
            conn,
            backend,
            unknown_photo(606),
            [unknown_observation(0.11)],
            0.15,
        )
        scoped_ids = [
            int(row[0])
            for row in conn.execute(
                """SELECT id FROM unknown_faces
                   WHERE engine = ? AND photo_id IN (601, 603)
                   ORDER BY id""",
                (backend.name,),
            )
        ]
        scoped_clusters = cluster_unknown_faces(
            conn,
            backend,
            0.15,
            scoped_ids,
        )
        scoped_photos = {
            occurrence["photo"]
            for cluster in discovery_metadata(conn, backend.name, scoped_ids)
            for occurrence in cluster["occurrences"]
        }
        check_that(
            sorted(len(ids) for _, ids in scoped_clusters) == [1, 1]
            and scoped_photos == {601, 603},
            "discovery scope: out-of-scope vectors neither cluster with nor appear beside current rows",
        )
        check_that(
            conn.execute(
                """SELECT COUNT(*) FROM unknown_faces
                   WHERE engine = ? AND photo_id IN (602, 606)""",
                (backend.name,),
            ).fetchone()[0] == 2,
            "discovery scope: out-of-scope observations remain stored for a broader later run",
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
            backend,
            unknown_photo(601),
            corrected_known,
            0.15,
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
                      engine, photo_id,
                      box_x, box_y, box_w, box_h,
                      vector, dismissed_at
                   ) VALUES (?, 605, 10, 10, 20, 20, ?, 1)""",
                (
                   backend.name,
                   np.array([0.3, 0.0, 0.0], dtype=np.float32).tobytes(),
                ),
            )
        reconcile_unknown_photo(
            conn,
            backend,
            unknown_photo(605),
            [unknown_observation(0.31, [12, 11, 20, 20], "i:1")],
            0.15,
        )
        check_that(
            conn.execute(
                """SELECT COUNT(*) FROM unknown_faces
                   WHERE engine = ? AND photo_id = 605""",
                (backend.name,),
            ).fetchone()[0] == 0,
            "discovery dismissal: the same face stays dismissed after small movement and reordering",
        )
        reconcile_unknown_photo(
            conn,
            backend,
            unknown_photo(605),
            [unknown_observation(2.0, [60, 10, 20, 20], "i:0")],
            0.15,
        )
        check_that(
            conn.execute(
                """SELECT COUNT(*) FROM unknown_faces
                   WHERE engine = ? AND photo_id = 605""",
                (backend.name,),
            ).fetchone()[0] == 1,
            "discovery dismissal: a different face moving into ordinal zero remains visible",
        )
        reconcile_unknown_photo(
            conn,
            backend,
            unknown_photo(605),
            [unknown_observation(2.0, [11, 10, 20, 20], "i:0")],
            0.15,
        )
        check_that(
            conn.execute(
                """SELECT COUNT(*) FROM unknown_faces
                   WHERE engine = ? AND photo_id = 605""",
                (backend.name,),
            ).fetchone()[0] == 1,
            "discovery dismissal: box overlap alone cannot suppress a different embedding",
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
                return encoded.getvalue()

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

        # The one pairing of labels to faces, pinned on its own.
        _mboxes = [[0, 0, 20, 20], [100, 0, 20, 20], [200, 0, 20, 20]]
        _mlabels = [
            {"name": "Anna", "box": [101, 1, 20, 20]},     # on the second face
            "not a label",
            {"name": "", "box": [0, 0, 20, 20]},           # no name
            {"name": "Berta", "box": [100, 0, 20, 20]},    # second face again: taken
            {"name": "Cora", "box": [2, 2, 20, 20]},       # on the first face
            {"name": "Dora", "box": [500, 500, 20, 20]},   # on nothing
            {"name": "Erna", "box": [200, 0, 20]},         # not a box
        ]
        _pairs = match_labels_to_boxes(_mlabels, _mboxes)
        check_that(
            [(li, bi, name) for li, bi, name, _ in _pairs] == [(0, 1, "Anna"), (4, 0, "Cora")]
            and _pairs[0][3] == [101, 1, 20, 20],
            "label matching: each label takes its best free face once; bad, unplaced and second-comer labels take none",
        )
        check_that(
            [(li, bi, name) for li, bi, name, _ in match_labels_to_boxes(
                _mlabels, _mboxes, skip=lambda name: name == "Anna")] == [(3, 1, "Berta"), (4, 0, "Cora")],
            "label matching: a skipped name does not use up the face it sits on",
        )

        # A photo that could not be downloaded is studied on the next run, and
        # the correction it carried lands - instead of the old name staying.
        class FlakyLearnApi(LearnApi):
            def __init__(self, photo, failures):
                super().__init__(photo)
                self.failures = failures

            def get(self, path, **params):
                if path != "/confirmed":
                    raise RuntimeError(f"unexpected learn path {path}")
                self.get_params.append(params)
                after = (str(params.get("after") or ""), int(params.get("after_id") or 0))
                mine = (str(self.photo["modified"]), int(self.photo["id"]))
                return {"photos": [self.photo] if mine > after else []}

            def image(self, _url):
                if self.failures > 0:
                    self.failures -= 1
                    raise requests.ConnectionError("selftest: the host dropped the connection")
                return encoded.getvalue()

        learn(LearnApi({
            "id": 502, "modified": "2026-08-09 01:00:00", "url": "selftest://502",
            "people": [], "labels": [{"name": "Anna", "box": [10, 10, 20, 20]}],
        }), conn, backend, verbose=False)
        mark_before = state_get(conn, state_key(backend.name, "learned_modified"), "")
        fix = {
            "id": 502, "modified": "2026-08-09 02:00:00", "url": "selftest://502",
            "people": [], "labels": [{"name": "Berta", "box": [10, 10, 20, 20]}],
        }
        flaky = FlakyLearnApi(fix, failures=1)
        learn(flaky, conn, backend, verbose=False)
        after_fail = conn.execute(
            "SELECT person FROM refs WHERE photo_id = 502 AND engine = ?", (backend.name,)
        ).fetchall()
        mark_held = state_get(conn, state_key(backend.name, "learned_modified"), "")
        learn(flaky, conn, backend, verbose=False)
        after_retry = conn.execute(
            "SELECT person FROM refs WHERE photo_id = 502 AND engine = ?", (backend.name,)
        ).fetchall()
        check_that(
            after_fail == [("Anna",)] and mark_held == mark_before
            and after_retry == [("Berta",)]
            and state_get(conn, state_key(backend.name, "learned_modified"), "") == fix["modified"]
            and json.loads(state_get(conn, state_key(backend.name, "learn_retry"), "{}")) == {},
            "learn: a photo that could not be downloaded holds the watermark and is studied next run",
        )
        # One the host never serves is not waited for indefinitely.
        dead = FlakyLearnApi({
            "id": 503, "modified": "2026-08-09 03:00:00", "url": "selftest://503",
            "people": [], "labels": [{"name": "Cora", "box": [10, 10, 20, 20]}],
        }, failures=99)
        for _ in range(LEARN_RETRY_RUNS):
            learn(dead, conn, backend, verbose=False)
        still_held = state_get(conn, state_key(backend.name, "learned_modified"), "") == fix["modified"]
        learn(dead, conn, backend, verbose=False)
        check_that(
            still_held
            and state_get(conn, state_key(backend.name, "learned_modified"), "") == "2026-08-09 03:00:00",
            "learn: a photo that never downloads is given up on after a few runs, not waited for indefinitely",
        )
        conn.close()

        # migration: a pre-engine database is stamped as dlib, not reinterpreted.
        legacy = Path(tempfile.mkdtemp()) / "faces.db"
        raw = sqlite3.connect(legacy)
        raw.execute("CREATE TABLE refs (id INTEGER PRIMARY KEY, person TEXT, photo_id INTEGER, vector BLOB)")
        raw.execute("INSERT INTO refs (person, photo_id, vector) VALUES ('Old', 1, ?)", (vec,))
        raw.execute(
            """CREATE TABLE unknown_dismissals (
                   engine TEXT NOT NULL,
                   photo_id INTEGER NOT NULL,
                   face_key TEXT NOT NULL,
                   dismissed_at INTEGER NOT NULL,
                   PRIMARY KEY(engine, photo_id, face_key)
               )"""
        )
        raw.execute(
            """INSERT INTO unknown_dismissals (
                   engine, photo_id, face_key, dismissed_at
               ) VALUES ('old-engine', 1, 'i:0', 1)"""
        )
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
        migrated_ref_cols = {
            row[1]
            for row in conn.execute("PRAGMA table_info(refs)")
        }
        check_that(
            {"quality", "redundancy", "active", "captured_at"}.issubset(migrated_ref_cols)
            and conn.execute("SELECT COUNT(*) FROM refs").fetchone()[0] == 1,
            "db: legacy references gain quality metadata without data loss",
        )
        dismissal_cols = {
            row[1]
            for row in conn.execute("PRAGMA table_info(unknown_dismissals)")
        }
        check_that(
            "vector" in dismissal_cols
            and conn.execute("SELECT COUNT(*) FROM unknown_dismissals").fetchone()[0] == 0,
            "db: unsafe ordinal-only dismissals are invalidated during migration",
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
        progress_line(0, 339, 0) == "339 photo(s) waiting."
        and progress_line(50, 339, 3000) == "50 of 339 photo(s) done; at the pace so far, about 4h 49m to go."
        and progress_line(339, 339, 900).endswith("about 0s to go.")
        and "batch" not in progress_line(25, 339, 100).lower(),
        "scan: progress is told in photos of the whole run, with time left once there is a pace",
    )
    check_that(
        "Tampa Bay" not in CAPTION_SYSTEM and f"the {CLUB_NAME}" in CAPTION_SYSTEM,
        "caption: the prompt names the club correctly",
    )
    check_that(
        _clean_caption("Members of the German-American Society of Tampa Bay gather in the hall.")
        == "Members of the German-American Society gather in the hall."
        and _clean_caption("Dancers at the Tampa Bay German American Society's Oktoberfest.")
        == "Dancers at the German-American Society's Oktoberfest."
        and _clean_caption("A German-American Society Friendship of Pinellas County banner.")
        == "A German-American Society Friendship of Pinellas County banner.",
        "caption: an invented 'Tampa Bay' club name is corrected, the real ones kept",
    )
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

        # WebP must reach Ollama as JPEG: Ollama 0.32 answers 400 to WebP, which
        # failed every WebP photo's caption while its faces scanned fine.
        from PIL import Image as _Image, features as _features

        if _features.check("webp"):
            buf = io.BytesIO()
            _Image.new("RGBA", (8, 8), (200, 40, 40, 128)).save(buf, "WEBP")
            caption_calls.clear()
            local_caption(buf.getvalue(), cap_cfg, {})
            sent = base64.b64decode(caption_calls[0]["json"]["images"][0])
            check_that(
                sent[:3] == b"\xff\xd8\xff" and len(caption_calls) == 2,
                "caption: WebP is re-encoded as JPEG before it reaches Ollama",
            )
        jpeg = io.BytesIO()
        _Image.new("RGB", (8, 8), (10, 20, 30)).save(jpeg, "JPEG")
        caption_calls.clear()
        local_caption(jpeg.getvalue(), cap_cfg, {})
        check_that(
            base64.b64decode(caption_calls[0]["json"]["images"][0]) == jpeg.getvalue(),
            "caption: JPEG reaches Ollama byte-for-byte unchanged",
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
        def image(self, _url):
            return b"\xff\xd8\xff\xe0 not a real picture"

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
                "active_learning": True,
                "active_score": 0.75,
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
                and '<option value="active">Active learning</option>' in page.text
                and "setupViewControls()" in page.text,
                "label UI: side controls and the optional active-learning filter are present",
            )
            # Source shape only, and said to be: the page's behaviour cannot be
            # driven from here without a browser. This pins that a save compares
            # the edit count it sent with, and that a thumbnail saves before it
            # opens - not that either works.
            check_that(
                "if(editSeq===sentSeq){ dirty=false; }" in page.text
                and "await saveAndOpen(parseInt(b.getAttribute('data-i'),10)||0);" in page.text,
                "label UI: a save only clears edits it actually sent, and a thumbnail saves first (source check)",
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
            # A save is remembered by the board. Without that, coming back to
            # the photo shows empty fields, and the next save - which replaces
            # the photo's labels - deletes the name saved the first time.
            requests.post(
                base + "/api/save",
                headers=headers,
                json={"photo": 7, "labels": [{"name": "Anna", "box": [10, 10, 20, 20]}]},
                timeout=3,
            )
            reopened = requests.get(base + "/api/photo?i=0", headers=headers, timeout=3)
            gallery = requests.get(base + "/api/meta", headers=headers, timeout=3).json().get("gallery") or [{}]
            check_that(
                reopened.status_code == 200
                and reopened.json().get("prefill") == {"0": "Anna"}
                and gallery[0].get("status") == "full",
                "label UI: a saved name is still there when the photo is reopened",
            )
            requests.post(
                base + "/api/save",
                headers=headers,
                json={"photo": 7, "labels": []},
                timeout=3,
            )
            check_that(
                requests.get(base + "/api/photo?i=0", headers=headers, timeout=3).json().get("prefill") == {},
                "label UI: clearing the names is remembered too",
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
                # Two: the reopen check above saved Anna once, and finish saved her again.
                not label_thread.is_alive() and label_result.get("saved") == 2,
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
            backend,
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
            0.15,
        )
        # Two more sightings of the same face, so the group reaches
        # MIN_DISCOVERY_CLUSTER and the board still offers it. This test is about
        # the board's lifecycle - tokens, CSP, naming, cleanup - and a fixture
        # that fell under the floor would fail it for an unrelated reason.
        for extra_photo in (702, 703):
            reconcile_unknown_photo(
                discovery_conn,
                backend,
                {
                    "id": extra_photo,
                    "url": f"selftest://{extra_photo}",
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
                0.15,
            )
        discovery_scope = [
            int(row[0])
            for row in discovery_conn.execute(
                "SELECT id FROM unknown_faces WHERE engine = ?",
                (backend.name,),
            )
        ]
        cluster_unknown_faces(discovery_conn, backend, 0.15, discovery_scope)
        globals()["_open_preview_html"] = capture_discovery_url

        def run_discovery_board():
            local_discovery_board(
                DiscoveryApi(encoded.getvalue()),
                discovery_conn,
                backend,
                0.15,
                ["Anna"],
                discovery_scope,
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
            # Every occurrence in the group, because naming one face of a person
            # and leaving their others in the queue is not what the board does:
            # "one name applies to all selected faces", and all of them are
            # selected by default.
            occurrence_ids = [o["id"] for o in board_meta["clusters"][0]["occurrences"]]
            crop = requests.get(
                base + f"/api/crop?id={occurrence_id}",
                headers=headers,
                timeout=3,
            )
            crop_again = requests.get(
                base + f"/api/crop?id={occurrence_id}",
                headers=headers,
                timeout=3,
            )
            check_that(
                crop.status_code == 200
                and crop.headers.get("Content-Type") == "image/jpeg",
                "discovery UI: representative crops are generated locally",
            )
            check_that(
                crop.headers.get("X-GASF-Crop-Cache") == "miss"
                and crop_again.headers.get("X-GASF-Crop-Cache") == "hit"
                and crop_again.content == crop.content,
                "discovery UI: a crop is made once and then served from memory",
            )
            _disc_html = _discovery_ui_html("selftest-token")
            check_that(
                "URL.revokeObjectURL" in _disc_html and "pruneCropCache()" in _disc_html,
                "discovery UI: the browser frees crops that are no longer on screen",
            )
            named = requests.post(
                base + "/api/name",
                headers=headers,
                json={
                    "cluster": board_meta["clusters"][0]["id"],
                    "name": "Anna",
                    "selected": occurrence_ids,
                },
                timeout=3,
            ).json()
            sent_occurrence = discovery_posts[0]["occurrences"][0]
            check_that(
                named.get("applied") == len(occurrence_ids)
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
            flow_events == ["learn", "scan-faces", "label", "learn", "scan-faces"]
            and flow_stored == 2,
            "label flow: learn and face-scan before labeling, then relearn and face-scan again (no captions)",
        )
    finally:
        globals()["learn"] = original_learn
        globals()["scan"] = original_scan
        globals()["local_label"] = original_label

    print("\n" + ("selftest passed." if not failures else f"selftest FAILED: {len(failures)} problem(s)."))
    return 0 if not failures else 1


# --------------------------------------------------------------------------- main


def _duration(seconds):
    """'3h 20m', '12m', '45s' -- for progress lines a person reads."""
    seconds = int(max(0, seconds))
    h, rem = divmod(seconds, 3600)
    m, s = divmod(rem, 60)
    if h:
        return f"{h}h {m:02d}m"
    if m:
        return f"{m}m"
    return f"{s}s"


def progress_line(done, total, seconds_spent):
    """
    Where the whole run stands, in photos -- not in batches.

    The scanner asks the site for work 25 photos at a time (small requests the
    host's firewall tolerates, and each round picks up anything newly uploaded),
    but that is plumbing. What a volunteer wants is "how many, and how long".
    The estimate appears only once some photos are done: before that there is no
    pace, and caption work runs far slower than face work, so a guess misleads.
    """
    if done <= 0 or seconds_spent <= 0:
        return f"{total} photo(s) waiting."
    left = max(0, total - done) * (seconds_spent / done)
    return f"{done} of {total} photo(s) done; at the pace so far, about {_duration(left)} to go."


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
        print("label flow 5/5: final face scan with refreshed references (descriptions are their own task)")
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
    ap.add_argument("--version", action="version", version=f"GASF face scanner {SCANNER_VERSION}")
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
    # Names only unless descriptions are asked for. Captions take a minute or
    # more a photo with the large model against well under a second for faces,
    # and a plain run is what a volunteer types to get name suggestions.
    ap.add_argument(
        "--describe", action="store_true",
        help="also write photo descriptions with the local caption model (slow; best left running)",
    )
    ap.add_argument(
        "--no-captions", action="store_true",
        help="names only -- the default since 1.5.1; kept so older commands still work",
    )
    args = ap.parse_args()
    if args.discover and (args.label or args.label_flow or args.learn or args.watch):
        ap.error("--discover cannot be combined with --label, --label-flow, --learn, or --watch")
    if args.describe and args.no_captions:
        ap.error("--describe and --no-captions contradict each other")
    if (args.describe or args.no_captions) and (args.label or args.discover):
        ap.error("--describe and --no-captions apply to scanning, not to --label or --discover")

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
    verbose = not args.quiet
    describe = args.describe
    if describe and not cfg_caption_model(cfg):
        # Warn and carry on with names rather than fail: the scheduled task
        # passes --describe, and a laptop without a caption model must still
        # get its name suggestions every run.
        print(
            "No caption model is set up, so no photo descriptions will be written this run; "
            "suggesting names only. To add descriptions, set \"caption_model\" in config.json "
            "(for example \"qwen3-vl:8b\") and run Check my setup.",
            flush=True,
        )
        describe = False
    if verbose:
        print(f"GASF face scanner {SCANNER_VERSION}")
        print(
            "Loading the face-recognition models. This takes a minute or so "
            "(longer the first time after a restart) and nothing else happens until it finishes...",
            flush=True,
        )
    started = time.time()
    backend = build_backend(engine)
    if verbose:
        where = getattr(backend, "device", "")
        print(
            f"Face recognition ready ({backend.name}{' on the ' + where if where else ''}) "
            f"in {time.time() - started:.0f}s.",
            flush=True,
        )
    tolerance = cfg_tolerance(cfg, engine)
    api = Api(url, key)
    conn = db()
    uploaded_after = parse_ymd(args.uploaded_after, "--uploaded-after")
    uploaded_before = parse_ymd(args.uploaded_before, "--uploaded-before")
    if uploaded_after and uploaded_before and uploaded_after > uploaded_before:
        sys.exit("--uploaded-after must be on or before --uploaded-before")

    if args.discover:
        discovery_limit = args.discovery_limit or cfg_discovery_limit(cfg)
        if discovery_limit < 1 or discovery_limit > 1000:
            sys.exit("--discovery-limit must be between 1 and 1000")
        threshold = cfg_discovery_tolerance(cfg, backend.name)
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
        scan(
            api, conn, backend, tolerance, cfg, verbose, uploaded_after, uploaded_before,
            include_captions=describe,
        )
        if not args.watch:
            break
        if verbose:
            print(f"— sleeping {args.watch}s —")
        time.sleep(args.watch)


if __name__ == "__main__":
    main()
