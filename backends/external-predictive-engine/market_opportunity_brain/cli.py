"""Fixed, vetted CLI entrypoint for the PHP `ForecastGateway` to invoke as a subprocess -- the
same pattern `StrategyLabRunner.php` already uses for `v4.lab_runner` (fixed argument list, no
shell, no user text in the command beyond already-validated numeric/symbol/JSON values). This is
the ONLY way the PHP side of this module may ever reach a Python model; it never imports or
executes arbitrary downloaded Pine/market/news text as code (MASTER_SPEC.md: "No arbitrary code
execution of downloaded content").

Usage:
    python -m market_opportunity_brain.cli predict --model naive_last --symbol BTCUSDT \
        --timeframe 15m --horizon-bars 4 --as-of-utc 2026-10-15T00:00:00Z \
        --workspace-id local-research-workspace --closes-json '[100.0,101.0,102.0]'

Prints exactly one JSON object (the ForecastRecord) to stdout and exits 0, or prints a JSON error
object to stdout and exits 1 -- never a Python traceback on stdout, so the PHP side always gets
parseable JSON either way.
"""

from __future__ import annotations

import argparse
import json
import sys

from market_opportunity_brain.model_registry import get_predict_fn, list_models


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(prog="market_opportunity_brain.cli")
    sub = parser.add_subparsers(dest="command", required=True)

    predict_parser = sub.add_parser("predict")
    predict_parser.add_argument("--model", required=True, choices=list_models())
    predict_parser.add_argument("--symbol", required=True)
    predict_parser.add_argument("--timeframe", required=True)
    predict_parser.add_argument("--horizon-bars", required=True, type=int)
    predict_parser.add_argument("--as-of-utc", required=True)
    predict_parser.add_argument("--workspace-id", required=True)
    predict_parser.add_argument("--closes-json", required=True)
    predict_parser.add_argument("--last-close-time-utc", default=None)

    batch_parser = sub.add_parser("predict-batch")
    batch_parser.add_argument("--model", required=True, choices=["chronos2"])
    batch_parser.add_argument("--input-file", required=True)
    batch_parser.add_argument("--output-file", required=True)
    batch_parser.add_argument("--horizon-bars", required=True, type=int)
    batch_parser.add_argument("--batch-size", type=int, default=16)

    args = parser.parse_args(argv)

    if args.command == "predict-batch":
        try:
            with open(args.input_file, "r", encoding="utf-8") as fh:
                windows = json.load(fh)["windows"]
            from market_opportunity_brain.models import chronos2

            out = chronos2.predict_batch(windows, args.horizon_bars, args.batch_size)
            with open(args.output_file, "w", encoding="utf-8") as fh:
                json.dump(out, fh)
            print(json.dumps({"status": out["status"], "reason": out["reason"], "n": len(out["results"])}))

            return 0 if out["status"] == "OK" else 1
        except Exception as exc:  # noqa: BLE001
            print(json.dumps({"status": "MODEL_UNAVAILABLE", "reason": f"CLI_ERROR: {exc}"}))

            return 1

    if args.command == "predict":
        try:
            closes = json.loads(args.closes_json)
            if not isinstance(closes, list):
                raise ValueError("closes-json must be a JSON array of numbers")
            predict_fn = get_predict_fn(args.model)
            record = predict_fn(
                closes=[float(c) for c in closes],
                horizon_bars=args.horizon_bars,
                symbol=args.symbol,
                timeframe=args.timeframe,
                workspace_id=args.workspace_id,
                as_of_utc=args.as_of_utc,
                last_close_time_utc=args.last_close_time_utc,
            )
            print(json.dumps(record.to_dict()))

            return 0
        except Exception as exc:  # noqa: BLE001 -- must always emit JSON, never a raw traceback
            print(json.dumps({"status": "MODEL_UNAVAILABLE", "reason": f"CLI_ERROR: {exc}", "execution_authorized": False}))

            return 1

    return 1


if __name__ == "__main__":
    sys.exit(main())
