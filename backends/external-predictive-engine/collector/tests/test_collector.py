import gzip
import json
import time

import pytest

from collector.app import Collector
from collector.bookagg import Book1sAggregator
from collector.config import Config
from collector.integrity import DailyStats, GapLog, LivenessMonitor, SeqChecker, StreamStats
from collector.parse import parse_frame, stream_key
from collector.report import build_report, missing_reports, write_report
from collector.writer import HourlyGzipWriter, WriterSet

S = 1_000_000_000
T0 = 1_760_000_000 * S  # a fixed instant; not on an hour boundary on purpose
T0 = T0 - (T0 % (3600 * S)) + 10 * S  # 10 s into an hour


def frame(stream, data):
    return json.dumps({"stream": stream, "data": data})


def agg(sym, a, E=1000):
    return frame(f"{sym}@aggTrade", {"e": "aggTrade", "E": E, "s": sym.upper(), "a": a, "p": "1", "q": "1", "f": a, "l": a, "T": E, "m": False})


def bt(sym, b, a, E=1):
    return {"e": "bookTicker", "u": 1, "E": E, "T": E, "s": sym.upper(), "b": b, "B": "1.5", "a": a, "A": "2.5"}


def read_gz(path):
    with gzip.open(path, "rt") as f:
        return [json.loads(x) for x in f]


@pytest.fixture
def cfg(tmp_path):
    return Config(data_dir=tmp_path / "data")


# ---------- parsing ----------
def test_parse_kinds():
    assert parse_frame(agg("btcusdt", 5))[:3] == ("aggTrade", "btcusdt", 1000)
    k, s, ev, d = parse_frame(frame("ethusdt@markPrice@1s", {"e": "markPriceUpdate", "E": 7, "s": "ETHUSDT", "p": "1"}))
    assert (k, s, ev) == ("markPrice", "ethusdt", 7)
    k, s, ev, d = parse_frame(frame("btcusdt@depth20@500ms", {"e": "depthUpdate", "E": 9, "s": "BTCUSDT", "b": [], "a": []}))
    assert (k, s) == ("depth", "btcusdt")
    k, s, ev, d = parse_frame(frame("!forceOrder@arr", {"e": "forceOrder", "E": 11, "o": {"s": "SOLUSDT", "T": 10}}))
    assert (k, s, ev) == ("forceOrder", "solusdt", 11)
    assert stream_key("forceOrder", "solusdt") == "forceOrder"
    assert parse_frame('{"result":null,"id":1}') is None


# ---------- rotation ----------
def test_hourly_rotation_and_append_members(tmp_path):
    w = HourlyGzipWriter(tmp_path, "aggTrade")
    w.write(b'{"i":1}\n', T0)
    w.write(b'{"i":2}\n', T0 + 3600 * S)  # next hour -> new file
    w.close()
    p1, p2 = w.path_for(T0 // (3600 * S)), w.path_for(T0 // (3600 * S) + 1)
    assert p1.exists() and p2.exists() and p1 != p2
    assert read_gz(p1) == [{"i": 1}] and read_gz(p2) == [{"i": 2}]
    # restart in the same hour appends a second gzip member, still one readable file
    w2 = HourlyGzipWriter(tmp_path, "aggTrade")
    w2.write(b'{"i":3}\n', T0 + S)
    w2.close()
    assert read_gz(p1) == [{"i": 1}, {"i": 3}]


def test_flush_makes_open_hour_readable_after_crash(tmp_path):
    w = HourlyGzipWriter(tmp_path, "x")
    w.write(b'{"i":1}\n', T0)
    w.flush()  # simulate periodic sync flush, then crash (never closed)
    raw = w.path.read_bytes()
    import zlib
    d = zlib.decompressobj(31)
    assert b'{"i":1}' in d.decompress(raw)
    w.close()


def test_rotate_if_stale_closes_idle_hour(tmp_path):
    ws = WriterSet(tmp_path)
    ws.write("forceOrder", {"a": 1}, T0)
    assert ws.writers["forceOrder"]._gz is not None
    ws.rotate(T0 + 3600 * S)
    assert ws.writers["forceOrder"]._gz is None


# ---------- 1 s aggregation ----------
def test_book_1s_aggregation():
    a = Book1sAggregator()
    base = T0
    assert a.add("btcusdt", bt("btcusdt", "100.0", "100.1", 1), base + 100_000_000) is None
    assert a.add("btcusdt", bt("btcusdt", "100.0", "100.3", 2), base + 400_000_000) is None
    assert a.add("btcusdt", bt("btcusdt", "100.2", "100.3", 3), base + 900_000_000) is None
    rec = a.add("btcusdt", bt("btcusdt", "101", "101.1", 4), base + 1_100_000_000)  # next second closes the first
    d = rec["d"]
    assert d["n"] == 3 and d["bid"] == "100.2" and d["ask"] == "100.3" and d["bsz"] == "1.5" and d["asz"] == "2.5"
    assert d["spr_min"] == pytest.approx(0.1) and d["spr_max"] == pytest.approx(0.3)
    assert rec["et"] == 3
    # tick flush emits the still-open second only after it is over
    assert a.flush(base + 1_500_000_000) == []
    out = a.flush(base + 2_000_000_000)
    assert len(out) == 1 and out[0]["d"]["n"] == 1


# ---------- gaps / sequence ----------
def test_aggtrade_sequence_detection():
    s = SeqChecker()
    assert s.feed("btcusdt", 10) is None and s.feed("btcusdt", 11) is None
    g = s.feed("btcusdt", 15)
    assert g == {"kind": "missing_agg_ids", "sym": "btcusdt", "from_id": 12, "to_id": 14, "count": 3}
    assert s.feed("btcusdt", 16) is None
    assert s.feed("btcusdt", 16)["kind"] == "dup_or_out_of_order"
    assert s.feed("ethusdt", 1) is None  # symbols independent


def test_collector_logs_missing_ids_and_writes(cfg):
    c = Collector(cfg, free_fn=lambda: 90e9)
    for i, a in enumerate([1, 2, 5]):
        c.handle_frame(agg("btcusdt", a), T0 + i * S)
    c.writers.close()
    gaps = [json.loads(x) for x in cfg.gap_log_path.read_text().splitlines()]
    assert gaps[0]["kind"] == "missing_agg_ids" and gaps[0]["count"] == 2
    assert c.daily.days[next(iter(c.daily.days))]["missing_ids"] == {"btcusdt": 2}
    files = list(cfg.raw_dir.rglob("*.jsonl.gz"))
    assert len(files) == 1
    recs = read_gz(files[0])
    assert [r["d"]["a"] for r in recs] == [1, 2, 5] and recs[0]["rt"] == T0 and recs[0]["et"] == 1000


def test_book_not_stored_raw(cfg):
    c = Collector(cfg, free_fn=lambda: 90e9)
    c.handle_frame(frame("btcusdt@bookTicker", bt("btcusdt", "1", "2")), T0)
    c.handle_frame(frame("btcusdt@bookTicker", bt("btcusdt", "1", "2")), T0 + S + 1)
    c.writers.close()
    kinds = {p.parent.parent.name for p in cfg.raw_dir.rglob("*.jsonl.gz")}
    assert kinds == {"book1s"}
    assert c.stats.count["bookTicker@btcusdt"] == 2 and c.stats.count["book1s@btcusdt"] == 1


# ---------- liveness ----------
def test_liveness_detects_silent_stream_and_rearms():
    st = StreamStats(["aggTrade@btcusdt", "markPrice@btcusdt"])
    keys = {"aggTrade@btcusdt": ("aggTrade", "market"), "markPrice@btcusdt": ("markPrice", "market")}
    m = LivenessMonitor(keys, {"aggTrade": 180, "markPrice": 15}, st, T0)
    st.hit("aggTrade@btcusdt", T0 + 1 * S)
    st.hit("markPrice@btcusdt", T0 + 1 * S)
    assert m.check(T0 + 10 * S) == []
    # markPrice never again; aggTrade keeps flowing (the silent-drop case: one stream dead, connection alive)
    st.hit("aggTrade@btcusdt", T0 + 20 * S)
    al = m.check(T0 + 30 * S)
    assert [a["key"] for a in al] == ["markPrice@btcusdt"] and al[0]["conn"] == "market"
    assert m.check(T0 + 35 * S) == []  # no alarm spam inside the same period
    assert [a["key"] for a in m.check(T0 + 50 * S)] == ["markPrice@btcusdt"]  # re-alarm after a full threshold
    m.reset_grace(T0 + 51 * S, "market")  # reconnected -> fresh chance
    assert m.check(T0 + 55 * S) == []


def test_liveness_never_seen_stream_alarms():
    st = StreamStats(["markPrice@suiusdt"])
    m = LivenessMonitor({"markPrice@suiusdt": ("markPrice", "market")}, {"markPrice": 15}, st, T0)
    al = m.check(T0 + 20 * S)
    assert al and al[0]["never_seen"] is True


def test_collector_forces_reconnect_on_silence(cfg):
    c = Collector(cfg, free_fn=lambda: 90e9)
    c.liveness.start_ns = T0
    c.liveness._grace.clear()
    al = c.check_liveness(T0 + 100 * S)
    assert any(a["key"] == "markPrice@btcusdt" for a in al)
    assert c.conns["market"]["force"] is True and c.conns["public"]["force"] is True
    assert "silent_stream" in cfg.gap_log_path.read_text()


# ---------- disk guard ----------
def test_disk_guard_stops_and_resumes(cfg):
    free = {"v": 50e9}
    c = Collector(cfg, free_fn=lambda: free["v"])
    c.check_disk()
    assert not c.writers.paused
    free["v"] = 19e9
    c.check_disk()
    assert c.writers.paused
    assert c.writers.write("aggTrade", {"x": 1}, T0) is False and c.writers.dropped == 1
    free["v"] = 21e9  # below min + margin: stays paused (hysteresis)
    c.check_disk()
    assert c.writers.paused
    free["v"] = 23e9
    c.check_disk()
    assert not c.writers.paused
    text = cfg.gap_log_path.read_text()
    assert "disk_guard_stop" in text and "below minimum" in text and "disk_guard_resume" in text


# ---------- clock / heartbeat / report ----------
def test_clock_offset_and_heartbeat(cfg):
    now_ms = time.time() * 1000
    c = Collector(cfg, fetch_json=lambda url: {"serverTime": int(now_ms + 250)}, free_fn=lambda: 90e9)
    rec = c.measure_clock()
    assert rec["rtt_ms"] >= 0 and 150 < rec["offset_ms"] < 350
    assert cfg.clock_log_path.exists()
    c.heartbeat()
    hb = json.loads(cfg.heartbeat_path.read_text())
    assert hb["streams"]["aggTrade@btcusdt"]["count"] == 0 and hb["writing"] is True and hb["last_clock"]


def test_daily_report_json_md(cfg):
    c = Collector(cfg, free_fn=lambda: 90e9)
    c.handle_frame(agg("btcusdt", 1), T0)
    c.handle_frame(agg("btcusdt", 3), T0 + S)
    c.writers.close()
    c.daily.save()
    day = time.strftime("%Y-%m-%d", time.gmtime(T0 // S))
    rep = write_report(cfg, day, c.daily, now_ns=T0 + 86400 * 2 * S)
    assert rep["complete"] is True
    assert rep["streams"]["aggTrade@btcusdt"]["count"] == 2
    assert rep["streams"]["markPrice@ethusdt"]["status"] == "NO_DATA"
    assert rep["agg_missing_ids"] == {"btcusdt": 1}
    assert (cfg.reports_dir / f"{day}.md").exists() and (cfg.reports_dir / f"{day}.json").exists()
    assert missing_reports(cfg, time.strftime("%Y-%m-%d", time.gmtime(T0 // S + 86400))) == []  # already complete
    assert build_report(cfg, day)["files"]["aggTrade"]["hours_with_data"] == 1


def test_config_loads_relative_and_unknown_key(tmp_path):
    from collector.config import load_config
    p = tmp_path / "c.json"
    p.write_text(json.dumps({"data_dir": "d"}))
    assert load_config(p).data_dir == (tmp_path / "d").resolve()
    p.write_text(json.dumps({"data_dir": "d", "bogus": 1}))
    with pytest.raises(ValueError):
        load_config(p)
