"""Resumable background runner for Edge Search v1.

  python -m edge_search.runner dry-run      # job counts + timed sample jobs -> ETA, nothing is saved
  python -m edge_search.runner run          # full pipeline (resumes: finished jobs are skipped)
  python -m edge_search.runner report       # rebuild REPORT.md / leaderboard from the saved results

One job at a time, low CPU priority, progress.json, STOP file pauses, atomic result files, a failing job is logged and
skipped. No orders, no API keys, no server access; only public Binance data and the local research files.
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

from . import reporting, rules, special, stages
from .core import (COST_BASE, DISCOVERY, SYMBOLS, World, clean, compute_segments, read_json_safe, write_json)

WORK = Path(os.environ.get("AUTOFINX_V4_WORK", r"D:\autofinx-ml-v4"))
EDGE = WORK / "edge_search_v1"
RESULTS = EDGE / "results"
ENGINE = Path(__file__).resolve().parents[1]
PLAN = ENGINE.parent / "docs" / "ml-engine-v1" / "EDGE_SEARCH_V1.md"
LOG = WORK / "logs" / "edge_search_v1.log"


def log(msg: str) -> None:
    line = f"{dt.datetime.now().strftime('%Y-%m-%d %H:%M:%S')} {msg}"
    print(line, flush=True)
    try:
        LOG.parent.mkdir(parents=True, exist_ok=True)
        with open(LOG, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception:
        pass


def low_priority() -> None:
    try:
        import ctypes
        ctypes.windll.kernel32.SetPriorityClass(ctypes.windll.kernel32.GetCurrentProcess(), 0x4000)   # BELOW_NORMAL
    except Exception:
        pass


def avail_commit_gb() -> float:
    try:
        import ctypes

        class MS(ctypes.Structure):
            _fields_ = [("dwLength", ctypes.c_ulong), ("dwMemoryLoad", ctypes.c_ulong), ("ullTotalPhys", ctypes.c_ulonglong),
                        ("ullAvailPhys", ctypes.c_ulonglong), ("ullTotalPageFile", ctypes.c_ulonglong),
                        ("ullAvailPageFile", ctypes.c_ulonglong), ("ullTotalVirtual", ctypes.c_ulonglong),
                        ("ullAvailVirtual", ctypes.c_ulonglong), ("sullAvailExtendedVirtual", ctypes.c_ulonglong)]
        ms = MS()
        ms.dwLength = ctypes.sizeof(ms)
        ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(ms))
        return ms.ullAvailPageFile / 2 ** 30
    except Exception:
        return 99.0


class Progress:
    def __init__(self):
        self.t0 = time.time()
        self.stage, self.t_stage = "init", time.time()
        self.done = self.total = 0
        self.status = "RUNNING"

    def start_stage(self, stage: str, total: int) -> None:
        self.stage, self.t_stage, self.done, self.total = stage, time.time(), 0, total
        self.write("")

    def write(self, current: str) -> None:
        el = time.time() - self.t_stage
        eta = el / self.done * (self.total - self.done) if self.done else None
        write_json(EDGE / "progress.json", {"status": self.status, "stage": self.stage, "done": self.done, "total": self.total,
                                            "eta_s_this_stage": eta, "current": current, "elapsed_total_s": time.time() - self.t0,
                                            "updated_utc": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")})

    def tick(self, current: str) -> None:
        self.done += 1
        self.write(current)

    def gate(self, current: str) -> None:
        """STOP file pauses; low free memory waits."""
        while (EDGE / "STOP").exists() or avail_commit_gb() < 3.0:
            self.status = "PAUSED" if (EDGE / "STOP").exists() else "WAITING_MEMORY"
            self.write(current)
            time.sleep(20)
        self.status = "RUNNING"


def file_sha(p: Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()


def job_name(parts) -> str:
    return ".".join(str(x) for x in parts)


def stage1_keys(specs) -> list[tuple]:
    keys = {(s.family, s.name, tf, coin) for s in specs for tf in s.tfs for coin in s.coins}
    return sorted(keys, key=lambda k: (k[2], k[3], k[0], k[1]))


def run_tests() -> tuple[bool, str]:
    r = subprocess.run([sys.executable, "-m", "pytest", "tests", "-q", "-x", "-p", "no:cacheprovider"], cwd=ENGINE,
                       capture_output=True, text=True, timeout=1800)
    out = (r.stdout + r.stderr)[-1500:]
    return r.returncode == 0, out


def prepare() -> tuple[str, dict]:
    RESULTS.mkdir(parents=True, exist_ok=True)
    plan_sha = file_sha(PLAN)
    shaf = RESULTS / "PLAN_SHA256.txt"
    if shaf.exists() and shaf.read_text("utf-8").strip() != plan_sha:
        raise RuntimeError("EDGE_SEARCH_V1.md changed after the run started: the plan is frozen. Restore it.")
    shaf.write_text(plan_sha, "utf-8")
    segf = RESULTS / "segments.json"
    seg = read_json_safe(segf)
    if not seg:
        seg = compute_segments(WORK)
        write_json(segf, seg)
    return plan_sha, seg


def load_runtime(plan_sha: str, seg: dict) -> stages.Runtime:
    t = time.time()
    world = World(WORK, seg)
    dev_end = seg["global_dev_end"]
    for s, c in world.coins.items():
        b = c.bars["5m"]
        last = b.close_time[np.isfinite(b.c)].max()
        assert last <= dev_end, f"{s}: bars after the global seal ({last} > {dev_end})"
    log(f"world loaded in {time.time() - t:.0f}s; sealed at {dt.datetime.fromtimestamp(dev_end / 1000, dt.timezone.utc):%Y-%m-%d}")
    return stages.Runtime(WORK, RESULTS, world, seg, plan_sha)


# ------------------------------------------------------------------------------------------ stage 1
def run_stage1(rt: stages.Runtime, prog: Progress, only: list | None = None, save: bool = True) -> dict:
    keys = only if only is not None else stage1_keys(rt.specs)
    prog.start_stage("stage1", len(keys))
    times = {}
    for key in keys:
        name = job_name(key)
        f = RESULTS / "stage1" / f"{name}.json"
        if save and f.exists():
            prog.tick(name + " (cached)")
            continue
        prog.gate(name)
        t = time.time()
        try:
            items = stages.stage1_job(rt, *key)
            if save:
                write_json(f, {"job": name, "seconds": time.time() - t, "items": items})
        except Exception:
            log(f"JOB FAILED {name}\n{traceback.format_exc()}")
            write_json(RESULTS / "errors" / f"{name}.json", {"job": name, "trace": traceback.format_exc()})
        times[name] = time.time() - t
        prog.tick(name)
    return times


def load_stage1_items() -> list[dict]:
    items = []
    for f in sorted((RESULTS / "stage1").glob("*.json")):
        items.extend(read_json_safe(f)["items"])
    return items


# ------------------------------------------------------------------------------------------ the whole pipeline
def pipeline() -> None:
    low_priority()
    EDGE.mkdir(parents=True, exist_ok=True)
    prog = Progress()
    plan_sha, seg = prepare()
    log(f"plan sha256 {plan_sha}")
    ok, tail = run_tests()
    write_json(RESULTS / "leakage_tests.json", {"passed": ok, "tail": tail, "utc": dt.datetime.now(dt.timezone.utc).isoformat()})
    if not ok:
        log("TESTS FAILED, aborting:\n" + tail)
        prog.status = "ABORTED_TESTS"
        prog.write("tests")
        return
    log("tests passed")
    cov = special.coverage_report(WORK, seg)
    write_json(RESULTS / "data_coverage.json", cov)
    for s, d in cov.items():
        log(f"coverage {s}: {d['coverage_pct']}% of 5m, gaps>{5}m {d['gaps_gt_5m']}, funding {d['funding_events']} events, "
            f"metrics days {d['metrics_days']}, ctx {d['has_ctx']}, premium files {d['premium_files']}")
    rt = load_runtime(plan_sha, seg)

    # ---- stage 1 scan
    run_stage1(rt, prog)
    items = load_stage1_items()
    info = stages.stage1_decide(items)
    write_json(RESULTS / "stage1_items.json", items)
    write_json(RESULTS / "stage1_summary.json", info)
    log(f"stage 1: {info}")
    survivors = [it for it in items if it["s1_status"] == "SURVIVOR"]

    # ---- benchmarks, cost wall
    bench = [(c, tf) for c in DISCOVERY for tf in rt.world.coins[c].bars]
    prog.start_stage("benchmarks", len(bench))
    for coin, tf in bench:
        f = RESULTS / "benchmarks" / f"{coin}_{tf}.json"
        if not f.exists():
            prog.gate(f"bench {coin} {tf}")
            write_json(f, special.benchmarks(rt, coin, tf))
        prog.tick(f"bench {coin} {tf}")

    # ---- stage 2 / 3 / 4 for the stage-1 survivors
    cands = [(it, m) for it in survivors for m in stages.EXITS]
    prog.start_stage("stage2-4", len(cands))
    for it, mode in cands:
        cid = f"{it['item_id']}#{mode}"
        h = hashlib.md5(cid.encode()).hexdigest()[:12]
        prog.gate(cid)
        try:
            f2 = RESULTS / "stage2" / f"{h}.json"
            r2 = read_json_safe(f2) or stages.stage2(rt, it, mode)
            write_json(f2, r2)
            if r2["verdict"] == "PASS":
                f3 = RESULTS / "stage3" / f"{h}.json"
                r3 = read_json_safe(f3) or stages.stage3(rt, r2)
                write_json(f3, r3)
                if r3["verdict"] == "PASS":
                    f4 = RESULTS / "stage4" / f"{h}.json"
                    if not f4.exists():
                        write_json(f4, stages.stage4(WORK, RESULTS, seg, plan_sha, r2))
        except Exception:
            log(f"CANDIDATE FAILED {cid}\n{traceback.format_exc()}")
            write_json(RESULTS / "errors" / f"cand_{h}.json", {"cid": cid, "trace": traceback.format_exc()})
        prog.tick(cid)

    # ---- F7 funding carry
    run_f7(rt, prog, seg, plan_sha)

    # ---- F8 ML on families that had survivors
    run_f8(rt, prog, survivors)

    # ---- reports
    prog.start_stage("report", 1)
    reporting.build_reports(RESULTS, plan_sha)
    prog.status = "DONE"
    prog.tick("report")
    log("ALL DONE")


def run_f7(rt: stages.Runtime, prog: Progress, seg: dict, plan_sha: str) -> None:
    from v4 import binance_data as bd
    cells = [(c, i, o) for c in DISCOVERY for i in special.CARRY_IN for o in special.CARRY_OUT]
    prog.start_stage("f7-carry", len(cells) + len(SYMBOLS))
    for s in SYMBOLS:
        prog.gate(f"spot download {s}")
        try:
            special.download_spot(WORK, s, bd.HISTORY_MONTHS.get(s, 36))
        except Exception:
            log(f"spot download failed {s}\n{traceback.format_exc()}")
        prog.tick(f"spot {s}")
    spot = {}
    for s in SYMBOLS:
        P = rt.world.coins[s].bars["1h"]
        spot[s] = special.spot_open_on_grid(WORK, s, P.t0, P.n)
    for coin, thr_in, thr_out in cells:
        cid = f"F7.carry[in{thr_in}/out{thr_out}]@{coin}/1h"
        f = RESULTS / "f7" / f"{hashlib.md5(cid.encode()).hexdigest()[:12]}.json"
        prog.gate(cid)
        try:
            if not f.exists():
                r = special.f7_cell(rt, spot, coin, thr_in, thr_out)
                if r["verdict"] == "PASS":
                    r["stage3"] = special.f7_stage3(rt, spot, r)
                    if r["stage3"]["verdict"] == "PASS":
                        r["stage4"] = special.f7_holdout(WORK, RESULTS, seg, plan_sha, r)
                write_json(f, r)
        except Exception:
            log(f"F7 FAILED {cid}\n{traceback.format_exc()}")
            write_json(RESULTS / "errors" / f"f7_{f.stem}.json", {"cid": cid, "trace": traceback.format_exc()})
        prog.tick(cid)


def run_f8(rt: stages.Runtime, prog: Progress, survivors: list[dict]) -> None:
    f_no = RESULTS / "f8" / "STATUS.json"
    if not survivors:
        write_json(f_no, {"status": "NOT_RUN_NO_STAGE1_SURVIVORS"})
        log("F8 not run: no stage-1 survivors (reported result)")
        return
    cells = sorted({(it["coin"], it["tf"], it["H"]) for it in survivors})
    fam_by_cell = {}
    for it in survivors:
        fam_by_cell.setdefault((it["coin"], it["tf"], it["H"]), set()).add(it["family"])
    prog.start_stage("f8-ml", len(cells))
    for coin, tf, H in cells:
        cid = f"F8.ml@{coin}/{tf}/H{H}"
        f = RESULTS / "f8" / f"{hashlib.md5(cid.encode()).hexdigest()[:12]}.json"
        prog.gate(cid)
        try:
            if not f.exists():
                write_json(f, special.f8_cell(rt, coin, tf, H, fam_by_cell[(coin, tf, H)]))
        except Exception:
            log(f"F8 FAILED {cid}\n{traceback.format_exc()}")
        prog.tick(cid)
    write_json(f_no, {"status": "RUN", "cells": len(cells),
                      "note": "F8 results stop at DEV/VAL; a PASS there needs the Stage 3-4 follow-up described in the report"})


# ------------------------------------------------------------------------------------------ dry run
def dry_run() -> None:
    low_priority()
    EDGE.mkdir(parents=True, exist_ok=True)
    plan_sha, seg = prepare()
    specs = rules.build_specs()
    keys = stage1_keys(specs)
    n_items = sum(len(rules.hs_for(s, tf)) for s in specs for tf in s.tfs for _c in s.coins)
    log(f"DRY RUN plan {plan_sha[:12]}  specs {len(specs)}  stage-1 jobs {len(keys)}  items (config x coin x tf x H) {n_items}")
    import datetime as _d
    u = lambda ms: _d.datetime.fromtimestamp(ms / 1000, _d.timezone.utc).strftime("%Y-%m-%d")
    for s in SYMBOLS:
        w = seg["symbols"][s]
        log(f"  {s}: DEV {u(w['first_open'])}..{u(w['train_end'])}  VAL {u(w['val_start'])}..{u(w['val_end'])}  HOLDOUT(sealed) {u(w['hold_start'])}..")
    ok, tail = run_tests()
    log(f"tests: {'passed' if ok else 'FAILED'}  {tail.strip().splitlines()[-1] if tail.strip() else ''}")
    rt = load_runtime(plan_sha, seg)
    prog = Progress()
    by_fam: dict = {}
    for k in keys:
        by_fam.setdefault(k[0], []).append(k)
    est, sample_t = {}, {}
    for fam, ks in by_fam.items():
        # time the heaviest and the lightest job of each family and extrapolate with the per-timeframe bar counts
        bars_per_tf = {tf: rt.world.coins["BTCUSDT"].bars[tf].n for tf in ("5m", "15m", "1h", "4h", "1d")}
        pick = sorted(ks, key=lambda k: bars_per_tf[k[2]])
        sample = [pick[-1], pick[len(pick) // 2]]
        tt = []
        for k in sample:
            t = time.time()
            stages.stage1_job(rt, *k)
            tt.append((k, time.time() - t, bars_per_tf[k[2]]))
        rate = float(np.mean([t / n for _, t, n in tt]))
        est[fam] = float(sum(rate * bars_per_tf[k[2]] for k in ks))
        sample_t[fam] = [(job_name(k), round(t, 1)) for k, t, _ in tt]
        log(f"  sample {fam}: {sample_t[fam]}  -> family estimate {est[fam] / 60:.1f} min for {len(ks)} jobs")
    total = sum(est.values())
    extra = 10 * 60 + 5 * 60
    log(f"ETA stage 1: {total / 60:.0f} min (+ ~{extra / 60:.0f} min for benchmarks, F7 and the report; stages 2-4 depend on how many "
        f"stage-1 survivors exist, ~1-2 min per candidate)")
    write_json(EDGE / "dry_run.json", {"plan_sha": plan_sha, "jobs": len(keys), "items": n_items, "est_stage1_s": total,
                                       "per_family_s": est, "samples": sample_t, "tests_passed": ok})


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "dry-run"
    if cmd == "dry-run":
        dry_run()
    elif cmd == "run":
        try:
            pipeline()
        except Exception:
            log("PIPELINE ERROR\n" + traceback.format_exc())
            write_json(EDGE / "progress.json", {"status": "ERROR", "trace": traceback.format_exc()})
            raise
    elif cmd == "report":
        plan_sha, _ = prepare()
        reporting.build_reports(RESULTS, plan_sha)
        log("report rebuilt")
