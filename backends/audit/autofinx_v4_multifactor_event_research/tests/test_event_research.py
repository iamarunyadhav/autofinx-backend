import unittest

from autofinx_v4_multifactor_event_research.contracts import EventTrade, ExitPolicy
from autofinx_v4_multifactor_event_research.event_simulator import simulate_event_trade
from autofinx_v4_multifactor_event_research.features import causal_features
from autofinx_v4_multifactor_event_research.splits import chronological_split


class EventResearchTest(unittest.TestCase):
    def policy(self):
        return ExitPolicy(.02, .01, .005, .003, .03)

    def trade(self):
        return EventTrade("BTCUSDT", 1, 2, 100.0, "LONG", self.policy(), "v2", "hash")

    def test_tp_event_and_costs(self):
        out = simulate_event_trade(self.trade(), [{"ts_ms": 3, "high": 102, "low": 99.5, "open": 100, "close": 101}], [])
        self.assertEqual(out.event, "TP"); self.assertLess(out.net_return, out.gross_return)

    def test_both_sides_are_ambiguous_and_conservative(self):
        out = simulate_event_trade(self.trade(), [{"ts_ms": 3, "high": 102, "low": 98.5, "open": 100, "close": 100}], [])
        self.assertEqual(out.event, "SL"); self.assertTrue(out.ambiguous)

    def test_open_is_censored_not_a_win(self):
        out = simulate_event_trade(self.trade(), [{"ts_ms": 3, "high": 100.2, "low": 99.9, "open": 100, "close": 100.1}], [])
        self.assertEqual(out.event, "OPEN_CENSORED"); self.assertTrue(out.open_censored); self.assertIsNone(out.net_return)

    def test_features_are_causal_and_missing_data_is_explicit(self):
        rows = [{"open": 100+i, "high": 101+i, "low": 99+i, "close": 100+i, "volume": 10+i} for i in range(21)]
        before = causal_features(rows)
        self.assertEqual(before, causal_features(rows[:]))
        rows.append({"open": 1, "high": 2, "low": 1, "close": 1, "volume": 1})
        self.assertIsNone(causal_features(rows[:10])["rsi_14"])

    def test_splits_are_chronological(self):
        out = chronological_split([{"ts_ms": 1}, {"ts_ms": 2}, {"ts_ms": 3}], 1, 2)
        self.assertEqual([len(out[x]) for x in ("development", "validation", "test")], [1, 1, 1])


if __name__ == "__main__":
    unittest.main()
