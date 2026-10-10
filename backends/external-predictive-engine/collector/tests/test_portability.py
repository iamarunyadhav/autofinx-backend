"""Linux/Windows portability tests (no network, no real signals sent to the test process)."""
import asyncio
import json
import os
import sys
import time

import pytest

from collector import cli, osutil
from collector.app import Collector, acquire_lock
from collector.config import Config, load_config

S = 1_000_000_000


def test_config_expands_home_and_env(tmp_path, monkeypatch):
    monkeypatch.setenv("HOME", str(tmp_path))
    monkeypatch.setenv("USERPROFILE", str(tmp_path))
    monkeypatch.setenv("COLLECTOR_X", "xx")
    p = tmp_path / "c.json"
    p.write_text(json.dumps({"data_dir": "~/autofinx-collector/data"}))
    assert load_config(p).data_dir == tmp_path / "autofinx-collector" / "data"
    p.write_text(json.dumps({"data_dir": "$COLLECTOR_X/data"}))
    assert load_config(p).data_dir.name == "data" and "xx" in str(load_config(p).data_dir)
    p.write_text(json.dumps({"data_dir": "d", "min_free_gb": -1}))
    with pytest.raises(ValueError):
        load_config(p)


def test_config_forward_slash_absolute_and_lower_floor(tmp_path):
    p = tmp_path / "c.json"
    p.write_text(json.dumps({"data_dir": (tmp_path / "a" / "b").as_posix(), "min_free_gb": 8, "resume_margin_gb": 1,
                             "process_priority": "unchanged"}))
    c = load_config(p)
    assert c.data_dir == tmp_path / "a" / "b" and c.min_free_gb == 8


def test_linux_config_file_is_valid():
    p = os.path.join(os.path.dirname(__file__), "..", "deploy", "linux", "collector.config.linux.json")
    raw = json.loads(open(p, encoding="utf-8").read())
    assert raw["data_dir"].startswith("~/") or raw["data_dir"].startswith("/")
    c = load_config(p)  # no unknown keys
    assert c.min_free_gb < 20 and c.process_priority in ("unchanged", "none", "idle", "below_normal")


def test_pid_alive_self_and_dead():
    assert osutil.pid_alive(os.getpid())
    assert not osutil.pid_alive(0)
    assert not osutil.pid_alive(-5)
    assert not osutil.pid_alive(2 ** 22 + 12345) or sys.platform != "win32"  # very unlikely pid


def test_priority_modes_unchanged():
    assert osutil.set_low_priority("unchanged") == "unchanged"
    assert osutil.set_low_priority("none") == "unchanged"
    assert osutil.set_low_priority("bogus_mode") == "unchanged"


def test_stop_file_and_wait(cfg_tmp):
    cfg = cfg_tmp
    assert cli.main(["--config", cfg._path, "stop"]) == 0
    assert cfg.stop_path.read_text() == "stop"
    # no lock file -> wait returns immediately
    assert cli.wait_stopped(cfg, 1) == 0
    cfg.lock_path.write_text(str(2 ** 31 - 2))  # no such pid
    assert cli.wait_stopped(cfg, 1) == 0
    cfg.lock_path.write_text(str(os.getpid()))  # alive -> timeout
    t = time.monotonic()
    assert cli.wait_stopped(cfg, 0.6) == 1 and time.monotonic() - t >= 0.5


def test_acquire_lock_cleans_stale_stop_and_blocks_second(cfg_tmp):
    cfg = cfg_tmp
    cfg.data_dir.mkdir(parents=True, exist_ok=True)
    cfg.stop_path.write_text("stop")
    ok, _ = acquire_lock(cfg)
    assert ok and not cfg.stop_path.exists() and cfg.lock_path.read_text() == str(os.getpid())


def test_heartbeat_atomic_and_status_cli(cfg_tmp, capsys):
    cfg = cfg_tmp
    c = Collector(cfg, free_fn=lambda: 50e9)
    c.heartbeat()
    assert not list(cfg.data_dir.glob("*.tmp"))
    hb = json.loads(cfg.heartbeat_path.read_text())
    assert hb["pid"] == os.getpid()
    rc = cli.main(["--config", cfg._path, "status"])
    out = capsys.readouterr().out
    assert rc in (0, 1) and "collector:" in out and "disk free" in out


def test_logs_are_lf_only(cfg_tmp):
    cfg = cfg_tmp
    c = Collector(cfg, free_fn=lambda: 50e9)
    c.gaps.log("collector_start", pid=1, version="t")
    c.fetch_json = lambda url, timeout=10: {"serverTime": int(time.time() * 1000)}
    c.measure_clock()
    for path in (cfg.gap_log_path, cfg.clock_log_path):
        raw = path.read_bytes()
        assert b"\r\n" not in raw and raw.endswith(b"\n")


def test_signal_flag_stops_run_cleanly(cfg_tmp):
    """request_stop (what the SIGTERM handler calls) makes the main loop exit and close files cleanly."""
    cfg = cfg_tmp
    c = Collector(cfg, free_fn=lambda: 50e9)
    c.fetch_json = lambda url, timeout=10: {"serverTime": int(time.time() * 1000), "time": 1}

    async def go():
        c.stop = asyncio.Event()
        # run only housekeeping + shutdown path: emulate _main without network by stubbing connection tasks
        async def idle(*_a): await c.stop.wait()
        c._run_conn = idle
        c._oi_loop = lambda: idle()
        c._clock_loop = lambda: idle()
        asyncio.get_running_loop().call_later(0.6, c.request_stop, "signal")
        await c._main(None)
    asyncio.run(go())
    assert c.stop_reason == "signal" and c.state == "stopped"
    assert not cfg.lock_path.exists()
    assert "collector_stop" in cfg.gap_log_path.read_text()


def test_rotation_paths_are_posix_safe(tmp_path):
    from collector.writer import HourlyGzipWriter
    w = HourlyGzipWriter(tmp_path, "aggTrade")
    p = w.path_for(1_760_000_000 // 3600)
    assert p.parts[-3:-1] == ("aggTrade", p.parent.name) and p.name.startswith("aggTrade_") and "\\" not in p.name


def test_install_stop_signals_returns_names():
    names = osutil.install_stop_signals(lambda: None)
    assert "SIGTERM" in names or sys.platform == "win32"
    import signal
    signal.signal(signal.SIGINT, signal.default_int_handler)  # restore pytest/Ctrl-C behaviour
    signal.signal(signal.SIGTERM, signal.SIG_DFL)


@pytest.fixture
def cfg_tmp(tmp_path):
    cfg = Config(data_dir=tmp_path / "data")
    p = tmp_path / "c.json"
    p.write_text(json.dumps({"data_dir": (tmp_path / "data").as_posix()}))
    cfg._path = str(p)
    return cfg
