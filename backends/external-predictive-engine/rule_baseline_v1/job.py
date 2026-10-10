"""Per-coin job for Rule Baseline v1: candidates -> entry-time distance guard -> path simulation on the 5m bars -> greedy non-overlap chain -> per-trade
table, per-day arrays and the matched random-side null on the SAME entries with the SAME stop / take-profit / timeout geometry."""

from __future__ import annotations

import time

import numpy as np

from edge_search.core import DAY, outcomes_time
from quick_trade_replay_v1 import job as QJ
from quick_trade_replay_v1 import paths as P
from regime_v1 import data as RD

from . import spec as S

WINDOW_LO, CUT, M5 = RD.WINDOW_LO, RD.CUT, 300_000
N_DAYS, year_of_day = QJ.N_DAYS, QJ.year_of_day


def chain(entry_t, exit_t) -> np.ndarray:
    """Greedy non-overlap: take the first candidate, then the first one whose entry time is >= the previous exit time. Inputs sorted by entry time."""
    keep = []
    last = -1
    for i in range(len(entry_t)):
        if entry_t[i] >= last:
            keep.append(i)
            last = exit_t[i]
    return np.array(keep, np.int64)


def candidates(coin, b5, tf: str, h: int, H5: int, var: dict) -> tuple[dict, dict]:
    """All signal bars that pass the in-window, complete-bar and floor / cap rules, with the stop fraction measured at the ACTUAL entry price."""
    b = coin.bars[tf]
    sg = S.signals(b, var)
    ct = b.close_time
    out1 = outcomes_time(b, h)
    stat = {"signals": int((sg["side"] != 0).sum())}
    ok = (sg["side"] != 0) & out1.valid & np.isfinite(b.c) & (ct >= WINDOW_LO) & (ct + H5 * M5 <= CUT)
    ok &= np.isfinite(sg["dist"])
    stat["window_ok"] = int(ok.sum())
    ok &= S.distance_ok(sg["dist"])                      # floor / cap at the signal close (strictly causal)
    stat["after_signal_distance"] = int(ok.sum())
    idx = np.nonzero(ok)[0]
    entry_t = ct[idx]
    e0 = P.entry_index(b5, entry_t)
    E = b5.o[e0]
    side = sg["side"][idx]
    frac = S.stop_fraction(var, side, sg["stop_px"][idx], sg["dist"][idx], E)
    fine = S.distance_ok(frac) & np.isfinite(E) & (E > 0)   # gap guard at entry: the order is not placed if the fill price breaks the floor / cap
    stat["gap_cancelled"] = int((~fine).sum())
    idx, entry_t, e0, side, frac = idx[fine], entry_t[fine], e0[fine], side[fine], frac[fine]
    return {"idx": idx, "entry_t": entry_t, "e0": e0, "side": side, "frac": frac}, stat


def simulate_candidates(coin, b5, H5: int, c: dict) -> dict:
    """Worst-case path simulation of every candidate: gross %, exit bar, reason, signed funding."""
    n = len(c["idx"])
    g, k, r = np.empty(n), np.empty(n, np.int64), np.empty(n, np.int8)
    for a in range(0, n, 20_000):
        sl = slice(a, a + 20_000)
        W = P.windows(b5, c["e0"][sl], H5)
        res = P.simulate(W, c["side"][sl], stop=c["frac"][sl], tp=S.RR * c["frac"][sl])
        g[sl], k[sl], r[sl] = res["gross"], res["exit_k"], res["reason"]
    xt = P.exit_times(c["entry_t"], k)
    fund = coin.funding(c["entry_t"], xt) if n else np.zeros(0)
    return {"gross": g, "exit_k": k, "reason": r, "exit_t": xt, "fund": fund}


def run_variant(coin, sym: str, pname: str, vname: str, tabs_out: dict, log=print) -> dict:
    tf, h, H5 = S.PRESETS[pname]
    var = S.VARIANTS[vname]
    b5 = coin.bars["5m"]
    c, stat = candidates(coin, b5, tf, h, H5, var)
    sim = simulate_candidates(coin, b5, H5, c)
    keep = chain(c["entry_t"], sim["exit_t"])
    stat["candidates"] = int(len(c["idx"]))
    stat["trades"] = int(len(keep))
    pre = f"{pname}|{vname}|"
    if len(keep) == 0:
        return stat
    tr = {k: v[keep] for k, v in c.items()}
    sm = {k: v[keep] for k, v in sim.items()}
    side = tr["side"]
    f = side * sm["fund"]                                              # net = gross - cost - f
    day = ((tr["entry_t"] - WINDOW_LO) // DAY).astype(np.int64)
    ones = np.ones(len(keep))
    tabs_out[pre + "day"] = QJ.day_array(day, ones, sm["gross"], f)
    for key, val in (("entry_t", tr["entry_t"]), ("exit_t", sm["exit_t"]), ("side", side), ("R", tr["frac"] * 100.0), ("gross", sm["gross"]),
                     ("f", f), ("reason", sm["reason"])):
        tabs_out[pre + "tbl_" + key] = val
    # matched random-direction null: SAME entries, SAME stop distance R, take-profit 2 R, timeout; sides random, S_SEEDS seeds
    W = P.windows(b5, tr["e0"], H5)
    kw = {"stop": tr["frac"], "tp": S.RR * tr["frac"]}
    rl, rs_ = P.simulate(W, 1, **kw), P.simulate(W, -1, **kw)
    fL = coin.funding(tr["entry_t"], P.exit_times(tr["entry_t"], rl["exit_k"]))
    fS = -coin.funding(tr["entry_t"], P.exit_times(tr["entry_t"], rs_["exit_k"]))
    ex = {"gL": rl["gross"], "gS": rs_["gross"], "fL": fL, "fS": fS, "rL": rl["reason"], "rS": rs_["reason"]}
    SL = QJ.seed_sides(sym, pname, "RB|" + vname, len(keep), S.S_SEEDS)
    m = np.ones(len(keep), bool)
    arr, rs, seed = QJ.rnd_variant(ex, SL, m, m, day)
    tabs_out[pre + "null"], tabs_out[pre + "null_reasons"], tabs_out[pre + "null_seed"] = arr, rs, seed
    return stat


def run_coin(work, sym: str, log=print, only_presets=None, only_variants=None, coin=None) -> tuple[dict, dict]:
    t0 = time.time()
    coin = coin if coin is not None else RD.build_coin(work, sym, tfs=("5m", "15m"))
    arrays: dict = {}
    meta: dict = {"coin": sym, "stats": {}}
    for pname in S.PRESETS:
        if only_presets and pname not in only_presets:
            continue
        for vname in S.VARIANTS:
            if only_variants and vname not in only_variants:
                continue
            meta["stats"][f"{pname}|{vname}"] = run_variant(coin, sym, pname, vname, arrays, log)
        log(f"{sym}: preset {pname} done {time.time() - t0:.0f}s")
    return arrays, meta
