"""Strategy Lab runner (SPEC §105, D-030/D-031): Quick Test / Deep Test of a TRANSLATED candidate.

Called by the Laravel job (Symfony Process) or by hand:
  python -m v4.lab_runner --adapter autofinx-v13 --mode quick --work D:\\autofinx-ml-v4 --out <dir>
Writes <out>/result.json (+ chart.json for quick). Progress lines on stdout: "PROGRESS <pct> <phase>".
Exit codes: 0 ok, 3 DATA_UNAVAILABLE, 4 ADAPTER_UNKNOWN, other = failure.

Research only: no order path exists here. Development data only — dev_val is reserved for P5 and the
holdout is cut off before simulation (strategy_v13.load_sealed). The sample policies and verdict rules below
are fixed in code (versioned) and recorded in every result, before any result is computed.
"""

from __future__ import annotations

import argparse
import hashlib
import importlib
import json
import sys
import time
from pathlib import Path

import numpy as np

ADAPTERS = {"autofinx-v13": "v4.strategy_v13"}
POLICIES = {
    "QUICK_V1": {"symbols": ["BTCUSDT", "ETHUSDT"], "charts": ["15m"], "window": "last 25% of dev_train (by time)",
                 "costs": [0.12], "slices": 4},
    "DEEP_V1": {"symbols": ["BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT", "XRPUSDT", "SUIUSDT"], "charts": ["5m", "15m"],
                "window": "all of dev_train", "costs": [0.12, 0.18], "slices": 4},
}
MIN_TRADES, MIN_PF, MIN_POSITIVE_SLICES = 30, 1.05, 3
CHART_BARS = 1500


def progress(pct: float, phase: str) -> None:
    print(f"PROGRESS {pct:.0f} {phase}", flush=True)


def scorecard(trades: list[dict], cost: float, slices: int, lo: int, hi: int) -> dict:
    sel = [x for x in trades if lo <= x["entry_time"] <= hi]
    n = len(sel)
    if n == 0:
        return {"trades": 0}
    gross = np.array([x["gross_pct"] for x in sel])
    fund = np.array([x["funding_pct"] for x in sel])
    net = gross - cost - fund
    side = np.array([x["side"] for x in sel])
    wins, losses = net[net > 0], net[net < 0]
    gp, gl = wins.sum(), -losses.sum()
    std = net.std(ddof=1) if n > 1 else 0.0
    equity = np.cumsum(net)
    edges = np.linspace(lo, hi + 1, slices + 1)
    entry = np.array([x["entry_time"] for x in sel])
    per_slice = []
    for a, b in zip(edges[:-1], edges[1:]):
        m = (entry >= a) & (entry < b)
        per_slice.append({"trades": int(m.sum()), "avg_net_pct": round(float(net[m].mean()), 5) if m.any() else None})
    reasons: dict[str, int] = {}
    for x in sel:
        k = f"{x['reason_a']}/{x['reason_b']}"
        reasons[k] = reasons.get(k, 0) + 1
    return {
        "trades": n, "buy_trades": int((side > 0).sum()), "sell_trades": int((side < 0).sum()),
        "gross_total_pct": round(float(gross.sum()), 4), "fees_total_pct": round(cost * n, 4),
        "funding_total_pct": round(float(fund.sum()), 4), "net_total_pct": round(float(net.sum()), 4),
        "avg_net_pct": round(float(net.mean()), 5), "median_net_pct": round(float(np.median(net)), 5),
        "profit_factor": round(float(gp / gl), 3) if gl > 0 else None,
        "win_rate_pct": round(float((net > 0).mean() * 100), 2), "loss_rate_pct": round(float((net < 0).mean() * 100), 2),
        "avg_win_pct": round(float(wins.mean()), 5) if len(wins) else None,
        "avg_loss_pct": round(float(losses.mean()), 5) if len(losses) else None,
        "reward_risk": round(float(wins.mean() / -losses.mean()), 3) if len(wins) and len(losses) else None,
        "max_drawdown_additive_pct": round(float((np.maximum.accumulate(np.r_[0.0, equity])[1:] - equity).max()), 4),
        "t_stat": round(float(net.mean() / (std / np.sqrt(n))), 3) if std > 0 else None,
        "avg_hold_minutes": round(float(np.mean([(x["exit_time"] - x["entry_time"]) / 60_000 for x in sel])), 1),
        "slices": per_slice, "exit_reasons": dict(sorted(reasons.items())),
    }


def verdict(mode: str, primary: dict, per_symbol: dict[str, dict], stressed: dict | None) -> tuple[str, list[str]]:
    """Pre-registered rule. Win rate / accuracy is never used."""
    n = primary.get("trades", 0)
    if n < MIN_TRADES:
        return ("TOO_FEW_TRADES" if mode == "quick" else "REJECTED_TOO_FEW_TRADES"), [f"{n} trades < {MIN_TRADES}"]
    avg, pf = primary["avg_net_pct"], primary["profit_factor"] or 0.0
    pos_slices = sum(1 for s in primary["slices"] if (s["avg_net_pct"] or -1) > 0)
    why = [f"avg net {avg:+.4f}%/trade after costs, PF {pf:.3f}, positive slices {pos_slices}/{len(primary['slices'])}"]
    if avg <= 0 and pf < 1.0:
        return ("NO_VALUE" if mode == "quick" else "REJECTED_NO_VALUE"), why
    if avg <= 0 or pf < MIN_PF:
        return "WEAK", why
    if pos_slices < MIN_POSITIVE_SLICES:
        return "FRAGILE", why
    losing = [s for s, v in per_symbol.items() if (v.get("avg_net_pct") or -1) <= 0]
    if losing:
        why.append(f"negative on {', '.join(losing)}")
        return "FRAGILE", why
    if mode == "deep" and stressed is not None and (stressed.get("avg_net_pct") or -1) <= 0:
        why.append("negative at the stressed cost")
        return "REJECTED_COST_FRAGILE", why
    return ("PROMISING_FOR_DEEP_TEST" if mode == "quick" else "PROMISING"), why


def run(adapter: str, mode: str, work: Path, out: Path) -> dict:
    started = time.monotonic()
    module = importlib.import_module(ADAPTERS[adapter])
    policy_name = "QUICK_V1" if mode == "quick" else "DEEP_V1"
    pol = POLICIES[policy_name]
    result = {"adapter": adapter, "mode": mode, "sample_policy": policy_name, "policy": pol,
              "rules": {"min_trades": MIN_TRADES, "min_profit_factor": MIN_PF, "min_positive_slices": MIN_POSITIVE_SLICES,
                        "accuracy_used_for_verdict": False},
              "execution": "AutoFinX normalized: 1m path, stop first in the same minute, gaps at open, costs + funding",
              "data": "development only (dev_train); dev_val reserved for P5; holdout removed before simulation",
              "symbols": {}}
    symbols = [s for s in pol["symbols"] if (work / "data" / s / "5m").exists()]
    if not symbols:
        raise FileNotFoundError(f"no market data under {work}")
    all_trades: dict[str, list[dict]] = {tf: [] for tf in pol["charts"]}
    windows, chart = {}, None
    for i, sym in enumerate(symbols):
        progress(5 + 80 * i / len(symbols), f"loading {sym}")
        data = module.load_sealed(work, sym)
        span = data["train_end"] - data["train_start"]
        lo = data["train_start"] + int(span * 0.75) if mode == "quick" else data["train_start"]
        hi = data["train_end"]
        windows[sym] = {"start_ms": lo, "end_ms": hi}
        result["symbols"][sym] = {"window": windows[sym], "charts": {}}
        for tf in pol["charts"]:
            progress(5 + 80 * (i + 0.5) / len(symbols), f"simulating {sym} {tf}")
            bars, _, trades = module.s0_trades(data, sym, tf)
            sel = [x for x in trades if lo <= x["entry_time"] <= hi]
            all_trades[tf].extend(sel)
            result["symbols"][sym]["charts"][tf] = {str(c): scorecard(sel, c, pol["slices"], lo, hi) for c in pol["costs"]}
            if mode == "quick" and chart is None:
                t = np.asarray(bars["open_time"], np.int64)
                m = np.nonzero((t >= lo) & (t <= hi))[0][-CHART_BARS:]
                first = int(t[m[0]]) if len(m) else hi
                chart = {"symbol": sym, "timeframe": tf,
                         "candles": [[int(t[j]), float(bars["open"][j]), float(bars["high"][j]), float(bars["low"][j]),
                                      float(bars["close"][j])] for j in m],
                         "trades": [{"entry_time": x["entry_time"], "exit_time": x["exit_time"],
                                     "side": "BUY" if x["side"] > 0 else "SELL", "entry_price": round(x["entry_price"], 6),
                                     "exit_price_a": round(x["exit_price_a"], 6), "exit_price_b": round(x["exit_price_b"], 6),
                                     "net_pct": round(x["net_pct"], 4), "reason_a": x["reason_a"], "reason_b": x["reason_b"]}
                                    for x in sel if x["entry_time"] >= first]}
    progress(90, "scoring")
    lo_all = min(w["start_ms"] for w in windows.values())
    hi_all = max(w["end_ms"] for w in windows.values())
    combined = {}
    for tf in pol["charts"]:
        merged = sorted(all_trades[tf], key=lambda x: x["entry_time"])
        combined[tf] = {str(c): scorecard(merged, c, pol["slices"], lo_all, hi_all) for c in pol["costs"]}
    primary_tf, base = pol["charts"][-1], str(pol["costs"][0])
    per_symbol = {s: v["charts"][primary_tf][base] for s, v in result["symbols"].items()}
    stressed = combined[primary_tf][str(pol["costs"][-1])] if len(pol["costs"]) > 1 else None
    v, why = verdict(mode, combined[primary_tf][base], per_symbol, stressed)
    result.update({"combined": combined, "primary_chart": primary_tf, "verdict": v, "verdict_reasons": why})
    if mode == "deep":
        layers = {}
        key = adapter.split("-")[-1]
        for sym in symbols:
            p = work / "reports" / "optional-layers" / f"{sym}_h15.json"
            rep = json.loads(p.read_text("utf-8")).get("layers", {}).get(key, {}) if p.exists() else {}
            if rep and "feature" in rep:
                layers[sym] = {"feature": rep["feature"]["verdict"],
                               "wait": {s: {k: w[k]["verdict"] for k in w if k.startswith("keep_top")} for s, w in rep.get("wait", {}).items()},
                               "rule": {k: r["verdict"] for k, r in rep.get("rule", {}).items()}}
        result["component_layers_h15"] = layers or None
    result["seconds"] = round(time.monotonic() - started, 1)
    out.mkdir(parents=True, exist_ok=True)
    body = json.dumps(result, indent=1, default=str)
    (out / "result.json").write_text(body, "utf-8")
    (out / "result.sha256").write_text(hashlib.sha256(body.encode()).hexdigest(), "utf-8")
    if chart is not None:
        (out / "chart.json").write_text(json.dumps(chart), "utf-8")
    progress(100, "done")
    return result


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="v4.lab_runner")
    ap.add_argument("--adapter", required=True)
    ap.add_argument("--mode", choices=["quick", "deep"], required=True)
    ap.add_argument("--work", required=True, type=Path)
    ap.add_argument("--out", required=True, type=Path)
    a = ap.parse_args(argv)
    if a.adapter not in ADAPTERS:
        print(f"ADAPTER_UNKNOWN {a.adapter}", file=sys.stderr)
        return 4
    try:
        r = run(a.adapter, a.mode, a.work, a.out)
    except FileNotFoundError as exc:
        print(f"DATA_UNAVAILABLE {exc}", file=sys.stderr)
        return 3
    print(f"VERDICT {r['verdict']}", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
