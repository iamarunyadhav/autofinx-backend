"""Baseline strategies, regime-matched gates, composites and trade sets (plan sections 3.3, 4, 5 Q2-Q3).

Trades come from the v1 engine (`edge_search.core.select_trades` / `outcomes_time` / `outcomes_atr`): signal on the bar close, fill at the next
open, greedy non-overlap, ATR exit with the stop winning inside a bar. Filters are masks on the SIGNAL bar (closed-bar features only); the
primary filtered set is the subset of the unfiltered trades that pass the mask.
"""

from __future__ import annotations

import numpy as np

from edge_search import rules
from edge_search.core import COST_BASE, outcomes_atr, outcomes_time, select_trades

from . import data as D
from . import features as F

H = F.H_STRAT
EXITS = ("time", "atr")
TFS = ("15m", "1h", "4h")
FILTERS = {"T40": 0.40, "T50": 0.50, "T60": 0.60}            # plus "M", "TM"
MASKS = ("T40", "T50", "T60", "M", "TM", "G", "X")
# name -> (class, family, rule, params, label)
STRATS = {
    "S1_always_long": ("always", None),
    "S2_always_short": ("always", None),
    "S3_ema_cross": ("trend", ("F1", "ema_cross", (("i", 1),), "12/26")),
    "S4_tsmom": ("trend", ("F1", "tsmom", (("n", 30),), "n30")),
    "S5_supertrend": ("trend", ("F1", "supertrend", (("factor", 3),), "x3")),
    "S6_donchian": ("breakout", ("F1", "donchian", (("n", 55),), "n55")),
    "S7_squeeze": ("breakout", ("F3", "squeeze", (("p", 20),), "p20")),
    "S8_bb_z": ("meanrev", ("F2", "bb_z", (("k", 2.5),), "k2.5")),
    "S9_rsi": ("meanrev", ("F2", "rsi_extreme", (("lo", 25),), "25/75")),
    "S10_range_fade": ("meanrev", ("F2", "range_fade", (("n", 48),), "n48")),
}
COMPOSITES = ("D_dir", "D_meta")
ALL_UNITS = tuple(STRATS) + COMPOSITES


def base_signals(world, coin: str, tf: str) -> dict:
    n = world.coins[coin].bars[tf].n
    out = {"S1_always_long": np.ones(n, np.int8), "S2_always_short": -np.ones(n, np.int8)}
    for name, (cls, spec) in STRATS.items():
        if spec is None:
            continue
        fam, rule, params, label = spec
        sp = rules.Spec(fam, rule, params, label, (tf,))
        out[name] = rules.signal(sp, world, coin, tf)
    return out


def gate(cls: str, reg, side, brk) -> np.ndarray:
    """Regime-matched gate on the signal bar (plan Q3). reg / side / brk are equal-length arrays (bars or trades)."""
    reg, side, brk = np.asarray(reg), np.asarray(side), np.asarray(brk)
    trend = ((np.isin(reg, (F.STRONG_BULL, F.WEAK_BULL))) & (side > 0)) | ((np.isin(reg, (F.STRONG_BEAR, F.WEAK_BEAR))) & (side < 0))
    if cls == "trend":
        return trend
    if cls == "breakout":
        return (reg == F.BREAKOUT) & (brk == side) & (side != 0)
    if cls == "meanrev":
        return np.isin(reg, F.RANGE)
    return np.ones(len(reg), bool)


def mismatch(cls: str, reg, side, brk) -> np.ndarray:
    """Descriptive control: the regime the strategy was NOT designed for (classified bars only)."""
    reg = np.asarray(reg)
    if cls == "trend":
        return np.isin(reg, F.RANGE)
    if cls == "meanrev":
        return np.isin(reg, F.TREND)
    if cls == "breakout":
        return (reg != F.BREAKOUT) & (reg != F.UNCERTAIN)
    return np.zeros(len(reg), bool)


def composites(feat: dict, bb_sig: np.ndarray, tm: np.ndarray, m: np.ndarray) -> dict:
    reg, brk = feat["regime"], feat["brk_dir"]
    n = len(reg)
    bull = np.isin(reg, (F.STRONG_BULL, F.WEAK_BULL))
    bear = np.isin(reg, (F.STRONG_BEAR, F.WEAK_BEAR))
    up, dn = (reg == F.BREAKOUT) & (brk > 0), (reg == F.BREAKOUT) & (brk < 0)
    d_dir = np.zeros(n, np.int8)
    d_dir[tm & (bull | up)] = 1
    d_dir[tm & (bear | dn)] = -1
    d_meta = np.zeros(n, np.int8)
    rng = np.isin(reg, F.RANGE)
    d_meta[rng & m] = bb_sig[rng & m]
    d_meta[tm & bull] = 1
    d_meta[tm & bear] = -1
    d_meta[m & up] = 1
    d_meta[m & dn] = -1
    return {"D_dir": d_dir, "D_meta": d_meta}


def build_tf(world, coin: str, tf: str, feat: dict, sigs: dict | None = None) -> dict:
    """All units for one coin x timeframe -> flat dict of arrays keyed 'unit|exit|field'."""
    cobj = world.coins[coin]
    b = cobj.bars[tf]
    ct = b.close_time
    T, atrp = feat["T"], feat["atrp"]
    bar_masks = {k: F.t_gate(T, v) for k, v in FILTERS.items()}
    bar_masks["M"] = F.move_gate(atrp)
    bar_masks["TM"] = bar_masks["T50"] & bar_masks["M"]
    sigs = sigs if sigs is not None else base_signals(world, coin, tf)
    sigs.update(composites(feat, sigs["S8_bb_z"], bar_masks["TM"], bar_masks["M"]))
    outs = {"time": outcomes_time(b, H), "atr": outcomes_atr(b, H, 1.5)}
    res: dict = {}
    for name in ALL_UNITS:
        sig = sigs[name]
        cls = STRATS[name][0] if name in STRATS else "composite"
        for ex, out in outs.items():
            tr = select_trades(sig, out, ct, D.WINDOW_LO, D.CUT)
            idx = tr.idx
            fund = tr.side * cobj.funding(tr.entry_t, tr.exit_t) if len(idx) else np.zeros(0)
            key = f"{name}|{ex}|"
            res[key + "entry_t"], res[key + "exit_t"] = tr.entry_t.astype(np.int64), tr.exit_t.astype(np.int64)
            res[key + "side"] = tr.side.astype(np.int8)
            res[key + "gross"] = tr.gross.astype(float)
            res[key + "fund"] = fund.astype(float)
            res[key + "net"] = (tr.gross - COST_BASE - fund).astype(float)
            res[key + "reg"] = feat["regime"][idx].astype(np.int8)
            for mk in ("T40", "T50", "T60", "M", "TM"):
                res[key + "m_" + mk] = bar_masks[mk][idx]
            if cls in ("composite",):
                res[key + "m_G"] = np.ones(len(idx), bool)
                res[key + "m_X"] = np.zeros(len(idx), bool)
            else:
                res[key + "m_G"] = gate(cls, feat["regime"][idx], tr.side, feat["brk_dir"][idx]) if cls != "always" else np.ones(len(idx), bool)
                res[key + "m_X"] = mismatch(cls, feat["regime"][idx], tr.side, feat["brk_dir"][idx])
            # secondary: filter the signals first, then select (a skipped signal frees the next bar)
            for mk in ("TM", "G"):
                if mk == "TM":
                    bm = bar_masks["TM"]
                elif cls in ("composite", "always"):
                    continue
                else:
                    bm = gate(cls, feat["regime"], sig, feat["brk_dir"])
                tr2 = select_trades(np.where(bm, sig, 0).astype(np.int8), out, ct, D.WINDOW_LO, D.CUT)
                f2 = tr2.side * cobj.funding(tr2.entry_t, tr2.exit_t) if len(tr2.idx) else np.zeros(0)
                res[key + f"re_{mk}_entry_t"] = tr2.entry_t.astype(np.int64)
                res[key + f"re_{mk}_gross"] = tr2.gross.astype(float)
                res[key + f"re_{mk}_net"] = (tr2.gross - COST_BASE - f2).astype(float)
    return res
