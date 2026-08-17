#!/usr/bin/env python3
"""Simple launcher UI for scan.py.

Runs the scanner with checkbox-selected options, blocks unsupported combos,
and streams output in one window.
"""

import json
import os
import shutil
import subprocess
import sys
import threading
from datetime import datetime
from pathlib import Path
import tkinter as tk
from tkinter import filedialog, messagebox, scrolledtext, ttk


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


def load_saved_scan_py():
    """The remembered scanner, if it is still there. Never guesses."""
    try:
        data = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
        saved = str(data.get("scan_py") or "").strip()
    except Exception:
        return ""
    return saved if saved and os.path.isfile(saved) else ""


def save_scan_py(path):
    """Remember this scanner for next time. Failing to is not worth an error."""
    try:
        CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        CONFIG_PATH.write_text(
            json.dumps({"scan_py": str(path)}, indent=2), encoding="utf-8"
        )
        return True
    except Exception:
        return False


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


class ScanGui(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("GASF Face Scanner Launcher")
        self.geometry("920x700")
        self.minsize(860, 620)

        self.proc = None
        self.worker = None

        self.v_learn = tk.BooleanVar(value=False)
        self.v_label = tk.BooleanVar(value=False)
        self.v_discover = tk.BooleanVar(value=False)
        self.v_label_flow = tk.BooleanVar(value=True)
        self.v_watch = tk.BooleanVar(value=False)
        self.v_status = tk.BooleanVar(value=False)
        self.v_check = tk.BooleanVar(value=False)
        self.v_selftest = tk.BooleanVar(value=False)
        self.v_quiet = tk.BooleanVar(value=False)
        self.v_engine = tk.StringVar(value="auto")
        self.v_watch_seconds = tk.StringVar(value="900")
        self.v_label_limit = tk.StringVar(value="500")
        self.v_discovery_limit = tk.StringVar(value="1000")
        self.v_uploaded_after = tk.StringVar(value="")
        self.v_uploaded_before = tk.StringVar(value="")
        self.v_python = tk.StringVar(value="")
        self.v_scan_py = tk.StringVar(value=SCAN_PY)

        self._build()
        self._refresh_constraints()
        self.v_python.set(self._python_label())

    def _current_scan_py(self):
        return self.v_scan_py.get().strip()

    def _refresh_scan_note(self):
        """
        Say when the scanner is not the one beside this launcher.

        Silent in the ordinary case. Loud when they differ, because that is
        exactly the situation that goes unnoticed - two copies of the same file,
        one of them months old, and nothing on screen distinguishing them.
        """
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
        try:
            if CONFIG_PATH.exists():
                CONFIG_PATH.unlink()
        except Exception:
            pass
        self._apply_scan_py(ADJACENT_SCAN_PY, remember=False)

    def _build(self):
        wrap = ttk.Frame(self, padding=12)
        wrap.pack(fill="both", expand=True)

        # Which scanner this will actually run, and a way to change it. Shown as
        # a live row rather than a fixed label because the whole point is that
        # the answer is not obvious from where the launcher was started.
        scan_row = ttk.Frame(wrap)
        scan_row.pack(fill="x", pady=(0, 2))
        ttk.Label(scan_row, text="Scanner:").pack(side="left")
        self.lbl_scan = ttk.Label(scan_row, textvariable=self.v_scan_py, foreground="#1f4d7a")
        self.lbl_scan.pack(side="left", padx=(4, 8))
        ttk.Button(scan_row, text="Change...", command=self._pick_scan_py).pack(side="left")
        ttk.Button(scan_row, text="Use the one beside this launcher", command=self._use_adjacent).pack(side="left", padx=(6, 0))

        self.lbl_scan_note = ttk.Label(wrap, text="", foreground="#8a6508", wraplength=880, justify="left")
        self.lbl_scan_note.pack(anchor="w", pady=(0, 8))
        self._refresh_scan_note()

        opts = ttk.LabelFrame(wrap, text="Options", padding=10)
        opts.pack(fill="x")

        self.cb_learn = self._option_row(
            opts,
            self.v_learn,
            "--learn",
            "Refresh reference set before scanning (incremental).",
            self._on_flag_change,
        )
        self.cb_label = self._option_row(
            opts,
            self.v_label,
            "--label",
            "Open local face-label UI (browser flow).",
            self._on_flag_change,
        )
        self.cb_discover = self._option_row(
            opts,
            self.v_discover,
            "--discover",
            "Prepare unknown faces and open the entirely local People Discovery board.",
            self._on_discover_change,
        )
        self.cb_label_flow = self._option_row(
            opts,
            self.v_label_flow,
            "Refinement flow: Learn -> Scan -> Label -> Learn -> Scan",
            "Recommended: resolve familiar faces before asking you to label the remainder.",
            self._on_flag_change,
        )
        self.cb_watch = self._option_row(
            opts,
            self.v_watch,
            "--watch SECONDS",
            "Loop continuously: learn/scan/sleep/repeat.",
            self._on_flag_change,
        )
        self.cb_status = self._option_row(
            opts,
            self.v_status,
            "--status",
            "Show corpus quality, queue status, and confidence calibration.",
            self._on_flag_change,
        )
        self.cb_check = self._option_row(
            opts,
            self.v_check,
            "--check",
            "Doctor/preflight: config, backend, server key.",
            self._on_flag_change,
        )
        self.cb_selftest = self._option_row(
            opts,
            self.v_selftest,
            "--selftest",
            "Offline plumbing tests; no ML, no network.",
            self._on_flag_change,
        )
        self.cb_quiet = self._option_row(
            opts,
            self.v_quiet,
            "--quiet",
            "Reduce routine output noise.",
            self._on_flag_change,
        )

        fields = ttk.Frame(opts)
        fields.pack(fill="x", pady=(8, 0))
        ttk.Label(fields, text="Watch seconds").grid(row=0, column=0, sticky="w")
        self.ent_watch = ttk.Entry(fields, textvariable=self.v_watch_seconds, width=10)
        self.ent_watch.grid(row=0, column=1, sticky="w", padx=(8, 20))

        ttk.Label(fields, text="Label limit").grid(row=0, column=2, sticky="w")
        self.ent_label = ttk.Entry(fields, textvariable=self.v_label_limit, width=10)
        self.ent_label.grid(row=0, column=3, sticky="w", padx=(8, 20))

        ttk.Label(fields, text="Engine").grid(row=0, column=4, sticky="w")
        self.cmb_engine = ttk.Combobox(
            fields,
            textvariable=self.v_engine,
            values=["auto", "insightface", "face_recognition"],
            width=16,
            state="readonly",
        )
        self.cmb_engine.grid(row=0, column=5, sticky="w", padx=(8, 0))

        ttk.Label(fields, text="Uploaded after").grid(row=1, column=0, sticky="w", pady=(8, 0))
        self.ent_after = ttk.Entry(fields, textvariable=self.v_uploaded_after, width=12)
        self.ent_after.grid(row=1, column=1, sticky="w", padx=(8, 20), pady=(8, 0))
        ttk.Label(fields, text="Uploaded before").grid(row=1, column=2, sticky="w", pady=(8, 0))
        self.ent_before = ttk.Entry(fields, textvariable=self.v_uploaded_before, width=12)
        self.ent_before.grid(row=1, column=3, sticky="w", padx=(8, 20), pady=(8, 0))
        ttk.Label(fields, text="(YYYY-MM-DD)", foreground="#666").grid(row=1, column=4, columnspan=2, sticky="w", pady=(8, 0))
        ttk.Label(fields, text="Discovery limit").grid(row=2, column=0, sticky="w", pady=(8, 0))
        self.ent_discovery = ttk.Entry(fields, textvariable=self.v_discovery_limit, width=10)
        self.ent_discovery.grid(row=2, column=1, sticky="w", padx=(8, 20), pady=(8, 0))

        info = ttk.Frame(wrap)
        info.pack(fill="x", pady=(8, 0))
        ttk.Label(info, text="Python runner:").pack(side="left")
        ttk.Label(info, textvariable=self.v_python, foreground="#555").pack(side="left", padx=(6, 0))

        btns = ttk.Frame(wrap)
        btns.pack(fill="x", pady=(10, 6))
        self.btn_run = ttk.Button(btns, text="Start", command=self._run)
        self.btn_run.pack(side="left")
        self.btn_stop = ttk.Button(btns, text="Stop", command=self._stop, state="disabled")
        self.btn_stop.pack(side="left", padx=(8, 0))

        self.out = scrolledtext.ScrolledText(wrap, wrap="word", height=22)
        self.out.pack(fill="both", expand=True, pady=(4, 0))
        self.out.insert("end", "Ready.\n")
        self.out.configure(state="disabled")

    def _option_row(self, parent, var, title, desc, cmd):
        row = ttk.Frame(parent)
        row.pack(fill="x", pady=2)
        cb = ttk.Checkbutton(row, text=title, variable=var, command=cmd)
        cb.pack(side="left", anchor="w")
        ttk.Label(row, text=desc, foreground="#555").pack(side="left", padx=(10, 0), anchor="w")
        return cb

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

    def _append(self, text):
        self.out.configure(state="normal")
        self.out.insert("end", text)
        self.out.see("end")
        self.out.configure(state="disabled")

    def _on_flag_change(self):
        # Mutually exclusive "single-purpose" modes.
        if self.v_label.get():
            self.v_discover.set(False)
            self.v_status.set(False)
            self.v_check.set(False)
            self.v_selftest.set(False)
            self.v_watch.set(False)
            self.v_learn.set(False)
        if self.v_status.get():
            self.v_label.set(False)
            self.v_discover.set(False)
            self.v_check.set(False)
            self.v_selftest.set(False)
            self.v_watch.set(False)
            self.v_learn.set(False)
        if self.v_check.get():
            self.v_label.set(False)
            self.v_discover.set(False)
            self.v_status.set(False)
            self.v_selftest.set(False)
            self.v_watch.set(False)
            self.v_learn.set(False)
        if self.v_selftest.get():
            self.v_label.set(False)
            self.v_discover.set(False)
            self.v_status.set(False)
            self.v_check.set(False)
            self.v_watch.set(False)
            self.v_learn.set(False)
        if self.v_watch.get():
            self.v_label.set(False)
            self.v_discover.set(False)
            self.v_label_flow.set(False)
            self.v_status.set(False)
            self.v_check.set(False)
            self.v_selftest.set(False)
        if self.v_discover.get():
            self.v_label.set(False)
            self.v_label_flow.set(False)
            self.v_status.set(False)
            self.v_check.set(False)
            self.v_selftest.set(False)
            self.v_watch.set(False)
            self.v_learn.set(False)
        self._refresh_constraints()

    def _on_discover_change(self):
        if self.v_discover.get():
            self.v_label.set(False)
            self.v_label_flow.set(False)
            self.v_status.set(False)
            self.v_check.set(False)
            self.v_selftest.set(False)
            self.v_watch.set(False)
            self.v_learn.set(False)
        self._refresh_constraints()

    def _refresh_constraints(self):
        special = (
            self.v_label.get()
            or self.v_discover.get()
            or self.v_status.get()
            or self.v_check.get()
            or self.v_selftest.get()
        )
        watch = self.v_watch.get()
        lock_learn = special
        lock_watch = special

        self.cb_learn.configure(state="disabled" if lock_learn else "normal")
        self.cb_watch.configure(state="disabled" if lock_watch else "normal")
        self.cb_label_flow.configure(state="normal" if self.v_label.get() else "disabled")

        for cb in (self.cb_label, self.cb_discover, self.cb_status, self.cb_check, self.cb_selftest):
            cb.configure(state="disabled" if watch else "normal")

        self.ent_watch.configure(state="normal" if watch else "disabled")
        self.ent_label.configure(state="normal" if self.v_label.get() else "disabled")
        self.ent_discovery.configure(state="normal" if self.v_discover.get() else "disabled")

    def _build_cmd(self):
        # Read from the field, not the startup constant: the whole point of the
        # picker is that this can change without restarting the launcher.
        scan_py = self._current_scan_py()
        if not os.path.isfile(scan_py):
            raise ValueError(
                "The scanner was not found:\n" + scan_py +
                "\n\nUse Change... to pick the scan.py you want to run."
            )

        py = self._python_cmd()
        if not py:
            raise ValueError("Python launcher not found. Install Python or add 'py'/'python' to PATH.")

        cmd = py + ["-u", scan_py]
        if self.v_learn.get():
            cmd.append("--learn")
        if self.v_label.get():
            limit = int(self.v_label_limit.get().strip() or "500")
            if limit < 1:
                raise ValueError("Label limit must be >= 1.")
            cmd += ["--label", "--label-limit", str(limit)]
            if self.v_label_flow.get():
                cmd.append("--label-flow")
        if self.v_discover.get():
            limit = int(self.v_discovery_limit.get().strip() or "1000")
            if limit < 1 or limit > 1000:
                raise ValueError("Discovery limit must be between 1 and 1000.")
            cmd += ["--discover", "--discovery-limit", str(limit)]
        if self.v_watch.get():
            seconds = int(self.v_watch_seconds.get().strip() or "900")
            if seconds < 1:
                raise ValueError("Watch seconds must be >= 1.")
            cmd += ["--watch", str(seconds)]
        if self.v_status.get():
            cmd.append("--status")
        if self.v_check.get():
            cmd.append("--check")
        if self.v_selftest.get():
            cmd.append("--selftest")
        if self.v_quiet.get():
            cmd.append("--quiet")
        after = self.v_uploaded_after.get().strip()
        if after:
            self._validate_ymd(after, "Uploaded after")
            cmd += ["--uploaded-after", after]
        before = self.v_uploaded_before.get().strip()
        if before:
            self._validate_ymd(before, "Uploaded before")
            cmd += ["--uploaded-before", before]
        if after and before and after > before:
            raise ValueError("Uploaded after must be on or before Uploaded before.")

        engine = self.v_engine.get().strip()
        if engine and engine != "auto":
            cmd += ["--engine", engine]
        return cmd

    @staticmethod
    def _validate_ymd(raw, label):
        try:
            datetime.strptime(raw, "%Y-%m-%d")
        except ValueError as e:
            raise ValueError(f"{label} must be YYYY-MM-DD.") from e

    def _set_running(self, running):
        self.btn_run.configure(state="disabled" if running else "normal")
        self.btn_stop.configure(state="normal" if running else "disabled")

    def _run(self):
        if self.proc is not None:
            return
        try:
            cmd = self._build_cmd()
        except Exception as e:
            messagebox.showerror("Cannot run", str(e))
            return

        self._append("\n$ " + " ".join(cmd) + "\n\n")
        self._set_running(True)

        def worker():
            code = 1
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
                )
                assert self.proc.stdout is not None
                for line in self.proc.stdout:
                    self.after(0, self._append, line)
                code = self.proc.wait()
            except Exception as e:
                self.after(0, self._append, f"\nLauncher error: {e}\n")
            finally:
                self.proc = None
                self.after(0, self._append, f"\nExit code: {code}\n")
                self.after(0, self._set_running, False)

        self.worker = threading.Thread(target=worker, daemon=True)
        self.worker.start()

    def _stop(self):
        if self.proc is None:
            return
        try:
            self.proc.terminate()
            self._append("\nStopping...\n")
        except Exception as e:
            self._append(f"\nCould not stop process: {e}\n")


if __name__ == "__main__":
    app = ScanGui()
    app.mainloop()
