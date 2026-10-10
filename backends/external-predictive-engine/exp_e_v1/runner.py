"""Resumable runner for Experiment E v1.

  python -I run_exp_e_v1.py audit     # E0 / E1 artifact + OOF audit -> results/report_A_oof_audit.md
  python -I run_exp_e_v1.py dry       # BTC 2023 fold into a scratch dir (timing + file layout), no analysis
  python -I run_exp_e_v1.py run       # tests -> events per coin -> walk-forward OOF jobs per (coin, year) -> analysis -> reports A-G
  python -I run_exp_e_v1.py analyze   # rebuild the analysis and reports from the saved OOF predictions

One heavy job at a time, below-normal priority, memory guard (waits while available commit < 2 GB), STOP file pauses, D: free space must stay > 30 GB, atomic outputs, markers per coin and per (coin, year).
Research only: public local files, no orders, no keys, no server, no DB. Never reads anything at or after 2026-01-01 (data.py).
"""

from __future__ import annotations

import datetime as dt
import hashlib
import json
import os
import shutil
import subprocess
import sys
import time
import traceback
from pathlib import Path

import numpy as np

from edge_search.core import write_json
from edge_search.runner import avail_commit_gb, low_priority

from . import data as D
from . import job as J
from . import targets as T

WORK = Path(os.environ.get("AUTOFINX_V4_WORK", r"D:\autofinx-ml-v4"))
OUT = WORK / "exp_e_v1"
ENGINE = Path(__file__).resolve().parents[1]
PLAN = ENGINE.parent / "docs" / "ml-engine-v1" / "EXPERIMENT_E_V1.md"
LOG = WORK / "logs" / "exp_e_v1.log"
GUARD_GB = 2.0
MIN_FREE_GB = 30.0
ORDER = D.LIVE_SYMBOLS


def log(msg: str) -> None:
    line = f"{dt.datetime.now().strftime('%Y-%m-%d %H:%M:%S')} {msg}"
    print(line, flush=True)
    try:
        LOG.parent.mkdir(parents=True, exist_ok=True)
        with open(LOG, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception:
        pass


def gate(current: str) -> None:
    while (OUT / "STOP").exists() or avail_commit_gb() < GUARD_GB or shutil.disk_usage(WORK).free / 2 ** 30 < MIN_FREE_GB:
        write_json(OUT / "progress.json", {"status": "PAUSED" if (OUT / "STOP").exists() else "WAITING_RESOURCES", "current": current,
                                           "avail_commit_gb": avail_commit_gb(), "free_gb": shutil.disk_usage(WORK).free / 2 ** 30,
                                           "utc": dt.datetime.now(dt.timezone.utc).isoformat()})
        time.sleep(20)


def private_gb() -> float:
    """Private (commit) bytes of this process in GB."""
    try:
        import ctypes
        from ctypes import wintypes

        class PMC(ctypes.Structure):
            _fields_ = [("cb", wintypes.DWORD), ("PageFaultCount", wintypes.DWORD), ("PeakWorkingSetSize", ctypes.c_size_t), ("WorkingSetSize", ctypes.c_size_t),
                        ("QuotaPeakPagedPoolUsage", ctypes.c_size_t), ("QuotaPagedPoolUsage", ctypes.c_size_t), ("QuotaPeakNonPagedPoolUsage", ctypes.c_size_t),
                        ("QuotaNonPagedPoolUsage", ctypes.c_size_t), ("PagefileUsage", ctypes.c_size_t), ("PeakPagefileUsage", ctypes.c_size_t), ("PrivateUsage", ctypes.c_size_t)]
        pmc = PMC()
        pmc.cb = ctypes.sizeof(pmc)
        ctypes.windll.kernel32.K32GetProcessMemoryInfo.argtypes = [wintypes.HANDLE, ctypes.POINTER(PMC), wintypes.DWORD]
        ctypes.windll.kernel32.K32GetProcessMemoryInfo(ctypes.windll.kernel32.GetCurrentProcess(), ctypes.byref(pmc), pmc.cb)
        return pmc.PrivateUsage / 2 ** 30
    except Exception:
        return -1.0


def progress(status: str, done: int, total: int, current: str, t0: float) -> None:
    el = time.time() - t0
    write_json(OUT / "progress.json", {"status": status, "done": done, "total": total, "current": current, "elapsed_s": el,
                                       "eta_s": (el / done * (total - done)) if done else None, "avail_commit_gb": avail_commit_gb(),
                                       "free_gb": shutil.disk_usage(WORK).free / 2 ** 30, "utc": dt.datetime.now(dt.timezone.utc).isoformat()})


def plan_sha() -> str:
    sha = hashlib.sha256(PLAN.read_bytes()).hexdigest()
    ref = (OUT / "PLAN_SHA256.txt").read_text("utf-8").split()[0] if (OUT / "PLAN_SHA256.txt").exists() else None
    if ref and ref != sha:
        raise RuntimeError("EXPERIMENT_E_V1.md changed after it was frozen (sha mismatch). Restore it or add an AMENDMENT and re-register.")
    return sha


def np_save(path: Path, arrays: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + ".tmp.npz")
    np.savez_compressed(tmp, **arrays)
    os.replace(tmp, path)


def run_tests() -> tuple[bool, str]:
    r = subprocess.run([sys.executable, "-m", "pytest", "tests/test_exp_e_v1.py", "-q", "-x", "-p", "no:cacheprovider"], cwd=ENGINE,
                       capture_output=True, text=True, timeout=3600)
    return r.returncode == 0, (r.stdout + r.stderr)[-1500:]


def events_path(base: Path, sym: str) -> Path:
    return base / "events" / f"{sym}.npz"


def ensure_events(base: Path, sym: str) -> dict:
    p = events_path(base, sym)
    if p.exists():
        return dict(np.load(p))
    t = time.time()
    ev = T.build_events(WORK, sym, log)
    np_save(p, ev)
    write_json(p.with_suffix(".done"), {"coin": sym, "rows": int(len(ev["anchor"])), "seconds": time.time() - t})
    return ev


def pipeline(dry: bool = False, only: list | None = None) -> int:
    low_priority()
    base = OUT if not dry else OUT / "dry"
    base.mkdir(parents=True, exist_ok=True)
    sha = plan_sha()
    log(f"plan sha256 {sha}  dry={dry}")
    ok, tail = run_tests()
    write_json(base / "tests.json", {"passed": ok, "tail": tail, "utc": dt.datetime.now(dt.timezone.utc).isoformat()})
    if not ok:
        log("TESTS FAILED\n" + tail)
        return 2
    log("exp_e_v1 tests passed")
    coins = only or (["BTCUSDT"] if dry else list(ORDER))
    jobs = [(c, y) for c in coins for y in J.YEARS if y >= J.FIRST_YEAR[c]]
    if dry:
        jobs = [("BTCUSDT", 2023)]
    t0 = time.time()
    done = 0
    cache: dict = {}
    for sym, year in jobs:
        marker = base / "oof" / sym / f"{year}.done"
        if marker.exists():
            done += 1
            continue
        progress("RUNNING", done, len(jobs), f"{sym} {year}", t0)
        for attempt in range(1, 31):
            gate(f"{sym} {year}")
            try:
                ts = time.time()
                if cache.get("sym") != sym:
                    cache.clear()
                    import gc
                    gc.collect()
                    ev = ensure_events(base, sym)
                    names, X = J.load_features(WORK, sym, ev["anchor"])
                    cache.update(sym=sym, ev=ev, names=names, X=X, v4=J.V4Sim(WORK, sym))
                    log(f"{sym}: events {len(ev['anchor'])} rows, features {X.shape}, V4 grid ready (process private {private_gb():.2f} GB)")
                arrays, prov = J.run_fold(WORK, sym, year, cache["ev"], cache["names"], cache["X"], cache["v4"], log)
                np_save(base / "oof" / sym / f"{year}.npz", arrays)
                prov.update(plan_sha256=sha, utc=dt.datetime.now(dt.timezone.utc).isoformat(), features=cache["names"], attempts=attempt)
                write_json(base / "oof" / sym / f"{year}.json", prov)
                write_json(marker, {"coin": sym, "year": year, "seconds": time.time() - ts})
                log(f"{sym} {year} job complete {time.time() - ts:.0f}s (process private {private_gb():.2f} GB, avail commit {avail_commit_gb():.2f} GB, attempt {attempt})")
                break
            except Exception as exc:
                if not (isinstance(exc, MemoryError) or "alloc" in str(exc).lower()):
                    log("JOB FAILED " + sym + " " + str(year) + " | " + traceback.format_exc())
                    write_json(base / "errors" / f"{sym}_{year}.json", {"trace": traceback.format_exc()})
                    return 1
                log(f"MEMORY ERROR {sym} {year} attempt {attempt} (process private {private_gb():.2f} GB, avail commit {avail_commit_gb():.2f} GB); waiting and retrying")
                cache.clear()
                import gc
                gc.collect()
                time.sleep(60)
        else:
            log(f"JOB GAVE UP {sym} {year}: out of memory after 30 attempts")
            return 1
        done += 1
    if dry:
        progress("DRY_DONE", done, len(jobs), "dry", t0)
        return 0
    progress("ANALYSIS", done, len(jobs), "analysis", t0)
    analyze()
    progress("DONE", done, len(jobs), "done", t0)
    return 0


def analyze(results: Path | None = None) -> None:
    from . import analysis, report
    sha = plan_sha()
    t = time.time()
    results = results or (OUT / "results")
    results.mkdir(parents=True, exist_ok=True)
    summ = analysis.run_all(OUT, results, log)
    summ["plan_sha256"] = sha
    (results / "summary.json").write_text(json.dumps(summ, indent=1, default=float), "utf-8")
    report.write(OUT, results, summ)
    log(f"analysis + reports written ({time.time() - t:.0f}s)")


def audit() -> int:
    from . import audit as A
    low_priority()
    results = OUT / "results"
    results.mkdir(parents=True, exist_ok=True)
    A.run(WORK, OUT, results, log)
    return 0


def main(argv=None) -> int:
    argv = argv or sys.argv[1:]
    cmd = argv[0] if argv else "run"
    OUT.mkdir(parents=True, exist_ok=True)
    if cmd == "dry":
        t = time.time()
        rc = pipeline(dry=True)
        log(f"dry run finished rc={rc} in {time.time() - t:.0f}s")
        return rc
    if cmd == "run":
        return pipeline(dry=False)
    if cmd == "analyze":
        low_priority()
        analyze()
        return 0
    if cmd == "audit":
        return audit()
    print(__doc__)
    return 3
