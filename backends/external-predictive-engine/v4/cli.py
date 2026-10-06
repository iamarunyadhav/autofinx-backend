"""V4 data CLI (P1). Usage (from external-predictive-engine/):

  python -m v4.cli download --work D:\\autofinx-ml-v4 --symbols BTCUSDT,ETHUSDT [--months 2] [--metrics]
  python -m v4.cli verify   --work D:\\autofinx-ml-v4 --symbols BTCUSDT [--months 2]
  python -m v4.cli status   --work D:\\autofinx-ml-v4

--months overrides the per-symbol history (SPEC §3) — use it only for smoke tests.
Exit code: 0 ok, 1 warnings, 2 failures / stopped.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import sys
import time
from pathlib import Path

from . import binance_data as bd
from .download import Stopped, download_funding, download_klines, download_metrics
from .verify import verify_symbol


def _months(symbol: str, override: int | None) -> int:
    return override if override else bd.HISTORY_MONTHS.get(symbol, 36)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="v4.cli")
    parser.add_argument("command", choices=["download", "verify", "status", "targets", "import-candles", "export-features", "p3", "resolve", "p4a", "p4b"])
    parser.add_argument("--schema", default="qt-shadow-features-v3", help="export-features: qt-shadow-features-v3 or -v4")
    parser.add_argument("--days", type=int, default=30, help="resolve: random development days to sample")
    parser.add_argument("--horizons", default="15,60,120,240,1440")
    parser.add_argument("--cost-pct", type=float, default=None, help="round-trip cost for Layer A (default 0.12, R-002)")
    parser.add_argument("--work", required=True, type=Path)
    parser.add_argument("--symbols", default="BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT,SUIUSDT")
    parser.add_argument("--timeframes", default="1m,5m")
    parser.add_argument("--months", type=int, default=None)
    parser.add_argument("--metrics", action="store_true", help="also download daily metrics for the G7 coverage audit")
    args = parser.parse_args(argv)

    symbols = [s.strip().upper() for s in args.symbols.replace(";", ",").split(",") if s.strip()]
    today = dt.datetime.now(dt.timezone.utc).date()
    reports = args.work / "reports"
    reports.mkdir(parents=True, exist_ok=True)
    worst = 0
    try:
        for symbol in symbols:
            months = _months(symbol, args.months)
            if args.command == "download":
                started = time.monotonic()
                print(f"== {symbol}: download {months} months (+ current month)", flush=True)
                result = {"symbol": symbol, "months": months}
                for timeframe in [t.strip() for t in args.timeframes.split(",") if t.strip()]:
                    result[timeframe] = download_klines(args.work, symbol, timeframe, months, today)
                result["funding"] = download_funding(args.work, symbol, months, today)
                if args.metrics:
                    result["metrics"] = download_metrics(args.work, symbol, months, today)
                result["seconds"] = round(time.monotonic() - started, 1)
                (reports / f"download_{symbol}.json").write_text(json.dumps(result, indent=1), "utf-8")
                print(f"   done in {result['seconds']}s: {json.dumps({k: v for k, v in result.items() if k in ('1m', '5m')}, default=str)[:400]}", flush=True)
            elif args.command == "verify":
                report = verify_symbol(args.work, symbol, months, today)
                (reports / f"data_{symbol}.json").write_text(json.dumps(report, indent=1), "utf-8")
                v = report["verdict"]
                print(f"== {symbol}: {v['status']}  1m rows {report['1m'].get('rows')}, 5m rows {report['5m'].get('rows')}, "
                      f"funding {report['funding'].get('rows')}, coverage 1m {report['1m']['coverage'].get('coverage_pct')}%, "
                      f"RAM est {report['ram_estimate_mb']} MB, {report['seconds']}s", flush=True)
                for line in v["problems"]:
                    print(f"   FAIL {line}")
                for line in v["warnings"]:
                    print(f"   WARN {line}")
                if "metrics_g7" in report:
                    g7 = report["metrics_g7"]
                    print(f"   G7 metrics: days {g7.get('days_present')} present / {g7.get('days_missing')} missing, "
                          f"{g7.get('first_utc')} -> {g7.get('last_utc')}, step {g7.get('median_step_minutes')} min, gaps>15m {g7.get('gaps_over_15min')}")
                worst = max(worst, {"OK": 0, "WARN": 1, "FAIL": 2}[v["status"]])
            elif args.command == "p4b":
                from .ablation import run as run_p4b
                for h in [int(v) for v in args.horizons.replace(";", ",").split(",") if v.strip()]:
                    r = run_p4b(args.work, symbol, h)
                    print(f"== {symbol} h{h}: baseline AUC folds {r['baseline']['fold_auc']} -> selected {r['selected_groups'] or 'none'} "
                          f"({r['selected_feature_count']} features), {r['seconds']}s", flush=True)
                    for st in r["steps"]:
                        print(f"   {st['group']}: gains {st['fold_gain']} brier {st['brier_delta']:+.6f} -> {st['reason']}", flush=True)
            elif args.command == "p4a":
                from .train_v4 import run as run_p4a
                for h in [int(v) for v in args.horizons.replace(";", ",").split(",") if v.strip()]:
                    r = run_p4a(args.work, symbol, h)
                    print(f"== {symbol} h{h}: fit {r['rows']['fit']}, cal {r['rows']['calibration']}, dev_val {r['rows']['dev_val']}, {r['seconds']}s", flush=True)
                    for name, c in r["classifiers"].items():
                        print(f"   {name:9s} val AUC {c['val_auc']}  base {c['val_base_rate_pct']}%  Brier {c['val_brier']} vs {c['val_brier_climatology']}  p>=floor {c['val_rows_p_ge_floor_pct']}%", flush=True)
                    for name, s in r["arms_dev_val"].items():
                        print(f"   {name:26s} trades {s.get('trades', 0):6d}  avg net {s.get('avg_net_pct', 0):+.4f}%  total {s.get('total_net_pct', 0):+.2f}%  "
                              f"win {s.get('win_rate_pct', 0)}%  PF {s.get('profit_factor')}  t {s.get('t_stat')}", flush=True)
            elif args.command == "resolve":
                from .resolver import run_sample
                horizons = [int(h) for h in args.horizons.replace(";", ",").split(",") if h.strip()]
                s = run_sample(args.work, symbol, horizons, days=args.days)
                print(f"== {symbol}: {s['resolved']} flagged trades resolved on {s['days_sampled']} sampled days "
                      f"(of {s['flagged_trades_total']} flagged in dev), {s['seconds']}s", flush=True)
                for name, g in s["groups"].items():
                    print(f"   {name}: resolved {g['resolved']}, outcome changed {g['outcome_changed_pct']}%, "
                          f"avg net change {g['avg_delta_net_pct']:+.4f}% (conservative understated {g['conservative_understates_pct']}%, "
                          f"overstated {g['conservative_overstates_pct']}%)", flush=True)
            elif args.command == "p3":
                from .lab import markdown_summary, run_symbol
                horizons = [int(h) for h in args.horizons.replace(";", ",").split(",") if h.strip()]
                for r in run_symbol(args.work, symbol, horizons):
                    a = r["label_audit_dev"]
                    print(f"== {symbol} h{r['horizon_minutes']}: dev valid {a['valid_anchors']}, V4 WAIT {a['v4_wait_pct']}%, "
                          f"V3 buy+ {a['v3_buy_positive']} vs V4 long opp {a['v4_long_opportunity']}, "
                          f"long disagree {a['long_v3_v4_disagree_pct']}%, {r['seconds']}s", flush=True)
                (args.work / "reports" / "p3" / "SUMMARY.md").write_text(markdown_summary(args.work), "utf-8")
            elif args.command == "import-candles":
                from .features import import_candles
                print(f"== {symbol}: {import_candles(args.work, symbol)} new 5m candles imported into the feature sqlite", flush=True)
            elif args.command == "export-features":
                from .features import export
                meta = export(args.work, symbol, args.schema)
                print(f"== {symbol}: {meta['rows']} v3 feature rows ({len(meta['features'])} features, NaN cells {meta['nan_cells']}), "
                      f"{meta.get('label_rows', 0)} V3 label rows exported in {meta['seconds']}s ({args.schema})", flush=True)
            elif args.command == "targets":
                from .targets import DEFAULT_COST_PCT, build_symbol
                horizons = [int(h) for h in args.horizons.replace(";", ",").split(",") if h.strip()]
                cost = args.cost_pct if args.cost_pct is not None else DEFAULT_COST_PCT
                for meta in build_symbol(args.work, symbol, horizons, cost):
                    c = meta["counts"]
                    print(f"== {symbol} h{meta['horizon_minutes']}: anchors {c['anchors']}, valid {c['valid']}, "
                          f"gap {c['invalid_gap']}, out of range {c['out_of_range']}, {meta['seconds']}s "
                          f"(load {meta['load_seconds']}s, RAM est {meta['ram_estimate_mb']} MB)", flush=True)
            else:
                manifest = bd.Manifest(args.work / "data" / symbol / "manifest.json")
                done = sum(1 for k, v in manifest.data.items() if isinstance(v, dict))
                print(f"{symbol}: {done} files stored, not listed: {sum(1 for v in manifest.data.values() if v == 'not_listed')}")
    except Stopped as stop:
        print(f"STOPPED: {stop}. Everything already written is kept; run again to resume.")
        return 2
    return worst


if __name__ == "__main__":
    sys.exit(main())
