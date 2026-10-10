"""Edge Search v2 pilot: items, statistics, verdicts, report (plan sections 4-5). Reuses the v1 trade engine (edge_search.core).

Reads ONLY the pilot-window files (klines / funding monthly 2025-10..2025-12, v2 features). Never opens the holdout gate.
"""

from __future__ import annotations

import csv
import datetime as dt
import hashlib
import json
import math
from pathlib import Path

import numpy as np

from edge_search import core
from edge_search.core import COST_BASE, M5, Coin, describe, outcomes_time, random_null, select_trades

from . import events as ev
from . import features as fe
from .pipeline import PILOT_END, ms_of, months_between

SYMBOLS = ("BTCUSDT", "ETHUSDT")
HORIZONS = {"15m": 3, "1h": 12, "4h": 48}
COST_MAKER = 2 * 0.02
MID_MS = ms_of(dt.date(2025, 11, 15)) + 12 * 3_600_000


# ------------------------------------------------------------------------------------------ market data (pilot months only)
def load_market(work: Path, symbol: str, t0_ms: int, n5: int, months: list[str]) -> Coin:
    from v4 import binance_data as bd
    k5 = bd.concat(bd.read_parquet(work / "data" / symbol / "5m" / "monthly" / f"{m}.parquet") for m in months)
    fund = bd.concat(bd.read_parquet(work / "data" / symbol / "funding" / "monthly" / f"{m}.parquet") for m in months)
    t1 = t0_ms + n5 * M5
    keep = (k5["open_time"] >= t0_ms) & (k5["open_time"] < t1)
    k5 = {k: v[keep] for k, v in k5.items()}
    return Coin(symbol, t0_ms, n5, k5, fund, None, tfs=("5m",))


def window(max_day: dt.date) -> tuple[int, int, int, list[str]]:
    t0 = ms_of(dt.date(2025, 10, 1))
    t1 = ms_of(max_day + dt.timedelta(days=1))
    return t0, t1, (t1 - t0) // M5, months_between("2025-10", f"{max_day.year:04d}-{max_day.month:02d}")


# ------------------------------------------------------------------------------------------ one item
def trade_stats(coin: Coin, out, sig, lo: int, hi: int, H: int) -> dict:
    b = coin.bars["5m"]
    tr = select_trades(sig, out, b.close_time, lo, hi)
    gross = tr.gross
    net = tr.net(coin, COST_BASE)
    d = describe(gross)
    dn = describe(net)
    first = tr.entry_t < MID_MS
    long_m, short_m = tr.side > 0, tr.side < 0
    mean = lambda x: float(x.mean()) if len(x) else None
    # maker scenario (reported only): trade-through fill on the entry bar, optimistic exit
    n = b.n
    ent = np.minimum(tr.idx + 1, n - 1)
    filled = np.where(tr.side > 0, b.l[ent] < b.o[ent], b.h[ent] > b.o[ent]) if len(tr.idx) else np.zeros(0, bool)
    mk_g = gross[filled]
    mk_net = (gross - COST_MAKER - tr.side * coin.funding(tr.entry_t, tr.exit_t))[filled] if len(tr.idx) else np.zeros(0)
    return {"n": d["n"], "gross": d["mean"], "net": dn["mean"], "sd": d["sd"], "t": d["t"], "p1": d["p1"], "mde": d["mde"],
            "hit": d["hit"], "t_net": dn["t"],
            "gross_long": mean(gross[long_m]), "gross_short": mean(gross[short_m]), "n_long": int(long_m.sum()),
            "n_short": int(short_m.sum()),
            "gross_h1": mean(gross[first]), "gross_h2": mean(gross[~first]), "n_h1": int(first.sum()), "n_h2": int((~first).sum()),
            "maker_filled": int(filled.sum()), "maker_gross": mean(mk_g), "maker_net": mean(mk_net),
            "_tr": tr}


def verdict_inconclusive(s: dict) -> bool:
    return s["n"] < 30 or s["mde"] is None or s["mde"] > COST_BASE


def classify(rows: list[dict]) -> None:
    p = np.array([r["p1"] if r["p1"] is not None else 1.0 for r in rows])
    rej = core.bh_reject(p, 0.05)
    adj = core.bh_adjust(p)
    for r, a, b in zip(rows, rej, adj):
        r["bh_reject"], r["bh_adj_p"] = bool(a), float(b)
        if verdict_inconclusive(r):
            r["verdict"], r["reason"] = "INCONCLUSIVE", ("N<30" if r["n"] < 30 else "MDE>cost")
        elif (r["bh_reject"] and r["gross"] is not None and r["gross"] >= core.GROSS_MIN and r["gross_h1"] is not None
              and r["gross_h2"] is not None and r["gross_h1"] > 0 and r["gross_h2"] > 0):
            r["verdict"], r["reason"] = "LEAD", "FDR+gross>=0.06+both halves>0"
        else:
            r["verdict"], r["reason"] = "FAIL", "powered, no lead"


def lead_checks(coin: Coin, out, sig, row: dict, lo: int, hi: int, H: int) -> dict:
    b = coin.bars["5m"]
    delayed = np.r_[0, sig[:-1]].astype(np.int8)
    ts = trade_stats(coin, out, delayed, lo, hi, H)
    se = row["sd"] / math.sqrt(row["n"])
    improved = (ts["gross"] is not None and ts["gross"] - row["gross"] > se)
    nl, ns = core.net_all(out, coin, b, COST_BASE)
    tr = row["_tr"]
    long_frac = float((tr.side > 0).mean()) if len(tr) else 0.5
    null = random_null(nl, ns, out.valid, 0, b.n - 1, row["n"], long_frac, H)
    p_null = float((1 + (null >= row["net"]).sum()) / (len(null) + 1)) if len(null) else None
    return {"shift_gross": ts["gross"], "shift_anomaly": bool(improved), "null_p": p_null,
            "bonferroni_p": min(1.0, row["p1"] * 60)}


def required_n(sd: float, edge: float) -> float:
    return (core.MDE_FACTOR * sd / edge) ** 2


# ------------------------------------------------------------------------------------------ data quality
def data_quality(work: Path, edge: Path, symbol: str, coin: Coin, flow: dict, hist: dict, depth: dict, months: list[str]) -> dict:
    b = coin.bars["5m"]
    n = len(flow["bucket_open_ms"])
    comp = flow["complete"].astype(bool)
    qty_tot = flow["qty_buy"] + flow["qty_sell"]
    v = np.nan_to_num(b.v)
    with np.errstate(invalid="ignore", divide="ignore"):
        rel = np.abs(qty_tot - v) / np.maximum(v, 1e-9)
        relb = np.abs(flow["qty_buy"] - np.nan_to_num(b.tbv)) / np.maximum(np.nan_to_num(b.tbv), 1e-9)
    ok_o = np.isfinite(b.o) & np.isfinite(flow["p_first"])
    ok_c = np.isfinite(b.c) & np.isfinite(flow["p_last"])
    markers = [json.loads((edge / "markers" / f"flow-{symbol}-{m}.json").read_text("utf-8")) for m in months]
    snaps = depth.get("snap_n", np.array([]))
    dq = {
        "symbol": symbol, "buckets": int(n), "complete_buckets": int(comp.sum()), "complete_pct": float(comp.mean() * 100),
        "rows": int(sum(m["rows"] for m in markers)), "id_missing_total": float(sum(m["id_missing_total"] for m in markers)),
        "out_of_range": int(sum(m["out_of_range"] for m in markers)),
        "non_monotone_batches": int(sum(m["non_monotone_ts"] for m in markers)),
        "volume_vs_kline_median_rel_err": float(np.nanmedian(rel[comp])), "volume_vs_kline_max_rel_err": float(np.nanmax(rel[comp])),
        "buy_vs_kline_taker_buy_median_rel_err": float(np.nanmedian(relb[comp])),
        "buy_vs_kline_taker_buy_max_rel_err": float(np.nanmax(relb[comp])),
        "first_price_eq_kline_open_pct": float((np.abs(flow["p_first"][ok_o] - b.o[ok_o]) < 1e-9).mean() * 100),
        "last_price_eq_kline_close_pct": float((np.abs(flow["p_last"][ok_c] - b.c[ok_c]) < 1e-9).mean() * 100),
        "zero_trade_buckets": int(((flow["n_buy"] + flow["n_sell"]) == 0).sum()),
        "depth_buckets_with_snapshot_pct": float((snaps > 0).mean() * 100) if len(snaps) else 0.0,
        "depth_snapshots_per_bucket_mean": float(np.nanmean(snaps[snaps > 0])) if len(snaps) and (snaps > 0).any() else None,
        "months": [{"month": m["month"], "rows": m["rows"], "zip_bytes": m["zip_bytes"], "download_s": m["download_s"],
                    "aggregate_s": m["aggregate_s"], "sha256": m["sha256"], "id_missing_total": m["id_missing_total"]} for m in markers],
    }
    return dq


# ------------------------------------------------------------------------------------------ main
def run_analysis(work: Path, max_day: dt.date = PILOT_END, out_dir: Path | None = None) -> dict:
    edge = work / "edge_search_v2"
    out_dir = out_dir or edge / "results"
    t0, t1, n5, months = window(max_day)
    days = [dt.date(2025, 10, 1) + dt.timedelta(days=i) for i in range((t1 - t0) // 86_400_000)]
    rows, bench, quality, feats_store = [], [], {}, {}
    lead_info = {}
    for sym in SYMBOLS:
        coin = load_market(work, sym, t0, n5, months)
        flow, hist = fe.load_flow(edge, sym, months)
        assert len(flow["bucket_open_ms"]) == n5 and flow["bucket_open_ms"][0] == t0
        depth = fe.load_depth(edge, sym, t0, n5, days)
        f = fe.build_features(flow, hist, depth)
        sigs = ev.signals(f)
        quality[sym] = data_quality(work, edge, sym, coin, flow, hist, depth, months)
        quality[sym]["signals_per_event"] = {k: int((v != 0).sum()) for k, v in sigs.items()}
        b = coin.bars["5m"]
        for hname, H in HORIZONS.items():
            out = outcomes_time(b, H)
            for name in ev.EVENTS:
                s = trade_stats(coin, out, sigs[name], t0, t1, H)
                s.update({"event": name, "coin": sym, "h": hname, "H": H})
                rows.append(s)
                feats_store[(name, sym, hname)] = (coin, out, sigs[name], H)
            for label, side in (("always_long", 1), ("always_short", -1)):
                s = trade_stats(coin, out, np.full(b.n, side, np.int8), t0, t1, H)
                s.update({"bench": label, "coin": sym, "h": hname})
                bench.append(s)
    classify(rows)
    for r in rows:
        if r["verdict"] == "LEAD":
            coin, out, sig, H = feats_store[(r["event"], r["coin"], r["h"])]
            r["lead"] = lead_checks(coin, out, sig, r, t0, t1, H)
    # required counts for a full run
    days_n = n5 / 288
    for r in rows:
        if r["sd"]:
            r["n_req_0.12"] = required_n(r["sd"], 0.12)
            r["n_req_0.17"] = required_n(r["sd"], 0.17)
            rate = r["n"] / days_n * 365
            r["trades_per_year"] = rate
            r["years_for_0.12"] = r["n_req_0.12"] / rate if rate > 0 else None
    verdicts = [r["verdict"] for r in rows]
    counts = {v: verdicts.count(v) for v in ("INCONCLUSIVE", "FAIL", "LEAD")}
    overall = ("LEADS_FOR_FULL_RUN (not a result)" if counts["LEAD"] else
               "INCONCLUSIVE" if counts["INCONCLUSIVE"] * 2 >= len(rows) else "NO_EDGE_IN_PILOT")
    summary = {"overall": overall, "counts": counts, "n_items": len(rows), "window_days": days_n, "plan_sha256": plan_sha(work),
               "holdout_reads": 0}
    write_results(out_dir, rows, bench, quality, summary)
    return {"rows": rows, "bench": bench, "quality": quality, "summary": summary}


def plan_sha(work: Path | None = None) -> str:
    for p in (Path(__file__).resolve().parents[2] / "docs" / "ml-engine-v1" / "EDGE_SEARCH_V2_PILOT.md",):
        if p.exists():
            return hashlib.sha256(p.read_bytes()).hexdigest()
    return "UNKNOWN"


# ------------------------------------------------------------------------------------------ output
FIELDS = ["event", "coin", "h", "n", "gross", "net", "sd", "t", "p1", "bh_adj_p", "mde", "verdict", "reason", "hit", "gross_long",
          "gross_short", "n_long", "n_short", "gross_h1", "gross_h2", "n_h1", "n_h2", "maker_filled", "maker_gross", "maker_net",
          "n_req_0.12", "n_req_0.17", "trades_per_year", "years_for_0.12"]


def _fmt(x, nd=3):
    if x is None or (isinstance(x, float) and not math.isfinite(x)):
        return "-"
    return f"{x:.{nd}f}" if isinstance(x, float) else str(x)


def write_results(out: Path, rows, bench, quality, summary) -> None:
    out.mkdir(parents=True, exist_ok=True)
    with open(out / "items.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, FIELDS, extrasaction="ignore")
        w.writeheader()
        for r in rows:
            w.writerow({k: r.get(k) for k in FIELDS})
    with open(out / "benchmarks.csv", "w", newline="", encoding="utf-8") as f:
        cols = ["bench", "coin", "h", "n", "gross", "net", "sd", "mde"]
        w = csv.DictWriter(f, cols, extrasaction="ignore")
        w.writeheader()
        for r in bench:
            w.writerow({k: r.get(k) for k in cols})
    clean_rows = [{k: v for k, v in r.items() if k != "_tr"} for r in rows]
    core.write_json(out / "items.json", clean_rows)
    core.write_json(out / "data_quality.json", quality)
    core.write_json(out / "summary.json", summary)
    core.write_json(out / "holdout_reads.json", {"holdout_reads": 0, "note": "pilot window 2025-10-01..2025-12-31 only"})
    (out / "PLAN_SHA256.txt").write_text(summary["plan_sha256"] + "\n", "utf-8")
    (out / "REPORT.md").write_text(report_md(rows, bench, quality, summary), "utf-8")


def report_md(rows, bench, quality, summary) -> str:
    L = [f"# Edge Search v2 PILOT - machine report", "",
         f"Plan sha256 `{summary['plan_sha256']}`. Window {summary['window_days']:.0f} days (2025-10-01..2025-12-31), BTCUSDT + ETHUSDT.",
         f"Holdout reads: {summary['holdout_reads']}. Items (N_trials): {summary['n_items']}.", "",
         f"**Overall verdict: {summary['overall']}**  (INCONCLUSIVE {summary['counts']['INCONCLUSIVE']}, FAIL {summary['counts']['FAIL']}, "
         f"LEAD {summary['counts']['LEAD']}).", "",
         "GROSS = before fee/slippage/funding; NET = after 0.12 % cost and actual funding (taker). Maker columns are a reported-only,",
         "optimistic scenario (0.04 % round trip, trade-through fills, exit assumed filled).", "",
         "| event | coin | H | N | gross % | net % | SD % | MDE % | N need (0.12) | verdict | maker N | maker gross | maker net |",
         "|---|---|---|---|---|---|---|---|---|---|---|---|---|"]
    for r in rows:
        L.append(f"| {r['event']} | {r['coin'][:3]} | {r['h']} | {r['n']} | {_fmt(r['gross'])} | {_fmt(r['net'])} | {_fmt(r['sd'])} | "
                 f"{_fmt(r['mde'])} | {_fmt(r.get('n_req_0.12'), 0)} | {r['verdict']} | {r['maker_filled']} | "
                 f"{_fmt(r['maker_gross'])} | {_fmt(r['maker_net'])} |")
    L += ["", "## Benchmarks (always long / always short, same non-overlapping grid)", "",
          "| bench | coin | H | N | gross % | net % |", "|---|---|---|---|---|---|"]
    for r in bench:
        L.append(f"| {r['bench']} | {r['coin'][:3]} | {r['h']} | {r['n']} | {_fmt(r['gross'])} | {_fmt(r['net'])} |")
    leads = [r for r in rows if r["verdict"] == "LEAD"]
    L += ["", "## Leads", ""] + ([f"- {r['event']} {r['coin']} {r['h']}: N {r['n']}, gross {_fmt(r['gross'])}, net {_fmt(r['net'])}, "
                                  f"BH-adj p {_fmt(r['bh_adj_p'])}, checks {r['lead']}" for r in leads] or ["none"])
    return "\n".join(L) + "\n"
