#!/usr/bin/env python3
"""
GASF-CRM face scanner — runs on a private machine, never on the web host.

    python scan.py                 # scan whatever is waiting, then stop
    python scan.py --learn         # refresh the reference set first
    python scan.py --watch 900     # keep going, every 15 minutes
    python scan.py --status        # what do I know, and what is waiting

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

SETUP
-----
    pip install face_recognition requests
    (face_recognition needs dlib; on Windows the easiest route is
     `pip install dlib-bin` first, or use WSL.)

    Then either set environment variables:
        set GASF_URL=https://germantampabay.com
        set GASF_FACE_KEY=gasf_face_xxxxxxxx
    or copy config.example.json to config.json and fill it in.

The key comes from wp-admin → Email CRM → Photos → Face suggestions.
It is shown once. If you lose it, issue a new one.
"""

import argparse
import io
import json
import os
import sqlite3
import sys
import time
from pathlib import Path

try:
    import requests
except ImportError:
    sys.exit("pip install requests")

try:
    import face_recognition
    import numpy as np
except ImportError:
    sys.exit("pip install face_recognition   (needs dlib; see the docstring)")


HERE = Path(__file__).resolve().parent
DB_PATH = HERE / "faces.db"

# How close two faces must be to count as the same person. face_recognition
# works in euclidean distance where LOWER is more alike; 0.6 is the library's
# own default and is already fairly permissive. 0.5 is deliberately stricter:
# a missed suggestion costs a volunteer one typed name, while a confident wrong
# one puts a member's name on a stranger's face in the club archive. Those are
# not symmetric mistakes and the threshold should not pretend they are.
TOLERANCE = 0.50

# Below this many reference faces, a person is not offered at all. One photo of
# somebody is an accident waiting to happen — a bad angle becomes "the system
# thinks everyone is Hans".
MIN_REFERENCES = 3


# --------------------------------------------------------------------------- config


def load_config():
    cfg = {}
    path = HERE / "config.json"
    if path.exists():
        cfg = json.loads(path.read_text(encoding="utf-8"))
    url = os.environ.get("GASF_URL", cfg.get("url", "")).rstrip("/")
    key = os.environ.get("GASF_FACE_KEY", cfg.get("key", ""))
    if not url or not key:
        sys.exit(
            "Missing configuration.\n"
            "  Set GASF_URL and GASF_FACE_KEY, or create config.json next to this script.\n"
            "  The key comes from wp-admin -> Email CRM -> Photos -> Face suggestions."
        )
    return url, key


class Api:
    """The CRM, reached the only way this machine talks to anything: outward."""

    def __init__(self, base, key):
        self.base = base + "/wp-json/gasf/v1/crm/photos/faces"
        self.s = requests.Session()
        self.s.headers["Authorization"] = "Bearer " + key
        self.s.headers["User-Agent"] = "gasf-face-scanner/1.0"

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
        r = self.s.get(url, timeout=120)
        r.raise_for_status()
        return r.content


# --------------------------------------------------------------------------- store


def db():
    conn = sqlite3.connect(DB_PATH)
    conn.execute(
        """CREATE TABLE IF NOT EXISTS refs (
               id INTEGER PRIMARY KEY,
               person TEXT NOT NULL,
               photo_id INTEGER NOT NULL,
               vector BLOB NOT NULL,
               UNIQUE(person, photo_id)
           )"""
    )
    conn.execute("CREATE TABLE IF NOT EXISTS state (k TEXT PRIMARY KEY, v TEXT)")
    conn.execute("CREATE INDEX IF NOT EXISTS refs_person ON refs(person)")
    return conn


def state_get(conn, k, default=""):
    row = conn.execute("SELECT v FROM state WHERE k = ?", (k,)).fetchone()
    return row[0] if row else default


def state_set(conn, k, v):
    conn.execute("INSERT INTO state (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", (k, str(v)))
    conn.commit()


def load_references(conn):
    """person -> stacked vectors, for people with enough examples to trust."""
    people, vectors = {}, {}
    for person, blob in conn.execute("SELECT person, vector FROM refs"):
        vectors.setdefault(person, []).append(np.frombuffer(blob, dtype=np.float64))
    for person, vs in vectors.items():
        if len(vs) >= MIN_REFERENCES:
            people[person] = np.vstack(vs)
    return people


# --------------------------------------------------------------------------- faces


def faces_in(image_bytes):
    """[(box_in_css_order, vector)] for every face found."""
    img = face_recognition.load_image_file(io.BytesIO(image_bytes))
    boxes = face_recognition.face_locations(img, model="hog")
    if not boxes:
        return []
    vectors = face_recognition.face_encodings(img, boxes)
    return list(zip(boxes, vectors))


def identify(vector, references):
    """
    Closest person within tolerance, as (name, confidence) or (None, 0).

    Confidence is a readable rendering of distance, not a probability — the
    volunteer sees a percentage and it should move the way intuition expects:
    at the tolerance it is ~0, at a perfect match ~1.
    """
    best_name, best_dist = None, 999.0
    for name, vs in references.items():
        d = float(np.min(face_recognition.face_distance(vs, vector)))
        if d < best_dist:
            best_name, best_dist = name, d
    if best_name is None or best_dist > TOLERANCE:
        return None, 0.0
    return best_name, round(max(0.0, 1.0 - (best_dist / TOLERANCE)) * 0.5 + 0.5, 3)


# --------------------------------------------------------------------------- learn


def learn(api, conn, verbose=True):
    """
    Grow the reference set from photos volunteers have actually tagged.

    Only unambiguous pairs are learned: one face in the photo, one name on it.
    A crowd shot with six names cannot say which face is which, and guessing
    would poison the reference set with confident nonsense — the failure mode
    that makes a system like this worse than nothing.
    """
    since = int(state_get(conn, "learned_to", "0"))
    added = skipped = 0

    while True:
        data = api.get("/confirmed", since=since, limit=100)
        photos = data.get("photos", [])
        if not photos:
            break

        for p in photos:
            since = max(since, int(p["id"]))
            people = [n for n in p.get("people", []) if n.strip()]
            if len(people) != 1:
                skipped += 1
                continue
            try:
                found = faces_in(api.image(p["url"]))
            except Exception as e:  # a missing file must not stop the run
                if verbose:
                    print(f"  #{p['id']}: {e}")
                continue
            if len(found) != 1:
                skipped += 1
                continue

            _, vector = found[0]
            conn.execute(
                "INSERT OR IGNORE INTO refs (person, photo_id, vector) VALUES (?, ?, ?)",
                (people[0].strip(), int(p["id"]), vector.astype(np.float64).tobytes()),
            )
            added += 1

        conn.commit()
        state_set(conn, "learned_to", since)

    if verbose:
        print(f"learned: {added} new reference face(s); {skipped} photo(s) too ambiguous to learn from")
    return added


# --------------------------------------------------------------------------- scan


def scan(api, conn, verbose=True):
    references = load_references(conn)
    if verbose:
        print(f"reference set: {len(references)} person(s) with {MIN_REFERENCES}+ examples")

    data = api.get("/queue")
    photos = data.get("photos", [])
    if not photos:
        if verbose:
            print("nothing waiting")
        return 0

    results = []
    for p in photos:
        try:
            found = faces_in(api.image(p["url"]))
        except Exception as e:
            if verbose:
                print(f"  #{p['id']}: {e}")
            # Reported as looked-at-and-found-nothing rather than left in the
            # queue forever: a file this machine cannot read will never become
            # readable by asking again.
            results.append({"id": p["id"], "found": 0, "faces": []})
            continue

        already = {n.strip().lower() for n in p.get("people", [])}
        faces = []
        for (top, right, bottom, left), vector in found:
            name, conf = identify(vector, references)
            if not name or name.lower() in already:
                continue
            faces.append(
                {
                    "name": name,
                    "confidence": conf,
                    "box": [int(left), int(top), int(right - left), int(bottom - top)],
                }
            )

        results.append({"id": p["id"], "found": len(found), "faces": faces})
        if verbose:
            names = ", ".join(f["name"] for f in faces) or "no one recognised"
            print(f"  #{p['id']}: {len(found)} face(s) — {names}")

    out = api.post("/suggest", {"photos": results})
    if verbose:
        print(f"sent {out.get('photos', 0)} photo(s), {out.get('suggestions', 0)} suggestion(s) kept")
        print(f"{data.get('remaining', 0)} still waiting")
    return len(results)


def status(api, conn):
    refs = conn.execute("SELECT person, COUNT(*) FROM refs GROUP BY person ORDER BY COUNT(*) DESC").fetchall()
    print(f"reference set: {len(refs)} person(s), {sum(n for _, n in refs)} face(s) total")
    for person, n in refs[:20]:
        mark = "" if n >= MIN_REFERENCES else f"  (needs {MIN_REFERENCES - n} more to be offered)"
        print(f"  {n:3d}  {person}{mark}")
    if len(refs) > 20:
        print(f"  ... and {len(refs) - 20} more")
    print(f"\nlearned up to photo #{state_get(conn, 'learned_to', '0')}")
    print(f"waiting to be scanned: {api.get('/queue', limit=1).get('remaining', 0)}")
    print(f"\nvectors live in {DB_PATH} and nowhere else.")


# --------------------------------------------------------------------------- main


def main():
    ap = argparse.ArgumentParser(description="Suggest who is in the club's photos. Suggestions only — never tags.")
    ap.add_argument("--learn", action="store_true", help="refresh the reference set from confirmed tags first")
    ap.add_argument("--watch", type=int, metavar="SECONDS", help="keep running, pausing this long between passes")
    ap.add_argument("--status", action="store_true", help="show what is known and what is waiting")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    url, key = load_config()
    api = Api(url, key)
    conn = db()
    verbose = not args.quiet

    if args.status:
        status(api, conn)
        return

    while True:
        if args.learn or not load_references(conn):
            learn(api, conn, verbose)
        scan(api, conn, verbose)
        if not args.watch:
            break
        if verbose:
            print(f"— sleeping {args.watch}s —")
        time.sleep(args.watch)


if __name__ == "__main__":
    main()
