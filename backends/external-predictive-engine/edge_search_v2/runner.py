"""Edge Search v2 pilot runner.

  python -I run_edge_search_v2.py pipeline   # download + aggregate (resumable; pilot window by default)
  python -I run_edge_search_v2.py analyze    # events -> results folder (reads only pilot-window files)
  python -I run_edge_search_v2.py all        # pipeline then analyze
Options: --max-day YYYY-MM-DD (default 2025-12-31, never beyond the 2026-01-03 seal).
Work dir: D:\\autofinx-ml-v4 (env AUTOFINX_V4_WORK). One process, low priority, STOP file at <work>/edge_search_v2/STOP.
"""

from __future__ import annotations

import argparse
import datetime as dt
import os
import sys
import time
import traceback
from pathlib import Path

from . import analysis, pipeline

WORK = Path(os.environ.get("AUTOFINX_V4_WORK", r"D:\autofinx-ml-v4"))


def main(argv=None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("cmd", choices=["pipeline", "analyze", "all"])
    ap.add_argument("--max-day", default=pipeline.PILOT_END.isoformat())
    ap.add_argument("--flow-start", default="2025-10")
    ap.add_argument("--depth-start", default="2025-10-01")
    a = ap.parse_args(argv)
    max_day = dt.date.fromisoformat(a.max_day)
    pipeline.assert_allowed(max_day, max_day)                       # refuses anything after the seal
    pipeline.low_priority()
    edge = WORK / "edge_search_v2"
    edge.mkdir(parents=True, exist_ok=True)
    t0 = time.time()
    try:
        if a.cmd in ("pipeline", "all"):
            s = pipeline.run_pipeline(WORK, analysis.SYMBOLS, a.flow_start, f"{max_day.year:04d}-{max_day.month:02d}",
                                      dt.date.fromisoformat(a.depth_start), max_day, max_day)
            pipeline.log(WORK, f"pipeline done in {s['elapsed_s']} s: {len(s['flow'])} flow months, {s['depth_days']} depth days")
        if a.cmd in ("analyze", "all"):
            r = analysis.run_analysis(WORK, max_day)
            pipeline.log(WORK, f"analysis done: {r['summary']}")
    except pipeline.GuardStop as e:
        pipeline.log(WORK, f"STOPPED by guard: {e} (resumable)")
        return 3
    except Exception:
        pipeline.log(WORK, "FAILED:\n" + traceback.format_exc())
        return 1
    pipeline.log(WORK, f"total {time.time() - t0:.0f} s")
    return 0


if __name__ == "__main__":
    sys.exit(main())
