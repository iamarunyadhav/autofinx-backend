"""Market Structure POC (docs/ml-engine-v1/EXPERIMENT_F_POC_V1.md). BTCUSDT first; ETH / SOL report-only robustness.

Rule (fixed, no tuning): UP at the close of a 15m bar if 4H trend BULLISH and 1H trend BULLISH (last COMPLETED higher bars) and a close-based bullish BOS of a previously
confirmed 15m swing happens on this bar; DOWN is the mirror; otherwise WAIT. Comparison variant NOHTF = the same 15m BOS without the higher-timeframe condition.
Target = sign of the move from the NEXT 15m open to the close of the horizon bar (15m: one bar; secondary 1h = 4 bars, 4h = 16 bars). Research only.

    python -I market_structure_poc.py [run | dry]
"""

from __future__ import annotations

import csv
import datetime as dt
import hashlib
import json
import os
import sys
import time
from pathlib import Path

ENGINE = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ENGINE))

import numpy as np  # noqa: E402

from edge_search.core import DAY, outcomes_time, write_json  # noqa: E402
from quick_trade_replay_v1 import job as QJ  # noqa: E402
from quick_trade_replay_v1 import stats as ST  # noqa: E402
from regime_v1 import data as RD  # noqa: E402
from regime_v1.features import htf_align  # noqa: E402

from experiment_f.core import params as P  # noqa: E402
from experiment_f.core import structure as S  # noqa: E402

WORK = Path(os.environ.get("AUTOFINX_V4_WORK", r"D:\autofinx-ml-v4"))
OUT = WORK / "experiment_f_v1" / "poc"
PLAN = ENGINE.parent / "docs" / "ml-engine-v1" / "EXPERIMENT_F_POC_V1.md"
WINDOW_LO, CUT = RD.WINDOW_LO, RD.CUT
M15 = 900_000
HORIZONS = {"15m": 1, "1h": 4, "4h": 16}
COSTS = {"meas": 0.12, "code": 0.14}
FEE_TOTAL, SLIP_MEAS, SLIP_CODE = 0.10, 0.02, 0.04       # % round trip (0.05 % x 2 fee; slippage 0.01 % x 2 measured, 0.02 % x 2 code)
SEEDS = 1000
ACC_MATERIAL, ACC_MDE_OK = 0.01, 0.02                    # accuracy items: 1 percentage point effect, MDE <= 2 pp
PNL_MATERIAL, PNL_MDE_OK = ST.MATERIAL, ST.MDE_OK        # 0.03 %, 0.06 % per trade
PRIMARY_COIN = "BTCUSDT"
ROBUST_COINS = ("ETHUSDT", "SOLUSDT")
LABEL = P.LABEL
N_DAYS, year_of_day = QJ.N_DAYS, QJ.year_of_day
YEAR_MASK = [year_of_day(np.arange(N_DAYS)) == i for i in range(3)]


# ------------------------------------------------------------------------------------------ rule
def build_signals(coin) -> dict:
    """Per 15m bar: structure states of 15m / 1h / 4h (each from bars <= t of its own timeframe), higher-timeframe trends of the last COMPLETED bars, predictions."""
    b15, b1, b4 = coin.bars["15m"], coin.bars["1h"], coin.bars["4h"]
    st15 = S.run(b15.o, b15.h, b15.l, b15.c, P.PIVOT_K["15m"])
    st1 = S.run(b1.o, b1.h, b1.l, b1.c, P.PIVOT_K["1h"])
    st4 = S.run(b4.o, b4.h, b4.l, b4.c, P.PIVOT_K["4h"])
    t1 = htf_align(b15.close_time, b1.close_time, st1["trend"], fill=P.UNCERTAIN)
    t4 = htf_align(b15.close_time, b4.close_time, st4["trend"], fill=P.UNCERTAIN)
    return make_predictions(st15["bos"], t1, t4) | {"t1": t1, "t4": t4, "trend15": st15["trend"], "bos": st15["bos"], "ct": b15.close_time}


def make_predictions(bos, t1, t4) -> dict:
    """+1 UP / -1 DOWN / 0 WAIT. Pure function of the three factors (unit-tested)."""
    up = (bos == 1) & (t1 == P.BULLISH) & (t4 == P.BULLISH)
    dn = (bos == -1) & (t1 == P.BEARISH) & (t4 == P.BEARISH)
    pred = np.where(up, 1, np.where(dn, -1, 0)).astype(np.int8)
    nohtf = np.where(bos == 1, 1, np.where(bos == -1, -1, 0)).astype(np.int8)
    return {"pred": pred, "pred_nohtf": nohtf}


def forward(b15, H: int):
    """g[t] = (close[t+H] / open[t+1] - 1) in %; eligible[t] = complete bars t+1..t+H, inside the development window (signal close >= 2023-01-01, horizon end <= cut)."""
    o = outcomes_time(b15, H)
    ct = b15.close_time
    elig = o.valid & np.isfinite(b15.c) & (ct >= WINDOW_LO) & (ct + H * M15 <= CUT)
    return np.where(elig, o.g_long, np.nan), elig


# ------------------------------------------------------------------------------------------ statistics helpers
def day_of(ct):
    return ((np.asarray(ct, np.int64) - WINDOW_LO) // DAY).astype(np.int64)


def wilson(w, n):
    lo, hi = ST.wilson(w, n)
    return lo, hi


def chain(entry_t, exit_t):
    keep, last = [], -1
    for i in range(len(entry_t)):
        if entry_t[i] >= last:
            keep.append(i)
            last = exit_t[i]
    return np.array(keep, np.int64)


def accuracy_block(g, pred, day):
    """Direction accuracy on non-WAIT bars; flat moves (g == 0) are excluded from the denominator. Returns counts + the per-day excess array
    [n, sum(hit - 0.5 x nonflat), 0] (excess over a random side on the SAME bars) and the per-day [n, hits, nonflat] array."""
    m = pred != 0
    gs = g[m] * pred[m]
    nz, hit = (gs != 0).astype(float), (gs > 0).astype(float)
    d = day[m]
    ex = QJ.day_array(d, np.ones(len(d)), hit - 0.5 * nz, np.zeros(len(d)))
    hh = QJ.day_array(d, np.ones(len(d)), hit, nz)
    up = pred[m] > 0
    out = {"signals": int(m.sum()), "flat": int((gs == 0).sum()), "nonflat": int(nz.sum()), "hits": int(hit.sum()),
           "accuracy": float(hit.sum() / nz.sum()) if nz.sum() else None,
           "up_signals": int(up.sum()), "down_signals": int((~up).sum()),
           "up_accuracy": float(hit[up].sum() / max(nz[up].sum(), 1)) if up.any() else None,
           "down_accuracy": float(hit[~up].sum() / max(nz[~up].sum(), 1)) if (~up).any() else None}
    out["wilson95"] = list(wilson(hit.sum(), nz.sum())) if nz.sum() else None
    return out, ex, hh


def baselines(g_all, elig, g_sig, sig_mask):
    """Simple baselines. Majority direction = the more frequent non-flat sign of the forward move over ALL eligible bars of the window (hindsight baseline, descriptive)."""
    ga = g_all[elig]
    up_share = float((ga > 0).sum() / max((ga != 0).sum(), 1))
    maj = 1 if up_share >= 0.5 else -1
    gs = g_sig[sig_mask]
    nz = (gs != 0)
    res = {"up_share_all_eligible": up_share, "majority": "UP" if maj > 0 else "DOWN",
           "always_up_on_signal_bars": float((gs > 0).sum() / max(nz.sum(), 1)), "always_down_on_signal_bars": float((gs < 0).sum() / max(nz.sum(), 1)),
           "majority_on_signal_bars": float(((gs * maj) > 0).sum() / max(nz.sum(), 1)),
           "always_up_all_eligible": up_share, "always_down_all_eligible": 1 - up_share}
    return res


def random_seeds(g_sig_nonflat_abs_sign, n_seeds=SEEDS, key="poc"):
    """Accuracy of random sides on the same signal bars: mean / sd over `n_seeds` seeds and the array (for a one-sided p of the rule)."""
    s = g_sig_nonflat_abs_sign                       # sign of the forward move (+1 / -1) of the non-flat signal bars
    if len(s) == 0:
        return None
    rng = np.random.default_rng(int.from_bytes(hashlib.sha256(key.encode()).digest()[:8], "little"))
    acc = np.empty(n_seeds)
    for i in range(n_seeds):
        side = rng.integers(0, 2, len(s)) * 2 - 1
        acc[i] = float((side == s).mean())
    return acc


def metrics(gross, f, cost, slip):
    """Per-trade statistics: gross, fees, slippage, net, expectancy, PF, max drawdown (pp of notional, cumulative in trade order)."""
    n = len(gross)
    if n == 0:
        return {"n": 0}
    net = gross - cost - f
    win, loss = net[net > 0], net[net <= 0]
    eq = np.r_[0.0, np.cumsum(net)]
    return {"n": int(n), "gross": float(gross.mean()), "fees": FEE_TOTAL, "slippage": slip, "funding": float(f.mean()), "net": float(net.mean()),
            "expectancy_net": float(net.mean()), "win_rate": float((net > 0).mean()), "avg_win": float(win.mean()) if len(win) else None,
            "avg_loss": float(loss.mean()) if len(loss) else None, "pf": float(win.sum() / -loss.sum()) if loss.sum() < 0 else None,
            "max_dd_pp": float((np.maximum.accumulate(eq) - eq).max())}


# ------------------------------------------------------------------------------------------ per coin
def run_coin(coin, sym: str, log=print) -> dict:
    sig = build_signals(coin)
    b15 = coin.bars["15m"]
    ct = sig["ct"]
    R: dict = {"coin": sym, "horizons": {}}
    arrs: dict = {}
    rows = {}
    for hname, H in HORIZONS.items():
        g, elig = forward(b15, H)
        day = day_of(ct)
        R["horizons"][hname] = {"eligible_bars": int(elig.sum())}
        for var, pred_all in (("RULE", sig["pred"]), ("NOHTF", sig["pred_nohtf"])):
            pred = np.where(elig, pred_all, 0).astype(np.int8)
            acc, ex, hh = accuracy_block(np.nan_to_num(g), pred, day)
            arrs[f"acc|{var}|{hname}"] = ex
            h = R["horizons"][hname].setdefault(var, {})
            h["accuracy"] = acc
            h["coverage"] = {"eligible": int(elig.sum()), "UP": int((pred > 0).sum()), "DOWN": int((pred < 0).sum()), "WAIT": int(elig.sum() - (pred != 0).sum())}
            h["coverage"]["wait_share"] = h["coverage"]["WAIT"] / max(h["coverage"]["eligible"], 1)
            sm = pred != 0
            h["baselines"] = baselines(g, elig, np.nan_to_num(g), sm)
            sgn = np.sign(g[sm])
            sgn = sgn[sgn != 0]
            ra = random_seeds(sgn, SEEDS, f"{sym}|{var}|{hname}")
            if ra is not None:
                h["random"] = {"mean": float(ra.mean()), "sd": float(ra.std(ddof=1)), "p_dir": float((1 + (ra >= (acc["accuracy"] or 0)).sum()) / (len(ra) + 1)),
                               "seeds": SEEDS}
            h["by_year"] = []
            for yi in range(3):
                m = YEAR_MASK[yi]
                a_ = hh[m]
                h["by_year"].append({"signals": int(a_[:, 0].sum()), "accuracy": float(a_[:, 1].sum() / a_[:, 2].sum()) if a_[:, 2].sum() else None,
                                     "excess": float(ex[m][:, 1].sum() / ex[m][:, 0].sum()) if ex[m][:, 0].sum() else None})
            # profitability: entry = next open, exit = end of the horizon, non-overlapping chain, no stop / target / leverage
            idx = np.nonzero(pred != 0)[0]
            entry_t = ct[idx]
            exit_t = entry_t + H * M15
            keep = chain(entry_t, exit_t)
            idx, entry_t, exit_t = idx[keep], entry_t[keep], exit_t[keep]
            sd = pred[idx].astype(float)
            gross = sd * g[idx]
            f = sd * coin.funding(entry_t, exit_t) if len(idx) else np.zeros(0)
            d2 = day_of(entry_t)
            arrs[f"pnl|{var}|{hname}"] = QJ.day_array(d2, np.ones(len(idx)), gross, f)
            h["trades"] = {"meas": metrics(gross, f, COSTS["meas"], SLIP_MEAS), "code": metrics(gross, f, COSTS["code"], SLIP_CODE)}
            yr = year_of_day(d2) if len(d2) else np.zeros(0, int)
            h["trades"]["by_year"] = [metrics(gross[yr == i], f[yr == i], COSTS["meas"], SLIP_MEAS) for i in range(3)]
            first = ct[idx[0]] if len(idx) else None
            h["trades"]["half"] = {"2023-24": metrics(gross[yr < 2], f[yr < 2], COSTS["meas"], SLIP_MEAS), "2025": metrics(gross[yr == 2], f[yr == 2], COSTS["meas"], SLIP_MEAS)}
            if len(idx):
                nrs = np.random.default_rng(int.from_bytes(hashlib.sha256(f"{sym}|{var}|{hname}|pnl".encode()).digest()[:8], "little"))
                S_ = nrs.integers(0, 2, (SEEDS, len(idx))) * 2 - 1
                nm = (S_ * g[idx][None, :]).mean(axis=1) - COSTS["meas"] - (S_ * coin.funding(entry_t, exit_t)[None, :]).mean(axis=1)
                eff = h["trades"]["meas"]["net"]
                h["trades"]["random_side_null"] = {"mean_net": float(nm.mean()), "sd": float(nm.std(ddof=1)), "p_dir": float((1 + (nm >= eff).sum()) / (SEEDS + 1))}
        if hname == "15m":
            rows = {"g": g, "elig": elig}
    R["_arrays"] = arrs
    R["_pred"] = {"sig": sig, "g15": rows["g"], "elig15": rows["elig"]}
    return R


# ------------------------------------------------------------------------------------------ items / verdicts (BTC only)
def _by_year(item, arrs, cost):
    tms = ST.terms(item, arrs, cost)
    return [ST.point(tms, m) for m in YEAR_MASK]


def verdict_item(r, q, by_year, material, mde_ok):
    if r.get("effect") is None or r.get("se") is None or r.get("mde") is None:
        return "INCONCLUSIVE"
    if (q is not None and q < 0.05 and r["ci90"][0] > 0 and r["effect"] >= material and r["mde"] <= mde_ok
            and all(v is not None and v > 0 for v in by_year)):
        return "LEAD"
    if r["ci90"][1] < material and r["mde"] <= mde_ok:
        return "FAIL"
    return "INCONCLUSIVE"


def evaluate_items(arrs: dict) -> dict:
    items = {}
    for hname in HORIZONS:
        for var in ("RULE", "NOHTF"):
            items[f"ACC|{var}|{hname}"] = {"item": {"type": "P", "key": f"acc|{var}|{hname}"}, "cost": 0.0, "mat": ACC_MATERIAL, "mde_ok": ACC_MDE_OK, "unit": "fraction"}
            items[f"NET|{var}|{hname}"] = {"item": {"type": "P", "key": f"pnl|{var}|{hname}"}, "cost": COSTS["meas"], "mat": PNL_MATERIAL, "mde_ok": PNL_MDE_OK, "unit": "% per trade"}
        items[f"HTF|{hname}"] = {"item": {"type": "DG", "key": f"acc|RULE|{hname}", "base": f"acc|NOHTF|{hname}"}, "cost": 0.0, "mat": ACC_MATERIAL, "mde_ok": ACC_MDE_OK,
                                 "unit": "fraction (RULE excess minus NOHTF excess)"}
    res = {}
    for name, it in items.items():
        if arrs[it["item"]["key"]][:, 0].sum() < 1:
            res[name] = {"effect": None, "p": 1.0, "se": None, "mde": None, "ci90": None, "n": 0, "unit": it["unit"]}
        else:
            res[name] = ST.evaluate(it["item"], arrs, it["cost"], f"POC|{name}")
            res[name]["by_year"] = _by_year(it["item"], arrs, it["cost"])
        res[name]["unit"] = it["unit"]
    names = list(res)
    qs = ST.bh([res[n]["p"] for n in names])
    for n, q in zip(names, qs):
        r = res[n]
        r["q"] = float(q)
        it = items[n]
        r["verdict_item"] = verdict_item(r, q, r.get("by_year", [None]), it["mat"], it["mde_ok"]) if r.get("se") is not None else "INCONCLUSIVE"
    return res


def overall(res: dict) -> dict:
    prim = res["ACC|RULE|15m"]
    net_rule = [res[f"NET|RULE|{h}"] for h in HORIZONS]
    acc_lead = prim["verdict_item"] == "LEAD"
    any_net_lead = any(r["verdict_item"] == "LEAD" for r in net_rule)
    if acc_lead and any_net_lead:
        v = "PROMISING"
    elif prim["verdict_item"] == "FAIL" or all(r["verdict_item"] == "FAIL" for r in net_rule):
        v = "NOT PROMISING"
    else:
        v = "INCONCLUSIVE"
    return {"verdict": v, "primary_accuracy_item": prim["verdict_item"], "net_items": {h: res[f"NET|RULE|{h}"]["verdict_item"] for h in HORIZONS},
            "htf_items": {h: res[f"HTF|{h}"]["verdict_item"] for h in HORIZONS}}


# ------------------------------------------------------------------------------------------ outputs
def pct(x, d=2):
    return "-" if x is None else f"{x * 100:.{d}f} %"


def f4(x, d=4):
    return "-" if x is None else f"{x:.{d}f}"


def write_predictions(path: Path, sig: dict, coin) -> None:
    b15 = coin.bars["15m"]
    g1, e1 = forward(b15, 1)
    g4, e4 = forward(b15, 4)
    g16, e16 = forward(b15, 16)
    names = {1: "UP", -1: "DOWN", 0: "WAIT"}
    tn = P.TREND_NAMES
    tmp = path.with_suffix(".tmp")
    with open(tmp, "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["signal_close_utc", "signal_close_ms", "trend_4h", "trend_1h", "trend_15m", "bos_15m", "prediction", "prediction_no_htf",
                    "ret_15m_pct", "dir_15m", "correct_15m", "ret_1h_pct", "dir_1h", "correct_1h", "ret_4h_pct", "dir_4h", "correct_4h"])
        for i in np.nonzero(e1)[0]:
            row = [dt.datetime.fromtimestamp(sig["ct"][i] / 1000, dt.timezone.utc).strftime("%Y-%m-%d %H:%M"), int(sig["ct"][i]), tn[int(sig["t4"][i])],
                   tn[int(sig["t1"][i])], tn[int(sig["trend15"][i])], int(sig["bos"][i]), names[int(sig["pred"][i])], names[int(sig["pred_nohtf"][i])]]
            for g, e in ((g1, e1), (g4, e4), (g16, e16)):
                if not e[i]:
                    row += ["", "", ""]
                    continue
                d = 1 if g[i] > 0 else (-1 if g[i] < 0 else 0)
                p = int(sig["pred"][i])
                row += [f"{g[i]:.5f}", {1: "UP", -1: "DOWN", 0: "FLAT"}[d], "" if p == 0 else ("FLAT" if d == 0 else int(d == p))]
            w.writerow(row)
    os.replace(tmp, path)


def write_text(path: Path, R: dict, res: dict, ov: dict, robust: dict, sha: str) -> None:
    L = [f"MARKET STRUCTURE POC - BTCUSDT (plan EXPERIMENT_F_POC_V1.md sha256 {sha})", LABEL, "",
         f"VERDICT: {ov['verdict']}", f"  primary accuracy item (RULE 15m): {ov['primary_accuracy_item']}; net items RULE: {ov['net_items']}; HTF-helps items: {ov['htf_items']}", ""]
    for hname in HORIZONS:
        hz = R["horizons"][hname]
        L.append(f"=== Horizon {hname} (eligible closed 15m bars {hz['eligible_bars']}) ===")
        for var in ("RULE", "NOHTF"):
            h = hz[var]
            a, c = h["accuracy"], h["coverage"]
            L.append(f"[{var}] coverage: UP {c['UP']} DOWN {c['DOWN']} WAIT {c['WAIT']} (WAIT share {pct(c['wait_share'], 2)}); signals {a['signals']}, flat {a['flat']}")
            L.append(f"   accuracy (WAIT and flat excluded) {pct(a['accuracy'])}  Wilson95 {('[%s, %s]' % (pct(a['wilson95'][0]), pct(a['wilson95'][1]))) if a['wilson95'] else '-'}  "
                     f"UP {pct(a['up_accuracy'])} (n {a['up_signals']})  DOWN {pct(a['down_accuracy'])} (n {a['down_signals']})")
            b = h.get("baselines", {})
            L.append(f"   baselines on the same signal bars: always-UP {pct(b.get('always_up_on_signal_bars'))}, always-DOWN {pct(b.get('always_down_on_signal_bars'))}, "
                     f"majority({b.get('majority')}) {pct(b.get('majority_on_signal_bars'))}; all eligible bars: up-share {pct(b.get('up_share_all_eligible'))}")
            if "random" in h:
                L.append(f"   random sides ({h['random']['seeds']} seeds): mean {pct(h['random']['mean'])} sd {pct(h['random']['sd'], 3)}; share of seeds >= rule: p_dir {f4(h['random']['p_dir'], 3)}")
            L.append("   by year (signals / accuracy / excess vs random): " + "; ".join(f"{y}: {r['signals']} / {pct(r['accuracy'])} / {pct(r['excess'])}"
                                                                                       for y, r in zip((2023, 2024, 2025), h["by_year"])))
            for cn in ("meas", "code"):
                m = h["trades"][cn]
                if m.get("n"):
                    L.append(f"   profit @{COSTS[cn]:.2f}% (fees {m['fees']:.2f} + slippage {m['slippage']:.2f}; funding {f4(m['funding'])}): trades {m['n']}, gross {f4(m['gross'])} %, net {f4(m['net'])} % per trade, "
                             f"win {pct(m['win_rate'], 1)}, PF {f4(m['pf'], 3)}, maxDD {f4(m['max_dd_pp'], 1)} pp")
            if h["trades"].get("random_side_null"):
                nn = h["trades"]["random_side_null"]
                L.append(f"   random-side null (same entries, net @0.12): mean {f4(nn['mean_net'])} sd {f4(nn['sd'])}; rule better in {f4(1 - nn['p_dir'], 3)} of seeds (p_dir {f4(nn['p_dir'], 3)})")
            L.append("   net by year @0.12: " + "; ".join(f"{y}: n {m.get('n')} net {f4(m.get('net'))}" for y, m in zip((2023, 2024, 2025), h["trades"]["by_year"])))
            L.append("   halves @0.12: " + "; ".join(f"{k}: n {m.get('n')} net {f4(m.get('net'))}" for k, m in h["trades"]["half"].items()))
        L.append("")
    L.append("=== Declared items (one BH-FDR family, %d items; accuracy in percentage points, net in %% per trade) ===" % len(res))
    for n, r in res.items():
        sc = 100.0 if r["unit"].startswith("fraction") else 1.0
        ci = r.get("ci90") or [None, None]
        L.append(f"{n:14s} effect {f4(None if r.get('effect') is None else r['effect'] * sc, 4)} CI90 [{f4(None if ci[0] is None else ci[0] * sc, 4)}, {f4(None if ci[1] is None else ci[1] * sc, 4)}] "
                 f"MDE {f4(None if r.get('mde') is None else r['mde'] * sc, 4)} p {f4(r['p'], 4)} q {f4(r['q'], 4)} n {int(r.get('n') or 0)} years "
                 f"{[None if v is None else round(v * sc, 4) for v in r.get('by_year', [])]} -> {r['verdict_item']}   ({r['unit']})")
    L += ["", "=== Robustness (report-only, not in the family, not in the verdict) ==="]
    for sym, RR in robust.items():
        for hname in HORIZONS:
            for var in ("RULE", "NOHTF"):
                h = RR["horizons"][hname][var]
                a, c = h["accuracy"], h["coverage"]
                m = h["trades"]["meas"]
                L.append(f"{sym} {hname} {var}: UP {c['UP']} DOWN {c['DOWN']} WAIT {pct(c['wait_share'], 1)}; accuracy {pct(a['accuracy'])} (n {a['nonflat']}); "
                         f"trades {m.get('n')} gross {f4(m.get('gross'))} net@0.12 {f4(m.get('net'))}")
    L += ["", "Not tested: stops / targets / sizing / leverage, other pivot parameters, other timeframes, order-book / flow data, the sealed 2026 holdout (reads 0).",
          "This is a proof of concept on a REUSED development window; a PROMISING verdict would only justify a pre-registered forward paper test, never live use."]
    path.write_text("\n".join(L), "utf-8")


def plan_sha() -> str:
    sha = hashlib.sha256(PLAN.read_bytes()).hexdigest()
    ref = OUT / "PLAN_SHA256.txt"
    if not ref.exists():
        raise RuntimeError("plan not frozen: write PLAN_SHA256.txt first")
    if ref.read_text("utf-8").split()[0] != sha:
        raise RuntimeError("EXPERIMENT_F_POC_V1.md changed after it was frozen (sha mismatch)")
    return sha


def main(argv=None) -> int:
    argv = argv or sys.argv[1:]
    cmd = argv[0] if argv else "run"
    from edge_search.runner import avail_commit_gb, low_priority
    low_priority()
    OUT.mkdir(parents=True, exist_ok=True)
    sha = plan_sha()
    t0 = time.time()
    while avail_commit_gb() < 2.0 or (OUT / "STOP").exists():
        time.sleep(20)
    coin = RD.build_coin(WORK, PRIMARY_COIN, tfs=("15m", "1h", "4h"))
    assert int(coin.bars["15m"].close_time.max()) <= RD.CUT, "holdout guard"
    R = run_coin(coin, PRIMARY_COIN)
    print(f"BTC done {time.time() - t0:.0f}s", flush=True)
    res = evaluate_items(R["_arrays"])
    ov = overall(res)
    write_predictions(OUT / "market_structure_predictions.csv", R["_pred"]["sig"], coin)
    robust = {}
    if cmd != "dry":
        for sym in ROBUST_COINS:
            while avail_commit_gb() < 2.0 or (OUT / "STOP").exists():
                time.sleep(20)
            cn = RD.build_coin(WORK, sym, tfs=("15m", "1h", "4h"))
            RR = run_coin(cn, sym)
            RR.pop("_arrays"), RR.pop("_pred")
            robust[sym] = RR
            print(f"{sym} done {time.time() - t0:.0f}s", flush=True)
    Rj = {k: v for k, v in R.items() if not k.startswith("_")}
    write_json(OUT / "summary.json", {"plan_sha256": sha, "label": LABEL, "primary": Rj, "items": res, "overall": ov, "robustness": robust, "holdout_reads": 0})
    write_text(OUT / "market_structure_results.txt", Rj, res, ov, robust, sha)
    print(f"VERDICT {ov['verdict']}  ({time.time() - t0:.0f}s)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
