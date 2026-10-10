"""Daily integrity report (JSON + MD) per UTC day."""
from __future__ import annotations

import json
import os
import time
from pathlib import Path

from .integrity import day_of, utc_iso


def _gap_lines(cfg, day):
    out = []
    try:
        with open(cfg.gap_log_path, "r", encoding="utf-8") as f:
            for line in f:
                if line.startswith(f'{{"ts_utc":"{day}'):
                    try:
                        out.append(json.loads(line))
                    except ValueError:
                        pass
    except OSError:
        pass
    return out


def _files(cfg, day):
    ymd = day.replace("-", "")
    res = {}
    if cfg.raw_dir.exists():
        for kind_dir in sorted(p for p in cfg.raw_dir.iterdir() if p.is_dir()):
            d = kind_dir / ymd
            fs = list(d.glob("*.jsonl.gz")) if d.exists() else []
            res[kind_dir.name] = {"hours_with_data": len(fs), "bytes": sum(f.stat().st_size for f in fs)}
    return res


def build_report(cfg, day, daily=None, now_ns=None):
    now_ns = time.time_ns() if now_ns is None else now_ns
    if daily is not None and day in daily.days:
        st = daily.days[day]
    else:
        try:
            st = json.loads((cfg.stats_dir / f"{day}.json").read_text(encoding="utf-8"))
        except (OSError, ValueError):
            st = {"counts": {}, "missing_ids": {}, "dup_or_ooo": {}, "gaps": {}, "down_s": {}, "reconnects": {},
                  "clock": {"n": 0}, "dropped_disk": 0, "starts": 0}
    counts = st.get("counts", {})
    streams = {k: {"count": counts.get(k, 0), "status": "OK" if counts.get(k, 0) > 0 else "NO_DATA"} for k in cfg.expected_keys()}
    for k, v in counts.items():
        streams.setdefault(k, {"count": v, "status": "OK"})
    gaps = _gap_lines(cfg, day)
    clk = dict(st.get("clock", {}))
    if clk.get("n"):
        clk["mean_offset_ms"] = round(clk["sum"] / clk["n"], 3)
    files = _files(cfg, day)
    problems = []
    for k, v in streams.items():
        if v["status"] == "NO_DATA":
            problems.append(f"no data for {k}")
    if sum(st.get("missing_ids", {}).values()):
        problems.append(f"aggTrade ids missing: {st['missing_ids']}")
    if st.get("dropped_disk"):
        problems.append(f"disk guard dropped {st['dropped_disk']} records")
    if clk.get("max") is not None and max(abs(clk["max"]), abs(clk["min"])) > 1000:
        problems.append("clock offset above 1000 ms seen")
    silent = st.get("gaps", {}).get("silent_stream", 0)
    if silent:
        problems.append(f"{silent} silent-stream alarms")
    rep = {
        "day": day, "complete": day < day_of(now_ns), "generated_utc": utc_iso(now_ns),
        "starts": st.get("starts", 0), "streams": streams, "agg_missing_ids": st.get("missing_ids", {}),
        "agg_dup_or_out_of_order": st.get("dup_or_ooo", {}), "gap_events": st.get("gaps", {}),
        "ws_down_seconds": st.get("down_s", {}), "ws_reconnects": st.get("reconnects", {}),
        "clock": clk, "dropped_disk_guard": st.get("dropped_disk", 0), "files": files,
        "problems": problems, "gap_log_lines": len(gaps), "gap_log_sample": gaps[:50],
        "note": "Pilot data. This report states data quality only; it makes no statement about trading edge.",
    }
    return rep


def render_md(r):
    L = [f"# Collector integrity report {r['day']}", "",
         f"Generated {r['generated_utc']} - complete day: {r['complete']} - process starts that day: {r['starts']}", "",
         "## Problems", ""]
    L += [f"- {p}" for p in r["problems"]] or ["- none detected"]
    L += ["", "## Streams (records received/written)", "", "| stream | count | status |", "|---|---:|---|"]
    L += [f"| {k} | {v['count']:,} | {v['status']} |" for k, v in r["streams"].items()]
    L += ["", "## Gaps and connections", "",
          f"- gap events by kind: {r['gap_events'] or 'none'}",
          f"- websocket down seconds: {r['ws_down_seconds'] or 'none'}",
          f"- aggTrade missing ids: {r['agg_missing_ids'] or 'none'}; dup/out-of-order: {r['agg_dup_or_out_of_order'] or 'none'}",
          f"- clock: {r['clock']}", f"- records dropped by disk guard: {r['dropped_disk_guard']}", "",
          "## Files", "", "| kind | hours with data | bytes |", "|---|---:|---:|"]
    L += [f"| {k} | {v['hours_with_data']} | {v['bytes']:,} |" for k, v in r["files"].items()]
    L += ["", f"_{r['note']}_", ""]
    return "\n".join(L)


def write_report(cfg, day, daily=None, now_ns=None):
    rep = build_report(cfg, day, daily, now_ns)
    cfg.reports_dir.mkdir(parents=True, exist_ok=True)
    for ext, text in (("json", json.dumps(rep, indent=1)), ("md", render_md(rep))):
        p = cfg.reports_dir / f"{day}.{ext}"
        tmp = p.with_suffix(p.suffix + ".tmp")
        tmp.write_text(text, encoding="utf-8")
        os.replace(tmp, p)
    return rep


def missing_reports(cfg, today):
    """Past days that have stats but no complete report yet."""
    out = []
    if cfg.stats_dir.exists():
        for p in sorted(cfg.stats_dir.glob("????-??-??.json")):
            day = p.stem
            if day >= today:
                continue
            rp = cfg.reports_dir / f"{day}.json"
            try:
                done = json.loads(rp.read_text(encoding="utf-8")).get("complete", False)
            except (OSError, ValueError):
                done = False
            if not done:
                out.append(day)
    return out
