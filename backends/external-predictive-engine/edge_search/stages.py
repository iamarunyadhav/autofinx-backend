"""Stages 1–4 for the rule families (EDGE_SEARCH_V1.md Part B4–B7). Every threshold below is frozen in the plan."""

from __future__ import annotations

import datetime as dt
import json
from pathlib import Path

import numpy as np
from scipy import stats as sps

from . import rules
from .core import (COST_BASE, COST_STRESS_A, DAY, GROSS_MIN, HS, MIN, SYMBOLS, World, bh_adjust, bh_reject, block_ci, clean,
                   describe, net_all, outcomes_atr, outcomes_time, random_null, select_trades, windows, write_json)

N_MIN, N_MIN_LOW = 100, 40
FOLDS, FOLD_MIN_TRADES, FOLD_POS_SHARE = 5, 10, 0.60
YEAR_MIN_TRADES = 15
NULL_P, T_DEV, T_VAL, T_HOLD = 0.01, 3.0, 2.0, 1.65
EXITS = ("time", "atr")


def n_min(tf: str, family: str) -> int:
    return N_MIN_LOW if (tf == "1d" or family == "F7") else N_MIN


class Runtime:
    """World + caches shared by the jobs of one process."""

    def __init__(self, work: Path, results: Path, world: World, seg: dict, plan_sha: str):
        self.work, self.results, self.world, self.seg, self.plan_sha = Path(work), Path(results), world, seg, plan_sha
        self.specs = rules.build_specs()
        self.by_sid = {s.sid: s for s in self.specs}
        self._out: dict = {}
        self._sig: dict = {}
        self.ctx_built: set = set()

    def outcomes(self, coin: str, tf: str, H: int, mode: str):
        key = (coin, tf, H, mode)
        if key not in self._out:
            if len(self._out) > 30:
                self._out.clear()
            b = self.world.coins[coin].bars[tf]
            self._out[key] = outcomes_time(b, H) if mode == "time" else outcomes_atr(b, H, 1.5)
        return self._out[key]

    def signal(self, spec, coin: str, tf: str):
        key = (spec.sid, coin, tf)
        if key not in self._sig:
            if len(self._sig) > 400:
                self._sig.clear()
            self._sig[key] = rules.signal(spec, self.world, coin, tf)
        return self._sig[key]

    def ensure_ctx(self, coin: str) -> None:
        """Build ctx.parquet for a coin lazily (needed for F5 consistency checks on the other coins)."""
        c = self.world.coins[coin]
        if c.ctx("ctx_funding_last", "1h") is not None or coin in self.ctx_built:
            return
        from v4 import p4_context
        try:
            p4_context.build(self.work, coin)
        except Exception:
            pass
        self.ctx_built.add(coin)
        c._ctx, c._ctx_pos = None, {}


def delayed(sig: np.ndarray, k: int) -> np.ndarray:
    if k <= 0:
        return sig
    out = np.zeros_like(sig)
    out[k:] = sig[:-k]
    return out


# ------------------------------------------------------------------------------------------ stage 1
def _seg_stats(tr, coin, b, tf_min) -> dict:
    n = len(tr)
    net = tr.net(coin, COST_BASE)
    g, dn = describe(tr.gross), describe(net)
    st = {"n": n, "mean_gross": g["mean"], "sd_gross": g["sd"], "t_gross": g["t"], "p1_gross": g["p1"], "mde_gross": g["mde"],
          "hit": g["hit"], "mean_net": dn["mean"], "t_net": dn["t"]}
    if n:
        lg, sh = tr.side > 0, tr.side < 0
        st.update({"n_long": int(lg.sum()), "n_short": int(sh.sum()),
                   "mean_gross_long": float(tr.gross[lg].mean()) if lg.any() else None,
                   "mean_gross_short": float(tr.gross[sh].mean()) if sh.any() else None})
        if n >= 30 and lg.any() and sh.any():
            try:
                st["ic"] = float(sps.spearmanr(tr.side, tr.side * tr.gross).statistic)
            except Exception:
                st["ic"] = None
    return st


def stage1_job(rt: Runtime, family: str, name: str, tf: str, coin: str) -> list[dict]:
    specs = [s for s in rt.specs if s.family == family and s.name == name and tf in s.tfs and coin in s.coins]
    c = rt.world.coins[coin]
    b = c.bars[tf]
    win = windows(rt.seg, coin)
    items = []
    for spec in specs:
        sig = rt.signal(spec, coin, tf)
        for H in rules.hs_for(spec, tf):
            rec = {"item_id": f"{spec.sid}@{coin}/{tf}/H{H}", "family": family, "name": name, "label": spec.label,
                   "coin": coin, "tf": tf, "H": H}
            if sig is None or not (sig != 0).any():
                rec["s1_status"] = "INCONCLUSIVE"
                rec["s1_reason"] = "NO_DATA" if sig is None else "NO_SIGNALS"
                items.append(rec)
                continue
            out = rt.outcomes(coin, tf, H, "time")
            rec["n_signal_bars"] = int((sig != 0).sum())
            for seg_name in ("dev", "val"):
                tr = select_trades(sig, out, b.close_time, *win[seg_name])
                rec[seg_name] = _seg_stats(tr, c, b, b.tf_min)
            items.append(rec)
    return items


def stage1_decide(items: list[dict]) -> dict:
    """BH-FDR over ALL scanned items, then the survivor rule; verdicts for the rest."""
    p = np.array([(it.get("dev") or {}).get("p1_gross") if (it.get("dev") or {}).get("n", 0) >= 30 else 1.0 for it in items], float)
    p = np.where(np.isfinite(p), p, 1.0)
    rej, adj = bh_reject(p), bh_adjust(p)
    surv = 0
    for it, pi, r, a in zip(items, p, rej, adj):
        it["p_dev"], it["p_bh"], it["fdr_reject"] = float(pi), float(a), bool(r)
        if it.get("s1_status"):
            continue
        d, v = it["dev"], it["val"]
        reasons = []
        if not r:
            reasons.append("S1_NOT_FDR")
        if (d["mean_gross"] or -1) < GROSS_MIN:
            reasons.append("S1_GROSS_BELOW_0.06")
        if (v["n"] or 0) < 30:
            reasons.append("S1_VAL_N<30")
        elif (v["mean_gross"] or -1) <= 0:
            reasons.append("S1_VAL_SIGN")
        if not reasons:
            it["s1_status"], it["s1_reason"] = "SURVIVOR", "OK"
            surv += 1
        else:
            under = d["n"] < 30 or (d["mde_gross"] or 9) > COST_BASE
            it["s1_status"] = "INCONCLUSIVE" if under else "FAIL"
            it["s1_reason"] = ("S1_N<30" if d["n"] < 30 else "S1_UNDERPOWERED") if under else ",".join(reasons)
    return {"n_items": len(items), "n_fdr_reject": int(rej.sum()), "n_survivors": surv}


# ------------------------------------------------------------------------------------------ stage 2
def _equity_stats(net: np.ndarray, tr, tf_min: int) -> dict:
    if len(net) == 0:
        return {}
    gp, gl = net[net > 0].sum(), -net[net < 0].sum()
    eq = np.cumsum(net)
    return {"pf": float(gp / gl) if gl > 0 else None, "win": float((net > 0).mean()),
            "max_dd": float((np.maximum.accumulate(np.r_[0.0, eq])[1:] - eq).max()),
            "avg_hold_min": float(np.mean(tr.exit_t - tr.entry_t) / MIN)}


def _year_month(t_ms):
    d = np.asarray(t_ms, np.int64).astype("datetime64[ms]")
    return d.astype("datetime64[Y]").astype(int) + 1970, d.astype("datetime64[M]").astype(int)


def trades_for(rt, spec, coin, tf, H, mode, seg_name, cost=COST_BASE, delay=0, sig=None):
    c = rt.world.coins[coin]
    b = c.bars[tf]
    sig = rt.signal(spec, coin, tf) if sig is None else sig
    if sig is None:
        return None, None
    tr = select_trades(delayed(sig, delay), rt.outcomes(coin, tf, H, mode), b.close_time, *windows(rt.seg, coin)[seg_name])
    return tr, tr.net(c, cost)


def seg_report(net: np.ndarray, entry_t: np.ndarray, lo: int, hi: int, exit_t=None) -> dict:
    """Frozen per-segment statistics shared by the rule families, F7 and F8."""
    net = np.asarray(net, float)
    d = describe(net)
    r = {"n": d["n"], "mean_net": d["mean"], "t": d["t"], "mde_net": d["mde"], "sd": d["sd"]}
    if d["n"] < 2:
        return r
    entry_t = np.asarray(entry_t, np.int64)
    r["ci95"] = block_ci(net, entry_t)
    gp, gl = net[net > 0].sum(), -net[net < 0].sum()
    eq = np.cumsum(net)
    r.update({"pf": float(gp / gl) if gl > 0 else None, "win": float((net > 0).mean()),
              "max_dd": float((np.maximum.accumulate(np.r_[0.0, eq])[1:] - eq).max())})
    if exit_t is not None:
        r["avg_hold_min"] = float(np.mean(np.asarray(exit_t) - entry_t) / MIN)
    edges = np.linspace(lo, hi, FOLDS + 1)
    folds = []
    for x, z in zip(edges[:-1], edges[1:]):
        m = (entry_t >= x) & (entry_t <= z)
        folds.append({"n": int(m.sum()), "mean": float(net[m].mean()) if m.sum() else None})
    valid = [f for f in folds if f["n"] >= FOLD_MIN_TRADES]
    r["folds"], r["folds_valid"] = folds, len(valid)
    r["fold_pos_share"] = float(np.mean([f["mean"] > 0 for f in valid])) if valid else None
    yrs, _ = _year_month(entry_t)
    ys = {int(y): {"n": int((yrs == y).sum()), "mean": float(net[yrs == y].mean())} for y in np.unique(yrs)}
    vy = [v for v in ys.values() if v["n"] >= YEAR_MIN_TRADES]
    r["years"], r["years_valid"], r["years_pos"] = ys, len(vy), sum(1 for v in vy if v["mean"] > 0)
    return r


def judge(dev: dict, val: dict, nm: int) -> tuple[dict, str, list]:
    """Frozen DEV + VAL pass rules (Part A section 5, Part B5). INCONCLUSIVE = failed while underpowered."""
    chk = {
        "dev_n": dev["n"] >= nm,
        "dev_net_pos": (dev["mean_net"] if dev["mean_net"] is not None else -1) > 0,
        "dev_t": (dev["t"] if dev["t"] is not None else -9) >= T_DEV,
        "dev_folds": dev.get("folds_valid", 0) >= 3 and (dev.get("fold_pos_share") or 0) >= FOLD_POS_SHARE,
        "dev_years": dev.get("years_valid", 0) >= 2 and dev.get("years_pos", 0) >= 2,
        "dev_ci": bool(dev.get("ci95")) and dev["ci95"][0] > 0,
        "dev_null": (dev["null_p"] if dev.get("null_p") is not None else 1.0) < NULL_P,
        "val_n": val["n"] >= 30,
        "val_net_pos": (val["mean_net"] if val["mean_net"] is not None else -1) > 0,
        "val_t": (val["t"] if val["t"] is not None else -9) >= T_VAL,
        "val_half": bool(val["mean_net"] is not None and dev["mean_net"] is not None and dev["mean_net"] > 0
                         and val["mean_net"] >= 0.5 * dev["mean_net"]),
    }
    failed = [k for k, v in chk.items() if not v]
    if not failed:
        return chk, "PASS", []
    under = dev["n"] < nm or (dev["mde_net"] if dev["mde_net"] is not None else 9) > COST_BASE or val["n"] < 30
    return chk, ("INCONCLUSIVE" if under else "FAIL"), [("UNDERPOWERED:" if under else "") + k for k in failed]


def stage2(rt: Runtime, item: dict, mode: str) -> dict:
    spec = rt.by_sid[item["item_id"].split("@")[0]]
    coin, tf, H = item["coin"], item["tf"], item["H"]
    c = rt.world.coins[coin]
    b = c.bars[tf]
    win = windows(rt.seg, coin)
    nm = n_min(tf, spec.family)
    res = {"cid": f"{item['item_id']}#{mode}", "item_id": item["item_id"], "mode": mode, "family": spec.family, "coin": coin,
           "tf": tf, "H": H, "label": spec.sid}
    tr, net = trades_for(rt, spec, coin, tf, H, mode, "dev")
    lo, hi = win["dev"]
    dev = seg_report(net, tr.entry_t, lo, hi, tr.exit_t)
    dev["mean_gross"] = float(tr.gross.mean()) if len(tr) else None
    if len(tr):
        dev["fees_pct"] = COST_BASE
        dev["avg_funding_pct"] = float(np.mean(tr.side * c.funding(tr.entry_t, tr.exit_t)))
        for nm_, m in (("long", tr.side > 0), ("short", tr.side < 0)):
            dev[f"{nm_}_n"], dev[f"{nm_}_mean_net"] = int(m.sum()), float(net[m].mean()) if m.any() else None
        out = rt.outcomes(coin, tf, H, mode)
        nl, ns = net_all(out, c, b, COST_BASE)
        lo_i = int(np.searchsorted(b.close_time, lo, "left"))
        hi_i = int(np.searchsorted(b.close_time, hi - H * b.tf_ms, "right")) - 1
        null = random_null(nl, ns, out.valid, lo_i, hi_i, len(tr), float((tr.side > 0).mean()), H)
        dev["null_mean"] = float(null.mean()) if len(null) else None
        dev["null_p"] = float((1 + (null >= dev["mean_net"]).sum()) / (len(null) + 1)) if len(null) else None
    trv, netv = trades_for(rt, spec, coin, tf, H, mode, "val")
    val = seg_report(netv, trv.entry_t, *win["val"], trv.exit_t)
    val["mean_gross"] = float(trv.gross.mean()) if len(trv) else None
    res["dev"], res["val"] = dev, val
    res["checks"], res["verdict"], res["reasons"] = judge(dev, val, nm)
    return res


# ------------------------------------------------------------------------------------------ stage 3
def btc_regime(rt: Runtime, t_ms: np.ndarray) -> np.ndarray:
    b = rt.world.coins["BTCUSDT"].bars["1d"]
    ratio = b.c / __import__("v4.strategy_v13", fromlist=["sma"]).sma(b.c, 200) - 1.0
    i = np.searchsorted(b.close_time, t_ms, side="right") - 1
    r = np.where(i >= 0, ratio[np.maximum(i, 0)], np.nan)
    return np.where(~np.isfinite(r), "na", np.where(r > 0.05, "bull", np.where(r < -0.05, "bear", "range")))


def stage3(rt: Runtime, cand: dict) -> dict:
    spec = rt.by_sid[cand["item_id"].split("@")[0]]
    coin, tf, H, mode = cand["coin"], cand["tf"], cand["H"], cand["mode"]
    c = rt.world.coins[coin]
    out = {"cid": cand["cid"], "checks": {}, "detail": {}}
    # pooled DEV+VAL trades
    td, nd = trades_for(rt, spec, coin, tf, H, mode, "dev")
    tv, nv = trades_for(rt, spec, coin, tf, H, mode, "val")
    pool = np.r_[nd, nv]
    t_pool = np.r_[td.entry_t, tv.entry_t]
    g_pool = np.r_[td.gross, tv.gross]
    out["detail"]["pooled"] = {"n": len(pool), "mean_net": float(pool.mean()) if len(pool) else None}
    # stress A
    na = np.r_[td.net(c, COST_STRESS_A), tv.net(c, COST_STRESS_A)]
    out["detail"]["stress_A_mean_net"] = float(na.mean()) if len(na) else None
    out["checks"]["stress_A"] = len(na) > 0 and na.mean() >= 0
    # stress B (entry one extra bar later)
    sig = rt.signal(spec, coin, tf)
    tdb, ndb = trades_for(rt, spec, coin, tf, H, mode, "dev", delay=1, sig=sig)
    tvb, nvb = trades_for(rt, spec, coin, tf, H, mode, "val", delay=1, sig=sig)
    nb = np.r_[ndb, nvb]
    out["detail"]["stress_B_mean_net"] = float(nb.mean()) if len(nb) else None
    out["checks"]["stress_B"] = len(nb) > 0 and nb.mean() >= 0
    # shift test: delaying the signal must not improve gross by more than one SE
    gd = describe(td.gross)
    gb = float(tdb.gross.mean()) if len(tdb) else -9
    out["detail"]["shift"] = {"gross": gd["mean"], "gross_delayed": gb}
    out["checks"]["shift_test"] = not (gd["sd"] and gb - gd["mean"] > gd["sd"] / np.sqrt(gd["n"]))
    # parameter neighbours
    nb_res = []
    for ns_ in rules.neighbours(spec, rt.specs):
        if coin in ns_.coins and tf in ns_.tfs and H in rules.hs_for(ns_, tf):
            t_, n_ = trades_for(rt, ns_, coin, tf, H, mode, "dev")
            if t_ is not None and len(n_) >= 30:
                nb_res.append({"sid": ns_.sid, "n": len(n_), "mean_net": float(n_.mean())})
    out["detail"]["neighbours"] = nb_res
    out["checks"]["neighbours"] = (not nb_res) or (np.mean([r["mean_net"] > 0 for r in nb_res]) >= 2 / 3)
    # concentration
    if len(pool) and pool.sum() > 0:
        _, mons = _year_month(t_pool)
        share = max(float(pool[mons == m].sum()) for m in np.unique(mons)) / float(pool.sum())
        out["detail"]["max_month_share"] = share
        out["checks"]["concentration"] = share <= 0.25
    else:
        out["detail"]["max_month_share"] = None
        out["checks"]["concentration"] = False
    # regime split
    reg = btc_regime(rt, t_pool)
    rg = {r: {"n": int((reg == r).sum()), "mean": float(pool[reg == r].mean())} for r in ("bull", "bear", "range") if (reg == r).sum() >= 20}
    out["detail"]["regimes"] = rg
    out["checks"]["regime"] = True if len(rg) < 2 else sum(1 for v in rg.values() if v["mean"] > 0) >= 2
    # coin consistency
    cons, reasons = {}, {}
    for s2 in SYMBOLS:
        if spec.need and "ctx" in spec.need:
            rt.ensure_ctx(s2)
        if spec.name == "btc_leads" and s2 != "ETHUSDT":
            reasons[s2] = "rule defined for ETH only"
            continue
        sg = rt.signal(spec, s2, tf)
        if sg is None:
            reasons[s2] = "required data not available for this coin"
            continue
        t1, n1 = trades_for(rt, spec, s2, tf, H, mode, "dev", sig=sg)
        t2, n2 = trades_for(rt, spec, s2, tf, H, mode, "val", sig=sg)
        nn = np.r_[n1, n2]
        if len(nn) < 30:
            reasons[s2] = f"only {len(nn)} trades"
            continue
        cons[s2] = {"n": len(nn), "mean_net": float(nn.mean())}
    out["detail"]["coins"], out["detail"]["coin_skipped"] = cons, reasons
    pos = sum(1 for v in cons.values() if v["mean_net"] > 0)
    out["checks"]["coin_consistency"] = pos >= 3
    out["inconclusive_coins"] = len(cons) < 3
    failed = [k for k, v in out["checks"].items() if not v]
    out["verdict"] = "PASS" if not failed else ("INCONCLUSIVE" if failed == ["coin_consistency"] and len(cons) < 3 else "FAIL")
    out["reasons"] = failed
    return out


# ------------------------------------------------------------------------------------------ stage 4
class HoldoutGate:
    """The only door to the holdout: logs every access, refuses a second read of the same candidate."""

    def __init__(self, results: Path, plan_sha: str):
        self.path, self.plan_sha = Path(results) / "holdout_reads.json", plan_sha

    def _load(self) -> dict:
        return json.loads(self.path.read_text("utf-8")) if self.path.exists() else {"reads": {}}

    def read(self, cid: str, fn):
        reg = self._load()
        if cid in reg["reads"]:
            raise RuntimeError(f"holdout already read for candidate {cid}")
        reg["reads"][cid] = {"utc": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), "plan_sha": self.plan_sha}
        write_json(self.path, reg)                      # logged BEFORE the read: a crash still counts as a read
        result = fn()
        reg["reads"][cid]["result"] = clean(result)
        write_json(self.path, reg)
        return result


def stage4(work: Path, results: Path, seg: dict, plan_sha: str, cand: dict) -> dict:
    gate = HoldoutGate(results, plan_sha)

    def read():
        world = World(work, seg, include_hold=True)
        rt = Runtime(work, results, world, seg, plan_sha)
        spec = rt.by_sid[cand["item_id"].split("@")[0]]
        coin, tf, H, mode = cand["coin"], cand["tf"], cand["H"], cand["mode"]
        c, b = world.coins[coin], world.coins[coin].bars[tf]
        sig = rt.signal(spec, coin, tf)
        tr = select_trades(sig, rt.outcomes(coin, tf, H, mode), b.close_time, *windows(seg, coin)["hold"])
        net = tr.net(c, COST_BASE)
        d = describe(net)
        ok = len(net) >= 2 and d["mean"] > 0 and (d["t"] or -9) >= T_HOLD
        return {"n": d["n"], "mean_net": d["mean"], "t": d["t"], "pass": bool(ok)}
    return gate.read(cand["cid"], read)
