"""Resumable runner for Regime + Tradeability v1.

  python -I run_regime_v1.py dry      # BTC only, timed, nothing saved to results (writes to a scratch dir)
  python -I run_regime_v1.py run      # tests -> per-coin jobs (BTC, ETH first) -> analysis -> report (finished jobs are skipped)
  python -I run_regime_v1.py analyze  # rebuild tables / verdicts / REPORT.md from the saved job outputs

One heavy job at a time, below-normal priority, memory guard (waits while available commit < 2 GB), STOP file pauses, atomic outputs.
Research only: public local files, no orders, no keys, no server, no DB. Never reads anything at or after 2026-01-01.
"""

from __future__ import annotations

import datetime as dt
import hashlib
import os
import subprocess
import sys
import time
import traceback
from pathlib import Path

import numpy as np

from edge_search.core import clean, read_json_safe, write_json
from edge_search.runner import avail_commit_gb, low_priority

from . import analysis, q1
from . import data as D
from . import features as F
from . import trades as TR

WORK = Path(os.environ.get("AUTOFINX_V4_WORK", r"D:\autofinx-ml-v4"))
OUT = WORK / "regime_v1"
RESULTS = OUT / "results"
ENGINE = Path(__file__).resolve().parents[1]
PLAN = ENGINE.parent / "docs" / "ml-engine-v1" / "REGIME_TRADEABILITY_V1.md"
LOG = WORK / "logs" / "regime_v1.log"
GUARD_GB = 2.0
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


def gate(current: str, results: Path) -> None:
    while (OUT / "STOP").exists() or avail_commit_gb() < GUARD_GB:
        write_json(OUT / "progress.json", {"status": "PAUSED" if (OUT / "STOP").exists() else "WAITING_MEMORY", "current": current,
                                           "avail_commit_gb": avail_commit_gb(), "utc": dt.datetime.now(dt.timezone.utc).isoformat()})
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
        raise RuntimeError("REGIME_TRADEABILITY_V1.md changed after it was frozen (sha mismatch). Restore it or add an AMENDMENT and re-register.")
    return sha


def np_save(path: Path, arrays: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + ".tmp.npz")
    np.savez_compressed(tmp, **arrays)
    os.replace(tmp, path)


def descriptive(coin, feats) -> dict:
    out = {}
    for tf in ("15m", "1h", "4h", "1d"):
        b = coin.bars[tf]
        inw = (b.close_time >= D.WINDOW_LO) & np.isfinite(b.c)
        reg = feats[tf]["regime"][inw]
        n = max(1, len(reg))
        T = feats[tf]["T"][inw]
        d = {"n_bars": int(inw.sum()), "regime_share": {F.NAMES[i]: float((reg == i).sum() / n) for i in range(9)},
             "T_median": float(np.nanmedian(T)) if np.isfinite(T).any() else None}
        for k, v in TR.FILTERS.items():
            d[f"share_{k}"] = float(F.t_gate(T, v).mean())
        m = F.move_gate(feats[tf]["atrp"][inw])
        d["share_M"] = float(m.mean())
        d["share_TM"] = float((F.t_gate(T, 0.5) & m).mean())
        out[tf] = d
    return out


def coin_job(sym: str, results: Path) -> dict:
    t0 = time.time()
    coin = D.build_coin(WORK, sym)
    world = D.World({sym: coin}, WORK)
    feats = {}
    for tf in ("15m", "1h", "4h", "1d"):
        b = coin.bars[tf]
        feats[tf] = F.compute(b.o, b.h, b.l, b.c)
        np_save(results / "feat" / f"{sym}_{tf}.npz", {"regime": feats[tf]["regime"], "brk_dir": feats[tf]["brk_dir"],
                                                       "T": feats[tf]["T"].astype(np.float32), "atrp": feats[tf]["atrp"].astype(np.float32),
                                                       "close_time": b.close_time})
    log(f"{sym}: features done {time.time() - t0:.0f}s")
    cells = {"own": {}, "htf": {}}
    for (tf, H) in q1.CELLS:
        g, rv, mask, ct = q1.window_arrays(coin, tf, H)
        b = coin.bars[tf]
        h_tf = q1.HTF[tf]
        lab_htf = F.htf_align(b.close_time, coin.bars[h_tf].close_time, feats[h_tf]["regime"])
        cells["own"][f"{tf}_H{H}"] = q1.cell_stats(feats[tf]["regime"], g, rv, mask, ct, f"{sym}|{tf}|{H}|own")
        cells["htf"][f"{tf}_H{H}"] = q1.cell_stats(lab_htf, g, rv, mask, ct, f"{sym}|{tf}|{H}|htf")
        log(f"{sym}: q1 cell {tf} H{H} done {time.time() - t0:.0f}s")
    write_json(results / "q1" / f"{sym}.json", {"coin": sym, "cells": cells, "descriptive": descriptive(coin, feats)})
    for tf in TR.TFS:
        gate(f"{sym} trades {tf}", results)
        res = TR.build_tf(world, sym, tf, feats[tf])
        np_save(results / "trades" / f"{sym}_{tf}.npz", res)
        log(f"{sym}: trades {tf} done ({sum(1 for k in res if k.endswith('|net'))} sets) {time.time() - t0:.0f}s")
    return {"seconds": time.time() - t0}


def run_tests() -> tuple[bool, str]:
    r = subprocess.run([sys.executable, "-m", "pytest", "tests/test_regime_v1.py", "-q", "-x", "-p", "no:cacheprovider"], cwd=ENGINE,
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
    log("regime tests passed")
    coins = only or (["BTCUSDT"] if dry else list(ORDER))
    t0 = time.time()
    done = 0
    for sym in coins:
        marker = results / "jobs" / f"{sym}.done"
        if marker.exists():
            done += 1
            continue
        gate(sym, results)
        progress("RUNNING", done, len(coins), sym, t0)
        try:
            info = coin_job(sym, results)
            write_json(marker, {"coin": sym, "plan_sha": sha, **info})
            log(f"{sym} job complete {info['seconds']:.0f}s")
        except Exception:
            log(f"JOB FAILED {sym}\n{traceback.format_exc()}")
            write_json(results / "errors" / f"{sym}.json", {"trace": traceback.format_exc()})
            return 1
        done += 1
    progress("ANALYSIS", done, len(coins), "analysis", t0)
    if not dry:
        analyze(results)
    progress("DONE", done, len(coins), "done", t0)
    return 0


def analyze(results: Path | None = None) -> None:
    from . import report
    results = results or RESULTS
    sha = plan_sha()
    rows = analysis.q23(results, log)
    q1r = analysis.q1_rows(results)
    q1v = analysis.q1_verdicts(q1r)
    disc = [r for r in rows if r["pool"] == "DISC"]
    verd = {
        "plan_sha256": sha,
        "Q1": q1v,
        "Q2a": analysis.question_verdict([r for r in disc if r.get("q2q3") == "Q2" and r["variant"] in analysis.FILTER_VARIANTS], "help"),
        "Q2b": analysis.question_verdict([r for r in disc if r.get("q2q3") == "Q2" and r["variant"] in analysis.FILTER_VARIANTS], "profit"),
        "Q3a": analysis.question_verdict([r for r in disc if r["variant"] == "G"], "help"),
        "Q3b": analysis.question_verdict([r for r in disc if r["variant"] == "G" or r["unit"] in TR.COMPOSITES], "profit"),
        "Q3_composites": analysis.question_verdict([r for r in disc if r["unit"] in TR.COMPOSITES], "profit"),
        "baselines_unfiltered": analysis.question_verdict([r for r in disc if r["variant"] == "ALL" and r["unit"] not in TR.COMPOSITES], "profit"),
        "Q5": {"verdict": "NOT_TESTED", "reason": "rule-based states and scores only; no probability was fitted, so no calibration is reported"},
    }
    write_json(results / "verdicts.json", verd)
    flat = analysis.flatten_q23(rows)
    cols = list(flat[0].keys()) if flat else []
    allcols = list(dict.fromkeys(c for r in flat for c in r.keys()))
    analysis.write_csv(results / "q23_units.csv", flat, allcols)
    analysis.write_csv(results / "q23_by_coin.csv", analysis.by_coin_rows(rows),
                       ["coin", "unit", "tf", "exit", "variant", "n_all", "n_kept", "net_all", "net_kept", "gross_kept", "delta"])
    q1cols = ["coin", "label", "cell", "regime", "n_all", "n", "share", "mean_g", "mean_g_all", "hit", "hit_all", "mean_abs", "mean_abs_all",
              "mean_rv", "share_gt_c0", "abs_over_c0", "dD", "R", "R_rv", "hit_diff", "mde_g", "p_dir", "p_mag", "p_hit", "q_dir", "q_mag"]
    analysis.write_csv(results / "q1_regimes.csv", q1r, q1cols)
    write_json(results / "q23_full.json", rows)
    report.write(results, verd, rows, q1r)
    log("analysis + REPORT.md written")


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
    print(__doc__)
    return 3
