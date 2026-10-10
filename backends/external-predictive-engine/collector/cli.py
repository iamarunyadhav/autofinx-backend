"""python -m collector {start|status|stop|report}"""
from __future__ import annotations

import argparse
import logging
import logging.handlers
import sys
import time

from .app import Collector, acquire_lock, read_heartbeat
from .config import load_config
from .integrity import day_of
from .osutil import pid_alive
from .report import write_report


def _setup_logging(cfg, console=True):
    cfg.logs_dir.mkdir(parents=True, exist_ok=True)
    root = logging.getLogger()
    root.setLevel(logging.INFO)
    fmt = logging.Formatter("%(asctime)sZ %(levelname)s %(message)s")
    fmt.converter = time.gmtime
    fh = logging.handlers.RotatingFileHandler(cfg.logs_dir / "collector.log", maxBytes=5_000_000, backupCount=5, encoding="utf-8")
    fh.setFormatter(fmt)
    root.addHandler(fh)
    if console and sys.stderr is not None:
        sh = logging.StreamHandler()
        sh.setFormatter(fmt)
        root.addHandler(sh)


def cmd_status(cfg):
    hb = read_heartbeat(cfg)
    if not hb:
        print("collector: NOT RUNNING (no heartbeat file)")
        return 1
    now = time.time_ns()
    age = (now - hb["ts_ns"]) / 1e9
    alive = pid_alive(hb["pid"])
    running = alive and age < 3 * cfg.heartbeat_s + 5 and hb["state"] != "stopped"
    print(f"collector: {'RUNNING' if running else 'NOT RUNNING'}  pid={hb['pid']} process_alive={alive} state={hb['state']}")
    print(f"heartbeat age: {age:.1f}s   started: {hb['started_utc']}   writing: {hb['writing']}   dropped: {hb['dropped_records']}")
    print(f"disk free: {hb['disk_free_gb']} GB   data: {hb['data_dir']}")
    print("connections: " + ", ".join(f"{n}={'up' if c['connected'] else 'DOWN'} (reconnects {c['reconnects']})" for n, c in hb["conns"].items()))
    print("streams (count since process start, age of last message):")
    for k, v in hb["streams"].items():
        a = v["last_age_s"]
        print(f"  {k:<24}{v['count']:>12,}   last {'never' if a is None else f'{a:.1f}s ago'}")
    g = hb.get("last_gap")
    print("last gap-log entry: " + (f"{g['ts_utc']} {g['kind']} " + str({k: v for k, v in g.items() if k not in ('ts_utc', 'ts_ns', 'kind')}) if g else "none"))
    c = hb.get("last_clock")
    if c:
        print(f"last clock check: offset {c['offset_ms']} ms, rtt {c['rtt_ms']} ms at {c['ts_utc']}")
    return 0 if running else 1


def wait_stopped(cfg, timeout_s):
    """Wait until the process named in the lock file is gone (used by systemd ExecStop). 0 = stopped, 1 = timeout."""
    t_end = time.monotonic() + timeout_s
    while time.monotonic() < t_end:
        try:
            pid = int(cfg.lock_path.read_text().strip())
        except (OSError, ValueError):
            return 0  # lock removed by the collector on clean exit
        if not pid_alive(pid):
            return 0
        time.sleep(0.5)
    print("collector still running after wait")
    return 1


def main(argv=None):
    ap = argparse.ArgumentParser(prog="collector")
    ap.add_argument("--config", help="path to collector.config.json (or env AUTOFINX_COLLECTOR_CONFIG)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("start", help="run in the foreground (use start-collector.ps1 for a detached run)")
    s.add_argument("--duration", type=float, help="stop cleanly after N seconds (smoke tests)")
    sub.add_parser("status")
    st = sub.add_parser("stop", help="create the STOP file; the collector closes files and exits within a few seconds")
    st.add_argument("--wait", type=float, default=0, help="wait up to N seconds until the collector process has exited")
    r = sub.add_parser("report")
    r.add_argument("--day", help="UTC day YYYY-MM-DD (default: today, partial)")
    a = ap.parse_args(argv)
    cfg = load_config(a.config)

    if a.cmd == "status":
        return cmd_status(cfg)
    if a.cmd == "stop":
        cfg.data_dir.mkdir(parents=True, exist_ok=True)
        cfg.stop_path.write_text("stop")
        print(f"STOP file written: {cfg.stop_path}")
        if a.wait > 0:
            return wait_stopped(cfg, a.wait)
        return 0
    if a.cmd == "report":
        day = a.day or day_of(time.time_ns())
        rep = write_report(cfg, day)
        print(f"report written: {cfg.reports_dir / (day + '.md')}  problems: {rep['problems'] or 'none'}")
        return 0
    ok, msg = acquire_lock(cfg)
    if not ok:
        print(msg)
        return 2
    _setup_logging(cfg)
    c = Collector(cfg)
    try:
        c.run(a.duration)
    except KeyboardInterrupt:
        pass
    return 0
