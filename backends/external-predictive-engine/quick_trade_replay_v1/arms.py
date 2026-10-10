"""Quick Trade replay v1: presets, signals, range unit, gate masks, exit-arm grid (plan section 3). Everything here is fixed by the frozen plan."""

from __future__ import annotations

import numpy as np

from edge_search import rules
from regime_v1 import features as F
from regime_v1.trades import gate as regime_gate

from .paths import QS_SL, QS_TP

CODE_COST = 0.14                      # QuickTradeCostModel taker round trip, %
COST_MEAS = 0.12                      # D-040, primary
COSTS = {"meas": 0.12, "code": 0.14, "maker_code": 0.11, "maker_meas": 0.08}
DISPLACEMENT = 0.55                   # QuickTradeCostModel::DISPLACEMENT_RANGE_FACTOR
K_CODE, K_ALT = 2.0, 1.0
T_GATE = 0.50
S_SEEDS = 100

# name -> (signal timeframe, horizon bars on that timeframe, 5m bars of the horizon, binary_only)
PRESETS = {
    "P1": ("5m", 12, 12, False),      # 5m chart / 3600 s (recommended)
    "P2": ("5m", 6, 6, False),        # 5m chart / 1800 s
    "P3": ("15m", 4, 12, False),      # 15m chart / 3600 s
    "PP1": ("5m", 1, 1, True),        # paper-native 5m chart / 300 s (report-only)
    "PP2": ("5m", 3, 3, True),        # paper-native 5m chart / 900 s (report-only)
}
PRESET_SECONDS = {"P1": 3600, "P2": 1800, "P3": 3600, "PP1": 300, "PP2": 900}

# exit arms: name -> parameters (k = SL in R units, r = TP in SL units, a = arm in R units, d = trail in R units)
EXITS: dict[str, dict] = {"X1": {"kind": "time"}}
for _k in (0.5, 1.0, 1.5):
    for _r in (None, 1, 2):
        EXITS[f"X2_k{_k}_r{'none' if _r is None else _r}"] = {"kind": "sltp", "k": _k, "r": _r}
EXITS["X3_BE_a0.5"] = {"kind": "be", "a": 0.5}
EXITS["X3_BE_a1.0"] = {"kind": "be", "a": 1.0}
EXITS["X3_TR_a0.5_d0.5"] = {"kind": "trail", "a": 0.5, "d": 0.5}
EXITS["X3_TR_a1.0_d0.5"] = {"kind": "trail", "a": 1.0, "d": 0.5}
for _qi, (_qs, _qt) in enumerate(((0.75, 0.50), (0.90, 0.50), (0.75, 0.75))):
    EXITS[f"X4_q{_qs}_{_qt}"] = {"kind": "mfe", "qs": _qs, "qt": _qt}
GATES = ("G0", "G1a", "G1b", "G2", "G3", "G2m")
DET_ARMS = ("B", "C", "EL", "ES")
GATES_MAIN = ("G1a", "G1b", "G2", "G3", "G2m")


def range_unit(atrp, h: int):
    """rangePct = min(5.0, atrPct * sqrt(horizonCandles)) in % (QuickTradeCostModel::maxAchievableMovePct)."""
    with np.errstate(invalid="ignore"):
        return np.minimum(5.0, np.asarray(atrp, float) * np.sqrt(h))


def expected_move(R):
    return DISPLACEMENT * np.asarray(R, float)


def gate_masks(R, feat: dict, idx, cls: str | None) -> dict:
    """name -> (mask if long, mask if short) at the signal bars idx. NaN features never pass a pass-gate."""
    idx = np.asarray(idx, np.int64)
    em = expected_move(R)[idx]
    with np.errstate(invalid="ignore"):
        g1a = np.isfinite(em) & (em >= K_CODE * CODE_COST)
        g1b = np.isfinite(em) & (em >= K_ALT * CODE_COST)
    g2 = F.t_gate(feat["T"][idx], T_GATE)
    g3 = feat["regime"][idx] != F.LOWVOL_COMPRESSION
    out = {"G0": (np.ones(len(idx), bool),) * 2, "G1a": (g1a, g1a), "G1b": (g1b, g1b), "G2": (g2, g2), "G3": (g3, g3)}
    if cls in ("trend", "meanrev"):
        reg, brk = feat["regime"][idx], feat["brk_dir"][idx]
        one = np.ones(len(idx), np.int8)
        out["G2m"] = (regime_gate(cls, reg, one, brk), regime_gate(cls, reg, -one, brk))
    return out


def signals(world, sym: str, tf: str, h: int) -> dict:
    """B momentum (tsmom n = h) and C mean reversion (bb_z k = 2.0), int8 per bar of the signal timeframe."""
    b = rules.Spec("F1", "tsmom", (("n", h),), f"n{h}", (tf,))
    c = rules.Spec("F2", "bb_z", (("k", 2.0),), "k2.0", (tf,))
    return {"B": rules.signal(b, world, sym, tf), "C": rules.signal(c, world, sym, tf)}


def exit_params(name: str, R_frac, tab_row=None) -> dict:
    """simulate() keyword arguments for an exit arm. R_frac = range unit as a fraction of price per trade (n,). tab_row = (n, 2(kind), 2(q)) quantile values
    for the X4 arms (already selected for each trade's side)."""
    p = EXITS[name]
    kind = p["kind"]
    if kind == "time":
        return {}
    if kind == "sltp":
        s = p["k"] * R_frac
        return {"stop": s, "tp": None if p["r"] is None else p["r"] * s}
    if kind in ("be", "trail"):
        kw = {"stop": 1.0 * R_frac, "arm": p["a"] * R_frac}
        if kind == "be":
            kw["be"] = True
        else:
            kw["trail"] = p["d"] * R_frac
        return kw
    if kind == "mfe":
        qs, qt = QS_SL.index(p["qs"]), QS_TP.index(p["qt"])
        return {"stop": tab_row[:, 0, qs], "tp": tab_row[:, 1, qt]}
    raise KeyError(name)