"""The collector process. Public Binance USD-M futures streams only; no API keys, no orders."""
from __future__ import annotations

import asyncio
import json
import logging
import os
import shutil
import time
import urllib.request

from . import __version__
from .bookagg import Book1sAggregator
from .config import Config
from .integrity import DailyStats, GapLog, LivenessMonitor, SeqChecker, StreamStats, day_of, utc_iso
from .osutil import install_stop_signals, pid_alive, set_low_priority
from .parse import parse_frame, stream_key
from .report import missing_reports, write_report
from .writer import WriterSet

log = logging.getLogger("collector")


def default_fetch_json(url, timeout=10):
    req = urllib.request.Request(url, headers={"User-Agent": "autofinx-collector/" + __version__})
    with urllib.request.urlopen(req, timeout=timeout) as r:  # public endpoint, no auth
        return json.loads(r.read())


def read_heartbeat(cfg):
    try:
        return json.loads(cfg.heartbeat_path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None


class Collector:
    def __init__(self, cfg: Config, fetch_json=None, free_fn=None):
        self.cfg = cfg
        self.fetch_json = fetch_json or default_fetch_json
        self.free_fn = free_fn or (lambda: shutil.disk_usage(cfg.data_dir).free)
        cfg.data_dir.mkdir(parents=True, exist_ok=True)
        self.daily = DailyStats(cfg.stats_dir)
        self.gaps = GapLog(cfg.gap_log_path, self.daily)
        self.writers = WriterSet(cfg.raw_dir, cfg.gzip_level)
        self.stats = StreamStats(cfg.expected_keys())
        self.seq = SeqChecker()
        self.book = Book1sAggregator()
        self.start_ns = time.time_ns()
        keymap = {}
        for s in cfg.symbols_all:
            keymap[f"aggTrade@{s}"] = ("aggTrade", "market")
            keymap[f"markPrice@{s}"] = ("markPrice", "market")
            keymap[f"openInterest@{s}"] = ("openInterest", "rest")
        keymap["forceOrder"] = ("forceOrder", "market")
        for s in cfg.symbols_book:
            keymap[f"bookTicker@{s}"] = ("bookTicker", "public")
            keymap[f"depth@{s}"] = ("depth", "public")
        self.liveness = LivenessMonitor(keymap, cfg.liveness_s, self.stats, self.start_ns)
        self.conns = {n: {"connected": False, "force": False, "reconnects": 0, "down_from": None, "since": None}
                      for n in ("market", "public")}
        self.last_clock = None
        self.state = "starting"
        self.stop = None  # asyncio.Event, created in run
        self.stop_reason = None
        self.signal_stop = False  # set from a signal handler (plain flag: polled by housekeeping every 0.25 s)

    def request_stop(self, reason="signal"):
        if not self.signal_stop:
            self.stop_reason = reason
        self.signal_stop = True

    # ---------- message path (sync, testable) ----------
    def _w(self, kind, rec, ns):
        if not self.writers.write(kind, rec, ns):
            self.daily.add_scalar("dropped_disk", ns)

    def _write_book(self, r):
        self._w("book1s", {"rt": r["rt"], "et": r["et"], "s": r["sym"], "d": r["d"]}, r["rt"])
        k = f"book1s@{r['sym']}"
        self.stats.hit(k, r["rt"], r["et"])
        self.daily.count(k, r["rt"])

    def handle_frame(self, raw, recv_ns):
        p = parse_frame(raw)
        if p is None:
            return
        kind, sym, ev, data = p
        key = stream_key(kind, sym)
        self.stats.hit(key, recv_ns, ev)
        self.daily.count(key, recv_ns)
        if kind == "bookTicker":
            rec = self.book.add(sym, data, recv_ns)
            if rec:
                self._write_book(rec)
            return
        if kind == "aggTrade":
            g = self.seq.feed(sym, data["a"])
            if g:
                self._seq_gap(g, recv_ns)
        self._w(kind, {"rt": recv_ns, "et": ev, "s": sym, "d": data}, recv_ns)

    def _seq_gap(self, g, ns):
        k = g.pop("kind")
        self.gaps.log(k, **g)
        self.daily.bump("missing_ids" if k == "missing_agg_ids" else "dup_or_ooo", g["sym"], ns, g["count"])

    # ---------- connections ----------
    def _url(self, name):
        streams = self.cfg.market_streams() if name == "market" else self.cfg.public_streams()
        return f"{self.cfg.ws_base}/{name}/stream?streams=" + "/".join(streams)

    async def _watch_close(self, ws, c):
        while True:
            await asyncio.sleep(0.5)
            if c["force"] or self.stop.is_set():
                await ws.close()
                return

    async def _run_conn(self, name):
        from websockets.asyncio.client import connect
        c = self.conns[name]
        backoff = 1.0
        while not self.stop.is_set():
            reason = "closed"
            connected_at = None
            try:
                async with connect(self._url(name), ping_interval=20, ping_timeout=30, max_size=2 ** 23,
                                   open_timeout=20, close_timeout=3, compression=None) as ws:
                    connected_at = time.time_ns()
                    if c["down_from"] is not None:
                        down = (connected_at - c["down_from"]) / 1e9
                        self.gaps.log("ws_reconnected", conn=name, down_s=round(down, 2))
                        self.daily.bump("down_s", name, connected_at, round(down, 2))
                    c.update(connected=True, force=False, down_from=None, since=connected_at)
                    self.liveness.reset_grace(connected_at, name)
                    log.info("%s connected", name)
                    watcher = asyncio.create_task(self._watch_close(ws, c))
                    try:
                        async for raw in ws:
                            try:
                                self.handle_frame(raw, time.time_ns())
                            except Exception as e:
                                log.warning("%s bad frame: %r", name, e)
                                self.daily.bump("gaps", "parse_error", time.time_ns())
                    finally:
                        watcher.cancel()
                    reason = "forced_reconnect" if c["force"] else ("stop" if self.stop.is_set() else "server_closed")
            except asyncio.CancelledError:
                raise
            except Exception as e:
                reason = repr(e)[:200]
            now = time.time_ns()
            c["connected"] = False
            if self.stop.is_set():
                break
            if c["down_from"] is None:
                c["down_from"] = now
            c["reconnects"] += 1
            self.daily.bump("reconnects", name, now)
            lived = (now - connected_at) / 1e9 if connected_at else 0
            self.gaps.log("ws_disconnect", conn=name, reason=reason, connected_for_s=round(lived, 1))
            log.warning("%s disconnected (%s); retry in %.0fs", name, reason, backoff)
            backoff = 1.0 if lived > 60 else min(backoff * 2, 60.0)
            try:
                await asyncio.wait_for(self.stop.wait(), timeout=backoff)
            except asyncio.TimeoutError:
                pass

    # ---------- REST pollers ----------
    async def _sleep(self, s):
        try:
            await asyncio.wait_for(self.stop.wait(), timeout=max(0.0, s))
        except asyncio.TimeoutError:
            pass

    async def _oi_loop(self):
        loop = asyncio.get_running_loop()
        while not self.stop.is_set():
            t0 = time.monotonic()
            for sym in self.cfg.symbols_all:
                if self.stop.is_set():
                    break
                try:
                    data = await loop.run_in_executor(None, self.fetch_json, f"{self.cfg.rest_base}/fapi/v1/openInterest?symbol={sym.upper()}")
                    ns = time.time_ns()
                    k = f"openInterest@{sym}"
                    self._w("openInterest", {"rt": ns, "et": data.get("time"), "s": sym, "d": data}, ns)
                    self.stats.hit(k, ns, data.get("time"))
                    self.daily.count(k, ns)
                except Exception as e:
                    self.gaps.log("oi_fetch_failed", sym=sym, error=repr(e)[:200])
            await self._sleep(self.cfg.oi_interval_s - (time.monotonic() - t0))

    def measure_clock(self):
        t0 = time.time_ns()
        data = self.fetch_json(f"{self.cfg.rest_base}/fapi/v1/time")
        t1 = time.time_ns()
        rtt_ms = (t1 - t0) / 1e6
        offset_ms = data["serverTime"] - (t0 + t1) / 2e6  # positive: local clock behind exchange
        rec = {"ts_utc": utc_iso(t1), "ts_ns": t1, "server_ms": data["serverTime"],
               "rtt_ms": round(rtt_ms, 2), "offset_ms": round(offset_ms, 2)}
        with open(self.cfg.clock_log_path, "a", encoding="utf-8", newline="\n") as f:
            f.write(json.dumps(rec, separators=(",", ":")) + "\n")
        self.daily.clock(t1, offset_ms, rtt_ms)
        self.last_clock = rec
        if abs(offset_ms) > 1000 or rtt_ms > 2000:
            self.gaps.log("clock_warning", offset_ms=rec["offset_ms"], rtt_ms=rec["rtt_ms"])
        return rec

    async def _clock_loop(self):
        loop = asyncio.get_running_loop()
        while not self.stop.is_set():
            try:
                await loop.run_in_executor(None, self.measure_clock)
            except Exception as e:
                self.gaps.log("clock_check_failed", error=repr(e)[:200])
            await self._sleep(self.cfg.clock_interval_s)

    # ---------- guards ----------
    def check_disk(self):
        free = self.free_fn()
        gb = free / 1e9
        if not self.writers.paused and gb < self.cfg.min_free_gb:
            self.writers.flush()
            self.writers.paused = True
            self.gaps.log("disk_guard_stop", free_gb=round(gb, 2), min_free_gb=self.cfg.min_free_gb,
                          why="free space below minimum; writing stopped to protect the drive")
            log.error("DISK GUARD: free %.2f GB < %s GB, writing stopped", gb, self.cfg.min_free_gb)
        elif self.writers.paused and gb >= self.cfg.min_free_gb + self.cfg.resume_margin_gb:
            self.writers.paused = False
            self.gaps.log("disk_guard_resume", free_gb=round(gb, 2))
            log.warning("disk guard released, writing resumed")
        return gb

    def check_liveness(self, now_ns=None):
        now_ns = now_ns or time.time_ns()
        alarms = self.liveness.check(now_ns)
        for a in alarms:
            self.gaps.log("silent_stream", **a)
            log.error("SILENT STREAM %s for %ss (threshold %ss) conn=%s", a["key"], a["silent_s"], a["threshold_s"], a["conn"])
            if a["conn"] in self.conns:
                self.conns[a["conn"]]["force"] = True  # silent drop: reconnect
        return alarms

    def heartbeat(self, now_ns=None):
        now_ns = now_ns or time.time_ns()
        hb = {
            "pid": os.getpid(), "version": __version__, "state": self.state, "ts_ns": now_ns, "ts_utc": utc_iso(now_ns),
            "started_utc": utc_iso(self.start_ns), "writing": not self.writers.paused,
            "dropped_records": self.writers.dropped,
            "disk_free_gb": round(self.free_fn() / 1e9, 2), "data_dir": str(self.cfg.data_dir),
            "streams": {k: {"count": n, "last_age_s": (None if self.stats.age_s(k, now_ns) is None else round(self.stats.age_s(k, now_ns), 2))}
                        for k, n in self.stats.count.items()},
            "conns": {n: {"connected": c["connected"], "reconnects": c["reconnects"]} for n, c in self.conns.items()},
            "last_gap": self.gaps.tail(), "last_clock": self.last_clock,
        }
        p = self.cfg.heartbeat_path
        tmp = p.with_suffix(".tmp")
        tmp.write_text(json.dumps(hb), encoding="utf-8")
        os.replace(tmp, p)

    def make_reports(self, now_ns=None):
        now_ns = now_ns or time.time_ns()
        today = day_of(now_ns)
        self.daily.save()
        for d in missing_reports(self.cfg, today):
            write_report(self.cfg, d, self.daily, now_ns)
            log.info("daily report written for %s", d)

    # ---------- main loop ----------
    async def _housekeeping(self, deadline):
        cfg = self.cfg
        last = {"flush": 0.0, "live": 0.0, "hb": 0.0, "disk": -1e9, "save": 0.0}
        while not self.stop.is_set():
            m = time.monotonic()
            now = time.time_ns()
            if self.signal_stop:
                self.stop.set()
                break
            if cfg.stop_path.exists():
                self.stop_reason = "STOP file"
                self.stop.set()
                break
            if deadline and m >= deadline:
                self.stop_reason = "duration reached"
                self.stop.set()
                break
            for r in self.book.flush(now):
                self._write_book(r)
            if m - last["flush"] >= cfg.flush_s:
                last["flush"] = m
                self.writers.rotate(now)
                self.writers.flush()
            if m - last["disk"] >= 30:
                last["disk"] = m
                self.check_disk()
            if m - last["live"] >= 5:
                last["live"] = m
                self.check_liveness(now)
            if m - last["hb"] >= cfg.heartbeat_s:
                last["hb"] = m
                self.state = "running"
                self.heartbeat(now)
            if m - last["save"] >= 60:
                last["save"] = m
                try:
                    self.make_reports(now)
                except Exception as e:
                    log.error("report/save failed: %r", e)
            await asyncio.sleep(0.25)

    async def _main(self, duration):
        self.stop = asyncio.Event()
        deadline = time.monotonic() + duration if duration else None
        self.gaps.log("collector_start", pid=os.getpid(), version=__version__)
        self.daily.add_scalar("starts", time.time_ns())
        self.check_disk()
        tasks = [asyncio.create_task(self._run_conn("market")), asyncio.create_task(self._run_conn("public")),
                 asyncio.create_task(self._oi_loop()), asyncio.create_task(self._clock_loop())]
        try:
            await self._housekeeping(deadline)
        finally:
            self.stop.set()
            self.state = "stopping"
            try:
                await asyncio.wait_for(asyncio.gather(*tasks, return_exceptions=True), timeout=15)
            except asyncio.TimeoutError:
                for t in tasks:
                    t.cancel()
            for r in self.book.flush_all():
                self._write_book(r)
            self.writers.close()
            self.gaps.log("collector_stop", reason=self.stop_reason or "interrupted")
            self.state = "stopped"
            try:
                self.make_reports()
                write_report(self.cfg, day_of(time.time_ns()), self.daily)  # partial report for the current day
                self.heartbeat()
            except Exception as e:
                log.error("final save failed: %r", e)
            for p in (self.cfg.stop_path, self.cfg.lock_path):
                try:
                    p.unlink()
                except OSError:
                    pass

    def run(self, duration=None):
        log.info("priority: %s", set_low_priority(self.cfg.process_priority))
        log.info("stop signals handled: %s", ",".join(install_stop_signals(lambda: self.request_stop("signal"))) or "none")
        asyncio.run(self._main(duration))


def acquire_lock(cfg: Config):
    """Refuse to start a second instance. Returns (ok, message)."""
    cfg.data_dir.mkdir(parents=True, exist_ok=True)
    try:
        pid = int(cfg.lock_path.read_text().strip())
    except (OSError, ValueError):
        pid = None
    if pid and pid != os.getpid() and pid_alive(pid):
        hb = read_heartbeat(cfg)
        age = (time.time_ns() - hb["ts_ns"]) / 1e9 if hb else None
        if age is not None and age < 60:
            return False, f"already running (pid {pid}, heartbeat {age:.0f}s old)"
    if cfg.stop_path.exists():
        cfg.stop_path.unlink()  # stale STOP from a previous shutdown; no live instance
    cfg.lock_path.write_text(str(os.getpid()))
    return True, "ok"
