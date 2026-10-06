"""P1 data layer tests: parsing, checksum, resume, gaps, 1m/5m alignment, funding dedupe, STOP."""

import datetime as dt
import hashlib
import io
import sys
import zipfile
from pathlib import Path

import numpy as np
import pytest

sys.path.insert(0, str(Path(__file__).parents[2]))
pytest.importorskip("pyarrow")

from v4 import binance_data as bd  # noqa: E402
from v4 import download as dl  # noqa: E402
from v4 import verify as vf  # noqa: E402

MIN = 60_000
T0 = 1_633_046_400_000  # 2021-10-01 00:00 UTC
HEADER = "open_time,open,high,low,close,volume,close_time,quote_volume,count,taker_buy_volume,taker_buy_quote_volume,ignore"


def kline_rows(start_ms, n, interval=MIN, price=100.0):
    rows = []
    for i in range(n):
        t = start_ms + i * interval
        o = price + i
        rows.append(f"{t},{o},{o + 2},{o - 1},{o + 1},10,{t + interval - 1},1000,5,4,400,0")
    return rows


def zip_bytes(name, text):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        z.writestr(name, text)
    return buf.getvalue()


class FakeBinance:
    def __init__(self):
        self.files, self.calls = {}, []

    def add(self, url, payload, checksum=True):
        self.files[url] = payload
        if checksum:
            self.files[url + ".CHECKSUM"] = f"{hashlib.sha256(payload).hexdigest()} *x.zip".encode()

    def __call__(self, url):
        self.calls.append(url)
        if "fapi.binance.com" in url:
            return b"[]"
        if url not in self.files:
            raise bd.NotFound(url)
        return self.files[url]


def test_csv_with_and_without_header_and_microseconds():
    plain = bd.parse_klines_csv("\n".join(kline_rows(T0, 3)))
    with_header = bd.parse_klines_csv(HEADER + "\n" + "\n".join(kline_rows(T0, 3)))
    assert plain["open_time"].tolist() == with_header["open_time"].tolist() == [T0, T0 + MIN, T0 + 2 * MIN]
    micro = bd.parse_klines_csv(f"{T0 * 1000},1,2,0.5,1.5,1,{(T0 + MIN - 1) * 1000},1,1,0.5,1,0")
    assert micro["open_time"][0] == T0 and micro["close_time"][0] == T0 + MIN - 1


def test_checksum_mismatch_is_refused():
    fake = FakeBinance()
    fake.add("u.zip", b"payload")
    assert bd.fetch_verified_zip("u.zip", fake)[0] == b"payload"
    fake.files["u.zip.CHECKSUM"] = b"deadbeef *u.zip"
    with pytest.raises(ValueError, match="checksum mismatch"):
        bd.fetch_verified_zip("u.zip", fake)


def test_month_list_and_days():
    today = dt.date(2026, 10, 6)
    assert bd.month_list(3, today) == ["2026-07", "2026-08", "2026-09"]
    assert bd.month_list(13, today)[0] == "2025-09"
    assert bd.current_month_days(today) == ["2026-10-01", "2026-10-02", "2026-10-03", "2026-10-04", "2026-10-05"]


def test_download_is_resumable_and_marks_pre_listing_months(tmp_path):
    today = dt.date(2021, 12, 3)
    fake = FakeBinance()
    base = f"{bd.BULK}/monthly/klines/BTCUSDT/1m/BTCUSDT-1m-"
    # 2021-09 does not exist (pre-listing), 2021-10 and 2021-11 do.
    fake.add(base + "2021-10.zip", zip_bytes("a.csv", "\n".join(kline_rows(T0, 5))))
    fake.add(base + "2021-11.zip", zip_bytes("b.csv", HEADER + "\n" + "\n".join(kline_rows(T0 + 31 * 1440 * MIN, 5))))
    fake.add(f"{bd.BULK}/daily/klines/BTCUSDT/1m/BTCUSDT-1m-2021-12-01.zip", zip_bytes("c.csv", "\n".join(kline_rows(T0 + 61 * 1440 * MIN, 5))))

    first = dl.download_klines(tmp_path, "BTCUSDT", "1m", 3, today, get=fake, now_ms=T0 + 70 * 1440 * MIN)
    assert first["months_fetched"] == 2
    assert first["months_not_listed"] == ["2021-09"]
    assert first["days_fetched"] == 1 and first["days_missing"] == ["2021-12-02"]

    fake.calls.clear()
    second = dl.download_klines(tmp_path, "BTCUSDT", "1m", 3, today, get=fake, now_ms=T0 + 70 * 1440 * MIN)
    assert second["months_cached"] == 2 and second["months_fetched"] == 0
    assert not any("monthly/klines" in c for c in fake.calls)       # nothing re-downloaded
    data = bd.load_klines(tmp_path, "BTCUSDT", "1m")
    assert len(data["open_time"]) == 15 and np.all(np.diff(data["open_time"]) > 0)


def test_recent_month_is_never_marked_not_listed(tmp_path):
    fake = FakeBinance()
    dl.download_klines(tmp_path, "BTCUSDT", "1m", 1, dt.date(2021, 11, 1), get=fake, now_ms=T0)
    manifest = bd.Manifest(tmp_path / "data" / "BTCUSDT" / "manifest.json")
    assert manifest.get("1m:monthly:2021-10") is None              # retried next run, not frozen as missing


def test_stop_file_stops_cleanly(tmp_path):
    (tmp_path / "STOP").write_text("")
    with pytest.raises(dl.Stopped):
        dl.download_klines(tmp_path, "BTCUSDT", "1m", 1, dt.date(2021, 11, 5), get=FakeBinance())


def test_load_klines_dedupes_overlapping_sources(tmp_path):
    base = tmp_path / "data" / "BTCUSDT" / "1m"
    bd.write_parquet(base / "monthly" / "2021-10.parquet", bd.parse_klines_csv("\n".join(kline_rows(T0, 4))))
    bd.write_parquet(base / "tail.parquet", bd.parse_klines_csv("\n".join(kline_rows(T0 + 2 * MIN, 4))))
    data = bd.load_klines(tmp_path, "BTCUSDT", "1m")
    assert data["open_time"].tolist() == [T0 + i * MIN for i in range(6)]


def test_kline_checks_find_gaps_and_invalid_ohlc():
    data = bd.parse_klines_csv("\n".join(kline_rows(T0, 3) + kline_rows(T0 + 5 * MIN, 2)))
    data["high"][1] = data["low"][1] - 1          # impossible candle
    report = vf.kline_checks(data, MIN, now_ms=T0 + 10**9)
    assert report["missing_candles"] == 2 and report["gap_count"] == 1
    assert report["invalid_ohlc"] == 1
    assert report["largest_gaps"][0]["missing_candles"] == 2


def test_one_minute_aggregates_exactly_to_native_five_minute():
    m1 = bd.parse_klines_csv("\n".join(kline_rows(T0, 10)))
    agg = vf.aggregate_1m_to_5m(m1)
    m5 = {"open_time": agg["open_time"].copy(), "open": agg["open"].copy(), "high": agg["high"].copy(),
          "low": agg["low"].copy(), "close": agg["close"].copy(), "volume": agg["volume"].copy()}
    assert agg["open"].tolist() == [100.0, 105.0] and agg["close"].tolist() == [105.0, 110.0]
    assert agg["high"].tolist() == [106.0, 111.0] and agg["volume"].tolist() == [50.0, 50.0]
    assert vf.alignment_check(m1, m5)["price_mismatch"] == 0
    m5["close"][1] += 0.5
    assert vf.alignment_check(m1, m5)["price_mismatch"] == 1


def test_partial_five_minute_bucket_is_not_compared():
    m1 = bd.parse_klines_csv("\n".join(kline_rows(T0, 4)))          # only 4 of 5 minutes
    assert len(vf.aggregate_1m_to_5m(m1)["open_time"]) == 0


def test_funding_bulk_and_rest_rows_are_deduplicated(tmp_path):
    base = tmp_path / "data" / "BTCUSDT" / "funding"
    bd.write_parquet(base / "monthly" / "2021-10.parquet", bd.parse_funding_csv(
        "calc_time,funding_interval_hours,last_funding_rate\n1633046400005,8,0.0001\n1633075200000,8,0.0002"))
    bd.write_parquet(base / "tail.parquet", {"funding_time": np.array([1633075200000, 1633104000000]),
                                             "interval_hours": np.array([0, 0]), "funding_rate": np.array([0.0002, 0.0003])})
    funding = dl.load_funding(tmp_path, "BTCUSDT")
    assert funding["funding_time"].tolist() == [1633046400005, 1633075200000, 1633104000000]
