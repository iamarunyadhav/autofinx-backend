"""D-036 P0 regression tests: the defects reproduced by the independent (Codex) review stay fixed."""

import numpy as np

from v4 import execution as ex
from v4 import p5
from v4.p4b_audit import spearman_matrix


def _grid(rows):
    o, h, l, c = (np.array(x, float) for x in zip(*rows))
    return {"start": np.array(0, np.int64), "open": o, "high": h, "low": l, "close": c}


def test_a_maker_fill_minute_cannot_create_pre_entry_profit():
    # Codex counterexample: limit 100; the fill minute spikes to 101 (possibly BEFORE the fill) then the
    # next minute falls through the 1 % stop. The old simulator armed trailing on the pre-fill high (+0.02 % net).
    g = _grid([(100, 100.1, 100.0, 100.05), (100.05, 101.0, 99.9, 100.1), (100.1, 100.15, 98.8, 99.0)] + [(99, 99.1, 98.9, 99)] * 5)
    out = ex.simulate_c1(g, np.array([0]), 3, "long", 1.0, 0.5, 0.2, None)
    assert out["filled"][0]
    assert np.isclose(out["gross_pct"][0], -1.0) and np.isclose(out["net_pct"][0], -1.0 - ex.COST_C1)


def test_a_fill_minute_adverse_extreme_counts():
    g = _grid([(100, 100.1, 100.0, 100.05), (100.05, 100.2, 98.5, 99.5)] + [(99.5, 99.6, 99.4, 99.5)] * 5)
    out = ex.simulate_c1(g, np.array([0]), 4, "long", 1.0, 0.5, 0.2, None)
    assert out["fill_minute_stop"][0] and np.isclose(out["net_pct"][0], -1.0 - ex.COST_C1)


def test_a_expiry_stays_signal_plus_h():
    g = _grid([(100, 100.1, 100.0, 100.05), (100.05, 100.1, 99.95, 100.0)] + [(100, 100.05, 99.97, 100.0)] * 10)
    out = ex.simulate_c1(g, np.array([0]), 5, "long", 1.0, 0.5, 0.2, None)
    assert out["exit_time_ms"][0] == 5 * 60_000                 # signal + H, not fill + H


def test_b_quality_gate_sees_raw_crossing():
    y = np.linspace(0, 1, 200)
    fold = {"y": y, "q25": np.full(200, 0.9), "q50": np.full(200, 0.5), "q75": np.full(200, 0.1),
            "const": {0.25: 0.25, 0.5: 0.5, 0.75: 0.75}}
    g = p5.quality_gate([fold, fold, fold], 0.25)
    assert g["crossing_rate"] == 1.0 and not g["passed"]


def test_b_p5_run_feeds_raw_predictions_to_the_gate():
    import inspect
    from v4 import p5_run
    src = inspect.getsource(p5_run.run)
    assert "_predict_q_raw(quant[s][comp]" in src


def test_e_spearman_ties_and_constants():
    x = np.column_stack([np.zeros(500), np.arange(500, dtype=float), np.repeat([0.0, 1.0], 250)])
    rho = spearman_matrix(x)
    assert np.isnan(rho[0, 1])                                   # constant: undefined, not ~1.0
    assert 0.8 < rho[1, 2] < 0.9                                 # ties averaged (exact value ~0.866)


def test_c_score_accepts_fold_specific_labels():
    from v4.ablation import score
    rng = np.random.default_rng(0)
    n = 6000
    x = rng.normal(size=(n, 3))
    anchors = np.arange(n, dtype=np.int64) * 300_000
    lab = {s: (x[:, 0] + rng.normal(size=n) > 0).astype(int) for s in ("long", "short")}
    ok = {s: np.ones(n, bool) for s in ("long", "short")}
    folds = [(np.arange(0, 3000), np.arange(3100, 4000)), (np.arange(0, 4000), np.arange(4100, 5000))]
    a, _ = score(x, [lab, lab], [ok, ok], anchors, folds, horizon=15)
    b, _ = score(x, lab, ok, anchors, folds, horizon=15)
    assert np.allclose(a, b) and min(a) > 0.6


def test_p1_block_bootstrap_and_execution():
    from v4.p1_signal import block_bootstrap_ci, executed
    rng = np.random.default_rng(3)
    times = np.arange(2000, dtype=np.int64) * 300_000
    ci = block_bootstrap_ci(rng.normal(0.5, 1.0, 2000), times)
    assert ci[0] > 0.3 and ci[1] < 0.7
    assert block_bootstrap_ci(np.ones(10), times[:10]) is None
    a = np.array([0, 10, 20, 30], np.int64) * 60_000
    side = np.array([1, -1, 0, 1])
    r = executed(a, side, np.array([0.5, 0, 0, 0.1]), np.array([0, 0.2, 0, 0]),
                 a + 5 * 60_000, a + 5 * 60_000, cost_shift=0.03)
    assert r["trades"] == 3 and abs(r["avg_net_pct"] - ((0.5 + 0.2 + 0.1) / 3 - 0.03)) < 1e-5   # rounded to 5 dp
