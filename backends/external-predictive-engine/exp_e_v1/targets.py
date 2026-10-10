"""Experiment E v1 event table (plan sections 2-3): for every V3 feature anchor of a coin the Quick Trade targets under the live presets.

All arrays are aligned to the master anchor list (V3 feature anchors < CUT that also have a VALID h60 Layer A target row). Fill, path and cost conventions are the Quick Trade
replay's (quick_trade_replay_v1.paths): signal on the closed bar that ends at the anchor, entry at the open of the next 5m bar, expiry exit at the close of the last horizon bar,
gap fills at the worse open, same-bar SL + TP = AMBIGUOUS counted as the stop, actual funding. Nothing here uses data after the event's exit time; future bars only create targets.
"""

from __future__ import annotations

import numpy as np

from edge_search.core import outcomes_time  # noqa: F401  (imported for the equivalence test)
from quick_trade_replay_v1 import arms as QA
from quick_trade_replay_v1 import paths as P
from regime_v1 import data as RD
from regime_v1 import features as F

from . import data as D

M5, M15 = D.M5, 900_000
PRESETS = {"P1": 12, "P2": 6}            # 5m-chart presets: horizon in 5m bars (P3 = P1 events at 15m anchors)
SL_R, TP_R = 1.0, 1.0                    # barrier B: SL = TP = 1.0 R
COST = 0.12
COMP_KEYS = ("ER", "CHOP", "PERSIST", "REVERS", "NETATR", "OVERLAP", "WICK")
SOFT_BASE = ("regime", "brk_dir", "T", "atrp", "adx", "vol_pct", "bbw_pct", "structure")
CHUNK = 40_000
MBP_COLS = [f"{s}_{k}" for s in ("long", "short") for k in ("max_adverse_before_profit_pct", "peak_retracement_pct")]


def event_targets(coin, e0: np.ndarray, entry_t: np.ndarray, H5: int, Rfrac: np.ndarray) -> dict:
    """Targets of the events entering at 5m bar index e0 (open time entry_t) with horizon H5 5m bars and range unit Rfrac (fraction of price). NaN where the window is incomplete."""
    b5 = coin.bars["5m"]
    n = len(e0)
    out = {"ok": np.zeros(n, bool), "gX1": np.full(n, np.nan), "fX1": np.full(n, np.nan)}
    for s in ("L", "S"):
        out["gB" + s] = np.full(n, np.nan)
        out["rB" + s] = np.full(n, -1, np.int8)
        out["fB" + s] = np.full(n, np.nan)
    okr = (e0 >= 0) & (e0 + H5 <= b5.n)
    for a in range(0, n, CHUNK):
        sl = slice(a, min(a + CHUNK, n))
        idx = np.nonzero(okr[sl])[0] + a
        if len(idx) == 0:
            continue
        W = P.windows(b5, e0[idx], H5)
        Wo, Wh, Wl, Wc = W
        good = np.isfinite(Wo).all(1) & np.isfinite(Wh).all(1) & np.isfinite(Wl).all(1) & np.isfinite(Wc).all(1) & np.isfinite(Rfrac[idx]) & (Rfrac[idx] > 0)
        idx, W = idx[good], tuple(w[good] for w in W)
        if len(idx) == 0:
            continue
        E = W[0][:, 0]
        out["ok"][idx] = True
        out["gX1"][idx] = (W[3][:, -1] / E - 1.0) * 100.0
        xt = entry_t[idx] + H5 * M5
        out["fX1"][idx] = coin.funding(entry_t[idx], xt)
        for side, tag in ((1, "L"), (-1, "S")):
            r = P.simulate(W, side, stop=SL_R * Rfrac[idx], tp=TP_R * Rfrac[idx])
            out["gB" + tag][idx] = r["gross"]
            out["rB" + tag][idx] = r["reason"]
            out["fB" + tag][idx] = coin.funding(entry_t[idx], P.exit_times(entry_t[idx], r["exit_k"]))
    return out


def rule_signals(world, sym: str, coin) -> dict:
    """Quick Trade replay rule arms (B momentum tsmom n = h, C Bollinger fade k = 2) per bar of each preset's chart timeframe."""
    return {"P1": QA.signals(world, sym, "5m", 12), "P2": QA.signals(world, sym, "5m", 6), "P3": QA.signals(world, sym, "15m", 4)}


def build_events(work, sym: str, log=print, coin=None) -> dict:
    """Everything for one coin as a dict of equal-length numpy arrays (see module doc)."""
    from pathlib import Path
    work = Path(work)
    coin = coin if coin is not None else D.build_coin(work, sym)
    b5, b15 = coin.bars["5m"], coin.bars["15m"]
    f_tab = D.read_anchor_table(work / "features" / sym / "v3.parquet", columns=["anchor_time_ms"])
    t_tab = D.read_anchor_table(work / "targets" / sym / "h60.parquet",
                                columns=["anchor_time_ms", "status", "long_final_time_exit_gross_pct", "funding_long_pct"] + MBP_COLS)
    from v4.targets import VALID
    fa = f_tab["anchor_time_ms"].astype(np.int64)
    ta = t_tab["anchor_time_ms"].astype(np.int64)
    pos = np.minimum(np.searchsorted(ta, fa), len(ta) - 1)
    has = ta[pos] == fa
    t_ok = has & (t_tab["status"][pos] == VALID)
    aligned = ((fa - coin.t0) % M5 == 0) & (fa > coin.t0)
    keep = t_ok & aligned
    a = fa[keep]
    n = len(a)
    log(f"{sym}: {len(fa)} feature anchors < cut, {n} with a VALID h60 target and 5m alignment")
    e0 = (a - coin.t0) // M5                      # open index of the entry bar (the bar that opens at the anchor)
    isig = e0 - 1                                  # last closed bar = the bar that ends at the anchor
    ev = {"anchor": a, "e0": e0, "L1_gross": t_tab["long_final_time_exit_gross_pct"][pos][keep].astype(float),
          "L1_fund": t_tab["funding_long_pct"][pos][keep].astype(float)}
    for s_, tag in (("long", "L"), ("short", "S")):
        for k in ("max_adverse_before_profit_pct", "peak_retracement_pct"):
            ev[f"L1_{k}_{tag}"] = t_tab[f"{s_}_{k}"][pos][keep].astype(float)
    F5 = F.compute(b5.o, b5.h, b5.l, b5.c)
    F15 = F.compute(b15.o, b15.h, b15.l, b15.c)
    for k in SOFT_BASE:
        ev["soft_" + k] = np.asarray(F5[k], float)[isig]
    for k in COMP_KEYS:
        ev["soft_" + k] = np.asarray(F5["comp"][k], float)[isig]
    for p, H5 in PRESETS.items():
        R = QA.range_unit(F5["atrp"][isig], H5)            # % (the code's rangePct)
        ev[f"R_{p}"] = R
        ev[f"M_{p}"] = QA.expected_move(R)
        tg = event_targets(coin, e0, a, H5, R / 100.0)
        for k, v in tg.items():
            ev[f"{k}_{p}"] = v
        log(f"{sym}: preset {p} targets done, valid {int(tg['ok'].sum())}/{n}")
    # soft R / M for P1 only are also the feature columns of SOFT (same arrays as R_P1, M_P1)
    # rule signals and the G1a gate for the replay baselines
    world = RD.World({sym: coin}, work)
    sigs = rule_signals(world, sym, coin)
    for p, h in (("P1", 12), ("P2", 6)):
        for r in ("B", "C"):
            ev[f"sig{r}_{p}"] = np.asarray(sigs[p][r], np.int8)[isig]
    on15 = (a % M15 == 0)
    j15 = np.clip((a - coin.t0) // M15 - 1, 0, b15.n - 1)
    for r in ("B", "C"):
        ev[f"sig{r}_P3"] = np.where(on15, np.asarray(sigs["P3"][r], np.int8)[j15], 0).astype(np.int8)
    ev["on15"] = on15
    R15 = QA.range_unit(F15["atrp"][j15], 4)
    ev["M_P3"] = np.where(on15, QA.expected_move(R15), np.nan)
    ev["valid_L1"] = np.isfinite(ev["L1_gross"]) & np.isfinite(ev["L1_fund"])
    return ev
