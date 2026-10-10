"""Aggregation + pre-registered verdicts for Q1-Q5 (plan section 5). Reads the per-coin job outputs, writes csv / json."""

from __future__ import annotations

import csv
import json
from pathlib import Path

import numpy as np

from . import data as D
from . import stats as S
from . import trades as TR
from .features import NAMES
from .q1 import CELLS, YEARS

C0 = 0.12
PRIMARY = D.PRIMARY
STRATS_Q23 = [n for n in TR.STRATS if TR.STRATS[n][0] != "always"]
FILTER_VARIANTS = ("T40", "T50", "T60", "M", "TM")


def load_trades(res: Path, coin: str, tf: str):
    f = res / "trades" / f"{coin}_{tf}.npz"
    return dict(np.load(f)) if f.exists() else None


def pool(store: dict, coins, unit: str, tf: str, ex: str, mask: str | None):
    parts = {k: [] for k in ("net", "gross", "fund", "entry_t", "exit_t", "coin", "keep")}
    for ci, c in enumerate(coins):
        d = store.get((c, tf))
        if d is None:
            continue
        key = f"{unit}|{ex}|"
        n = len(d[key + "net"])
        parts["net"].append(d[key + "net"]); parts["gross"].append(d[key + "gross"]); parts["fund"].append(d[key + "fund"])
        parts["entry_t"].append(d[key + "entry_t"]); parts["exit_t"].append(d[key + "exit_t"])
        parts["coin"].append(np.array([c] * n))
        parts["keep"].append(d[key + "m_" + mask] if mask else np.ones(n, bool))
    if not parts["net"]:
        return None
    P = {k: np.concatenate(v) for k, v in parts.items()}
    return P


def _years_ok(by, field, minn=30):
    return all(by[str(y)]["n_kept"] >= minn and by[str(y)][field] is not None for y in YEARS)


def flags(r: dict, kind: str) -> dict:
    """HELPS / PROFITABLE per plan. kind in {'filter', 'kept_only'}."""
    out = {}
    k = r["kept"]
    by = r["by_year"]
    coins_ev = {c: v for c, v in r["by_coin"].items() if v["n_kept"] >= 30 and v["n_all"] >= 30}
    out["n_coins_eval"] = len(coins_ev)
    if kind == "filter":
        d = r.get("delta")
        out["helps_parts"] = {
            "delta>0": bool(d is not None and d > 0), "q_help<0.05": bool(r.get("q_help", 1) < 0.05),
            "ci90>0": bool(r.get("ci90_delta") and r["ci90_delta"][0] > 0),
            "years": bool(_years_ok(by, "delta") and all(by[str(y)]["delta"] > 0 for y in YEARS)),
            "coins>=4": bool(sum(1 for v in coins_ev.values() if v["delta"] is not None and v["delta"] > 0) >= 4)}
        out["HELPS"] = all(out["helps_parts"].values())
    out["profit_parts"] = {
        "n>=100": bool(k.get("n", 0) >= 100), "net>0": bool(k.get("net") is not None and k["net"] > 0),
        "q_profit<0.05": bool(r.get("q_profit", 1) < 0.05), "ci95>0": bool(r.get("ci95_net") and r["ci95_net"][0] > 0),
        "years": bool(_years_ok(by, "net_kept") and all(by[str(y)]["net_kept"] > 0 for y in YEARS)),
        "pf>=1.15": bool(k.get("pf") is not None and k["pf"] >= 1.15),
        "coins>=4": bool(sum(1 for v in coins_ev.values() if v["net_kept"] is not None and v["net_kept"] > 0) >= 4)}
    out["PROFITABLE"] = all(out["profit_parts"].values())
    out["inconc_profit"] = bool(k.get("n", 0) < 100 or (k.get("mde") is not None and k["mde"] > C0))
    out["inconc_help"] = bool(r.get("mde_delta") is not None and r["mde_delta"] > C0) if kind == "filter" else None
    return out


def q23(res: Path, log=print):
    store = {}
    for c in D.SYMBOLS:
        for tf in TR.TFS:
            d = load_trades(res, c, tf)
            if d is not None:
                store[(c, tf)] = d
    rows = []
    pools = {"DISC": list(PRIMARY), "ALL6": list(D.SYMBOLS)}
    for unit in TR.ALL_UNITS:
        for tf in TR.TFS:
            for ex in TR.EXITS:
                variants = ["ALL"] if unit in TR.COMPOSITES else (["ALL"] + (list(FILTER_VARIANTS) + ["G", "X"] if unit != "S1_always_long" and unit != "S2_always_short" else list(FILTER_VARIANTS)))
                for var in variants:
                    for pname, coins in pools.items():
                        P = pool(store, coins, unit, tf, ex, None if var == "ALL" else var)
                        if P is None or len(P["net"]) == 0:
                            continue
                        tests = (pname == "DISC") and var != "X"
                        keep = P["keep"]
                        r = S.summarize(P, keep, tests and var != "ALL", f"{unit}|{tf}|{ex}|{var}|{pname}")
                        if pname == "DISC" and var == "ALL":
                            # unfiltered: only the profit test
                            if len(P["net"]) >= 30:
                                rng = S.rng_for(f"{unit}|{tf}|{ex}|ALL|p")
                                inv, k = S.day_index(P["entry_t"])
                                bm = S.boot_means(P["net"], inv, k, rng)
                                r["p_profit"] = float((1 + np.sum(bm <= 0)) / (len(bm) + 1))
                                r["ci95_net"] = [float(np.percentile(bm, 2.5)), float(np.percentile(bm, 97.5))]
                        # secondary: re-selected filtered strategy
                        if var in ("TM", "G"):
                            re_net = np.concatenate([store[(c, tf)][f"{unit}|{ex}|re_{var}_net"] for c in coins if (c, tf) in store and f"{unit}|{ex}|re_{var}_net" in store[(c, tf)]] or [np.zeros(0)])
                            re_gross = np.concatenate([store[(c, tf)][f"{unit}|{ex}|re_{var}_gross"] for c in coins if (c, tf) in store and f"{unit}|{ex}|re_{var}_gross" in store[(c, tf)]] or [np.zeros(0)])
                            r["reselected"] = {"n": int(len(re_net)), "net": float(re_net.mean()) if len(re_net) else None,
                                               "gross": float(re_gross.mean()) if len(re_gross) else None}
                        r.update({"unit": unit, "tf": tf, "exit": ex, "variant": var, "pool": pname,
                                  "class": TR.STRATS[unit][0] if unit in TR.STRATS else "composite"})
                        rows.append(r)
        log(f"q23 aggregated {unit}")
    # BH families on DISC rows
    disc = [r for r in rows if r["pool"] == "DISC"]
    fh = [r for r in disc if r["variant"] in FILTER_VARIANTS or (r["variant"] == "G")]
    for r, q in zip(fh, S.bh([r.get("p_help") for r in fh])):
        r["q_help"] = float(q)
    fp = [r for r in disc if r["variant"] != "X"]
    for r, q in zip(fp, S.bh([r.get("p_profit") for r in fp])):
        r["q_profit"] = float(q)
    for r in disc:
        if r["variant"] == "X":
            continue
        kind = "filter" if r["variant"] != "ALL" else "kept_only"
        r["flags"] = flags(r, kind)
        r["q2q3"] = "Q3" if (r["variant"] == "G" or r["unit"] in TR.COMPOSITES) else "Q2"
    return rows


def question_verdict(rows, which: str):
    """which: 'help' (LEAD = HELPS and PROFITABLE) or 'profit' (LEAD = PROFITABLE). rows: DISC rows of the relevant family."""
    if not rows:
        return {"verdict": "NOT_RUN", "n_units": 0}
    leads, inc, loss_red = [], 0, []
    for r in rows:
        f = r["flags"]
        if which == "help":
            if f["HELPS"] and f["PROFITABLE"]:
                leads.append(r)
            elif f["HELPS"]:
                loss_red.append(r)
            if f["inconc_help"] and not (f["HELPS"] and f["PROFITABLE"]):
                inc += 1
        else:
            if f["PROFITABLE"]:
                leads.append(r)
            elif f["inconc_profit"]:
                inc += 1
    v = "LEAD" if leads else ("INCONCLUSIVE" if inc > len(rows) / 2 else "FAIL")
    return {"verdict": v, "n_units": len(rows), "n_lead": len(leads), "n_inconclusive": inc, "n_helps_not_profitable": len(loss_red),
            "leads": [(r["unit"], r["tf"], r["exit"], r["variant"]) for r in leads],
            "loss_reducers": [(r["unit"], r["tf"], r["exit"], r["variant"]) for r in loss_red]}


# ------------------------------------------------------------------------------------------ Q1
def q1_rows(res: Path):
    rows = []
    for c in D.SYMBOLS:
        f = res / "q1" / f"{c}.json"
        if not f.exists():
            continue
        j = json.loads(f.read_text("utf-8"))
        for lab in ("own", "htf"):
            for cell, st in j["cells"][lab].items():
                if "regimes" not in st:
                    continue
                for rn, v in st["regimes"].items():
                    if v.get("n", 0) == 0:
                        continue
                    rows.append({"coin": c, "label": lab, "cell": cell, "regime": rn, "n_all": st["n_all"], **{k: v.get(k) for k in (
                        "n", "share", "mean_g", "hit", "mean_abs", "mean_rv", "share_gt_c0", "abs_over_c0", "dD", "R", "R_rv", "hit_diff",
                        "mde_g", "p_dir", "p_mag", "p_hit")}, "by_year": v.get("by_year"), "mean_g_all": st["mean_g_all"],
                        "hit_all": st["hit_all"], "mean_abs_all": st["mean_abs_all"]})
    return rows


def q1_verdicts(rows):
    out = {}
    for lab in ("own", "htf"):
        sub = [r for r in rows if r["label"] == lab and r["coin"] in PRIMARY]
        fam = {}
        for typ, key in (("dir", "p_dir"), ("mag", "p_mag")):
            q = S.bh([r.get(key) for r in sub])
            for r, v in zip(sub, q):
                r["q_" + typ] = float(v)
        by = {}
        for r in sub:
            by.setdefault((r["cell"], r["regime"]), {})[r["coin"]] = r
        dir_leads, mag_leads, dir_sub, mag_sig = [], [], [], []
        for (cell, rn), d in by.items():
            if not all(c in d for c in PRIMARY):
                continue
            rs = [d[c] for c in PRIMARY]
            ys = lambda r, f: [(r["by_year"][str(y)][f], r["by_year"][str(y)]["n"]) for y in YEARS]
            # direction
            if all(r["q_dir"] < 0.05 for r in rs) and np.sign(rs[0]["dD"]) == np.sign(rs[1]["dD"]):
                stable = all(all(v is not None and n >= 30 and np.sign(v) == np.sign(r["dD"]) for v, n in ys(r, "dD")) for r in rs)
                big = all(abs(r["mean_g"]) >= C0 for r in rs)
                rec = (cell, rn, [round(r["dD"], 4) for r in rs], stable, big)
                (dir_leads if (stable and big) else dir_sub).append(rec)
            if all(r["q_mag"] < 0.05 for r in rs) and np.sign(np.log(rs[0]["R"])) == np.sign(np.log(rs[1]["R"])):
                stable = all(all(v is not None and n >= 30 and np.sign(np.log(v)) == np.sign(np.log(r["R"])) for v, n in ys(r, "R")) for r in rs)
                big = all((r["R"] >= 1.2 or r["R"] <= 0.8) for r in rs)
                rec = (cell, rn, [round(r["R"], 3) for r in rs], stable, big)
                (mag_leads if (stable and big) else mag_sig).append(rec)
        n_under = sum(1 for r in sub if r["n"] < 30 or (r.get("mde_g") or 9) > C0)
        dv = "LEAD" if dir_leads else ("INCONCLUSIVE" if n_under > len(sub) / 2 else "FAIL")
        mv = "LEAD" if mag_leads else ("INCONCLUSIVE" if sum(1 for r in sub if r["n"] < 30) > len(sub) / 2 else "FAIL")
        out[lab] = {"direction": {"verdict": dv, "leads": dir_leads, "significant_not_lead": dir_sub, "n_tests": len(sub),
                                  "n_underpowered": n_under},
                    "magnitude": {"verdict": mv, "leads": mag_leads, "significant_not_lead": mag_sig, "n_tests": len(sub)}}
    return out


def write_csv(path: Path, rows: list, cols: list):
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(cols)
        for r in rows:
            w.writerow([r.get(c) for c in cols])


def flatten_q23(rows):
    out = []
    for r in rows:
        k, a = r["kept"], r["all"]
        row = {"pool": r["pool"], "family": r.get("q2q3"), "unit": r["unit"], "class": r["class"], "tf": r["tf"], "exit": r["exit"],
               "variant": r["variant"], "n_all": a.get("n"), "n_kept": k.get("n"), "retention": r.get("retention"),
               "gross_all": a.get("gross"), "net_all": a.get("net"), "gross_kept": k.get("gross"), "net_kept": k.get("net"),
               "net_maker_proxy_kept": k.get("net_maker_proxy"), "delta_net": r.get("delta"), "pf_all": a.get("pf"), "pf_kept": k.get("pf"),
               "mdd_all": a.get("mdd"), "mdd_kept": k.get("mdd"), "win_kept": k.get("win"), "t_kept": k.get("t"), "mde_kept": k.get("mde"),
               "mde_delta": r.get("mde_delta"), "p_help": r.get("p_help"), "q_help": r.get("q_help"), "p_profit": r.get("p_profit"),
               "q_profit": r.get("q_profit"), "ci90_delta_lo": (r.get("ci90_delta") or [None, None])[0],
               "ci90_delta_hi": (r.get("ci90_delta") or [None, None])[1], "ci95_net_lo": (r.get("ci95_net") or [None, None])[0],
               "ci95_net_hi": (r.get("ci95_net") or [None, None])[1],
               "skipped_losers_n": r["skipped_losers"]["n"], "skipped_losers_sum": r["skipped_losers"]["sum_net"],
               "skipped_winners_n": r["skipped_winners"]["n"], "skipped_winners_sum": r["skipped_winners"]["sum_net"],
               "reselected_n": (r.get("reselected") or {}).get("n"), "reselected_net": (r.get("reselected") or {}).get("net"),
               "reselected_gross": (r.get("reselected") or {}).get("gross")}
        for y in YEARS:
            b = r["by_year"][str(y)]
            row.update({f"y{y}_n_kept": b["n_kept"], f"y{y}_gross_kept": b["gross_kept"], f"y{y}_net_kept": b["net_kept"],
                        f"y{y}_net_all": b["net_all"], f"y{y}_delta": b["delta"]})
        f = r.get("flags")
        if f:
            row.update({"HELPS": f.get("HELPS"), "PROFITABLE": f.get("PROFITABLE"), "inconc_help": f.get("inconc_help"),
                        "inconc_profit": f.get("inconc_profit")})
        out.append(row)
    return out


def by_coin_rows(rows):
    out = []
    for r in rows:
        if r["pool"] != "ALL6":
            continue
        for c, v in r["by_coin"].items():
            out.append({"coin": c, "unit": r["unit"], "tf": r["tf"], "exit": r["exit"], "variant": r["variant"], **v})
    return out
