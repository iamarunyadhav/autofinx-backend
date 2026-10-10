"""Pooled analysis of the per-coin replay arrays (plan section 4): items, families, BH-FDR, verdicts, gate / exit / binary / calibration tables."""

from __future__ import annotations

import csv
import json
from pathlib import Path

import numpy as np

from . import arms as A
from . import job as J
from . import stats as ST

POOL = ("BTCUSDT", "ETHUSDT")
ALL_COINS = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT", "XRPUSDT", "SUIUSDT")
PRESETS = ("P1", "P2", "P3")
BIN_PRESETS = ("P1", "P2", "P3", "PP1", "PP2")
ARMS = ("B", "C", "EL", "ES", "A")
PAYOUTS = {"0.86 (code BTCUSDT paper)": 0.86, "0.91 (default)": 0.91}


def load_coin(results: Path, sym: str):
    z = np.load(results / "arrays" / f"{sym}.npz")
    arrays = {k: z[k] for k in z.files}
    meta = json.loads((results / "arrays" / f"{sym}.meta.json").read_text("utf-8"))
    return arrays, meta


def pool_arrays(per_coin: dict, coins) -> dict:
    out: dict = {}
    for c in coins:
        if c not in per_coin:
            continue
        for k, a in per_coin[c].items():
            if k.endswith("|tail"):
                continue                                              # tail statistics are not additive; read per coin
            out[k] = out[k] + a if k in out else a.copy()
    return out


# ------------------------------------------------------------------------------------------ items
def build_items(have: set) -> list:
    items = []
    for p in PRESETS:
        for arm in ARMS:
            for x in A.EXITS:
                key = f"{p}|{arm}|{x}|G0"
                if key not in have:
                    continue
                items.append({"id": key, "family": "F1", "type": "P", "key": key, "preset": p, "arm": arm, "exit": x, "gate": "G0", "kind": "profit"})
                if x != "X1":
                    items.append({"id": "D:" + key, "family": "F1", "type": "DX", "key": key, "base": f"{p}|{arm}|X1|G0", "preset": p, "arm": arm,
                                  "exit": x, "gate": "G0", "kind": "exit_delta"})
            for g in A.GATES_MAIN:
                key = f"{p}|{arm}|X1|{g}"
                if key not in have:
                    continue
                items.append({"id": key, "family": "F1", "type": "P", "key": key, "preset": p, "arm": arm, "exit": "X1", "gate": g, "kind": "profit"})
                items.append({"id": "D:" + key, "family": "F1", "type": "DG", "key": key, "base": f"{p}|{arm}|X1|G0", "preset": p, "arm": arm,
                              "exit": "X1", "gate": g, "kind": "gate_delta"})
        for x in A.EXITS:
            for g in A.GATES:
                key = f"{p}|A|{x}|{g}"
                if key in have:
                    items.append({"id": "F2:" + key, "family": "F2", "type": "P", "key": key, "preset": p, "arm": "A", "exit": x, "gate": g,
                                  "kind": "random_cross"})
        for arm in ("B", "C"):                                    # F4: difference in differences vs the matched random-direction null
            nb = f"{p}|{arm}null|X1|G0"
            base = f"{p}|{arm}|X1|G0"
            if nb not in have or base not in have:
                continue
            for x in A.EXITS:
                if x == "X1":
                    continue
                key, nkey = f"{p}|{arm}|{x}|G0", f"{p}|{arm}null|{x}|G0"
                if key in have and nkey in have:
                    items.append({"id": f"DD:{key}", "family": "F4", "type": "DD", "key": key, "base": base, "nkey": nkey, "nbase": nb, "preset": p,
                                  "arm": arm, "exit": x, "gate": "G0", "kind": "exit_did"})
            for g in A.GATES_MAIN:
                key, nkey = f"{p}|{arm}|X1|{g}", f"{p}|{arm}null|X1|{g}"
                if key in have and nkey in have:
                    items.append({"id": f"DD:{key}", "family": "F4", "type": "DD", "key": key, "base": base, "nkey": nkey, "nbase": nb, "preset": p,
                                  "arm": arm, "exit": "X1", "gate": g, "kind": "gate_did"})
    return items


def eff_for(item, arrs, cost, mask=None):
    tms = ST.terms(item, arrs, cost)
    return ST.point(tms, mask)


def run_items(per_coin: dict, log=print) -> list:
    pooled = pool_arrays(per_coin, POOL)
    items = build_items(set(pooled))
    cost = A.COST_MEAS
    yr_masks = [(J.year_of_day(np.arange(J.N_DAYS)) == i) for i in range(3)]
    rows = []
    for i, it in enumerate(items):
        r = ST.evaluate(it, pooled, cost, it["id"])
        row = dict(it)
        row.update({"n": r["n"], "effect": r["effect"], "se": r["se"], "mde": r["mde"], "ci90_lo": r["ci90"][0] if r["ci90"] else None,
                    "ci90_hi": r["ci90"][1] if r["ci90"] else None, "p": r["p"]})
        for nm, c in (("code", A.COSTS["code"]), ("maker_code", A.COSTS["maker_code"]), ("maker_meas", A.COSTS["maker_meas"])):
            row["effect_" + nm] = eff_for(it, pooled, c)
        row["by_year"] = [eff_for(it, pooled, cost, m) for m in yr_masks]
        row["by_coin"] = {}
        for c in POOL:
            if c in per_coin and it["key"] in per_coin[c]:
                try:
                    row["by_coin"][c] = eff_for(it, per_coin[c], cost)
                except KeyError:
                    row["by_coin"][c] = None
        if it["type"] == "P":
            a = pooled[it["key"]]
            row["gross"] = float(a[:, 1].sum() / a[:, 0].sum()) if a[:, 0].sum() else None
            row["fund"] = float(a[:, 2].sum() / a[:, 0].sum()) if a[:, 0].sum() else None
        rows.append(row)
        if (i + 1) % 200 == 0:
            log(f"items {i + 1}/{len(items)}")
    for fam in ("F1", "F2", "F4"):
        sel = [r for r in rows if r["family"] == fam]
        q = ST.bh([r["p"] for r in sel])
        for r, qq in zip(sel, q):
            r["q"] = float(qq)
    # F3: direction vs matched random seeds (B, C; X1 / G0), pooled BTC + ETH
    seeds = pool_arrays(per_coin, POOL)
    for p in PRESETS:
        for arm in ("B", "C"):
            ka, kn = f"{p}|{arm}|X1|G0", f"{p}|{arm}null|X1|G0|seed"
            if ka not in seeds or kn not in seeds:
                continue
            a = seeds[ka]
            arm_mean = float((a[:, 1].sum() - a[:, 2].sum() - cost * a[:, 0].sum()) / a[:, 0].sum())
            s = seeds[kn]
            sm = (s[:, 1] - s[:, 2] - cost * s[:, 0]) / s[:, 0]
            p_dir = float((1 + (sm >= arm_mean).sum()) / (len(sm) + 1))
            rows.append({"id": f"N:{ka}", "family": "F3", "type": "N", "key": ka, "preset": p, "arm": arm, "exit": "X1", "gate": "G0", "kind": "direction_vs_null",
                         "n": float(a[:, 0].sum()), "effect": arm_mean - float(sm.mean()), "arm_mean": arm_mean, "null_mean": float(sm.mean()),
                         "null_sd": float(sm.std(ddof=1)), "null_max": float(sm.max()), "p": p_dir, "gross": float(a[:, 1].sum() / a[:, 0].sum())})
    sel = [r for r in rows if r["family"] == "F3"]
    for r, q in zip(sel, ST.bh([r["p"] for r in sel])):
        r["q"] = float(q)
    for r in rows:
        if r["type"] == "N":
            r["verdict"] = "LEAD" if (r["q"] < 0.05 and r["effect"] > 0) else "NO_EVIDENCE"
            continue
        rr = {"effect": r["effect"], "se": r["se"], "mde": r["mde"], "ci90": [r["ci90_lo"], r["ci90_hi"]] if r["ci90_lo"] is not None else None, "p": r["p"]}
        coin_vals = [r["by_coin"].get(c) for c in POOL]
        r["verdict"] = ST.verdict(rr, r.get("q"), r["by_year"], coin_vals)
    return rows


def write_csv(path: Path, rows: list, cols: list):
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(cols)
        for r in rows:
            w.writerow([("" if r.get(c) is None else (json.dumps(r[c]) if isinstance(r.get(c), (list, dict)) else r[c])) for c in cols])


# ------------------------------------------------------------------------------------------ by-coin table
def by_coin_rows(per_coin: dict) -> list:
    rows = []
    for c, arrs in per_coin.items():
        for k, a in arrs.items():
            if k.count("|") != 3 or a.ndim != 2 or a.shape[1] != 3:
                continue
            n = a[:, 0].sum()
            if n <= 0:
                continue
            g, f = a[:, 1].sum() / n, a[:, 2].sum() / n
            p = k.split("|")
            row = {"coin": c, "preset": p[0], "arm": p[1], "exit": p[2], "gate": p[3], "n": n, "gross": g, "fund": f}
            for nm, cc in A.COSTS.items():
                row["net_" + nm] = g - f - cc
            for i, y in enumerate(J.YEARS):
                m = J.year_of_day(np.arange(J.N_DAYS)) == i
                ny = a[m, 0].sum()
                row[f"net_meas_{y}"] = (a[m, 1].sum() - a[m, 2].sum() - A.COST_MEAS * ny) / ny if ny > 0 else None
            rows.append(row)
    return rows


def gate_rows(per_coin: dict) -> list:
    pooled = pool_arrays(per_coin, POOL)
    rows = []
    for p in PRESETS:
        for arm in ("B", "C", "EL", "ES"):
            base = pooled.get(f"{p}|{arm}|X1|G0")
            if base is None:
                continue
            for g in A.GATES_MAIN:
                k = f"{p}|{arm}|X1|{g}"
                if k not in pooled:
                    continue
                kept, gx = pooled[k], pooled[k + "|gx"]
                n_all, n_k = base[:, 0].sum(), kept[:, 0].sum()
                net_k = (kept[:, 1].sum() - kept[:, 2].sum() - A.COST_MEAS * n_k) / n_k if n_k else None
                net_a = (base[:, 1].sum() - base[:, 2].sum() - A.COST_MEAS * n_all) / n_all
                rows.append({"preset": p, "arm": arm, "gate": g, "n_all": n_all, "n_kept": n_k, "retention": n_k / n_all, "net_all": net_a, "net_kept": net_k,
                             "gross_all": base[:, 1].sum() / n_all, "gross_kept": kept[:, 1].sum() / n_k if n_k else None,
                             "skipped_losers_n": gx[0], "skipped_losers_sum": gx[1], "skipped_winners_n": gx[2], "skipped_winners_sum": gx[3]})
    return rows


def reason_rows(per_coin: dict) -> list:
    pooled = pool_arrays(per_coin, POOL)
    rows = []
    for k, v in pooled.items():
        if not k.endswith("|rs"):
            continue
        base = k[:-3]
        if base.count("|") != 3 or base.split("|")[3] != "G0":
            continue
        tot = v.sum()
        if tot <= 0:
            continue
        p = base.split("|")
        row = {"preset": p[0], "arm": p[1], "exit": p[2], "n": tot}
        for i, nm in enumerate(("TIME", "SL", "TP", "GAP", "STOP_TRAIL_BE", "AMBIGUOUS_INTRABAR")):
            row[nm] = v[i] / tot
        rows.append(row)
    return rows


def binary_rows(per_coin: dict) -> list:
    rows = []
    groups = {"BTC+ETH": POOL}
    groups.update({c: (c,) for c in ALL_COINS})
    for gname, coins in groups.items():
        pooled = pool_arrays(per_coin, coins)
        for p in BIN_PRESETS:
            for arm in ("A", "B", "C", "EL", "ES", "Bnull", "Cnull"):
                k = f"{p}|{arm}|bin"
                if k not in pooled:
                    continue
                a = pooled[k]
                if a.shape[1] == 9:                                  # random: (S, 9) per-seed counts -> mean per seed
                    m = a.mean(axis=0).reshape(3, 3)
                    ps = a.reshape(-1, 3, 3).sum(axis=1)
                    seeds_hit = ps[:, 0] / np.maximum(ps[:, :2].sum(axis=1), 1)
                else:
                    m = a
                    seeds_hit = None
                w, l, t = m[:, 0].sum(), m[:, 1].sum(), m[:, 2].sum()
                n = w + l
                lo, hi = ST.wilson(w, n)
                row = {"group": gname, "preset": p, "seconds": A.PRESET_SECONDS[p], "arm": arm, "wins": w, "losses": l, "ties": t, "hit": w / n if n else None,
                       "wilson_lo": lo, "wilson_hi": hi}
                for pn, po in PAYOUTS.items():
                    be = 1.0 / (1.0 + po)
                    row[f"be_{po}"] = be
                    row[f"ev_per_stake_{po}"] = (po * w - l) / (w + l + t) if (w + l + t) else None
                    row[f"above_be_{po}"] = None if lo is None else ("YES_CI" if lo > be else ("below_CI" if hi < be else "inside_CI"))
                for i, y in enumerate(J.YEARS):
                    wy, ly = m[i, 0], m[i, 1]
                    row[f"hit_{y}"] = wy / (wy + ly) if (wy + ly) else None
                if seeds_hit is not None:
                    row["seed_hit_min"], row["seed_hit_max"] = float(seeds_hit.min()), float(seeds_hit.max())
                rows.append(row)
    return rows


def tail_rows(per_coin: dict) -> list:
    rows = []
    for c, arrs in per_coin.items():
        for k, t in arrs.items():
            if not k.endswith("|tail"):
                continue
            base = k[:-5]
            p = base.split("|")
            a = arrs[base]
            n = a[:, 0].sum()
            rows.append({"coin": c, "preset": p[0], "arm": p[1], "exit": p[2], "gate": p[3], "n": n,
                         "net_meas": (a[:, 1].sum() - a[:, 2].sum() - A.COST_MEAS * n) / n if n else None,
                         "worst": t[0], "q01": t[1], "q05": t[2], "sd": t[3], "max_drawdown": t[4]})
    return rows


def calibration_rows(metas: dict) -> list:
    rows = []
    for c, m in metas.items():
        for p, cal in (m.get("calibration") or {}).items():
            if not cal:
                continue
            row = {"coin": c, "preset": p, "n": cal["n"], "spearman_M_absret": cal["spearman_M_absret"], "spearman_M_exc": cal["spearman_M_exc"],
                   "mean_M": cal["mean_M"], "mean_absret": cal["mean_absret"], "mean_exc": cal["mean_exc"],
                   "ratio_absret_over_M": cal["mean_absret"] / cal["mean_M"] if cal["mean_M"] else None}
            for g in ("G1a", "G1b"):
                d = cal[g]
                row[f"{g}_share_pass"] = d["share_pass"]
                row[f"{g}_pass_absret"], row[f"{g}_pass_P_ge_0.28"], row[f"{g}_pass_P_ge_0.14"] = d["pass"][1], d["pass"][2], d["pass"][3]
                row[f"{g}_fail_absret"], row[f"{g}_fail_P_ge_0.28"], row[f"{g}_fail_P_ge_0.14"] = d["fail"][1], d["fail"][2], d["fail"][3]
            row["deciles"] = cal["deciles"]
            rows.append(row)
    return rows


def run_all(results: Path, log=print) -> dict:
    per_coin, metas = {}, {}
    for c in ALL_COINS:
        if (results / "arrays" / f"{c}.npz").exists():
            per_coin[c], metas[c] = load_coin(results, c)
    rows = run_items(per_coin, log)
    cols = ["id", "family", "type", "preset", "arm", "exit", "gate", "kind", "n", "gross", "fund", "effect", "se", "mde", "ci90_lo", "ci90_hi", "p", "q", "verdict",
            "effect_code", "effect_maker_code", "effect_maker_meas", "by_year", "by_coin", "arm_mean", "null_mean", "null_sd", "null_max"]
    write_csv(results / "items.csv", rows, cols)
    write_csv(results / "by_coin.csv", by_coin_rows(per_coin), ["coin", "preset", "arm", "exit", "gate", "n", "gross", "fund", "net_meas", "net_code", "net_maker_code",
                                                                  "net_maker_meas", "net_meas_2023", "net_meas_2024", "net_meas_2025"])
    g = gate_rows(per_coin)
    write_csv(results / "gates.csv", g, list(g[0].keys()) if g else ["x"])
    rs = reason_rows(per_coin)
    write_csv(results / "reasons.csv", rs, list(rs[0].keys()) if rs else ["x"])
    bn = binary_rows(per_coin)
    write_csv(results / "binary.csv", bn, list(bn[0].keys()) if bn else ["x"])
    tl = tail_rows(per_coin)
    write_csv(results / "tails.csv", tl, list(tl[0].keys()) if tl else ["x"])
    cal = calibration_rows(metas)
    write_csv(results / "calibration.csv", cal, [k for k in cal[0].keys() if k != "deciles"] + ["deciles"] if cal else ["x"])
    summ = summarize(rows, g, bn, per_coin, metas)
    (results / "summary.json").write_text(json.dumps(summ, indent=1, default=float), "utf-8")
    return summ


def summarize(rows, gates, binary, per_coin, metas) -> dict:
    def cnt(sel):
        return {"n": len(sel), "LEAD": sum(r["verdict"] == "LEAD" for r in sel), "FAIL": sum(r["verdict"] == "FAIL" for r in sel),
                "INCONCLUSIVE": sum(r["verdict"] == "INCONCLUSIVE" for r in sel), "fdr_rejections": sum((r.get("q") or 1) < 0.05 for r in sel)}
    f1 = [r for r in rows if r["family"] == "F1"]
    out = {"counts": {"F1": cnt(f1), "F2_random_cross": cnt([r for r in rows if r["family"] == "F2"]), "F4_did_vs_matched_null": cnt([r for r in rows if r["family"] == "F4"]),
                      "F3_direction_vs_null": cnt([r for r in rows if r["family"] == "F3"])}}
    for kind in ("profit", "exit_delta", "gate_delta"):
        out["counts"][kind] = cnt([r for r in f1 if r["kind"] == kind])
    out["leads"] = [r["id"] for r in rows if r["verdict"] == "LEAD"]
    f4 = [r for r in rows if r["family"] == "F4"]
    out["best_did"] = sorted([(r["effect"], r["id"], r.get("q"), r["verdict"]) for r in f4 if r["effect"] is not None], reverse=True)[:12]
    f2 = [r for r in rows if r["family"] == "F2"]
    out["random_null"] = {"max_net_effect": max((r["effect"] for r in f2 if r["effect"] is not None), default=None),
                          "min_p": min((r["p"] for r in f2), default=None), "items_net_gt0": sum((r["effect"] or -1) > 0 for r in f2), "items": len(f2),
                          "best": sorted([(r["effect"], r["id"]) for r in f2 if r["effect"] is not None], reverse=True)[:5]}
    best_d = sorted([(r["effect"], r["id"], r.get("q"), r["verdict"]) for r in f1 if r["kind"] in ("exit_delta", "gate_delta") and r["effect"] is not None], reverse=True)[:12]
    out["best_deltas"] = best_d
    best_p = sorted([(r["effect"], r["id"], r.get("q"), r["verdict"]) for r in f1 if r["kind"] == "profit" and r["effect"] is not None], reverse=True)[:12]
    out["best_profit"] = best_p
    out["n_items"] = len(rows)
    return out
