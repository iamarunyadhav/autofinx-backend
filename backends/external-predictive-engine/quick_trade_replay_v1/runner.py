"""Resumable runner for Quick Trade Replay v1.

  python -I run_quick_trade_replay_v1.py dry      # BTC, preset P1 only, scratch dir, timed
  python -I run_quick_trade_replay_v1.py run      # tests -> per-coin jobs (BTC, ETH first) -> analysis -> report (finished jobs are skipped)
  python -I run_quick_trade_replay_v1.py analyze  # rebuild items / tables / REPORT.md from the saved arrays

One heavy job at a time, below-normal priority, memory guard (waits while available commit < 2 GB), STOP file pauses, D: free space must stay > 30 GB,
atomic outputs. Research only: public local files, no orders, no keys, no server, no DB. Never reads anything at or after 2026-01-01.
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

from . import analysis
from . import job as J

WORK = Path(os.environ.get("AUTOFINX_V4_WORK", r"D:\autofinx-ml-v4"))
OUT = WORK / "quick_trade_replay_v1"
RESULTS = OUT / "results"
ENGINE = Path(__file__).resolve().parents[1]
PLAN = ENGINE.parent / "docs" / "ml-engine-v1" / "QUICK_TRADE_REPLAY_V1.md"
LOG = WORK / "logs" / "quick_trade_replay_v1.log"
GUARD_GB = 2.0
MIN_FREE_GB = 30.0
ORDER = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT", "XRPUSDT", "SUIUSDT")


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


def progress(status: str, done: int, total: int, current: str, t0: float) -> None:
    el = time.time() - t0
    write_json(OUT / "progress.json", {"status": status, "done": done, "total": total, "current": current, "elapsed_s": el,
                                       "eta_s": (el / done * (total - done)) if done else None, "avail_commit_gb": avail_commit_gb(),
                                       "utc": dt.datetime.now(dt.timezone.utc).isoformat()})


def plan_sha() -> str:
    sha = hashlib.sha256(PLAN.read_bytes()).hexdigest()
    ref = (OUT / "PLAN_SHA256.txt").read_text("utf-8").split()[0] if (OUT / "PLAN_SHA256.txt").exists() else None
    if ref and ref != sha:
        raise RuntimeError("QUICK_TRADE_REPLAY_V1.md changed after it was frozen (sha mismatch). Restore it or add an AMENDMENT and re-register.")
    return sha


def np_save(path: Path, arrays: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + ".tmp.npz")
    np.savez_compressed(tmp, **arrays)
    os.replace(tmp, path)


def run_tests() -> tuple[bool, str]:
    r = subprocess.run([sys.executable, "-m", "pytest", "tests/test_quick_trade_replay_v1.py", "-q", "-x", "-p", "no:cacheprovider"], cwd=ENGINE,
                       capture_output=True, text=True, timeout=1800)
    return r.returncode == 0, (r.stdout + r.stderr)[-1500:]


def pipeline(dry: bool = False, only: list | None = None) -> int:
    low_priority()
    results = RESULTS if not dry else OUT / "dry_results"
    results.mkdir(parents=True, exist_ok=True)
    sha = plan_sha()
    log(f"plan sha256 {sha}  dry={dry}")
    ok, tail = run_tests()
    write_json(results / "tests.json", {"passed": ok, "tail": tail, "utc": dt.datetime.now(dt.timezone.utc).isoformat()})
    if not ok:
        log("TESTS FAILED\n" + tail)
        return 2
    log("replay tests passed")
    coins = only or (["BTCUSDT"] if dry else list(ORDER))
    t0 = time.time()
    done = 0
    for sym in coins:
        marker = results / "jobs" / f"{sym}.done"
        if marker.exists():
            done += 1
            continue
        gate(sym)
        progress("RUNNING", done, len(coins), sym, t0)
        try:
            ts = time.time()
            arrays, meta = J.run_coin(WORK, sym, log, only_presets=("P1",) if dry else None)
            np_save(results / "arrays" / f"{sym}.npz", arrays)
            write_json(results / "arrays" / f"{sym}.meta.json", meta)
            write_json(marker, {"coin": sym, "plan_sha": sha, "seconds": time.time() - ts, "n_arrays": len(arrays)})
            log(f"{sym} job complete {time.time() - ts:.0f}s ({len(arrays)} arrays)")
        except Exception:
            log(f"JOB FAILED {sym}\n{traceback.format_exc()}")
            write_json(results / "errors" / f"{sym}.json", {"trace": traceback.format_exc()})
            return 1
        done += 1
    progress("ANALYSIS", done, len(coins), "analysis", t0)
    analyze(results)
    progress("DONE", done, len(coins), "done", t0)
    return 0


def analyze(results: Path | None = None) -> None:
    from . import report
    results = results or RESULTS
    sha = plan_sha()
    t = time.time()
    summ = analysis.run_all(results, log)
    summ["plan_sha256"] = sha
    (results / "summary.json").write_text(json.dumps(summ, indent=1, default=float), "utf-8")
    report.write(results, summ)
    log(f"analysis + REPORT.md written ({time.time() - t:.0f}s)")


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
        analyze(Path(argv[1]) if len(argv) > 1 else None)
        return 0
    print(__doc__)
    return 3
