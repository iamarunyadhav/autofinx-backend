"""Config loading. No hardcoded paths: everything comes from a JSON file (relative paths are
resolved against the config file's folder)."""
from __future__ import annotations

import json
import os
from dataclasses import dataclass, field
from pathlib import Path

DEFAULT_CONFIG = Path(__file__).with_name("collector.config.json")
ENV_VAR = "AUTOFINX_COLLECTOR_CONFIG"


@dataclass
class Config:
    data_dir: Path
    ws_base: str = "wss://fstream.binance.com"
    rest_base: str = "https://fapi.binance.com"
    symbols_all: list = field(default_factory=lambda: ["btcusdt", "ethusdt", "bnbusdt", "solusdt", "xrpusdt", "suiusdt"])
    symbols_book: list = field(default_factory=lambda: ["btcusdt", "ethusdt"])
    oi_interval_s: float = 60
    clock_interval_s: float = 300
    heartbeat_s: float = 10
    flush_s: float = 5
    gzip_level: int = 5
    min_free_gb: float = 20
    resume_margin_gb: float = 2
    liveness_s: dict = field(default_factory=lambda: {
        "aggTrade": 180, "markPrice": 15, "bookTicker": 15, "depth": 15, "forceOrder": 1800, "openInterest": 180})
    process_priority: str = "below_normal"

    @property
    def raw_dir(self): return self.data_dir / "raw"
    @property
    def reports_dir(self): return self.data_dir / "reports"
    @property
    def stats_dir(self): return self.data_dir / "stats"
    @property
    def logs_dir(self): return self.data_dir / "logs"
    @property
    def heartbeat_path(self): return self.data_dir / "heartbeat.json"
    @property
    def gap_log_path(self): return self.data_dir / "gaps.jsonl"
    @property
    def clock_log_path(self): return self.data_dir / "clock.jsonl"
    @property
    def stop_path(self): return self.data_dir / "STOP"
    @property
    def lock_path(self): return self.data_dir / "collector.lock"

    def market_streams(self):
        s = [f"{x}@aggTrade" for x in self.symbols_all]
        s += [f"{x}@markPrice@1s" for x in self.symbols_all]
        s.append("!forceOrder@arr")
        return s

    def public_streams(self):
        s = [f"{x}@bookTicker" for x in self.symbols_book]
        s += [f"{x}@depth20@500ms" for x in self.symbols_book]
        return s

    def expected_keys(self):
        k = [f"aggTrade@{x}" for x in self.symbols_all] + [f"markPrice@{x}" for x in self.symbols_all]
        k += ["forceOrder"]
        k += [f"bookTicker@{x}" for x in self.symbols_book] + [f"depth@{x}" for x in self.symbols_book]
        k += [f"book1s@{x}" for x in self.symbols_book]
        k += [f"openInterest@{x}" for x in self.symbols_all]
        return k


def load_config(path=None) -> Config:
    p = Path(path or os.environ.get(ENV_VAR) or DEFAULT_CONFIG)
    raw = json.loads(p.read_text(encoding="utf-8"))
    dd = Path(os.path.expandvars(os.path.expanduser(str(raw.pop("data_dir")))))  # ~ and $VARS work on Linux and Windows
    if not dd.is_absolute():
        dd = (p.parent / dd).resolve()
    unknown = set(raw) - set(Config.__dataclass_fields__)
    if unknown:
        raise ValueError(f"unknown config keys: {sorted(unknown)}")
    cfg = Config(data_dir=dd, **raw)
    if cfg.min_free_gb < 0 or cfg.resume_margin_gb < 0:
        raise ValueError("min_free_gb and resume_margin_gb must be >= 0")
    return cfg
