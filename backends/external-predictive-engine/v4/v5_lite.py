"""V5-lite (D-041) — event-driven regime experts + direct net-return quantiles. Development only.

Pre-registered in DECISIONS D-041 before any run; nothing here is tuned. Holdout removed before anything is
computed (strategy_v13.load_sealed); only dev_train events are used (5 expanding purged walk-forward folds);
dev_val is untouched. Every rule is causal: completed 1h / 4h buckets only, 5m indicators up to T0.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import binance_data as bd
from . import policy as pol
from . import premium as prem
from . import strategy_v13 as v13
from .optional_layers import _load_layer
from .p1_signal import block_bootstrap_ci
from .targets import MINUTE
from .train_v4 import COST, MIN_EDGE, TREE, _weights
from .verify import conflict_buckets

SYMBOLS = ("BTCUSDT", "ETHUSDT")
FOLD_EDGES = (0.5, 0.6, 0.7, 0.8, 0.9, 1.0)
EXITS = {"E1": (1.5, None, None), "E2": (1.5, 1.0, 1.0)}
NEVER = 1e6
REGIMES = ("VOL_EXPANSION", "UP", "DOWN", "RANGE", "UNCERTAIN")
U_TAIL, U_STRESS, U_MARGIN = 0.25, 0.03, 0.02
H1, H4 = 3_600_000, 14_400_000


# ------------------------------------------------------------------ regime + events (causal)
def _bucket_values(base, anchors, tf_ms, values_fn):
    hb = v13.resample(base, tf_ms)
    idx = np.searchsorted(hb["open_time"] + tf_ms, anchors, side="right") - 1      # last COMPLETE bucket
    vals = values_fn(hb)
    ok = idx >= 0
    return {k: np.where(ok, v[np.maximum(idx, 0)], np.nan) for k, v in vals.items()}


def regimes(base: dict, anchors: np.ndarray) -> np.ndarray:
    def h1(hb):
        c, h, l = hb["close"], hb["high"], hb["low"]
        atr = v13.rma(v13.true_range(h, l, c), 14) / c * 100
        cs = np.r_[0.0, np.cumsum(np.nan_to_num(atr))]
        cnt = np.r_[0, np.cumsum(np.isfinite(atr))]
        k = np.arange(len(atr))
        lo = np.maximum(k - 720, 0)
        prev_mean = np.where(k - lo >= 360, (cs[k] - cs[lo]) / np.maximum(cnt[k] - cnt[lo], 1), np.nan)
        _, _, adx = v13.dmi(h, l, c, 14, 14)
        d = np.abs(np.diff(c, prepend=np.nan))
        eff = np.abs(c - v13.shift(c, 12)) / (np.r_[0.0, np.cumsum(np.nan_to_num(d))][1:] - v13.shift(np.r_[0.0, np.cumsum(np.nan_to_num(d))][1:], 12))
        return {"c": c, "e20": v13.ema(c, 20), "e50": v13.ema(c, 50), "atr": atr, "atr_prev": prev_mean, "adx": adx, "eff": eff}

    def h4(hb):
        return {"c4": hb["close"], "e20_4": v13.ema(hb["close"], 20)}
    with np.errstate(invalid="ignore", divide="ignore"):
        a = _bucket_values(base, anchors, H1, h1)
        b = _bucket_values(base, anchors, H4, h4)
        vol = a["atr"] > 1.5 * a["atr_prev"]
        up = (a["c"] > a["e20"]) & (a["e20"] > a["e50"]) & (b["c4"] > b["e20_4"])
        dn = (a["c"] < a["e20"]) & (a["e20"] < a["e50"]) & (b["c4"] < b["e20_4"])
        rng = (a["adx"] < 20) & (a["eff"] < 0.3)
    out = np.full(len(anchors), "UNCERTAIN", dtype=object)
    for mask, name in ((rng, "RANGE"), (dn, "DOWN"), (up, "UP"), (vol, "VOL_EXPANSION")):   # last write = highest precedence
        out[mask] = name
    return out


def events(base: dict, support_ret_1h: np.ndarray) -> dict[str, np.ndarray]:
    o, h, l, c = (np.asarray(base[k], float) for k in ("open", "high", "low", "close"))
    anchors = np.asarray(base["open_time"], np.int64) + 300_000
    with np.errstate(invalid="ignore", divide="ignore"):
        atr = v13.rma(v13.true_range(h, l, c), 14)
        e20 = v13.ema(c, 20)
        rsi = v13.rsi(c, 14)
        reg = regimes(base, anchors)
        low6, high6 = v13.lowest(l, 6), v13.highest(h, 6)
        ph, pl = v13.shift(h), v13.shift(l)
        rsi3 = v13.shift(rsi, 3)
        s1_long = ((reg == "UP") & (low6 <= e20) & ((v13.highest(h, 24) - low6) >= atr) & (rsi3 < 45)
                   & (c > ph) & (c > e20) & (support_ret_1h > 0))
        s1_short = ((reg == "DOWN") & (high6 >= e20) & ((high6 - v13.lowest(l, 24)) >= atr) & (rsi3 > 55)
                    & (c < pl) & (c < e20) & (support_ret_1h < 0))
        plo, phi = v13.shift(v13.lowest(l, 48)), v13.shift(v13.highest(h, 48))
        width = (phi - plo) / atr
        rng_ok = (reg == "RANGE") & (width >= 2) & (width <= 8)
        bar = h - l
        lower_wick = (np.minimum(o, c) - l) / np.where(bar > 0, bar, np.nan)
        upper_wick = (h - np.maximum(o, c)) / np.where(bar > 0, bar, np.nan)
        s2_long = rng_ok & (l < plo) & (c > plo) & (lower_wick >= 0.5) & ((c - l) <= 1.5 * atr)
        s2_short = rng_ok & (h > phi) & (c < phi) & (upper_wick >= 0.5) & ((h - c) <= 1.5 * atr)
        feats = {"ev_atr_pct": atr / c * 100, "ev_ema_dist": (c - e20) / atr, "ev_rsi": rsi, "ev_rsi3": rsi3,
                 "ev_pullback_depth": (v13.highest(h, 24) - low6) / atr, "ev_bounce_depth": (high6 - v13.lowest(l, 24)) / atr,
                 "ev_range_width": width, "ev_dist_low": (c - plo) / atr, "ev_dist_high": (phi - c) / atr,
                 "ev_lower_wick": lower_wick, "ev_upper_wick": upper_wick, "ev_support_ret_1h": support_ret_1h}
    return {"anchors": anchors, "regime": reg, "atr_pct": atr / c * 100,
            "S1": np.where(s1_long, 1, np.where(s1_short, -1, 0)), "S2": np.where(s2_long, 1, np.where(s2_short, -1, 0)),
            "features": feats}


# ------------------------------------------------------------------ decision + pass rule
def utility(mean, q10):
    return mean - U_TAIL * np.maximum(mean - q10, 0.0) - U_STRESS


def decide(u_long, u_short):
    side = np.zeros(len(u_long), np.int8)
    side[(u_long > 0) & (u_long - u_short >= U_MARGIN)] = 1
    side[(u_short > 0) & (u_short - u_long >= U_MARGIN)] = -1
    return side


def pass_rule(r: dict) -> tuple[bool, list[str]]:
    """D-041 PASS on the primary config summary."""
    why = []
    folds = r["fold_avg_net"]
    if sum(1 for v in folds if v is not None and v > 0) < 4:
        why.append("fewer than 4/5 folds positive")
    if (r["pf"] or 0) < 1.15:
        why.append(f"PF {r['pf']} < 1.15")
    if r["trades"] < 100:
        why.append(f"{r['trades']} trades < 100")
    if r["boot90"] is None or r["boot90"][0] < -0.01:
        why.append(f"CI90 {r['boot90']} lower bound < -0.01")
    if (r["avg_net_cost_0.15"] or -1) < 0:
        why.append("negative at cost 0.15")
    if (r["avg_net_delay_1m"] or -1) <= 0:
        why.append("not positive with 1-minute delay")
    if any((v or -1) <= 0 for v in r["per_symbol_avg_net"].values()):
        why.append("not positive on both symbols")
    if (r["max_fold_profit_share"] or 1) > 0.5:
        why.append("one fold holds > 50 % of the profit")
    if r["avg_net"] is None or r["rule_avg_net"] is None or r["avg_net"] - r["rule_avg_net"] < 0.02:
        why.append("does not beat RULE by 0.02")
    return (not why), why


# ------------------------------------------------------------------ data per symbol
def load_symbol(work: Path, symbol: str, horizon: int, setup: str) -> dict:
    data = v13.load_sealed(work, symbol)
    base, grid, funding = data["base"], data["grid"], data["funding"]
    anchors = np.asarray(base["open_time"], np.int64) + 300_000
    other = "ETHUSDT" if symbol == "BTCUSDT" else "BTCUSDT"
    ctx_names, ctx = _load_layer(work / "features" / symbol / "ctx.parquet", anchors)
    support = ctx[:, ctx_names.index("ctx_eth_ret_1h" if symbol == "BTCUSDT" else "ctx_btc_ret_1h")]
    ev = events(base, support)
    lo, hi = data["train_start"], data["train_end"]
    sel = np.nonzero((ev[setup] != 0) & (anchors >= lo) & (anchors <= hi) & np.isfinite(ev["atr_pct"]))[0]
    a = anchors[sel]
    v3_names, v3 = _load_layer(work / "features" / symbol / "v3.parquet", a)
    pf = prem.features(a, prem.load(work, symbol))
    names = v3_names + ctx_names + list(pf) + list(ev["features"])
    x = np.column_stack([v3, ctx[sel], np.column_stack(list(pf.values())), np.column_stack([f[sel] for f in ev["features"].values()])])
    m1 = bd.load_klines(work, symbol, "1m"); m5 = bd.load_klines(work, symbol, "5m")
    conflicts = conflict_buckets(m1, m5)
    out = {"symbol": symbol, "anchors": a, "event_side": ev[setup][sel], "regime": ev["regime"][sel], "x": x, "names": names,
           "regime_counts": {r: int(((ev["regime"] == r) & (anchors >= lo) & (anchors <= hi)).sum()) for r in REGIMES},
           "events_total": int(len(sel)), "train_start": lo, "train_end": hi}
    atr = ev["atr_pct"][sel]
    for ex, (k_sl, k_act, k_tr) in EXITS.items():
        sl = k_sl * atr
        act = k_act * atr if k_act else np.full(len(sel), NEVER)
        trail = k_tr * atr if k_tr else np.full(len(sel), NEVER)
        for side in ("long", "short"):
            for tag, shift in (("", 0), ("_delay", MINUTE)):
                s = pol.simulate(None, a + shift, horizon, side, sl, act, trail, COST, funding, conflicts, grid=grid)
                s = pol.apply_worst_case(s, grid, a + shift, horizon, side, sl, act, trail, COST, funding)
                out[f"{ex}_{side}{tag}"] = {"net": s["net_pct"], "gross": s["gross_pct"], "exit": s["exit_time_ms"],
                                            "stop": s["reason"] == pol.HARD_STOP}
    return out


# ------------------------------------------------------------------ models
def _fit_models(x, net, stop, times, cal_mask):
    import lightgbm as lgb
    from sklearn.isotonic import IsotonicRegression
    w = _weights(times)
    fit = ~cal_mask
    m = {"mean": lgb.LGBMRegressor(objective="huber", alpha=1.0, **TREE).fit(x[fit], net[fit], sample_weight=w[fit]).booster_,
         "q10": lgb.LGBMRegressor(objective="quantile", alpha=0.10, **TREE).fit(x[fit], net[fit], sample_weight=w[fit]).booster_,
         "q25": lgb.LGBMRegressor(objective="quantile", alpha=0.25, **TREE).fit(x[fit], net[fit], sample_weight=w[fit]).booster_}
    for name, y in (("p_pos", net > 0), ("p_edge", net >= MIN_EDGE), ("p_stop", stop)):
        y = y.astype(int)
        if y[fit].min() == y[fit].max() or cal_mask.sum() < 30:
            m[name] = None
            continue
        b = lgb.LGBMClassifier(**TREE).fit(x[fit], y[fit], sample_weight=w[fit]).booster_
        iso = IsotonicRegression(y_min=0.0, y_max=1.0, out_of_bounds="clip").fit(b.predict(x[cal_mask]), y[cal_mask])
        m[name] = (b, iso)
    win = net[cal_mask] >= MIN_EDGE
    m["avg_win"] = float(net[cal_mask][win].mean()) if win.any() else 0.0
    m["avg_other"] = float(net[cal_mask][~win].mean()) if (~win).any() else 0.0
    return m


def _predict(m, x):
    out = {k: m[k].predict(x) for k in ("mean", "q10", "q25")}
    for k in ("p_pos", "p_edge", "p_stop"):
        out[k] = m[k][1].predict(m[k][0].predict(x)) if m[k] is not None else np.full(len(x), np.nan)
    out["ev_cls"] = (out["p_edge"] * m["avg_win"] + (1 - out["p_edge"]) * m["avg_other"]) if m["p_edge"] is not None else np.full(len(x), -np.inf)
    return out


def _executed(sym_rows: dict, decisions: dict, ex: str, delay: bool = False):
    """Non-overlapping execution per symbol; returns arrays (net, gross, entry time, symbol)."""
    nets, gross, times, syms = [], [], [], []
    for sym, d in sym_rows.items():
        side = decisions[sym]
        tag = "_delay" if delay else ""
        L, S = d[f"{ex}_long{tag}"], d[f"{ex}_short{tag}"]
        exit_ms = np.where(side > 0, L["exit"], S["exit"])
        net = np.where(side > 0, L["net"], S["net"])
        g = np.where(side > 0, L["gross"], S["gross"])
        take = (side != 0) & np.isfinite(net)
        idx = pol.non_overlapping(d["anchors"], take, exit_ms)
        nets.append(net[idx]); gross.append(g[idx]); times.append(d["anchors"][idx]); syms += [sym] * len(idx)
    return (np.concatenate(nets) if nets else np.zeros(0), np.concatenate(gross) if gross else np.zeros(0),
            np.concatenate(times) if times else np.zeros(0, np.int64), np.array(syms))


def _summary(per_fold: list[dict], horizon) -> dict:
    net = np.concatenate([f["net"] for f in per_fold]) if per_fold else np.zeros(0)
    t = np.concatenate([f["times"] for f in per_fold]) if per_fold else np.zeros(0, np.int64)
    syms = np.concatenate([f["syms"] for f in per_fold]) if per_fold else np.array([])
    if len(net) == 0:
        return {"trades": 0, "avg_net": None, "pf": None, "boot90": None, "fold_avg_net": [None] * len(per_fold),
                "per_symbol_avg_net": {}, "max_fold_profit_share": None}
    gp, gl = net[net > 0].sum(), -net[net < 0].sum()
    fold_tot = [float(f["net"].sum()) for f in per_fold]
    pos = sum(v for v in fold_tot if v > 0)
    return {"trades": int(len(net)), "avg_net": round(float(net.mean()), 5),
            "avg_gross": round(float(np.concatenate([f["gross"] for f in per_fold]).mean()), 5),
            "pf": round(float(gp / gl), 3) if gl > 0 else None, "win_pct": round(float((net > 0).mean() * 100), 1),
            "boot90": block_bootstrap_ci(net, t),
            "fold_avg_net": [round(float(f["net"].mean()), 5) if len(f["net"]) else None for f in per_fold],
            "fold_trades": [int(len(f["net"])) for f in per_fold],
            "per_symbol_avg_net": {s: round(float(net[syms == s].mean()), 5) for s in np.unique(syms)},
            "max_fold_profit_share": round(max(v for v in fold_tot if v > 0) / pos, 3) if pos > 0 else None,
            "avg_net_cost_0.15": round(float((net - 0.03).mean()), 5), "avg_net_cost_0.18": round(float((net - 0.06).mean()), 5)}


def run(work: Path, setup: str, horizon: int) -> dict:
    started = time.monotonic()
    rows = {s: load_symbol(work, s, horizon, setup) for s in SYMBOLS}
    lo = min(r["train_start"] for r in rows.values())
    hi = max(r["train_end"] for r in rows.values())
    cuts = [lo + int((hi - lo) * f) for f in FOLD_EDGES]
    report = {"experiment": "V5-lite D-041", "setup": setup, "horizon_minutes": horizon, "cost": COST,
              "events": {s: r["events_total"] for s, r in rows.items()}, "regime_counts": {s: r["regime_counts"] for s, r in rows.items()},
              "configs": {}}
    for ex in EXITS:
        for pooling in ("per_symbol", "pooled"):
            arms = {k: [] for k in ("V5_UTILITY", "RULE", "CLASSIFIER_EV")}
            delay_folds, lift = [], []
            deciles_u, deciles_r = [], []
            for k in range(5):
                t0, t1 = cuts[k], cuts[k + 1]
                fold_dec = {a: {} for a in arms}
                fold_rows = {}
                models = {}
                groups = [[s] for s in SYMBOLS] if pooling == "per_symbol" else [list(SYMBOLS)]
                for grp in groups:
                    X, T, NET, STOP = {}, {}, {}, {}
                    tr_idx = {s: np.nonzero(rows[s]["anchors"] < t0 - horizon * MINUTE)[0] for s in grp}
                    if sum(len(v) for v in tr_idx.values()) < 300:
                        continue
                    for side in ("long", "short"):
                        xs = [np.column_stack([rows[s]["x"][tr_idx[s]], np.full(len(tr_idx[s]), i)]) for i, s in enumerate(grp)]
                        x = np.vstack(xs)
                        tt = np.concatenate([rows[s]["anchors"][tr_idx[s]] for s in grp])
                        net = np.concatenate([rows[s][f"{ex}_{side}"]["net"][tr_idx[s]] for s in grp])
                        stp = np.concatenate([rows[s][f"{ex}_{side}"]["stop"][tr_idx[s]] for s in grp])
                        ok = np.isfinite(net)
                        x, tt, net, stp = x[ok], tt[ok], net[ok], stp[ok]
                        o = np.argsort(tt)
                        x, tt, net, stp = x[o], tt[o], net[o], stp[o]
                        cut_t = tt[int(len(tt) * 0.8)]
                        cal = tt >= cut_t
                        keep = (tt < cut_t - horizon * MINUTE) | cal          # purge before the calibration slice
                        models[(tuple(grp), side)] = _fit_models(x[keep], net[keep], stp[keep], tt[keep], cal[keep])
                for i_g, grp in enumerate(groups):
                    for i, s in enumerate(grp):
                        te = np.nonzero((rows[s]["anchors"] >= t0) & (rows[s]["anchors"] < t1))[0]
                        if (tuple(grp), "long") not in models or len(te) == 0:
                            continue
                        x = np.column_stack([rows[s]["x"][te], np.full(len(te), i)])
                        pl, ps = _predict(models[(tuple(grp), "long")], x), _predict(models[(tuple(grp), "short")], x)
                        ul, us = utility(pl["mean"], pl["q10"]), utility(ps["mean"], ps["q10"])
                        sub = {kk: (v if not isinstance(v, dict) else {kk2: vv[te] for kk2, vv in v.items()})
                               for kk, v in rows[s].items() if kk.startswith("E") or kk == "anchors"}
                        sub["anchors"] = rows[s]["anchors"][te]
                        fold_rows[s] = sub
                        fold_dec["V5_UTILITY"][s] = decide(ul, us)
                        fold_dec["RULE"][s] = rows[s]["event_side"][te].astype(np.int8)
                        cls = np.zeros(len(te), np.int8)
                        cls[(pl["ev_cls"] > 0) & (pl["ev_cls"] >= ps["ev_cls"])] = 1
                        cls[(ps["ev_cls"] > 0) & (ps["ev_cls"] > pl["ev_cls"])] = -1
                        fold_dec["CLASSIFIER_EV"][s] = cls
                        gL, gS = sub[f"{ex}_long"]["gross"], sub[f"{ex}_short"]["gross"]
                        pref = np.where(ul >= us, 1, -1)
                        d = np.where(pref > 0, gL - gS, gS - gL)
                        okd = np.isfinite(d)
                        lift.append((d[okd], sub["anchors"][okd]))
                        best_u = np.maximum(ul, us)
                        real = np.where(pref > 0, sub[f"{ex}_long"]["net"], sub[f"{ex}_short"]["net"])
                        deciles_u.append(best_u[okd]); deciles_r.append(real[okd])
                for arm in arms:
                    if fold_rows:
                        n, g, t, sy = _executed(fold_rows, {s: fold_dec[arm].get(s, np.zeros(len(fold_rows[s]["anchors"]), np.int8)) for s in fold_rows}, ex)
                        arms[arm].append({"net": n, "gross": g, "times": t, "syms": sy})
                if fold_rows:
                    n, g, t, sy = _executed(fold_rows, {s: fold_dec["V5_UTILITY"].get(s, np.zeros(len(fold_rows[s]["anchors"]), np.int8)) for s in fold_rows}, ex, delay=True)
                    delay_folds.append(n)
            cfg = {a: _summary(v, horizon) for a, v in arms.items()}
            u = cfg["V5_UTILITY"]
            dn = np.concatenate(delay_folds) if delay_folds else np.zeros(0)
            u["avg_net_delay_1m"] = round(float(dn.mean()), 5) if len(dn) else None
            u["rule_avg_net"] = cfg["RULE"]["avg_net"]
            if lift:
                dd = np.concatenate([x for x, _ in lift]); tt = np.concatenate([y for _, y in lift])
                u["side_selection_lift"] = {"mean_gross": round(float(dd.mean()), 5), "boot90": block_bootstrap_ci(dd, tt)}
                bu, br = np.concatenate(deciles_u), np.concatenate(deciles_r)
                e = np.quantile(bu, np.linspace(0, 1, 11))
                q = np.clip(np.searchsorted(e, bu, side="right") - 1, 0, 9)
                u["utility_deciles"] = [{"decile": i + 1, "u": round(float(bu[q == i].mean()), 4) if (q == i).any() else None,
                                         "realised_net": round(float(br[q == i].mean()), 4) if (q == i).any() else None} for i in range(10)]
            ok, why = pass_rule(u) if u["trades"] else (False, ["no trades"])
            u["pass"], u["fail_reasons"] = ok, why
            report["configs"][f"{ex}_{pooling}"] = cfg
    primary = report["configs"]["E1_per_symbol"]["V5_UTILITY"]
    report["primary_pass"] = primary["pass"]
    from .runinfo import stamp
    report["seconds"] = round(time.monotonic() - started, 1)
    report["identity"] = stamp()
    out = work / "reports" / "v5lite"
    out.mkdir(parents=True, exist_ok=True)
    (out / f"{setup}_h{horizon}_{report['identity']['run_id'][:8]}.json").write_text(json.dumps(report, indent=1, default=str), "utf-8")
    return report
