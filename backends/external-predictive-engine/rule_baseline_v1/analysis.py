"""Rule Baseline v1 analysis (plan section 5): items, day-block bootstrap, BH-FDR, null comparison, per-year / per-coin, trade metrics, verdicts.

Pool = BTC + ETH + BNB + SOL (the live symbols), whole UTC-day blocks across coins. XRP / SUI report-only. All numbers are labelled
REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT in the report.
"""

from __future__ import annotations

import json
from pathlib import Path

import numpy as np

from quick_trade_replay_v1 import stats as ST
from regime_v1.data import WINDOW_LO

from . import spec as S
from .job import N_DAYS, year_of_day

YEARS = (2023, 2024, 2025)
MATERIAL, MDE_OK, FAIL_UPPER, Q = ST.MATERIAL, ST.MDE_OK, ST.FAIL_UPPER, 0.05
PRIMARY_COST = S.COSTS["meas"]
DAYS = np.arange(N_DAYS)
YEAR_MASK = [year_of_day(DAYS) == i for i in range(3)]
REASON_NAMES = ("TIME", "SL", "TP", "GAP", "STOP_TB", "AMBIG")


def load(results, coins) -> dict:
    out = {}
    for sym in coins:
        p = Path(results) / "arrays" / f"{sym}.npz"
        if p.exists():
            z = np.load(p)
            out[sym] = {k: z[k] for k in z.files}
    return out


def pooled(per_coin: dict, key: str, coins):
    arrs = [per_coin[c][key] for c in coins if c in per_coin and key in per_coin[c]]
    return np.sum(arrs, axis=0) if arrs else None


def verdict(r: dict, q, by_year: list, by_coin: dict) -> str:
    """LEAD / FAIL / INCONCLUSIVE by the frozen plan: LEAD needs q < 0.05, CI90 lower > 0, effect >= 0.03 % per trade, MDE <= 0.06 %, effect > 0 in each of
    2023 / 2024 / 2025, on BTC and on ETH, and on at least 3 of the 4 primary coins. FAIL = a powered test (MDE <= 0.06) excludes +0.03 %."""
    if r.get("effect") is None or r.get("se") is None or r.get("mde") is None:
        return "INCONCLUSIVE"
    pos = lambda v: v is not None and v > 0
    coins_pos = sum(pos(by_coin.get(c)) for c in S.PRIMARY_COINS)
    lead = (q is not None and q < Q and r["ci90"][0] > 0 and r["effect"] >= MATERIAL and r["mde"] <= MDE_OK and all(pos(v) for v in by_year)
            and pos(by_coin.get("BTCUSDT")) and pos(by_coin.get("ETHUSDT")) and coins_pos >= 3)
    if lead:
        return "LEAD"
    if r["ci90"][1] < FAIL_UPPER and r["mde"] <= MDE_OK:
        return "FAIL"
    return "INCONCLUSIVE"


def trade_table(per_coin: dict, pre: str, coins):
    cols = {k: [] for k in ("entry_t", "exit_t", "side", "R", "gross", "f", "reason", "coin")}
    for i, c in enumerate(coins):
        if c not in per_coin or pre + "tbl_gross" not in per_coin[c]:
            continue
        for k in cols:
            if k != "coin":
                cols[k].append(per_coin[c][pre + "tbl_" + k])
        cols["coin"].append(np.full(len(per_coin[c][pre + "tbl_gross"]), i))
    if not cols["gross"]:
        return None
    t = {k: np.concatenate(v) for k, v in cols.items()}
    o = np.argsort(t["entry_t"], kind="stable")
    return {k: v[o] for k, v in t.items()}


def metrics(t, cost: float) -> dict:
    """Per-trade net statistics from the trade table (cost in %)."""
    if t is None or len(t["gross"]) == 0:
        return {"n": 0}
    net = t["gross"] - cost - t["f"]
    win, loss = net[net > 0], net[net <= 0]
    eq = np.r_[0.0, np.cumsum(net)]
    blocks = len(np.unique(np.stack([t["coin"], (t["entry_t"] - WINDOW_LO) // 86_400_000], axis=1), axis=0))
    return {"n": int(len(net)), "gross": float(t["gross"].mean()), "net": float(net.mean()), "win_rate": float((net > 0).mean()),
            "avg_win": float(win.mean()) if len(win) else None, "avg_loss": float(loss.mean()) if len(loss) else None,
            "pf": float(win.sum() / -loss.sum()) if loss.sum() < 0 else None, "max_dd": float((np.maximum.accumulate(eq) - eq).max()),
            "eff_blocks": int(blocks), "mean_R": float((t["gross"] / t["R"]).mean()), "mean_stop_pct": float(t["R"].mean()),
            "reasons": {n: float((t["reason"] == i).mean()) for i, n in enumerate(REASON_NAMES)}}


def _year(t, i):
    if t is None:
        return None
    yr = year_of_day(((t["entry_t"] - WINDOW_LO) // 86_400_000).astype(np.int64))
    return {k: v[yr == i] for k, v in t.items()}


def run_all(results, log=print) -> dict:
    per_coin = load(results, S.PRIMARY_COINS + S.ROBUST_COINS)
    prim = [c for c in S.PRIMARY_COINS if c in per_coin]
    summ: dict = {"coins_loaded": list(per_coin), "primary_pool": prim, "items": {}, "stats": {}, "metrics": {}}
    arrs: dict = {}
    for pname in S.PRESETS:
        for vname in S.VARIANTS:
            pre = f"{pname}|{vname}|"
            a, nl = pooled(per_coin, pre + "day", prim), pooled(per_coin, pre + "null", prim)
            if a is None:
                continue
            arrs[pre + "S"], arrs[pre + "N"] = a, nl
            for c in per_coin:
                if pre + "day" in per_coin[c]:
                    arrs[f"{pre}S|{c}"], arrs[f"{pre}N|{c}"] = per_coin[c][pre + "day"], per_coin[c][pre + "null"]
    for pname in S.PRESETS:
        for vname in S.VARIANTS:
            pre = f"{pname}|{vname}|"
            if pre + "S" not in arrs:
                continue
            p_item = {"type": "P", "key": pre + "S"}
            d_item = {"type": "DG", "key": pre + "S", "base": pre + "N"}
            res = {}
            for kind, item in (("profit", p_item), ("vs_null", d_item)):
                r = ST.evaluate(item, arrs, PRIMARY_COST, f"RB|{pre}|{kind}")
                tms = ST.terms(item, arrs, PRIMARY_COST)
                r["by_year"] = [ST.point(tms, m) for m in YEAR_MASK]
                bc = {}
                for c in per_coin:
                    if f"{pre}S|{c}" not in arrs:
                        continue
                    it = {"type": item["type"], "key": f"{pre}S|{c}"}
                    if kind == "vs_null":
                        it["base"] = f"{pre}N|{c}"
                    bc[c] = ST.point(ST.terms(it, arrs, PRIMARY_COST))
                r["by_coin"] = bc
                res[kind] = r
            res["costs"] = {}                      # cost variants of the profit item: report-only (only 0.12 % is in the FDR family)
            for cn, cv in S.COSTS.items():
                rr = ST.evaluate(p_item, arrs, cv, f"RB|{pre}|cost{cn}")
                res["costs"][cn] = {"effect": rr["effect"], "ci90": rr.get("ci90"), "mde": rr.get("mde")}
            seeds = pooled(per_coin, pre + "null_seed", prim)
            sn = (seeds[:, 1] - seeds[:, 2] - PRIMARY_COST * seeds[:, 0]) / np.maximum(seeds[:, 0], 1e-12)
            eff = res["profit"]["effect"]
            res["p_dir"] = None if eff is None else float((1 + (sn >= eff).sum()) / (len(sn) + 1))
            res["null_mean_net"], res["null_sd"] = float(sn.mean()), float(sn.std(ddof=1))
            res["role"] = S.VARIANTS[vname]["role"]
            summ["items"][pre] = res
    keys = list(summ["items"])
    for kind in ("profit", "vs_null"):                                   # BH-FDR: family F1 (profit) and family F2 (vs null), 18 items each
        qs = ST.bh([summ["items"][k][kind]["p"] for k in keys])
        for k, q in zip(keys, qs):
            r = summ["items"][k][kind]
            r["q"] = float(q)
            r["verdict_item"] = verdict(r, q, r["by_year"], r["by_coin"])
    summ["family_size"] = len(keys)
    for pre in keys:
        t = trade_table(per_coin, pre, prim)
        summ["metrics"][pre] = {"pool": metrics(t, PRIMARY_COST), "by_year": [metrics(_year(t, i), PRIMARY_COST) for i in range(3)],
                                "by_coin": {c: metrics(trade_table(per_coin, pre, [c]), PRIMARY_COST) for c in per_coin if pre + "tbl_gross" in per_coin[c]},
                                "pool_cost": {cn: metrics(t, cv) for cn, cv in S.COSTS.items()}}
    pv = {}
    for pname in S.PRESETS:
        pre = f"{pname}|PRIMARY|"
        if pre in summ["items"]:
            a, b = summ["items"][pre]["profit"], summ["items"][pre]["vs_null"]
            pv[pname] = "LEAD" if (a["verdict_item"] == "LEAD" and b["verdict_item"] == "LEAD") else ("FAIL" if a["verdict_item"] == "FAIL" else "INCONCLUSIVE")
    summ["preset_verdicts"] = pv
    vals = list(pv.values())
    summ["overall"] = "LEAD" if "LEAD" in vals else ("FAIL" if vals and all(v == "FAIL" for v in vals) else "INCONCLUSIVE")
    meta = {}
    for c in per_coin:
        p = Path(results) / "arrays" / f"{c}.meta.json"
        if p.exists():
            meta[c] = json.loads(p.read_text("utf-8"))["stats"]
    summ["stats"] = meta
    log(f"analysis done: {len(keys)} items per family, overall {summ['overall']}")
    return summ
