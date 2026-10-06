"""P3 — V3-vs-V4 label audit (§79), path distributions (§80), policy laboratory (§81, §101 P3).

DEVELOPMENT DATA ONLY (D-005): every statistic uses rows inside splits.dev_mask; the code asserts it.
This phase is a DIAGNOSTIC (C3): nothing here is tuned, frozen or promoted. Every policy below uses
fixed, pre-declared rules; reference levels come only from dev-train distributions.

Policies evaluated for always-LONG and always-SHORT (non-overlapping, one position at a time):
  ENDPOINT     time exit only (the V3 assumption)
  REFERENCE    sl = p75 MAE-before-profit (dev-train, per side, >= 0.15%), act = cost + min edge,
               trail = p50 post-activation retracement (dev-train, per side, >= 0.05%)
  GRID         sl in {ref, 2 x ref} x act in {cost + edge, 2 x (cost + edge)}, trail = ref
  VOLATILITY   sigma_h = vol_48 x sqrt(H / 5) x 100 (causal V3 feature at T0):
               sl = 1.5 sigma_h, act = max(cost + edge, 1.0 sigma_h), trail = 0.5 sigma_h
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import binance_data as bd
from . import policy as pol
from . import splits
from .download import load_funding
from .targets import VALID, GAP, SOURCE_CONFLICT, dense_minutes
from .verify import conflict_buckets

PCTS = (25, 50, 75, 90, 95)
NEVER = 1e9   # a level that is never reached: turns a policy into a pure time exit


def _pct(values: np.ndarray) -> dict:
    v = values[np.isfinite(values)]
    if len(v) == 0:
        return {"n": 0}
    return {"n": int(len(v)), **{f"p{p}": round(float(x), 4) for p, x in zip(PCTS, np.percentile(v, PCTS))}}


def distributions(t: dict, rows: np.ndarray, side: str) -> dict:
    p = f"{side}_"
    reached = np.isfinite(t[p + "time_to_first_positive_net_s"][rows])
    return {
        "mfe_pct": _pct(t[p + "mfe_pct"][rows]),
        "mae_pct": _pct(t[p + "mae_pct"][rows]),
        "reaches_positive_net_pct": round(float(reached.mean() * 100), 2),
        "mae_before_profit_pct": _pct(t[p + "max_adverse_before_profit_pct"][rows]),
        "time_to_first_positive_net_s": _pct(t[p + "time_to_first_positive_net_s"][rows]),
        "time_to_mfe_s": _pct(t[p + "time_to_mfe_s"][rows]),
        "peak_retracement_pct": _pct(t[p + "peak_retracement_pct"][rows]),
        "profit_bar_ambiguous_pct": round(float(np.nanmean(t[p + "profit_bar_ambiguous"][rows]) * 100), 2),
        "time_exit_net_pct": _pct(t[p + "final_time_exit_net_pct"][rows]),
    }


def reference_levels(t: dict, train_rows: np.ndarray, side: str, cost: float, edge: float) -> dict:
    p = f"{side}_"
    mbp = t[p + "max_adverse_before_profit_pct"][train_rows]
    retr = t[p + "peak_retracement_pct"][train_rows]
    return {
        "sl": float(max(0.15, np.nanpercentile(mbp, 75))),
        "act": float(cost + edge),
        "trail": float(max(0.05, np.nanpercentile(retr, 50))),
    }


def run_policy(grid, anchors, rows, h, side, sl, act, trail, cost, funding, conflicts, train, val) -> dict:
    slr, actr, trailr = (v if np.ndim(v) == 0 else v[rows] for v in (sl, act, trail))
    sim = pol.simulate(None, anchors[rows], h, side, slr, actr, trailr, cost, funding, conflicts, grid=grid)
    sim = pol.apply_worst_case(sim, grid, anchors[rows], h, side, slr, actr, trailr, cost, funding)   # D-023
    out = {}
    for name, part in (("dev_train", train[rows]), ("dev_val", val[rows])):
        ok = part & (sim["status"] == VALID)
        chosen = pol.non_overlapping(anchors[rows], ok, sim["exit_time_ms"])
        stats = pol.trade_stats(sim["net_pct"][chosen], sim["reason"][chosen])
        if len(chosen):
            stats["same_bar_trail_pct"] = round(float(sim["same_bar_trail"][chosen].mean() * 100), 2)
            stats["armed_pct"] = round(float(sim["armed"][chosen].mean() * 100), 2)
        out[name] = stats
    return out, sim


def audit(t: dict, rows: np.ndarray, sims: dict, cost: float, required: float, edge: float) -> dict:
    gross = t["long_final_time_exit_gross_pct"][rows]
    fund = t["funding_long_pct"][rows]
    v3_long = gross > required + fund
    v3_short = -gross > required - fund
    out = {"valid_anchors": int(len(rows)), "v3_buy_positive": int(v3_long.sum()), "v3_sell_positive": int(v3_short.sum())}
    v4 = {}
    for side, v3 in (("long", v3_long), ("short", v3_short)):
        s = sims[side]
        reason = s["reason"]
        ambiguous = (reason == pol.AMBIGUOUS_INTRABAR) | s["same_bar_trail"]   # unresolved order (D-023)
        opp = (s["net_pct"] >= edge) & ~ambiguous
        v4[side] = opp
        out[f"v4_{side}_opportunity"] = int(opp.sum())
        out[f"v4_{side}_ambiguous"] = int(ambiguous.sum())
        out[f"{side}_v3_v4_disagree"] = int((v3 != opp).sum())
        out[f"{side}_v3_v4_disagree_pct"] = round(float((v3 != opp).mean() * 100), 2)
        out[f"{side}_endpoint_misses_profitable_path"] = int((~v3 & opp).sum())
        out[f"{side}_endpoint_profit_but_stop_first"] = int((v3 & np.isin(reason, (pol.HARD_STOP, pol.AMBIGUOUS_INTRABAR))).sum())
    both = v4["long"] & v4["short"]
    out["v4_both_sides"] = int(both.sum())
    out["v4_wait"] = int((~v4["long"] & ~v4["short"]).sum())
    out["v4_wait_pct"] = round(float(out["v4_wait"] / max(len(rows), 1) * 100), 2)
    return out


def run_symbol(work: Path, symbol: str, horizons: list[int], cost: float = 0.12, required: float = 0.18, edge: float = 0.06) -> list[dict]:
    m1 = bd.load_klines(work, symbol, "1m")
    m5 = bd.load_klines(work, symbol, "5m")
    grid = dense_minutes(m1)
    funding = load_funding(work, symbol)
    conflicts = conflict_buckets(m1, m5)
    import pyarrow.parquet as pq
    feat = pq.read_table(work / "features" / symbol / "v3.parquet", columns=["anchor_time_ms", "vol_48"]).to_pydict()
    f_anchor = np.asarray(feat["anchor_time_ms"], np.int64)
    f_vol = np.asarray(feat["vol_48"], float)
    reports = []
    for h in horizons:
        started = time.monotonic()
        t = {k: np.asarray(v) for k, v in pq.read_table(work / "targets" / symbol / f"h{h}.parquet").to_pydict().items()}
        anchors = t["anchor_time_ms"].astype(np.int64)
        dev = splits.dev_mask(anchors)
        train, val = splits.dev_train_val(anchors)
        rows = np.nonzero(dev & (t["status"] == VALID))[0]
        assert anchors[rows].max() < splits.holdout_start_ms(anchors), "holdout leaked into P3"
        train_rows = np.nonzero(train & (t["status"] == VALID))[0]

        vol = np.full(len(anchors), np.nan)
        pos = np.searchsorted(f_anchor, anchors)
        hit = (pos < len(f_anchor)) & (f_anchor[np.minimum(pos, len(f_anchor) - 1)] == anchors)
        vol[hit] = f_vol[pos[hit]]
        rows = rows[np.isfinite(vol[rows])]                        # need the causal feature row (lookback)
        sigma = vol * np.sqrt(h / 5.0) * 100.0

        report = {"symbol": symbol, "horizon_minutes": h, "split": splits.describe(anchors),
                  "status_counts_dev": {"valid_with_features": int(len(rows)),
                                        "gap": int((dev & (t["status"] == GAP)).sum()),
                                        "source_conflict": int((dev & (t["status"] == SOURCE_CONFLICT)).sum())},
                  "assumptions": {"cost_pct": cost, "v3_required_move_pct": required, "min_net_edge_pct": edge},
                  "distributions_dev": {}, "policies": {}}
        # Volatility regime terciles from dev-train only.
        q1, q2 = np.nanpercentile(vol[train_rows], [33.3, 66.7])
        regime = np.where(vol < q1, "low_vol", np.where(vol < q2, "mid_vol", "high_vol"))
        for side in ("long", "short"):
            report["distributions_dev"][side] = distributions(t, rows, side)
            report["distributions_dev"][side]["by_volatility_regime"] = {
                r: {"mae_before_profit_pct": _pct(t[f"{side}_max_adverse_before_profit_pct"][rows[regime[rows] == r]]),
                    "mfe_pct": _pct(t[f"{side}_mfe_pct"][rows[regime[rows] == r]])} for r in ("low_vol", "mid_vol", "high_vol")}

        sims_ref = {}
        for side in ("long", "short"):
            ref = reference_levels(t, train_rows, side, cost, edge)
            pols = {
                "ENDPOINT": (NEVER, NEVER, NEVER),
                "REFERENCE": (ref["sl"], ref["act"], ref["trail"]),
                "GRID_sl1_act1": (ref["sl"], ref["act"], ref["trail"]),
                "GRID_sl2_act1": (2 * ref["sl"], ref["act"], ref["trail"]),
                "GRID_sl1_act2": (ref["sl"], 2 * ref["act"], ref["trail"]),
                "GRID_sl2_act2": (2 * ref["sl"], 2 * ref["act"], ref["trail"]),
                "VOLATILITY": (np.maximum(1.5 * sigma, 0.05), np.maximum(cost + edge, sigma), np.maximum(0.5 * sigma, 0.02)),
            }
            pols.pop("GRID_sl1_act1")   # identical to REFERENCE
            report["policies"][side] = {"reference_levels": ref}
            for name, (sl, act, trail) in pols.items():
                stats, sim = run_policy(grid, anchors, rows, h, side, sl, act, trail, cost, funding, conflicts, train, val)
                report["policies"][side][name] = stats
                if name == "REFERENCE":
                    sims_ref[side] = sim
        report["label_audit_dev"] = audit(t, rows, sims_ref, cost, required, edge)
        report["seconds"] = round(time.monotonic() - started, 1)
        out = work / "reports" / "p3"
        out.mkdir(parents=True, exist_ok=True)
        (out / f"{symbol}_h{h}.json").write_text(json.dumps(report, indent=1), "utf-8")
        reports.append(report)
    return reports


def markdown_summary(work: Path) -> str:
    lines = ["# P3 diagnostic summary (development data only; holdout sealed)", "",
             "Always-take baselines, non-overlapping, after 0.12% cost + actual funding. dev_val = later 30% of dev.", "",
             "| Symbol | H | V4 WAIT % | long: endpoint misses path / profit-but-stop-first | best dev_val avg net % (policy, side) | same-bar trail % | ambiguous long |",
             "|---|---|---|---|---|---|---|"]
    for f in sorted((work / "reports" / "p3").glob("*_h*.json"), key=lambda p: (p.stem.split("_h")[0], int(p.stem.split("_h")[1]))):
        r = json.loads(f.read_text("utf-8"))
        a = r["label_audit_dev"]
        best = None
        for side in ("long", "short"):
            for name, s in r["policies"][side].items():
                if name == "reference_levels":
                    continue
                v = s.get("dev_val", {})
                if v.get("trades", 0) > 0 and (best is None or v["avg_net_pct"] > best[0]):
                    best = (v["avg_net_pct"], name, side, v.get("same_bar_trail_pct", 0), v["trades"])
        lines.append(f"| {r['symbol']} | {r['horizon_minutes']} | {a['v4_wait_pct']} | "
                     f"{a['long_endpoint_misses_profitable_path']} / {a['long_endpoint_profit_but_stop_first']} | "
                     f"{best[0] if best else '-'} ({best[1] if best else '-'}, {best[2] if best else '-'}, {best[4] if best else 0} trades) | "
                     f"{best[3] if best else '-'} | {a['v4_long_ambiguous']} |")
    return "\n".join(lines) + "\n"
