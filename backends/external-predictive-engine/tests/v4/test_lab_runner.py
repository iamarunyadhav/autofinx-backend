"""Strategy Lab runner: scorecard arithmetic and the pre-registered verdict rule (accuracy never used)."""

from v4 import lab_runner as lr


def _t(entry, gross, side=1, fund=0.0):
    return {"entry_time": entry, "exit_time": entry + 60_000, "gross_pct": gross, "funding_pct": fund, "side": side,
            "net_pct": gross - 0.12 - fund, "reason_a": "TP1", "reason_b": "TRAIL"}


def test_scorecard_costs_and_slices():
    trades = [_t(0, 0.5), _t(10, -0.2, -1), _t(20, 0.3), _t(30, -0.1)]
    s = lr.scorecard(trades, 0.12, 2, 0, 39)
    assert s["trades"] == 4 and s["buy_trades"] == 3 and s["sell_trades"] == 1
    assert abs(s["fees_total_pct"] - 0.48) < 1e-9
    assert abs(s["net_total_pct"] - (0.5 - 0.2 + 0.3 - 0.1 - 0.48)) < 1e-9
    assert [x["trades"] for x in s["slices"]] == [2, 2]
    assert lr.scorecard(trades, 0.18, 2, 0, 39)["avg_net_pct"] < s["avg_net_pct"]


def _card(avg, pf, slices, n=100):
    return {"trades": n, "avg_net_pct": avg, "profit_factor": pf, "slices": [{"avg_net_pct": v} for v in slices]}


def test_verdicts():
    good = _card(0.05, 1.3, [0.1, 0.02, 0.03, -0.01])
    assert lr.verdict("quick", good, {"A": {"avg_net_pct": 0.04}}, None)[0] == "PROMISING_FOR_DEEP_TEST"
    assert lr.verdict("quick", _card(-0.1, 0.6, [-1] * 4), {}, None)[0] == "NO_VALUE"
    assert lr.verdict("deep", _card(-0.1, 0.6, [-1] * 4), {}, None)[0] == "REJECTED_NO_VALUE"
    assert lr.verdict("quick", _card(0.05, 1.3, [0.1] * 4, n=10), {}, None)[0] == "TOO_FEW_TRADES"
    assert lr.verdict("quick", _card(0.05, 1.3, [0.1, -0.1, -0.1, 0.2]), {}, None)[0] == "FRAGILE"
    assert lr.verdict("quick", good, {"A": {"avg_net_pct": -0.01}}, None)[0] == "FRAGILE"
    assert lr.verdict("deep", good, {"A": {"avg_net_pct": 0.04}}, {"avg_net_pct": -0.01})[0] == "REJECTED_COST_FRAGILE"
    assert lr.verdict("quick", _card(0.01, 1.01, [0.1] * 4), {}, None)[0] == "WEAK"


def test_unknown_adapter_exit_code(tmp_path):
    assert lr.main(["--adapter", "nope", "--mode", "quick", "--work", str(tmp_path), "--out", str(tmp_path / "o")]) == 4


def test_missing_data_exit_code(tmp_path):
    assert lr.main(["--adapter", "autofinx-v13", "--mode", "quick", "--work", str(tmp_path), "--out", str(tmp_path / "o")]) == 3
