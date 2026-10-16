import unittest

from autofinx_v4_multifactor_event_research.aggregation import aggregate_closed_ohlcv


def candles(count=10):
    return [{"open_ms": i * 60_000, "close_ms": (i + 1) * 60_000 - 1,
             "open": 100 + i, "high": 101 + i, "low": 99 + i,
             "close": 100.5 + i, "volume": 2} for i in range(count)]


class AggregationTest(unittest.TestCase):
    def test_aligned_closed_groups(self):
        out = aggregate_closed_ohlcv(candles(10), 5 * 60_000, 10 * 60_000)
        self.assertEqual(len(out), 2)
        self.assertEqual(out[0]["source_count"], 5)
        self.assertEqual(out[0]["open"], 100)
        self.assertEqual(out[0]["close"], 104.5)
        self.assertEqual(out[0]["volume"], 10)

    def test_forming_group_is_excluded(self):
        out = aggregate_closed_ohlcv(candles(10), 5 * 60_000, 7 * 60_000)
        self.assertEqual(len(out), 1)

    def test_gap_group_is_excluded(self):
        rows = candles(10)
        rows.pop(2)
        out = aggregate_closed_ohlcv(rows, 5 * 60_000, 10 * 60_000)
        self.assertEqual(len(out), 1)

    def test_future_rows_do_not_appear(self):
        out = aggregate_closed_ohlcv(candles(10), 5 * 60_000, 299_999)
        self.assertEqual(out, [])


if __name__ == "__main__":
    unittest.main()
