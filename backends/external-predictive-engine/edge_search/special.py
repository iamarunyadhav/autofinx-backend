"""F7 funding carry (delta-neutral spot + perp), F8 ML on stage-1 survivor families, benchmarks, cost wall, data coverage."""

from __future__ import annotations

import datetime as dt
from pathlib import Path

import numpy as np

from v4 import binance_data as bd
from v4 import premium as premium_mod
from v4 import strategy_v13 as v13
from v4.download import _unzip_text

from . import rules
from .core import (COST_BASE, DAY, FEE, HOUR, M5, MIN, SLIP, SYMBOLS, World, block_ci, describe, net_all, outcomes_time,
                   random_null, select_trades, windows)
from .stages import (FOLDS, Runtime, btc_regime, judge, n_min, seg_report, trades_for, _year_month)

SPOT_FEE = 0.10                    # % per side, Binance spot VIP0 taker (assumption, stated in the plan)
CARRY_IN = (0.01, 0.02, 0.04)      # % funding per 8h event required to enter
CARRY_OUT = (0.0, 0.005)
MAX_EVENTS = 90                    # 30 days


def carry_cost(slip: float = SLIP) -> float:
    return 2 * (FEE + slip) + 2 * (SPOT_FEE + slip)


# ------------------------------------------------------------------------------------------ spot data
def spot_dir(work: Path, symbol: str) -> Path:
    return Path(work) / "data" / symbol / "spot1h"


def download_spot(work: Path, symbol: str, months: int, today: dt.date | None = None) -> dict:
    today = today or dt.datetime.now(dt.timezone.utc).date()
    base = spot_dir(work, symbol)
    root = "https://data.binance.vision/data/spot"
    jobs = [("monthly", m, f"{root}/monthly/klines/{symbol}/1h/{symbol}-1h-{m}.zip") for m in bd.month_list(months, today)]
    jobs += [("daily", d, f"{root}/daily/klines/{symbol}/1h/{symbol}-1h-{d}.zip") for d in bd.current_month_days(today)]
    got = {"files": 0, "rows": 0, "missing": 0}
    for kind, name, url in jobs:
        f = base / kind / f"{name}.parquet"
        if f.exists() or (base / kind / f"{name}.missing").exists():
            continue
        (base / kind).mkdir(parents=True, exist_ok=True)
        try:
            payload, _ = bd.fetch_verified_zip(url)
        except bd.NotFound:
            (base / kind / f"{name}.missing").write_text("404", "utf-8")
            got["missing"] += 1
            continue
        data = bd.parse_klines_csv(_unzip_text(payload))
        bd.write_parquet(f, data)
        got["files"] += 1
        got["rows"] += len(data["open_time"])
    return got


def spot_open_on_grid(work: Path, symbol: str, t0: int, n_hours: int) -> np.ndarray:
    base = spot_dir(work, symbol)
    files = sorted((base / "monthly").glob("*.parquet")) + sorted((base / "daily").glob("*.parquet"))
    out = np.full(n_hours, np.nan)
    if not files:
        return out
    d = bd.concat(bd.read_parquet(f) for f in files)
    idx = ((d["open_time"] - t0) // HOUR).astype(np.int64)
    ok = (idx >= 0) & (idx < n_hours)
    out[idx[ok]] = np.asarray(d["open"], float)[ok]
    return out


# ------------------------------------------------------------------------------------------ F7
def carry_trades(coin, spot_o: np.ndarray, thr_in: float, thr_out: float, lo: int, hi: int, slip: float = SLIP,
                 extra_delay_h: int = 0) -> dict:
    """Decision at funding event i with the published rate; enter (long spot / short perp) at the next hourly open;
    receive the later events' rates; exit at the hourly open after an event whose rate fell below thr_out, or after
    MAX_EVENTS events. PnL in % of one leg's notional."""
    ft, fr = coin.f_t, coin.f_r
    P = coin.bars["1h"]
    t0, nb = P.t0, P.n
    entry, exitt, net, recv_l, basis_l = [], [], [], [], []
    cost = carry_cost(slip)
    i, n = 0, len(ft)
    while i < n:
        if ft[i] < lo or fr[i] < thr_in:
            i += 1
            continue
        te = ft[i] + HOUR * (1 + extra_delay_h)
        j, recv = i + 1, 0.0
        while j < n and (j - i) <= MAX_EVENTS:
            recv += fr[j]
            if fr[j] < thr_out:
                break
            j += 1
        if j >= n:
            break
        tx = ft[j] + HOUR
        if tx > hi:
            break
        ie, ix = int((te - t0) // HOUR), int((tx - t0) // HOUR)
        if not (0 <= ie < nb and 0 <= ix < nb) or not np.isfinite([P.o[ie], P.o[ix], spot_o[ie], spot_o[ix]]).all():
            i = j + 1
            continue
        basis = ((spot_o[ix] / spot_o[ie] - 1.0) - (P.o[ix] / P.o[ie] - 1.0)) * 100.0
        entry.append(te)
        exitt.append(tx)
        recv_l.append(recv)
        basis_l.append(basis)
        net.append(recv + basis - cost)
        i = j + 1
    e = np.array(entry, np.int64)
    return {"entry_t": e, "exit_t": np.array(exitt, np.int64), "net": np.array(net), "recv": np.array(recv_l),
            "basis": np.array(basis_l), "n_events": None}


def carry_null(coin, spot_o, durations_h: np.ndarray, lo: int, hi: int, cost: float, n_mc: int = 1000, seed: int = 5):
    """Unconditional carry: random event, same holding durations (hours), same cost."""
    ft, fr = coin.f_t, coin.f_r
    P = coin.bars["1h"]
    cum = np.r_[0.0, np.cumsum(fr)]
    ev = np.nonzero((ft >= lo) & (ft + HOUR * (durations_h.max() + 2) <= hi))[0]
    if len(ev) < 10 or len(durations_h) < 1:
        return np.zeros(0)
    rng = np.random.default_rng(seed)
    out = np.empty(n_mc)
    for r in range(n_mc):
        s = rng.choice(ev, len(durations_h), replace=True)
        d = rng.permutation(durations_h)
        te, tx = ft[s] + HOUR, ft[s] + HOUR * (1 + d)
        ie, ix = ((te - P.t0) // HOUR).astype(int), ((tx - P.t0) // HOUR).astype(int)
        ok = (ix < P.n) & (ie >= 0)
        a, b = np.searchsorted(ft, te, "right"), np.searchsorted(ft, tx, "right")
        recv = cum[b] - cum[a]
        with np.errstate(invalid="ignore", divide="ignore"):
            basis = ((spot_o[ix.clip(0, P.n - 1)] / spot_o[ie.clip(0, P.n - 1)] - 1) - (P.o[ix.clip(0, P.n - 1)] / P.o[ie.clip(0, P.n - 1)] - 1)) * 100
        v = (recv + basis - cost)[ok]
        out[r] = np.nanmean(v) if v.size else np.nan
    return out[np.isfinite(out)]


def f7_cell(rt: Runtime, spot: dict, coin_name: str, thr_in: float, thr_out: float) -> dict:
    seg = rt.seg
    win = windows(seg, coin_name)
    c = rt.world.coins[coin_name]
    so = spot[coin_name]
    res = {"cid": f"F7.carry[in{thr_in}/out{thr_out}]@{coin_name}/1h", "family": "F7", "coin": coin_name, "tf": "1h", "H": None,
           "label": f"F7.carry[in{thr_in}/out{thr_out}]", "thr_in": thr_in, "thr_out": thr_out}
    seg_trades = {}
    for sn in ("dev", "val"):
        t = carry_trades(c, so, thr_in, thr_out, *win[sn])
        seg_trades[sn] = t
    dev = seg_report(seg_trades["dev"]["net"], seg_trades["dev"]["entry_t"], *win["dev"], seg_trades["dev"]["exit_t"])
    val = seg_report(seg_trades["val"]["net"], seg_trades["val"]["entry_t"], *win["val"], seg_trades["val"]["exit_t"])
    td = seg_trades["dev"]
    if len(td["net"]) >= 2:
        dev["avg_funding_pct"] = float(td["recv"].mean())
        dev["avg_basis_pct"] = float(td["basis"].mean())
        dur = np.maximum(1, np.round((td["exit_t"] - td["entry_t"]) / HOUR)).astype(int)
        null = carry_null(c, so, dur, win["dev"][0], win["dev"][1], carry_cost())
        dev["null_mean"] = float(null.mean()) if len(null) else None
        dev["null_p"] = float((1 + (null >= dev["mean_net"]).sum()) / (len(null) + 1)) if len(null) else None
    res["dev"], res["val"] = dev, val
    res["checks"], res["verdict"], res["reasons"] = judge(dev, val, n_min("1d", "F7"))
    return res


def f7_stage3(rt: Runtime, spot: dict, res: dict) -> dict:
    coin_name, thr_in, thr_out = res["coin"], res["thr_in"], res["thr_out"]
    c = rt.world.coins[coin_name]
    win = windows(rt.seg, coin_name)
    out = {"cid": res["cid"], "checks": {}, "detail": {}}

    def pooled(**kw):
        a = carry_trades(c, spot[coin_name], thr_in, thr_out, *win["dev"], **kw)
        b = carry_trades(c, spot[coin_name], thr_in, thr_out, *win["val"], **kw)
        return np.r_[a["net"], b["net"]], np.r_[a["entry_t"], b["entry_t"]]
    base_net, base_t = pooled()
    out["detail"]["pooled_n"] = len(base_net)
    a_net, _ = pooled(slip=2 * SLIP)
    out["detail"]["stress_A_mean_net"] = float(a_net.mean()) if len(a_net) else None
    out["checks"]["stress_A"] = len(a_net) > 0 and a_net.mean() >= 0
    b_net, _ = pooled(extra_delay_h=1)
    out["detail"]["stress_B_mean_net"] = float(b_net.mean()) if len(b_net) else None
    out["checks"]["stress_B"] = len(b_net) > 0 and b_net.mean() >= 0
    nb = []
    for ni, no in ((CARRY_IN[max(0, CARRY_IN.index(thr_in) - 1)], thr_out), (CARRY_IN[min(2, CARRY_IN.index(thr_in) + 1)], thr_out),
                   (thr_in, CARRY_OUT[1 - CARRY_OUT.index(thr_out)])):
        if (ni, no) != (thr_in, thr_out):
            a = carry_trades(c, spot[coin_name], ni, no, *win["dev"])
            if len(a["net"]) >= 10:
                nb.append({"in": ni, "out": no, "n": len(a["net"]), "mean_net": float(a["net"].mean())})
    out["detail"]["neighbours"] = nb
    out["checks"]["neighbours"] = (not nb) or np.mean([r["mean_net"] > 0 for r in nb]) >= 2 / 3
    if len(base_net) and base_net.sum() > 0:
        _, mons = _year_month(base_t)
        share = max(float(base_net[mons == m].sum()) for m in np.unique(mons)) / float(base_net.sum())
        out["detail"]["max_month_share"] = share
        out["checks"]["concentration"] = share <= 0.25
    else:
        out["checks"]["concentration"] = False
    reg = btc_regime(rt, base_t)
    rg = {r: {"n": int((reg == r).sum()), "mean": float(base_net[reg == r].mean())} for r in ("bull", "bear", "range") if (reg == r).sum() >= 20}
    out["detail"]["regimes"] = rg
    out["checks"]["regime"] = True if len(rg) < 2 else sum(1 for v in rg.values() if v["mean"] > 0) >= 2
    cons = {}
    for s2 in SYMBOLS:
        if s2 not in spot or not np.isfinite(spot[s2]).any():
            continue
        w2 = windows(rt.seg, s2)
        n2 = np.r_[carry_trades(rt.world.coins[s2], spot[s2], thr_in, thr_out, *w2["dev"])["net"],
                   carry_trades(rt.world.coins[s2], spot[s2], thr_in, thr_out, *w2["val"])["net"]]
        if len(n2) >= 10:
            cons[s2] = {"n": len(n2), "mean_net": float(n2.mean())}
    out["detail"]["coins"] = cons
    out["checks"]["coin_consistency"] = sum(1 for v in cons.values() if v["mean_net"] > 0) >= 3
    failed = [k for k, v in out["checks"].items() if not v]
    out["verdict"], out["reasons"] = ("PASS" if not failed else "FAIL"), failed
    return out


def f7_holdout(work: Path, results: Path, seg: dict, plan_sha: str, res: dict) -> dict:
    from .stages import HoldoutGate, T_HOLD

    def read():
        world = World(work, seg, include_hold=True)
        c = world.coins[res["coin"]]
        P = c.bars["1h"]
        so = spot_open_on_grid(work, res["coin"], P.t0, P.n)
        lo, hi = windows(seg, res["coin"])["hold"]
        t = carry_trades(c, so, res["thr_in"], res["thr_out"], lo, hi)
        d = describe(t["net"])
        return {"n": d["n"], "mean_net": d["mean"], "t": d["t"], "pass": bool(d["n"] >= 2 and d["mean"] > 0 and (d["t"] or -9) >= T_HOLD)}
    return HoldoutGate(results, plan_sha).read(res["cid"] + "#holdout", read)


# ------------------------------------------------------------------------------------------ F8
def f8_features(rt: Runtime, coin: str, tf: str, families: set) -> tuple[np.ndarray, list[str]]:
    """Causal feature groups, only for families that had >= 1 Stage-1 survivor."""
    c = rt.world.coins[coin]
    b = c.bars[tf]
    cols: dict[str, np.ndarray] = {}
    sh = v13.shift
    with np.errstate(invalid="ignore", divide="ignore"):
        if "F1" in families:
            for k in (1, 3, 6, 12):
                cols[f"ret{k}"] = np.log(b.c / sh(b.c, k))
            cols["ema20d"], cols["ema50d"] = b.c / v13.ema(b.c, 20) - 1, b.c / v13.ema(b.c, 50) - 1
        if "F2" in families:
            cols["rsi14"] = v13.rsi(b.c, 14)
            cols["bbz"] = (b.c - v13.sma(b.c, 20)) / rules.roll_std(b.c, 20)
            cols["adx"] = v13.dmi(b.h, b.l, b.c, 14, 14)[2]
            hi, lo = v13.highest(b.h, 48), v13.lowest(b.l, 48)
            cols["rpos48"] = (b.c - lo) / (hi - lo)
        if "F3" in families:
            cols["atrp"] = b.atr / b.c
            cols["bbw_pr"] = rules.pct_rank(4 * rules.roll_std(b.c, 20) / v13.sma(b.c, 20), 100)
        if "F4" in families:
            h = (b.close_time % DAY) / HOUR
            d = ((b.close_time // DAY) + 3) % 7
            cols["hsin"], cols["hcos"] = np.sin(2 * np.pi * h / 24), np.cos(2 * np.pi * h / 24)
            cols["dsin"], cols["dcos"] = np.sin(2 * np.pi * d / 7), np.cos(2 * np.pi * d / 7)
        if "F5" in families:
            for name in ("ctx_funding_z90", "ctx_funding_last", "ctx_oi_chg_4h", "ctx_global_ls", "ctx_top_ls_sum"):
                x = c.ctx(name, tf)
                if x is not None:
                    cols[name] = x
            cols["taker_z"] = rules.roll_z(b.tbv / b.v - 0.5, rules._days_30(b.tf_min))
            pf = c.premium(tf)
            if pf is not None:
                cols["prem_z"] = pf["ctx_premium_z24h"]
        if "F6" in families:
            btc = rt.world.coins["BTCUSDT"].bars[tf]
            cols["btc_ret3"] = np.log(btc.c / sh(btc.c, 3))
    names = list(cols)
    return (np.column_stack([cols[k] for k in names]) if names else np.zeros((b.n, 0))), names


def f8_cell(rt: Runtime, coin: str, tf: str, H: int, families: set) -> dict:
    import lightgbm as lgb
    c = rt.world.coins[coin]
    b = c.bars[tf]
    win = windows(rt.seg, coin)
    X, names = f8_features(rt, coin, tf, families)
    res = {"cid": f"F8.ml@{coin}/{tf}/H{H}", "family": "F8", "coin": coin, "tf": tf, "H": H, "label": "F8.ml", "features": names,
           "families": sorted(families)}
    if X.shape[1] == 0:
        res.update({"verdict": "INCONCLUSIVE", "reasons": ["NO_FEATURES"]})
        return res
    out = rt.outcomes(coin, tf, H, "time")
    fwd = out.g_long
    ct = b.close_time
    p = dict(n_estimators=150, learning_rate=0.05, num_leaves=15, min_child_samples=200, subsample=0.8, subsample_freq=1,
             colsample_bytree=0.8, verbose=-1, n_jobs=2)
    y_trade = (np.abs(fwd) > COST_BASE).astype(int)
    y_up = (fwd > 0).astype(int)
    ok_row = out.valid & np.isfinite(fwd) & np.isfinite(X).any(axis=1)
    lo_d, hi_d = win["dev"]
    idx_dev = np.nonzero(ok_row & (ct >= lo_d) & (ct <= hi_d))[0]
    edges = np.linspace(lo_d, hi_d, FOLDS + 1)
    sig = np.zeros(b.n, np.int8)

    def fit_predict(train, test):
        m1 = lgb.LGBMClassifier(**p).fit(X[train], y_trade[train])
        m2 = lgb.LGBMClassifier(**p).fit(X[train], y_up[train])
        score_tr = m1.predict_proba(X[train])[:, 1]
        thr = np.quantile(score_tr, 0.80)
        s = m1.predict_proba(X[test])[:, 1]
        d = m2.predict_proba(X[test])[:, 1]
        return np.where(s >= thr, np.where(d >= 0.5, 1, -1), 0).astype(np.int8)
    for k in range(1, FOLDS):
        test = idx_dev[(ct[idx_dev] > edges[k]) & (ct[idx_dev] <= edges[k + 1])]
        train = idx_dev[ct[idx_dev] <= edges[k] - H * b.tf_ms]
        if len(train) < 1000 or len(test) == 0:
            continue
        sig[test] = fit_predict(train, test)
    dev_lo = edges[1]
    tr = select_trades(sig, out, ct, dev_lo, hi_d)
    net = tr.net(c, COST_BASE)
    dev = seg_report(net, tr.entry_t, dev_lo, hi_d, tr.exit_t)
    nl, ns = net_all(out, c, b, COST_BASE)
    if len(tr):
        lo_i = int(np.searchsorted(ct, dev_lo, "left"))
        hi_i = int(np.searchsorted(ct, hi_d - H * b.tf_ms, "right")) - 1
        null = random_null(nl, ns, out.valid, lo_i, hi_i, len(tr), float((tr.side > 0).mean()), H)
        dev["null_p"] = float((1 + (null >= dev["mean_net"]).sum()) / (len(null) + 1)) if len(null) else None
    # VAL: models fit on all DEV, frozen
    lo_v, hi_v = win["val"]
    idx_val = np.nonzero(ok_row & (ct >= lo_v) & (ct <= hi_v))[0]
    sig_v = np.zeros(b.n, np.int8)
    if len(idx_dev) > 1000 and len(idx_val):
        sig_v[idx_val] = fit_predict(idx_dev, idx_val)
    trv = select_trades(sig_v, out, ct, lo_v, hi_v)
    val = seg_report(trv.net(c, COST_BASE), trv.entry_t, lo_v, hi_v, trv.exit_t)
    res["dev"], res["val"] = dev, val
    res["checks"], res["verdict"], res["reasons"] = judge(dev, val, n_min(tf, "F8"))
    return res


# ------------------------------------------------------------------------------------------ benchmarks, cost wall, coverage
def benchmarks(rt: Runtime, coin: str, tf: str) -> dict:
    """Never trade (0), always long / short (greedy non-overlap, base cost + funding) and buy-and-hold per H."""
    c = rt.world.coins[coin]
    b = c.bars[tf]
    win = windows(rt.seg, coin)
    res = {"coin": coin, "tf": tf, "never_trade": 0.0, "by_H": {}, "buy_hold_pct": {}}
    for sn in ("dev", "val"):
        lo, hi = win[sn]
        i0, i1 = int(np.searchsorted(b.close_time, lo, "left")), int(np.searchsorted(b.close_time, hi, "right")) - 1
        o, cl = b.o[min(i0 + 1, b.n - 1)], b.c[min(i1, b.n - 1)]
        res["buy_hold_pct"][sn] = float((cl / o - 1) * 100) if np.isfinite(o) and np.isfinite(cl) else None
    with np.errstate(invalid="ignore", divide="ignore"):
        bar_move = np.abs(b.c / b.o - 1) * 100
    lo, hi = win["dev"]
    m = (b.close_time >= lo) & (b.close_time <= hi)
    res["avg_abs_bar_move_pct"] = float(np.nanmean(bar_move[m]))
    for H in rules.HS[tf]:
        out = rt.outcomes(coin, tf, H, "time")
        row = {}
        for nm_, side in (("always_long", 1), ("always_short", -1)):
            sig = np.full(b.n, side, np.int8)
            for sn in ("dev", "val"):
                tr = select_trades(sig, out, b.close_time, *win[sn])
                d = describe(tr.net(c, COST_BASE))
                row[f"{nm_}_{sn}"] = {"n": d["n"], "mean_net": d["mean"], "t": d["t"]}
        with np.errstate(invalid="ignore"):
            hm = out.valid & np.isfinite(out.g_long) & m
            row["avg_abs_H_move_pct"] = float(np.abs(out.g_long[hm]).mean()) if hm.any() else None
        row["cost_wall_ratio"] = (row["avg_abs_H_move_pct"] / COST_BASE) if row["avg_abs_H_move_pct"] else None
        res["by_H"][H] = row
    return res


def coverage_report(work: Path, seg: dict) -> dict:
    out = {}
    for s in SYMBOLS:
        m5 = bd.load_klines(work, s, "5m")
        t = m5["open_time"]
        n_exp = int((t.max() - t.min()) // M5) + 1
        gaps = np.diff(t)
        w = Path(work)
        mets = sorted((w / "data" / s / "metrics" / "daily").glob("*.parquet"))
        ctxp = w / "features" / s / "ctx.parquet"
        d = {"first": int(t.min()), "last": int(t.max()), "n_5m": int(len(t)), "coverage_pct": round(100.0 * len(t) / n_exp, 4),
             "gaps_gt_5m": int((gaps > M5).sum()), "max_gap_min": int(gaps.max() // MIN),
             "metrics_days": len(mets), "has_ctx": ctxp.exists(), "premium_files": len(list((w / "data" / s / "premium5m").rglob("*.parquet"))),
             "spot1h_files": len(list(spot_dir(w, s).rglob("*.parquet")))}
        if ctxp.exists():
            ctx = bd.read_parquet(ctxp)
            d["ctx_nan_pct"] = {k: round(float(np.isnan(np.asarray(v, float)).mean() * 100), 1) for k, v in ctx.items()
                                if k.startswith(("ctx_oi", "ctx_top", "ctx_global", "ctx_taker", "ctx_funding"))}
        from v4.download import load_funding
        f = load_funding(w, s)
        d["funding_events"] = int(len(f["funding_time"]))
        d["funding_max_gap_h"] = float(np.diff(f["funding_time"]).max() / HOUR) if len(f["funding_time"]) > 1 else None
        out[s] = d
    return out
