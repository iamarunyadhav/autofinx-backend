"""Per-coin backtest job (plan section 5): arm decisions -> distance guard -> worst-case 5m path simulation -> greedy non-overlap chain -> per-trade table, per-day arrays,
matched random-side null; plus directional-accuracy arrays and BUY / SELL / WAIT coverage. Reuses the existing engine pieces (no copies of other experiments' code)."""

from __future__ import annotations

import time

import numpy as np

from edge_search.core import DAY, outcomes_time
from quick_trade_replay_v1 import job as QJ
from quick_trade_replay_v1 import paths as PA
from regime_v1 import data as RD

from . import params as P
from . import structure as S
from .brain import Brain

WINDOW_LO, CUT, M5 = RD.WINDOW_LO, RD.CUT, 300_000
N_DAYS, year_of_day = QJ.N_DAYS, QJ.year_of_day


def distance_ok(frac):
    with np.errstate(invalid="ignore"):
        return np.isfinite(frac) & (frac * 100.0 >= P.FLOOR_PCT - 1e-12) & (frac * 100.0 <= P.CAP_PCT + 1e-12)


def stop_fraction(side, stop_px, entry_px):
    """Stop distance as a fraction of the ENTRY price (open of the next bar)."""
    return np.where(side > 0, (entry_px - stop_px) / entry_px, (stop_px - entry_px) / entry_px)


def chain(entry_t, exit_t) -> np.ndarray:
    keep, last = [], -1
    for i in range(len(entry_t)):
        if entry_t[i] >= last:
            keep.append(i)
            last = exit_t[i]
    return np.array(keep, np.int64)


def window_mask(b, valid) -> np.ndarray:
    ct = b.close_time
    return valid & np.isfinite(b.c) & (ct >= WINDOW_LO) & (ct + P.TIMEOUT_5M * M5 <= CUT)


def candidates(b5, b, dec: dict, wmask) -> tuple[dict, dict]:
    side = dec["side"]
    stat = {"signals": int((side != 0).sum())}
    ok = (side != 0) & wmask
    stat["window_ok"] = int(ok.sum())
    sig_dist = np.where(side > 0, (b.c - dec["stop_px"]) / b.c, np.where(side < 0, (dec["stop_px"] - b.c) / b.c, np.nan))
    ok &= distance_ok(sig_dist)                          # floor / cap at the signal close (strictly causal)
    stat["after_signal_distance"] = int(ok.sum())
    idx = np.nonzero(ok)[0]
    entry_t = b.close_time[idx]
    e0 = PA.entry_index(b5, entry_t)
    E = b5.o[e0]
    sd = side[idx]
    frac = stop_fraction(sd, dec["stop_px"][idx], E)
    fine = distance_ok(frac) & np.isfinite(E) & (E > 0)  # gap guard at the actual entry price
    stat["gap_cancelled"] = int((~fine).sum())
    idx, entry_t, e0, sd, frac, E = idx[fine], entry_t[fine], e0[fine], sd[fine], frac[fine], E[fine]
    return {"idx": idx, "entry_t": entry_t, "e0": e0, "side": sd, "frac": frac, "E": E, "trigger": dec["trigger"][idx]}, stat


def simulate(coin, b5, c: dict, tp_mult: float) -> dict:
    n = len(c["idx"])
    g, k, r = np.empty(n), np.empty(n, np.int64), np.empty(n, np.int8)
    for a in range(0, n, 20_000):
        sl = slice(a, a + 20_000)
        W = PA.windows(b5, c["e0"][sl], P.TIMEOUT_5M)
        res = PA.simulate(W, c["side"][sl], stop=c["frac"][sl], tp=tp_mult * c["frac"][sl])
        g[sl], k[sl], r[sl] = res["gross"], res["exit_k"], res["reason"]
    xt = PA.exit_times(c["entry_t"], k)
    fund = coin.funding(c["entry_t"], xt) if n else np.zeros(0)
    return {"gross": g, "exit_k": k, "reason": r, "exit_t": xt, "fund": fund}


def opposing_zone_flags(brain: Brain, tr: dict) -> np.ndarray:
    """Descriptive only: an ACTIVE opposing zone (resistance for a BUY) lies between the entry and 1 R beyond it, as known at the signal bar."""
    z = brain.st[P.SIGNAL_TF]["zones"]
    out = np.zeros(len(tr["idx"]), bool)
    for i in range(len(out)):
        t, sd, E, R = int(tr["idx"][i]), int(tr["side"][i]), float(tr["E"][i]), float(tr["frac"][i])
        for zz in S.zones_at(z, t):
            if zz["status"] != "ACTIVE":
                continue
            if sd > 0 and zz["kind"] == "RESISTANCE" and E <= zz["hi"] and zz["lo"] <= E * (1 + R):
                out[i] = True
                break
            if sd < 0 and zz["kind"] == "SUPPORT" and E >= zz["lo"] and zz["hi"] >= E * (1 - R):
                out[i] = True
                break
    return out


def run_variant(coin, brain: Brain, sym: str, arm: str, dec: dict, tp_name: str, cand, tabs: dict) -> dict:
    b5 = coin.bars["5m"]
    c, stat = cand
    tp = P.TP_MULTS[tp_name]
    sim = simulate(coin, b5, c, tp)
    keep = chain(c["entry_t"], sim["exit_t"])
    stat = dict(stat)
    stat["candidates"], stat["trades"] = int(len(c["idx"])), int(len(keep))
    pre = f"{arm}|{tp_name}|"
    if len(keep) == 0:
        return stat
    tr = {k: v[keep] for k, v in c.items()}
    sm = {k: v[keep] for k, v in sim.items()}
    side = tr["side"]
    f = side * sm["fund"]
    day = ((tr["entry_t"] - WINDOW_LO) // DAY).astype(np.int64)
    tabs[pre + "day"] = QJ.day_array(day, np.ones(len(keep)), sm["gross"], f)
    oppz = opposing_zone_flags(brain, tr) if arm != "BS" else np.zeros(len(keep), bool)
    for key, val in (("entry_t", tr["entry_t"]), ("exit_t", sm["exit_t"]), ("side", side), ("R", tr["frac"] * 100.0), ("gross", sm["gross"]), ("f", f),
                     ("reason", sm["reason"]), ("entry_px", tr["E"]), ("trigger", tr["trigger"]), ("oppz", oppz), ("sig_idx", tr["idx"])):
        tabs[pre + "tbl_" + key] = val
    W = PA.windows(b5, tr["e0"], P.TIMEOUT_5M)
    kw = {"stop": tr["frac"], "tp": tp * tr["frac"]}
    rl, rs_ = PA.simulate(W, 1, **kw), PA.simulate(W, -1, **kw)
    fL = coin.funding(tr["entry_t"], PA.exit_times(tr["entry_t"], rl["exit_k"]))
    fS = -coin.funding(tr["entry_t"], PA.exit_times(tr["entry_t"], rs_["exit_k"]))
    ex = {"gL": rl["gross"], "gS": rs_["gross"], "fL": fL, "fS": fS, "rL": rl["reason"], "rS": rs_["reason"]}
    SL = QJ.seed_sides(sym, "F", "EF|" + pre, len(keep), P.S_SEEDS)
    m = np.ones(len(keep), bool)
    arr, rs, seed = QJ.rnd_variant(ex, SL, m, m, day)
    tabs[pre + "null"], tabs[pre + "null_reasons"], tabs[pre + "null_seed"] = arr, rs, seed
    return stat


def directional(b, dec: dict, wmask_dir: dict, arm: str, tabs: dict) -> None:
    """Hit rate of BUY / SELL decisions vs the forward return sign (entry = next 15m open) at 15m / 1h / 4h. excess = hit - 0.5 x (non-zero move): the hit rate a
    random side would have on the SAME entries. Per-day arrays [n, sum excess, 0] and [n, hits, non-zero] (all signals, overlapping, descriptive)."""
    side = dec["side"]
    for hname, H in P.DIR_HORIZONS.items():
        o = outcomes_time(b, H)
        ok = (side != 0) & wmask_dir[H] & np.isfinite(o.g_long)
        idx = np.nonzero(ok)[0]
        g = o.g_long[idx] * side[idx]
        nz = (g != 0).astype(float)
        hit = (g > 0).astype(float)
        day = ((b.close_time[idx] - WINDOW_LO) // DAY).astype(np.int64)
        ones = np.ones(len(idx))
        tabs[f"dir|{arm}|{hname}|day"] = QJ.day_array(day, ones, hit - 0.5 * nz, np.zeros(len(idx)))
        tabs[f"dir|{arm}|{hname}|hits"] = QJ.day_array(day, ones, hit, nz)
        up = (o.g_long[idx] > 0).astype(float)
        tabs[f"dir|{arm}|{hname}|upshare"] = np.array([len(idx), up.sum()], float)


def coverage(b, dec: dict, wmask) -> dict:
    m = wmask
    side, wait = dec["side"][m], dec["wait"][m]
    out = {"bars": int(m.sum()), "BUY": int((side > 0).sum()), "SELL": int((side < 0).sum()), "WAIT": int((side == 0).sum())}
    out["wait_reasons"] = {P.WAIT_NAMES[r]: int(((wait == r) & (side == 0)).sum()) for r in (1, 2, 3, 4, 5)}
    out["by_trigger"] = {"BOS": int(((dec["trigger"][m]) == 1).sum()), "CHOCH_CONFIRMED": int(((dec["trigger"][m]) == 2).sum())}
    return out


def run_coin(work, sym: str, log=print, only_arms=None, coin=None) -> tuple[dict, dict]:
    t0 = time.time()
    coin = coin if coin is not None else RD.build_coin(work, sym, tfs=("5m", "15m", "1h", "4h"))
    b5, b = coin.bars["5m"], coin.bars["15m"]
    valid24 = outcomes_time(b, P.TIMEOUT_5M // 3).valid
    wmask = window_mask(b, valid24)
    wdir = {H: window_mask(b, outcomes_time(b, H).valid) for H in P.DIR_HORIZONS.values()}
    arrays: dict = {}
    meta: dict = {"coin": sym, "stats": {}, "coverage": {}, "structure": {}}
    for sens in (False, True):
        brain = Brain(coin, sens)
        log(f"{sym}: structure built sens={sens} {time.time() - t0:.0f}s")
        for arm in [a for a, cfg in P.ARMS.items() if cfg["sens"] == sens]:
            if only_arms and arm not in only_arms:
                continue
            dec = brain.decisions(arm)
            meta["coverage"][arm] = coverage(b, dec, wmask)
            directional(b, dec, wdir, arm, arrays)
            cand = candidates(b5, b, dec, wmask)
            for tp_name in P.TP_MULTS:
                meta["stats"][f"{arm}|{tp_name}"] = run_variant(coin, brain, sym, arm, dec, tp_name, cand, arrays)
            log(f"{sym}: arm {arm} done {time.time() - t0:.0f}s")
        if not sens:
            st = {tf: {"swings": int(len(brain.st[tf]["swings"]["kind"])), "bos": int((brain.st[tf]["bos"] != 0).sum()),
                       "choch_confirmed": int((brain.st[tf]["ch_conf"] != 0).sum()), "choch_potential": int((brain.st[tf]["ch_pot"] != 0).sum()),
                       "trend_share": {P.TREND_NAMES[v]: float((brain.st[tf]["trend"] == v).mean()) for v in (1, -1, 0, 2)}} for tf in P.TFS}
            meta["structure"] = st
    return arrays, meta
