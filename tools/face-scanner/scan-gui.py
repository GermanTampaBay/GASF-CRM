#!/usr/bin/env python3
"""Friendly launcher for scan.py.

Every scanner mode is presented as a task in plain words - what it does, when
you would want it, and what it changes - rather than as a command-line flag.
The tasks are mutually exclusive by construction (you pick one), so the
unsupported flag combinations the old checkbox launcher had to police cannot
be expressed at all. Output streams into the same window.
"""

import json
import os
import queue
import shutil
import subprocess
import sys
import threading
from datetime import date, datetime, timedelta
from pathlib import Path
import tkinter as tk
from tkinter import filedialog, font as tkfont, messagebox, ttk


# The scanner beside this launcher, which is the right answer when the two were
# checked out together.
ADJACENT_SCAN_PY = str(Path(__file__).resolve().with_name("scan.py"))

# Where the remembered choice lives.
#
# Deliberately OUTSIDE any checkout. This launcher gets run from whichever copy
# of the repo is to hand - a worktree, an unpacked installer, a folder from
# months ago - and each of those has its own scan.py beside it. Running the one
# that happens to be adjacent is how a nine-day-old scanner on an abandoned
# branch kept being used while the real one sat updated somewhere else, with
# nothing on screen to say so. Remembering the path here means the answer
# survives the folder it was chosen from being deleted.
CONFIG_DIR = Path(
    os.environ.get("LOCALAPPDATA")
    or os.environ.get("XDG_CONFIG_HOME")
    or (Path.home() / ".config")
) / "GASF-Face-Scanner"
CONFIG_PATH = CONFIG_DIR / "launcher.json"


def load_prefs():
    try:
        data = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def save_prefs(**changes):
    """Merge into launcher.json; a value of None removes that key."""
    prefs = load_prefs()
    for k, v in changes.items():
        if v is None:
            prefs.pop(k, None)
        else:
            prefs[k] = v
    try:
        CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        CONFIG_PATH.write_text(json.dumps(prefs, indent=2), encoding="utf-8")
        return True
    except Exception:
        return False


def load_saved_scan_py():
    """The remembered scanner, if it is still there. Never guesses."""
    saved = str(load_prefs().get("scan_py") or "").strip()
    return saved if saved and os.path.isfile(saved) else ""


def save_scan_py(path):
    """Remember this scanner for next time. Failing to is not worth an error."""
    return save_prefs(scan_py=str(path))


def resolve_scan_py():
    """
    Remembered first, adjacent second.

    A saved path that has gone missing is ignored rather than reported as
    broken: the usual cause is a worktree that was tidied up, and falling back
    to the scanner beside this file is what the launcher did before any of this
    existed. The window says which one is in use either way.
    """
    return load_saved_scan_py() or ADJACENT_SCAN_PY


SCAN_PY = resolve_scan_py()


# ---------------------------------------------------------------------------
# Look and feel

COLORS = {
    "bg": "#f3f4f7",
    "surface": "#ffffff",
    "border": "#e1e4ea",
    "text": "#1c2430",
    "muted": "#5d6776",
    "faint": "#8a93a1",
    "accent": "#1f4d7a",
    "accent_hover": "#163a5d",
    "accent_soft": "#e7eef6",
    "hover": "#f1f3f6",
    "ok": "#1e7a46",
    "ok_soft": "#e6f4ec",
    "warn": "#9a6700",
    "warn_soft": "#fdf3dc",
    "err": "#b42318",
    "err_soft": "#fdecea",
    "log_bg": "#121821",
    "log_text": "#d5dce5",
    "log_dim": "#7f8b9b",
    "log_err": "#ff8f85",
    "log_ok": "#7fd6a0",
}


# ---------------------------------------------------------------------------
# The tasks, in words a volunteer would use.
#
# "learn", "label", and "discover" are the scanner's internal names. They mean,
# respectively: study the photos volunteers have already tagged; show me faces
# you could not place so I can name them; and group unnamed lookalike faces so
# I can name a whole group at once. The copy below says that instead.

TASKS = [
    {
        "key": "scan",
        "group": "Everyday",
        "title": "Suggest names for new photos",
        "short": "Find who is in photos it has not looked at yet.",
        "about": (
            "Goes through library photos the scanner has not seen yet and works out "
            "who is in each one. Confident matches are tagged automatically; the "
            "rest wait as suggestions in the photo editor for a volunteer to accept "
            "or reject. If a caption model is set up, it also drafts a short "
            "description of each photo."
        ),
        "when": "Use this after new photos have been added to the library.",
        "button": "Start suggesting",
        "running": "Looking through new photos...",
        "dates": True,
    },
    {
        "key": "label",
        "group": "Everyday",
        "title": "Name faces it does not know",
        "short": "Tell it who the faces are that it cannot place.",
        "about": (
            "Opens a page in your web browser showing photos with faces the scanner "
            "could not identify. Click a face, type who it is, and save. Every name "
            "you give becomes a new example it learns from, so its suggestions get "
            "better each time."
        ),
        "when": "Use this when suggestions are missing or wrong for people you know.",
        "button": "Open the naming page",
        "running": "Getting photos ready. Your browser will open when they are...",
        "dates": True,
    },
    {
        "key": "discover",
        "group": "Everyday",
        "title": "Group lookalike faces",
        "short": "Name a whole group of similar faces at once.",
        "about": (
            "Collects faces nobody has named yet, sorts the ones that look like the "
            "same person into groups, and shows each group as a contact sheet in "
            "your browser. Untick any face that does not belong, type one name, and "
            "the whole group is named together. The grouping happens entirely on "
            "this computer."
        ),
        "when": "Use this to catch up quickly on people who appear in many photos.",
        "button": "Open the groups page",
        "running": "Grouping faces. Your browser will open when they are ready...",
        "dates": True,
    },
    {
        "key": "watch",
        "group": "Everyday",
        "title": "Keep running on a timer",
        "short": "Repeat the suggesting on a schedule until you stop it.",
        "about": (
            "Does everything \"Suggest names for new photos\" does, then waits and "
            "does it again, over and over, until you press Stop or close this "
            "window. New tags from volunteers are studied at the start of every "
            "round."
        ),
        "when": "Use this to leave the scanner working during an event or overnight.",
        "button": "Start the timer",
        "running": "Running on a timer. Press Stop to end it...",
        "dates": True,
    },
    {
        "key": "status",
        "group": "Information",
        "title": "Show progress",
        "short": "See what it knows and what is still waiting.",
        "about": (
            "A quick summary: how many people it can recognize, how good its "
            "example photos are, how many photos are still waiting to be looked "
            "at, and how trustworthy its confidence scores currently are. Changes "
            "nothing."
        ),
        "when": "Use this when you are curious how far along it is.",
        "button": "Show progress",
        "running": "Gathering the numbers...",
        "dates": False,
    },
    {
        "key": "check",
        "group": "Information",
        "title": "Check my setup",
        "short": "Make sure everything is installed and connected.",
        "about": (
            "Confirms that the face-recognition software is installed and loads, "
            "the settings file is valid, the local database can be written, the "
            "caption model is available, and the club website accepts this "
            "computer's scanner key. Changes nothing."
        ),
        "when": "Use this first on a new computer, or whenever something seems off.",
        "button": "Run the check",
        "running": "Checking...",
        "dates": False,
    },
]
TASK_BY_KEY = {t["key"]: t for t in TASKS}

SETTINGS_ITEM = {
    "key": "settings",
    "group": "Settings",
    "title": "Advanced settings",
    "short": "Recognition engine, scanner file, and troubleshooting.",
}

HOW_IT_WORKS = [
    ("1", "Study", "It learns what each person looks like from photos volunteers have already tagged."),
    ("2", "Suggest", "It looks at new photos and suggests who is in them."),
    ("3", "Teach", "When it is unsure, you name the faces yourself, and it gets better."),
]

ENGINE_CHOICES = [
    ("Automatic (recommended)", "auto"),
    ("InsightFace", "insightface"),
    ("face_recognition (dlib)", "face_recognition"),
]


def enable_crisp_text():
    """Opt into per-monitor DPI so Windows does not blur the whole window."""
    if sys.platform != "win32":
        return
    try:
        import ctypes

        ctypes.windll.shcore.SetProcessDpiAwareness(1)
    except Exception:
        pass


class WrapLabel(tk.Label):
    """A label that re-wraps its text to whatever width it is given."""

    def __init__(self, parent, **kw):
        super().__init__(parent, justify="left", anchor="w", **kw)
        self.bind("<Configure>", lambda e: self.configure(wraplength=max(120, e.width - 4)))


class ScanGui(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("GASF Face Scanner")
        self.geometry("1120x%d" % min(800, max(660, self.winfo_screenheight() - 90)))
        self.minsize(980, 660)
        self.configure(bg=COLORS["bg"])

        self.proc = None
        self.worker = None
        self.lines = queue.Queue()
        self.stop_requested = False
        self.running_task = None

        self.v_task = tk.StringVar(value="scan")
        self.v_study_first = tk.BooleanVar(value=True)
        self.v_label_flow = tk.BooleanVar(value=True)
        self.v_label_limit = tk.StringVar(value="500")
        self.v_discovery_limit = tk.StringVar(value="1000")
        self.v_watch_minutes = tk.StringVar(value="15")
        self.v_range = tk.StringVar(value="all")
        self.v_uploaded_after = tk.StringVar(value="")
        self.v_uploaded_before = tk.StringVar(value="")
        self.v_quiet = tk.BooleanVar(value=False)
        self.v_engine_label = tk.StringVar(value=ENGINE_CHOICES[0][0])
        self.v_scan_py = tk.StringVar(value=SCAN_PY)
        self.v_python = tk.StringVar(value="")

        self._fonts()
        self._styles()
        self._build()
        self.v_python.set("Runs with " + self._python_label())
        self._select("scan")
        self.protocol("WM_DELETE_WINDOW", self._on_close)

    # -- look ---------------------------------------------------------------

    def _fonts(self):
        families = set(tkfont.families(self))
        ui = next(
            (f for f in ("Segoe UI Variable Text", "Segoe UI", "SF Pro Text", "Helvetica Neue", "Arial") if f in families),
            "TkDefaultFont",
        )
        mono = next((f for f in ("Cascadia Mono", "Consolas", "Menlo", "Courier New") if f in families), "TkFixedFont")
        self.f_body = tkfont.Font(family=ui, size=10)
        self.f_small = tkfont.Font(family=ui, size=9)
        self.f_bold = tkfont.Font(family=ui, size=10, weight="bold")
        self.f_nav = tkfont.Font(family=ui, size=10, weight="bold")
        self.f_group = tkfont.Font(family=ui, size=8, weight="bold")
        self.f_h1 = tkfont.Font(family=ui, size=16, weight="bold")
        self.f_h2 = tkfont.Font(family=ui, size=14, weight="bold")
        self.f_step_n = tkfont.Font(family=ui, size=11, weight="bold")
        self.f_mono = tkfont.Font(family=mono, size=9)
        self.option_add("*Font", self.f_body)

    def _styles(self):
        c = COLORS
        s = ttk.Style(self)
        try:
            s.theme_use("clam")
        except tk.TclError:
            pass
        s.configure(".", font=self.f_body, background=c["surface"], foreground=c["text"])
        s.configure(
            "Primary.TButton", font=self.f_bold, padding=(18, 9), background=c["accent"],
            foreground="#ffffff", borderwidth=0, focusthickness=0, focuscolor=c["accent"],
        )
        s.map(
            "Primary.TButton",
            background=[("disabled", "#9fb3c8"), ("pressed", c["accent_hover"]), ("active", c["accent_hover"])],
            foreground=[("disabled", "#eef2f6")],
        )
        s.configure(
            "Secondary.TButton", padding=(14, 8), background=c["surface"], foreground=c["text"],
            bordercolor=c["border"], lightcolor=c["surface"], darkcolor=c["surface"], focuscolor=c["surface"],
        )
        s.map("Secondary.TButton", background=[("disabled", c["surface"]), ("active", c["hover"])],
              foreground=[("disabled", c["faint"])])
        s.configure(
            "Danger.TButton", font=self.f_bold, padding=(18, 9), background=c["surface"], foreground=c["err"],
            bordercolor=c["err"], lightcolor=c["surface"], darkcolor=c["surface"], focuscolor=c["surface"],
        )
        s.map("Danger.TButton", background=[("disabled", c["surface"]), ("active", c["err_soft"])],
              foreground=[("disabled", c["faint"])], bordercolor=[("disabled", c["border"])])
        s.configure(
            "Link.TButton", padding=(8, 3), background=c["surface"], foreground=c["accent"],
            borderwidth=0, focuscolor=c["surface"], font=self.f_small,
        )
        s.map("Link.TButton", background=[("active", c["accent_soft"])])
        self._indicators(s)
        for name in ("TCheckbutton", "TRadiobutton"):
            s.configure(name, background=c["surface"], foreground=c["text"], font=self.f_bold,
                        focuscolor=c["surface"])
            s.map(name, background=[("active", c["surface"])], foreground=[("disabled", c["faint"])])
        s.configure("TEntry", padding=6, fieldbackground=c["surface"], bordercolor=c["border"],
                    lightcolor=c["border"], darkcolor=c["border"])
        s.map("TEntry", bordercolor=[("focus", c["accent"])], lightcolor=[("focus", c["accent"])],
              fieldbackground=[("disabled", c["bg"])])
        s.configure("TSpinbox", padding=5, fieldbackground=c["surface"], bordercolor=c["border"],
                    lightcolor=c["border"], darkcolor=c["border"], arrowsize=12)
        for name in ("TSpinbox", "TCombobox"):
            s.configure(name, background=c["surface"], arrowcolor=c["muted"])
        s.map("TSpinbox", bordercolor=[("focus", c["accent"])], lightcolor=[("focus", c["accent"])])
        s.configure("TCombobox", padding=5, fieldbackground=c["surface"], bordercolor=c["border"],
                    lightcolor=c["border"], darkcolor=c["border"], arrowsize=12)
        s.map("TCombobox", fieldbackground=[("readonly", c["surface"])], bordercolor=[("focus", c["accent"])])
        s.configure("Log.Vertical.TScrollbar", background="#2c3644", troughcolor=c["log_bg"],
                    bordercolor=c["log_bg"], arrowcolor=c["log_dim"], lightcolor=c["log_bg"], darkcolor=c["log_bg"])

    def _indicators(self, style):
        """
        Draw modern checkbox and radio indicators.

        The clam theme's own are a crossed square, which is most of why the old
        launcher looked like 1998. These are drawn pixel by pixel with simple
        antialiasing so no image files or extra packages are needed, and sized
        from Tk's scaling so they stay crisp on a high-DPI screen.
        """
        c = COLORS
        try:
            scale = float(self.tk.call("tk", "scaling")) / (96 / 72)
        except Exception:
            scale = 1.0
        n = max(14, round(16 * scale))
        ring = max(1.0, 1.4 * scale)

        def rgb(h):
            return tuple(int(h[i:i + 2], 16) for i in (1, 3, 5))

        def mix(top, under, a):
            a = max(0.0, min(1.0, a))
            return tuple(round(t * a + u * (1 - a)) for t, u in zip(top, under))

        def seg(px, py, ax, ay, bx, by):
            vx, vy = bx - ax, by - ay
            t = max(0.0, min(1.0, ((px - ax) * vx + (py - ay) * vy) / (vx * vx + vy * vy)))
            return ((px - ax - t * vx) ** 2 + (py - ay - t * vy) ** 2) ** 0.5

        gap = round(8 * scale)

        def draw(shape, border, fill, mark):
            # The gap to the label is drawn as background pixels, because an
            # image element's -padding does not move the label under clam.
            img = tk.PhotoImage(master=self, width=n + gap, height=n)
            bg, border, fill = rgb(c["surface"]), rgb(border), rgb(fill)
            white = (255, 255, 255)
            half, mid = n / 2 - 0.5, n / 2
            rows = []
            for y in range(n):
                row = []
                for x in range(n):
                    px, py = x + 0.5, y + 0.5
                    if shape == "box":
                        r = n * 0.24
                        qx, qy = abs(px - mid) - (half - r), abs(py - mid) - (half - r)
                        d = (max(qx, 0) ** 2 + max(qy, 0) ** 2) ** 0.5 + min(max(qx, qy), 0) - r
                    else:
                        d = ((px - mid) ** 2 + (py - mid) ** 2) ** 0.5 - half
                    col = mix(border, bg, 0.5 - d)
                    col = mix(fill, col, 0.5 - (d + ring))
                    if mark == "tick":
                        k = min(
                            seg(px, py, n * 0.28, n * 0.52, n * 0.44, n * 0.68),
                            seg(px, py, n * 0.44, n * 0.68, n * 0.73, n * 0.36),
                        )
                        col = mix(white, col, n * 0.07 + 0.5 - k)
                    elif mark == "dot":
                        k = ((px - mid) ** 2 + (py - mid) ** 2) ** 0.5 - n * 0.2
                        col = mix(rgb(c["accent"]), col, 0.5 - k)
                    row.append("#%02x%02x%02x" % col)
                row.extend([c["surface"]] * gap)
                rows.append("{" + " ".join(row) + "}")
            img.put(" ".join(rows))
            return img

        idle, hover, faint = "#9aa4b2", c["accent"], "#c9cfd8"
        box = {
            "off": draw("box", idle, c["surface"], None),
            "hover": draw("box", hover, c["surface"], None),
            "on": draw("box", c["accent"], c["accent"], "tick"),
            "off_dis": draw("box", faint, c["bg"], None),
            "on_dis": draw("box", faint, faint, "tick"),
        }
        dot = {
            "off": draw("round", idle, c["surface"], None),
            "hover": draw("round", hover, c["surface"], None),
            "on": draw("round", c["accent"], c["surface"], "dot"),
            "off_dis": draw("round", faint, c["bg"], None),
            "on_dis": draw("round", faint, c["bg"], None),
        }
        self._indicator_images = (box, dot)
        for widget, imgs in (("Checkbutton", box), ("Radiobutton", dot)):
            element = f"Gasf.{widget}.indicator"
            try:
                style.element_create(
                    element, "image", imgs["off"],
                    ("selected", "disabled", imgs["on_dis"]),
                    ("disabled", imgs["off_dis"]),
                    ("selected", imgs["on"]),
                    ("active", imgs["hover"]),
                    sticky="",
                )
            except tk.TclError:
                continue

            def swap(layout, old=f"{widget}.indicator", new=element):
                out = []
                for name, opts in layout:
                    opts = dict(opts)
                    if "children" in opts:
                        opts["children"] = swap(opts["children"])
                    out.append((new if name == old else name, opts))
                return out

            style.layout("T" + widget, swap(style.layout("T" + widget)))

    def _card(self, parent, **pack):
        """A white panel with a hairline border."""
        outer = tk.Frame(parent, bg=COLORS["border"])
        inner = tk.Frame(outer, bg=COLORS["surface"])
        inner.pack(fill="both", expand=True, padx=1, pady=1)
        outer.pack(**pack)
        return inner

    def _text(self, parent, text, font=None, color="text", bg="surface", wrap=False, **kw):
        cls = WrapLabel if wrap else tk.Label
        extra = {} if wrap else {"anchor": "w", "justify": "left"}
        return cls(parent, text=text, font=font or self.f_body, fg=COLORS[color], bg=COLORS[bg], **extra, **kw)

    # -- layout -------------------------------------------------------------

    def _build(self):
        c = COLORS
        root = tk.Frame(self, bg=c["bg"])
        root.pack(fill="both", expand=True, padx=20, pady=16)

        # Header: what this is, and the one privacy fact people ask about.
        head = tk.Frame(root, bg=c["bg"])
        head.pack(fill="x")
        self._text(head, "GASF Face Scanner", font=self.f_h1, bg="bg").pack(side="left")
        self.btn_intro = ttk.Button(head, text="How it works", style="Link.TButton",
                                    command=lambda: self._show_intro(True))
        self._text(
            head,
            "Face data stays on this computer. Only names and face positions are sent to the website.",
            font=self.f_small, color="muted", bg="bg",
        ).pack(side="right", pady=(6, 0))

        # How it works, in three steps, so "learn" and "label" never need defining.
        # Hideable: it earns its space on the first few runs, then costs it.
        self.intro_holder = tk.Frame(root, bg=c["bg"])
        self.intro_holder.pack(fill="x")
        steps = self._card(self.intro_holder, fill="x", pady=(12, 0))
        steps_row = tk.Frame(steps, bg=c["surface"])
        steps_row.pack(fill="x", padx=(16, 8), pady=12)
        for i, (n, name, blurb) in enumerate(HOW_IT_WORKS):
            steps_row.columnconfigure(i, weight=1, uniform="step")
            cell = tk.Frame(steps_row, bg=c["surface"])
            cell.grid(row=0, column=i, sticky="nsew", padx=(0 if i == 0 else 14, 0))
            badge = tk.Label(cell, text=n, font=self.f_step_n, fg=c["accent"], bg=c["accent_soft"], width=2)
            badge.pack(side="left", anchor="n", ipady=2)
            words = tk.Frame(cell, bg=c["surface"])
            words.pack(side="left", fill="both", expand=True, padx=(10, 0))
            self._text(words, name, font=self.f_bold).pack(fill="x")
            self._text(words, blurb, font=self.f_small, color="muted", wrap=True).pack(fill="x")
        ttk.Button(steps_row, text="Hide", style="Link.TButton",
                   command=lambda: self._show_intro(False)).grid(row=0, column=len(HOW_IT_WORKS), sticky="ne")

        body = tk.Frame(root, bg=c["bg"])
        body.pack(fill="both", expand=True, pady=(14, 0))
        self._show_intro(not load_prefs().get("hide_intro"), remember=False)

        # Sidebar: the tasks.
        side = self._card(body, side="left", fill="y")
        side.configure(width=300)
        side.pack_propagate(False)
        self.nav_items = {}
        last_group = None
        for item in TASKS + [SETTINGS_ITEM]:
            if item["group"] != last_group:
                last_group = item["group"]
                self._text(side, item["group"].upper(), font=self.f_group, color="faint").pack(
                    fill="x", padx=18, pady=(14 if item is TASKS[0] else 12, 4)
                )
            self.nav_items[item["key"]] = self._nav_item(side, item)

        # Right-hand side: the chosen task, then what happened.
        main = tk.Frame(body, bg=c["bg"])
        main.pack(side="left", fill="both", expand=True, padx=(14, 0))

        self.detail = self._card(main, fill="x")

        activity = self._card(main, fill="both", expand=True, pady=(14, 0))
        bar = tk.Frame(activity, bg=c["surface"])
        bar.pack(fill="x", padx=16, pady=(12, 8))
        self._text(bar, "Activity", font=self.f_bold).pack(side="left")
        self.lbl_state = tk.Label(bar, text="", font=self.f_small, padx=10, pady=2)
        self.lbl_state.pack(side="left", padx=(10, 0))
        ttk.Button(bar, text="Clear", style="Link.TButton", command=self._clear_log).pack(side="right")
        ttk.Button(bar, text="Copy", style="Link.TButton", command=self._copy_log).pack(side="right")

        log_wrap = tk.Frame(activity, bg=c["log_bg"])
        log_wrap.pack(fill="both", expand=True, padx=16, pady=(0, 16))
        self.out = tk.Text(
            log_wrap, wrap="word", height=10, bg=c["log_bg"], fg=c["log_text"], insertbackground=c["log_text"],
            relief="flat", borderwidth=0, font=self.f_mono, padx=12, pady=10, highlightthickness=0,
        )
        sb = ttk.Scrollbar(log_wrap, orient="vertical", command=self.out.yview, style="Log.Vertical.TScrollbar")
        self.out.configure(yscrollcommand=sb.set)
        sb.pack(side="right", fill="y")
        self.out.pack(side="left", fill="both", expand=True)
        self.out.tag_configure("dim", foreground=c["log_dim"])
        self.out.tag_configure("err", foreground=c["log_err"])
        self.out.tag_configure("ok", foreground=c["log_ok"])
        self.out.configure(state="disabled")
        self._set_state("idle")
        self._append("Pick a task on the left, then press its button.\n", "dim")

    def _show_intro(self, show, remember=True):
        if show:
            self.intro_holder.pack(fill="x", before=self.intro_holder.master.winfo_children()[-1])
            self.btn_intro.pack_forget()
        else:
            self.intro_holder.pack_forget()
            self.btn_intro.pack(side="left", padx=(12, 0), pady=(6, 0))
        if remember:
            save_prefs(hide_intro=None if show else True)

    def _nav_item(self, parent, item):
        c = COLORS
        row = tk.Frame(parent, bg=c["surface"], cursor="hand2")
        row.pack(fill="x", padx=8, pady=1)
        bar = tk.Frame(row, bg=c["surface"], width=4)
        bar.pack(side="left", fill="y")
        words = tk.Frame(row, bg=c["surface"])
        words.pack(side="left", fill="x", expand=True, padx=(10, 10), pady=7)
        title = self._text(words, item["title"], font=self.f_nav)
        title.pack(fill="x")
        short = self._text(words, item["short"], font=self.f_small, color="muted", wrap=True)
        short.pack(fill="x")
        parts = (row, words, title, short)

        def paint(bg):
            for w in parts:
                w.configure(bg=bg)

        def enter(_):
            if self.v_task.get() != item["key"]:
                paint(c["hover"])

        def leave(_):
            if self.v_task.get() != item["key"]:
                paint(c["surface"])

        for w in parts:
            w.bind("<Button-1>", lambda _e, k=item["key"]: self._select(k))
            w.bind("<Enter>", enter)
            w.bind("<Leave>", leave)
        return {"paint": paint, "bar": bar, "title": title}

    def _select(self, key):
        c = COLORS
        self.v_task.set(key)
        for k, nav in self.nav_items.items():
            on = k == key
            nav["paint"](c["accent_soft"] if on else c["surface"])
            nav["bar"].configure(bg=c["accent"] if on else c["surface"])
            nav["title"].configure(fg=c["accent"] if on else c["text"])
        for w in self.detail.winfo_children():
            w.destroy()
        if key == "settings":
            self._build_settings()
        else:
            self._build_task(TASK_BY_KEY[key])

    # -- the detail panel ---------------------------------------------------

    def _build_task(self, task):
        c = COLORS
        pad = tk.Frame(self.detail, bg=c["surface"])
        pad.pack(fill="x", padx=22, pady=18)
        self._text(pad, task["title"], font=self.f_h2).pack(fill="x")
        self._text(pad, task["about"], color="muted", wrap=True).pack(fill="x", pady=(6, 2))
        self._text(pad, task["when"], font=self.f_small, color="faint", wrap=True).pack(fill="x")

        opts = tk.Frame(pad, bg=c["surface"])
        opts.pack(fill="x", pady=(10, 0))
        key = task["key"]
        if key == "scan":
            self._check(
                opts, self.v_study_first, "Study newly tagged photos first",
                "Picks up names volunteers have added since the last run, so the "
                "suggestions are as up to date as possible. Usually takes seconds.",
            )
        elif key == "label":
            self._check(
                opts, self.v_label_flow, "Fill in familiar faces before the page opens (recommended)",
                "Studies new tags and identifies the faces it already recognizes first, "
                "so the page only shows you faces that genuinely need a name. Takes "
                "longer to open, saves time once it does.",
            )
            self._number(opts, "Photos to load", self.v_label_limit, 1, 1000, 50,
                          "the most recently tagged photos, up to 1,000")
        elif key == "discover":
            self._number(opts, "Photos to include", self.v_discovery_limit, 1, 1000, 50,
                         "the most recent library photos, up to 1,000")
        elif key == "watch":
            self._number(opts, "Check every", self.v_watch_minutes, 1, 1440, 5, "minutes")

        if task["dates"]:
            self._build_range(pad)

        actions = tk.Frame(pad, bg=c["surface"])
        actions.pack(fill="x", pady=(16, 0))
        self.btn_run = ttk.Button(actions, text=task["button"], style="Primary.TButton",
                                  command=lambda: self._run(task))
        self.btn_run.pack(side="left")
        # Packed only while something is running; an idle Stop is just clutter.
        self.btn_stop = ttk.Button(actions, text="Stop", style="Danger.TButton", command=self._stop)
        self._sync_buttons()

    def _build_range(self, parent):
        c = COLORS
        box = tk.Frame(parent, bg=c["surface"])
        box.pack(fill="x", pady=(12, 0))
        self._text(box, "Which photos", font=self.f_bold).pack(fill="x")
        row = tk.Frame(box, bg=c["surface"])
        row.pack(fill="x", pady=(4, 0))
        ttk.Radiobutton(row, text="All of them", value="all", variable=self.v_range,
                        command=self._sync_range).pack(side="left")
        ttk.Radiobutton(row, text="Only ones uploaded between", value="dates", variable=self.v_range,
                        command=self._sync_range).pack(side="left", padx=(18, 6))
        self.ent_after = ttk.Entry(row, textvariable=self.v_uploaded_after, width=11)
        self.ent_after.pack(side="left")
        self._text(row, "and", color="muted").pack(side="left", padx=6)
        self.ent_before = ttk.Entry(row, textvariable=self.v_uploaded_before, width=11)
        self.ent_before.pack(side="left")
        hint = tk.Frame(box, bg=c["surface"])
        hint.pack(fill="x", pady=(4, 0))
        self._text(hint, "Dates are year-month-day, for example 2026-09-27. Both days are included.",
                   font=self.f_small, color="faint").pack(side="left")
        self.btn_month = ttk.Button(hint, text="Last 30 days", style="Link.TButton", command=lambda: self._quick_range(30))
        self.btn_month.pack(side="right")
        self.btn_week = ttk.Button(hint, text="Last 7 days", style="Link.TButton", command=lambda: self._quick_range(7))
        self.btn_week.pack(side="right")
        self._sync_range()

    def _build_settings(self):
        c = COLORS
        pad = tk.Frame(self.detail, bg=c["surface"])
        pad.pack(fill="x", padx=22, pady=18)
        self._text(pad, "Advanced settings", font=self.f_h2).pack(fill="x")
        self._text(pad, "You should not normally need anything on this page. These apply to every task.",
                   color="muted", wrap=True).pack(fill="x", pady=(4, 6))

        grid = tk.Frame(pad, bg=c["surface"])
        grid.pack(fill="x")
        grid.columnconfigure(1, weight=1)

        self._text(grid, "Recognition engine", font=self.f_bold).grid(row=0, column=0, sticky="nw", pady=4)
        eng = tk.Frame(grid, bg=c["surface"])
        eng.grid(row=0, column=1, sticky="ew", padx=(16, 0), pady=4)
        ttk.Combobox(eng, textvariable=self.v_engine_label, values=[l for l, _ in ENGINE_CHOICES],
                     state="readonly", width=26).pack(anchor="w")
        self._text(eng, "The software that compares faces. Automatic uses whichever is installed. "
                   "Each engine keeps its own examples, so switching means it re-learns from scratch.",
                   font=self.f_small, color="muted", wrap=True).pack(fill="x", pady=(4, 0))

        self._text(grid, "Activity log", font=self.f_bold).grid(row=1, column=0, sticky="nw", pady=(8, 4))
        quiet = tk.Frame(grid, bg=c["surface"])
        quiet.grid(row=1, column=1, sticky="ew", padx=(16, 0), pady=(8, 4))
        ttk.Checkbutton(quiet, text="Show less detail", variable=self.v_quiet).pack(side="left")
        self._text(quiet, "Leaves out routine progress lines. Problems are always shown.",
                   font=self.f_small, color="muted").pack(side="left", padx=(10, 0))

        self._text(grid, "Scanner file", font=self.f_bold).grid(row=2, column=0, sticky="nw", pady=(8, 4))
        sf = tk.Frame(grid, bg=c["surface"])
        sf.grid(row=2, column=1, sticky="ew", padx=(16, 0), pady=(8, 4))
        self._text(sf, "", font=self.f_small, color="text", wrap=True, textvariable=self.v_scan_py).pack(fill="x")
        self.lbl_scan_note = self._text(sf, "", font=self.f_small, color="warn", wrap=True)
        self.lbl_scan_note.pack(fill="x")
        btns = tk.Frame(sf, bg=c["surface"])
        btns.pack(fill="x", pady=(2, 0))
        ttk.Button(btns, text="Choose another...", style="Link.TButton", command=self._pick_scan_py).pack(side="left")
        ttk.Button(btns, text="Use the one beside this launcher", style="Link.TButton",
                   command=self._use_adjacent).pack(side="left", padx=(4, 0))
        self._text(sf, "", font=self.f_small, color="faint", wrap=True, textvariable=self.v_python).pack(fill="x")
        self._refresh_scan_note()

        self._text(grid, "Troubleshooting", font=self.f_bold).grid(row=3, column=0, sticky="nw", pady=(8, 0))
        ts = tk.Frame(grid, bg=c["surface"])
        ts.grid(row=3, column=1, sticky="ew", padx=(16, 0), pady=(8, 0))
        self.btn_run = ttk.Button(ts, text="Run the self-test", style="Secondary.TButton",
                                  command=lambda: self._run({"key": "selftest", "title": "Self-test",
                                                             "running": "Running the self-test..."}))
        self.btn_run.pack(side="left", anchor="n")
        self._text(ts, "Tests the scanner's own inner workings without the internet or the recognition "
                   "software. Useful to send along when reporting a problem.",
                   font=self.f_small, color="muted", wrap=True).pack(side="left", fill="x", expand=True, padx=(12, 0))
        self.btn_stop = None
        self._sync_buttons()

    def _check(self, parent, var, title, desc):
        row = tk.Frame(parent, bg=COLORS["surface"])
        row.pack(fill="x", pady=(4, 0))
        ttk.Checkbutton(row, text=title, variable=var).pack(anchor="w")
        self._text(row, desc, font=self.f_small, color="muted", wrap=True).pack(fill="x", padx=(26, 0))

    def _number(self, parent, title, var, lo, hi, step, suffix):
        row = tk.Frame(parent, bg=COLORS["surface"])
        row.pack(fill="x", pady=(8, 0))
        self._text(row, title, font=self.f_bold).pack(side="left")
        ttk.Spinbox(row, textvariable=var, from_=lo, to=hi, increment=step, width=7).pack(side="left", padx=(10, 8))
        self._text(row, suffix, color="muted").pack(side="left")

    def _sync_range(self):
        on = self.v_range.get() == "dates"
        for w in (self.ent_after, self.ent_before, self.btn_week, self.btn_month):
            w.configure(state="normal" if on else "disabled")

    def _quick_range(self, days):
        today = date.today()
        self.v_uploaded_after.set((today - timedelta(days=days - 1)).isoformat())
        self.v_uploaded_before.set(today.isoformat())

    # -- scanner file -------------------------------------------------------

    def _current_scan_py(self):
        return self.v_scan_py.get().strip()

    def _refresh_scan_note(self):
        """
        Say when the scanner is not the one beside this launcher.

        Silent in the ordinary case. Loud when they differ, because that is
        exactly the situation that goes unnoticed - two copies of the same file,
        one of them months old, and nothing on screen distinguishing them.
        """
        if not getattr(self, "lbl_scan_note", None) or not self.lbl_scan_note.winfo_exists():
            return
        note = ""
        current = self._current_scan_py()
        if not os.path.isfile(current):
            note = "This file is missing. Choose the scan.py you want to run."
        elif os.path.normcase(current) != os.path.normcase(ADJACENT_SCAN_PY):
            note = (
                "Remembered choice - this is NOT the scan.py beside this launcher. "
                "That is fine if it is deliberate; it is worth checking if it is not."
            )
        self.lbl_scan_note.config(text=note)

    def _apply_scan_py(self, path, remember):
        path = str(Path(path).resolve())
        self.v_scan_py.set(path)
        global SCAN_PY
        SCAN_PY = path
        if remember and not save_scan_py(path):
            messagebox.showwarning(
                "Could not remember that",
                "The scanner will run from:\n\n" + path +
                "\n\nbut the choice could not be written to:\n" + str(CONFIG_PATH) +
                "\n\nIt will have to be chosen again next time.",
            )
        self._refresh_scan_note()

    def _pick_scan_py(self):
        start = self._current_scan_py()
        initial = os.path.dirname(start) if os.path.isfile(start) else os.path.dirname(ADJACENT_SCAN_PY)
        chosen = filedialog.askopenfilename(
            title="Choose the scan.py to run",
            initialdir=initial,
            filetypes=[("The scanner", "scan.py"), ("Python files", "*.py"), ("All files", "*.*")],
        )
        if not chosen:
            return
        name = os.path.basename(chosen).lower()
        if name != "scan.py" and not messagebox.askyesno(
            "That is not called scan.py",
            "You picked:\n\n" + chosen + "\n\nThe launcher expects the scanner itself. Use it anyway?",
        ):
            return
        self._apply_scan_py(chosen, remember=True)

    def _use_adjacent(self):
        """Forget the remembered path and go back to the neighbouring scanner."""
        save_prefs(scan_py=None)
        self._apply_scan_py(ADJACENT_SCAN_PY, remember=False)

    # -- running ------------------------------------------------------------

    def _python_label(self):
        py_cmd = self._python_cmd()
        return " ".join(py_cmd) if py_cmd else "(no Python found in PATH)"

    def _python_cmd(self):
        # The laptop installer launches this GUI from its private virtual
        # environment. Keep child scanner runs in that same environment.
        if sys.prefix != sys.base_prefix and Path(sys.executable).is_file():
            return [sys.executable]
        py = shutil.which("py")
        if py:
            return [py, "-3"]
        p = shutil.which("python")
        if p:
            return [p]
        exe = os.path.basename(sys.executable).lower()
        if exe.startswith("python"):
            return [sys.executable]
        return None

    @staticmethod
    def _whole(raw, label, lo, hi):
        try:
            n = int(str(raw).strip())
        except ValueError:
            raise ValueError(f"{label} must be a whole number.") from None
        if n < lo or n > hi:
            raise ValueError(f"{label} must be between {lo:,} and {hi:,}.")
        return n

    @staticmethod
    def _validate_ymd(raw, label):
        try:
            datetime.strptime(raw, "%Y-%m-%d")
        except ValueError as e:
            raise ValueError(f"{label} must be a date written like 2026-09-27.") from e

    def _build_cmd(self, key):
        # Read from the field, not the startup constant: the whole point of the
        # picker is that this can change without restarting the launcher.
        scan_py = self._current_scan_py()
        if not os.path.isfile(scan_py):
            raise ValueError(
                "The scanner was not found:\n" + scan_py +
                "\n\nChoose it under Advanced settings."
            )
        py = self._python_cmd()
        if not py:
            raise ValueError("Python was not found. Install Python, or add 'py' or 'python' to PATH.")

        cmd = py + ["-u", scan_py]
        if key == "scan":
            if self.v_study_first.get():
                cmd.append("--learn")
        elif key == "label":
            limit = self._whole(self.v_label_limit.get(), "Photos to load", 1, 1000)
            cmd += ["--label", "--label-limit", str(limit)]
            if self.v_label_flow.get():
                cmd.append("--label-flow")
        elif key == "discover":
            limit = self._whole(self.v_discovery_limit.get(), "Photos to include", 1, 1000)
            cmd += ["--discover", "--discovery-limit", str(limit)]
        elif key == "watch":
            minutes = self._whole(self.v_watch_minutes.get(), "Check every", 1, 1440)
            cmd += ["--watch", str(minutes * 60)]
        elif key in ("status", "check", "selftest"):
            cmd.append("--" + key)
        else:
            raise ValueError(f"Unknown task: {key}")

        if key in ("scan", "label", "discover", "watch") and self.v_range.get() == "dates":
            after = self.v_uploaded_after.get().strip()
            before = self.v_uploaded_before.get().strip()
            if not after and not before:
                raise ValueError("Enter at least one date, or choose \"All of them\".")
            if after:
                self._validate_ymd(after, "The first date")
                cmd += ["--uploaded-after", after]
            if before:
                self._validate_ymd(before, "The second date")
                cmd += ["--uploaded-before", before]
            if after and before and after > before:
                raise ValueError("The first date must be on or before the second.")

        if self.v_quiet.get():
            cmd.append("--quiet")
        engine = dict(ENGINE_CHOICES).get(self.v_engine_label.get(), "auto")
        if engine != "auto":
            cmd += ["--engine", engine]
        return cmd

    def _set_state(self, state, text=None):
        c = COLORS
        look = {
            "idle": ("Ready", c["muted"], c["hover"]),
            "running": (text or "Working...", c["accent"], c["accent_soft"]),
            "done": (text or "Finished", c["ok"], c["ok_soft"]),
            "stopped": (text or "Stopped", c["warn"], c["warn_soft"]),
            "failed": (text or "Something went wrong - the last lines below usually say why", c["err"], c["err_soft"]),
        }[state]
        self.lbl_state.configure(text=look[0], fg=look[1], bg=look[2])

    def _sync_buttons(self):
        running = self.proc is not None or (self.worker is not None and self.worker.is_alive())
        if getattr(self, "btn_run", None) and self.btn_run.winfo_exists():
            self.btn_run.configure(state="disabled" if running else "normal")
        if getattr(self, "btn_stop", None) and self.btn_stop.winfo_exists():
            if running:
                self.btn_stop.pack(side="left", padx=(10, 0))
            else:
                self.btn_stop.pack_forget()

    def _append(self, text, tag=None):
        if tag is None:
            low = text.lower()
            if "traceback" in low or "error" in low or "failed" in low or "[fail" in low:
                tag = "err"
            elif "[ok]" in low or "passed" in low:
                tag = "ok"
        self.out.configure(state="normal")
        self.out.insert("end", text, tag or ())
        self.out.see("end")
        self.out.configure(state="disabled")

    def _clear_log(self):
        self.out.configure(state="normal")
        self.out.delete("1.0", "end")
        self.out.configure(state="disabled")

    def _copy_log(self):
        self.clipboard_clear()
        self.clipboard_append(self.out.get("1.0", "end-1c"))
        if self.proc is None:
            self._set_state("done", "Copied to the clipboard")

    def _run(self, task):
        if self.proc is not None or (self.worker is not None and self.worker.is_alive()):
            return
        try:
            cmd = self._build_cmd(task["key"])
        except Exception as e:
            messagebox.showerror("Cannot start yet", str(e))
            return

        self.stop_requested = False
        self.running_task = task
        self._append("\n" + task["title"] + "\n", "dim")
        self._append("$ " + " ".join(cmd) + "\n\n", "dim")
        self._set_state("running", task["running"])

        try:
            self.proc = subprocess.Popen(
                cmd,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                encoding="utf-8",
                errors="replace",
                bufsize=1,
                # Run from the scanner's own folder, exactly as run.ps1 does
                # with Set-Location. scan.py finds faces.db and config.json
                # from __file__ so those were already right, but anything it
                # writes by relative path - the log among them - would
                # otherwise land wherever this launcher happened to start.
                cwd=os.path.dirname(self._current_scan_py()) or None,
                # Launched from the pythonw shortcut there is no console, and
                # without this each run would flash one up.
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
            )
        except Exception as e:
            self.proc = None
            self._append(f"\nThe launcher could not start the scanner: {e}\n", "err")
            self._finished(1)
            return

        # The reader thread never touches Tk: it only fills a queue, and the
        # window drains it on its own thread. Tk is not thread-safe, and calling
        # into it from a worker works only until it doesn't.
        proc, lines = self.proc, self.lines

        def reader():
            code = 1
            try:
                assert proc.stdout is not None
                for line in proc.stdout:
                    lines.put(("line", line))
                code = proc.wait()
            except Exception as e:
                lines.put(("line", f"\nThe launcher lost the scanner's output: {e}\n"))
            finally:
                lines.put(("done", code))

        self.worker = threading.Thread(target=reader, daemon=True)
        self.worker.start()
        self._sync_buttons()
        self.after(50, self._drain)

    def _drain(self):
        """Move the scanner's output from the reader thread into the window."""
        while True:
            try:
                kind, value = self.lines.get_nowait()
            except queue.Empty:
                break
            if kind == "done":
                self.proc = None
                self._finished(value)
                return
            self._append(value)
        self.after(50, self._drain)

    def _finished(self, code):
        if self.stop_requested:
            self._set_state("stopped")
            self._append("\nStopped.\n", "dim")
        elif code == 0:
            self._set_state("done")
            self._append("\nFinished.\n", "ok")
        else:
            self._set_state("failed")
            self._append(f"\nEnded with a problem (exit code {code}).\n", "err")
        self.running_task = None
        self.worker = None
        self._sync_buttons()

    def _stop(self):
        if self.proc is None:
            return
        try:
            self.stop_requested = True
            self.proc.terminate()
            self._set_state("running", "Stopping...")
        except Exception as e:
            self._append(f"\nCould not stop the scanner: {e}\n", "err")

    def _on_close(self):
        if self.proc is not None:
            if not messagebox.askyesno(
                "The scanner is still running",
                "Stop it and close the window?\n\nAnything already sent to the website is kept.",
            ):
                return
            try:
                self.proc.terminate()
            except Exception:
                pass
        self.destroy()


if __name__ == "__main__":
    enable_crisp_text()
    app = ScanGui()
    app.mainloop()
