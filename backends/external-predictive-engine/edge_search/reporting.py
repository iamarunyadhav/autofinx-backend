"""Leaderboard, per-family and survivor reports, REPORT.md (EDGE_SEARCH_V1.md section 7)."""

from __future__ import annotations

import csv
import datetime as dt
import hashlib
from collections import Counter, defaultdict
from pathlib import Path

import numpy as np

from .core import COST_BASE, read_json_safe

RANK = {"PASS": 3, "INCONCLUSIVE": 2, "FAIL": 1}


def _f(x, d=3):
    return "—" if x is None else (f"{x:+.{d}f}" if isinstance(x, (int, float)) else str(x))


def _glob(results: Path, sub: str) -> list[dict]:
    return [r for r in (read_json_safe(p) for p in sorted((results / sub).glob("*.json"))) if r]


def item_finals(results: Path) -> list[dict]:
    """One row per Stage-1 item with its final verdict, stage reached and reason (candidates merged in)."""
    items = read_json_safe(results / "stage1_items.json") or []
    s2 = {r["item_id"] + "#" + r["mode"]: r for r in _glob(results, "stage2")}
    s3 = {r["cid"]: r for r in _glob(results, "stage3")}
    s4 = {}
    for r in _glob(results, "stage4"):
        s4[r.get("cid", "")] = r
    rows = []
    for it in items:
        row = {"item_id": it["item_id"], "family": it["family"], "rule": it["name"], "label": it["label"], "coin": it["coin"],
               "tf": it["tf"], "H": it["H"], "variants": 0}
        d, v = it.get("dev") or {}, it.get("val") or {}
        row.update({"n_dev": d.get("n"), "mean_gross_dev": d.get("mean_gross"), "mean_net_dev": d.get("mean_net"), "t_gross_dev": d.get("t_gross"),
                    "mde_dev": d.get("mde_gross"), "p_dev": it.get("p_dev"), "p_bh": it.get("p_bh"), "ic_dev": d.get("ic"),
                    "hit_dev": d.get("hit"), "n_val": v.get("n"), "mean_gross_val": v.get("mean_gross"), "mean_net_val": v.get("mean_net")})
        row["stage"], row["verdict"], row["reason"] = 1, it["s1_status"], it.get("s1_reason")
        if it["s1_status"] == "SURVIVOR":
            best = None
            for mode in ("time", "atr"):
                cid = f"{it['item_id']}#{mode}"
                r2 = s2.get(cid)
                if not r2:
                    continue
                row["variants"] += 1
                stage, verdict, reason = 2, r2["verdict"], ",".join(r2["reasons"])
                if r2["verdict"] == "PASS" and cid in s3:
                    stage, verdict, reason = 3, s3[cid]["verdict"], ",".join(s3[cid]["reasons"])
                    if verdict == "PASS" and cid in s4:
                        stage = 4
                        verdict = "PASS" if s4[cid].get("pass") else "FAIL"
                        reason = "HOLDOUT_" + ("PASS" if s4[cid].get("pass") else "FAIL")
                cand = (RANK[verdict], stage, verdict, reason, mode, r2, s3.get(cid))
                if best is None or cand[:2] > best[:2]:
                    best = cand
            if best:
                row.update({"stage": best[1], "verdict": best[2], "reason": best[3], "exit": best[4]})
                r2 = best[5]
                row.update({"n_dev2": r2["dev"]["n"], "mean_net_dev2": r2["dev"]["mean_net"], "t_dev2": r2["dev"]["t"],
                            "mean_net_val2": r2["val"]["mean_net"], "null_p": r2["dev"].get("null_p"),
                            "ci95_lo": (r2["dev"].get("ci95") or [None])[0]})
        rows.append(row)
    return rows


def write_leaderboard(results: Path, rows: list[dict]) -> None:
    cols = ["item_id", "family", "rule", "label", "coin", "tf", "H", "exit", "stage", "verdict", "reason", "n_dev", "mean_gross_dev",
            "mean_net_dev", "t_gross_dev", "mde_dev", "p_dev", "p_bh", "ic_dev", "hit_dev", "n_val", "mean_gross_val", "mean_net_val",
            "variants", "n_dev2", "mean_net_dev2", "t_dev2", "mean_net_val2", "null_p", "ci95_lo"]
    with open(results / "leaderboard.csv", "w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=cols, extrasaction="ignore")
        w.writeheader()
        w.writerows(rows)
    extra = []
    for sub in ("f7", "f8"):
        for r in _glob(results, sub):
            if "cid" in r:
                extra.append({"item_id": r["cid"], "family": r["family"], "rule": r.get("label"), "label": r.get("label"), "coin": r["coin"],
                              "tf": r["tf"], "H": r.get("H"), "stage": 2, "verdict": r["verdict"], "reason": ",".join(r.get("reasons", [])),
                              "n_dev": r["dev"]["n"], "mean_net_dev": r["dev"]["mean_net"], "t_gross_dev": r["dev"]["t"],
                              "n_val": r["val"]["n"], "mean_net_val": r["val"]["mean_net"]})
    if extra:
        with open(results / "leaderboard.csv", "a", newline="", encoding="utf-8") as fh:
            csv.DictWriter(fh, fieldnames=cols, extrasaction="ignore").writerows(extra)


def cell_table(rows: list[dict]) -> list[str]:
    cells = defaultdict(list)
    for r in rows:
        cells[(r["family"], r["tf"], r["coin"])].append(r)
    out = ["| Family | tf | Coin | items | best DEV net/trade (in-sample, N≥30) | best item | P / F / I | verdict |", "|---|---|---|---|---|---|---|---|"]
    for (fam, tf, coin), rs in sorted(cells.items()):
        cnt = Counter(r["verdict"] if r["verdict"] in RANK else "FAIL" for r in rs)
        cnt["FAIL"] += cnt.pop("SURVIVOR", 0) * 0
        ok = [r for r in rs if (r.get("n_dev") or 0) >= 30 and r.get("mean_net_dev") is not None]
        best = max(ok, key=lambda r: r["mean_net_dev"]) if ok else None
        if cnt["PASS"]:
            verdict = "PASS"
        elif cnt["INCONCLUSIVE"] >= 0.5 * len(rs):
            verdict = "INCONCLUSIVE"
        else:
            verdict = "FAIL"
        out.append(f"| {fam} | {tf} | {coin[:-4]} | {len(rs)} | {_f(best['mean_net_dev']) if best else '—'} | "
                   f"{best['item_id'].split('@')[0] + ' H' + str(best['H']) if best else '—'} | {cnt['PASS']} / {cnt['FAIL']} / {cnt['INCONCLUSIVE']} | **{verdict}** |")
    return out


def write_by_family(results: Path, rows: list[dict]) -> None:
    d = results / "by_family"
    d.mkdir(parents=True, exist_ok=True)
    by = defaultdict(list)
    for r in rows:
        by[r["family"]].append(r)
    for fam, rs in by.items():
        cnt = Counter(r["verdict"] for r in rs)
        lines = [f"# {fam} — {len(rs)} items scanned", "", f"Verdicts: {dict(cnt)}", "",
                 "Top 25 by DEV mean net/trade (in-sample; multiple testing applies; N ≥ 30):", "",
                 "| item | coin | tf | H | N dev | gross dev | net dev | t | BH-adj p | stage | verdict | reason |", "|---|---|---|---|---|---|---|---|---|---|---|---|"]
        ok = sorted([r for r in rs if (r.get("n_dev") or 0) >= 30 and r.get("mean_net_dev") is not None], key=lambda r: -r["mean_net_dev"])[:25]
        for r in ok:
            lines.append(f"| {r['item_id'].split('@')[0]} | {r['coin'][:-4]} | {r['tf']} | {r['H']} | {r['n_dev']} | {_f(r['mean_gross_dev'])} | "
                         f"{_f(r['mean_net_dev'])} | {_f(r['t_gross_dev'], 1)} | {_f(r['p_bh'], 3)} | {r['stage']} | {r['verdict']} | {r['reason']} |")
        (d / f"{fam}.md").write_text("\n".join(lines) + "\n", "utf-8")


def write_survivors(results: Path) -> None:
    d = results / "survivors"
    d.mkdir(parents=True, exist_ok=True)
    s3 = {r["cid"]: r for r in _glob(results, "stage3")}
    s4 = {r.get("cid"): r for r in _glob(results, "stage4")}
    for r in _glob(results, "stage2"):
        h = hashlib.md5(r["cid"].encode()).hexdigest()[:8]
        dv, vv = r["dev"], r["val"]
        L = [f"# {r['cid']}", "", f"Stage-2 verdict: **{r['verdict']}** {r['reasons']}", "",
             f"- DEV: N {dv['n']}, net {_f(dv['mean_net'])}, t {_f(dv['t'], 2)}, CI95 {dv.get('ci95')}, PF {_f(dv.get('pf'), 2)}, win {_f(dv.get('win'), 2)}, "
             f"maxDD(additive) {_f(dv.get('max_dd'), 1)}, avg hold {_f(dv.get('avg_hold_min'), 0)} min, null p {_f(dv.get('null_p'), 4)}",
             f"- long-only net {_f(dv.get('long_mean_net'))} (N {dv.get('long_n')}), short-only net {_f(dv.get('short_mean_net'))} (N {dv.get('short_n')})",
             f"- folds {dv.get('folds')}, years {dv.get('years')}",
             f"- VAL: N {vv['n']}, net {_f(vv['mean_net'])}, t {_f(vv['t'], 2)}", ""]
        if r["cid"] in s3:
            t = s3[r["cid"]]
            L += ["## Stage 3", f"Verdict **{t['verdict']}**; checks {t['checks']}", f"Detail: {t['detail']}", ""]
        if r["cid"] in s4:
            L += ["## Holdout (read once)", f"{s4[r['cid']]}", ""]
        (d / f"{h}.md").write_text("\n".join(L) + "\n", "utf-8")


def build_reports(results: Path, plan_sha: str) -> None:
    rows = item_finals(results)
    write_leaderboard(results, rows)
    write_by_family(results, rows)
    write_survivors(results)
    s1 = read_json_safe(results / "stage1_summary.json") or {}
    seg = read_json_safe(results / "segments.json") or {}
    cov = read_json_safe(results / "data_coverage.json") or {}
    s2, s3, s4 = _glob(results, "stage2"), _glob(results, "stage3"), _glob(results, "stage4")
    f7, f8 = _glob(results, "f7"), _glob(results, "f8")
    f8_status = read_json_safe(results / "f8" / "STATUS.json") or {}
    bench = _glob(results, "benchmarks")
    reads = read_json_safe(results / "holdout_reads.json") or {"reads": {}}
    errors = len(list((results / "errors").glob("*.json"))) if (results / "errors").exists() else 0
    n1 = s1.get("n_items", 0)
    n_trials = n1 + len(s2) + len(f7) + len([r for r in f8 if "cid" in r])
    pass2 = [r for r in s2 if r["verdict"] == "PASS"]
    pass3 = [r for r in s3 if r["verdict"] == "PASS"]
    pass4 = [r for r in s4 if r.get("pass")]
    f7_pass = [r for r in f7 if r.get("stage4", {}).get("pass")]
    winners = [r for r in rows if r["verdict"] == "PASS"]
    decision = "A" if (winners or f7_pass) else "B"
    inc = [r for r in rows if r["verdict"] == "INCONCLUSIVE"]
    inc_reasons = Counter(r["reason"] for r in inc)
    L = ["# AutoFinX Edge Search v1 — REPORT", "",
         f"- Generated {dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}; plan sha256 `{plan_sha}`",
         f"- Costs: base {COST_BASE:.2f} % round trip (taker 0.05 + slippage 0.01 per side) + actual funding; 1× leverage; no orders were placed.",
         f"- Holdout reads logged: {len(reads['reads'])} (each candidate once). Holdout was never touched by Stages 1-3 (data physically cut at "
         f"{dt.datetime.fromtimestamp(seg.get('global_dev_end', 0) / 1000, dt.timezone.utc):%Y-%m-%d}).",
         f"- Jobs with errors: {errors}", "", "## Decision", ""]
    if decision == "A":
        L += ["**(A) At least one PASS.** See the winners below. Next step is a 4–8 week paper-forward test only; passing here does not "
              "mean live-ready, expect the real edge to be thinner than the estimate.", ""]
    else:
        L += ["**(B) No tradable edge in the tested families** — nothing survived all stages after real costs, multiple-testing control and the "
              "sealed holdout rule. Stop trying to find a directional rule on this data; keep risk tools only.", ""]
    L += ["(C) the INCONCLUSIVE list (what the data could not resolve) is at the end.", "", "## Funnel", "",
          f"- Stage 1 scanned **{n1}** items (rule config × coin × tf × H); FDR-rejected {s1.get('n_fdr_reject')}; survivors **{s1.get('n_survivors')}**",
          f"- Stage 2 candidates tested: {len(s2)} (both exits); passed {len(pass2)}",
          f"- Stage 3 stress tested: {len(s3)}; passed {len(pass3)}",
          f"- Stage 4 holdout reads: {len(s4)}; passed {len(pass4)}",
          f"- F7 carry cells: {len(f7)} (DEV/VAL verdicts: {dict(Counter(r['verdict'] for r in f7))}); holdout-passed {len(f7_pass)}",
          f"- F8 ML: {f8_status.get('status', 'n/a')}",
          f"- **N_trials = {n_trials}** (every config counted); Bonferroni factor for any winner = {n_trials}", "",
          "## Family × timeframe × coin (best net/trade is in-sample and NOT a result; P / F / I = items PASS / FAIL / INCONCLUSIVE)", ""]
    L += cell_table(rows)
    L += ["", "## Cost wall (average absolute move vs the 0.12 % cost; DEV)", "",
          "| coin | tf | avg abs 1-bar move % | H | avg abs H-bar move % | ÷ cost |", "|---|---|---|---|---|---|"]
    for b in bench:
        for H, row in sorted(b["by_H"].items(), key=lambda kv: int(kv[0])):
            L.append(f"| {b['coin'][:-4]} | {b['tf']} | {_f(b['avg_abs_bar_move_pct'], 3)} | {H} | {_f(row.get('avg_abs_H_move_pct'), 3)} | "
                     f"{_f(row.get('cost_wall_ratio'), 2)} |")
    L += ["", "## Benchmarks (net/trade after cost; never-trade = 0; buy-and-hold is a return over the segment)", "",
          "| coin | tf | H | always long DEV | always short DEV | always long VAL | always short VAL | B&H DEV % | B&H VAL % |", "|---|---|---|---|---|---|---|---|---|"]
    for b in bench:
        for H, row in sorted(b["by_H"].items(), key=lambda kv: int(kv[0])):
            g = lambda k: (row.get(k) or {}).get("mean_net")
            L.append(f"| {b['coin'][:-4]} | {b['tf']} | {H} | {_f(g('always_long_dev'))} | {_f(g('always_short_dev'))} | {_f(g('always_long_val'))} | "
                     f"{_f(g('always_short_val'))} | {_f(b['buy_hold_pct'].get('dev'), 1)} | {_f(b['buy_hold_pct'].get('val'), 1)} |")
    L += ["", "Reference (not re-run): the owner's Pine v13 / v11 logic was REJECTED in R-019 (24/24 negative after cost).", ""]
    if winners or f7_pass:
        L += ["## Winners", ""]
        for r in winners:
            L.append(f"- {r['item_id']} exit {r.get('exit')}: DEV net {_f(r.get('mean_net_dev2'))}, BH-adj p {_f(r.get('p_bh'), 4)}, "
                     f"Bonferroni p {_f(min(1.0, (r.get('p_dev') or 1) * n_trials), 4)}")
        for r in f7_pass:
            L.append(f"- {r['cid']}: DEV net {_f(r['dev']['mean_net'])}, holdout {r['stage4']}")
        L.append("")
    L += ["## F7 funding carry (delta-neutral, both legs' costs 0.34 % round trip)", "",
          "| coin | in % | out % | DEV N | DEV net | DEV t | avg funding recv | avg basis | VAL N | VAL net | verdict | reasons |", "|---|---|---|---|---|---|---|---|---|---|---|---|"]
    for r in sorted(f7, key=lambda r: r["cid"]):
        dv, vv = r["dev"], r["val"]
        L.append(f"| {r['coin'][:-4]} | {r['thr_in']} | {r['thr_out']} | {dv['n']} | {_f(dv['mean_net'])} | {_f(dv['t'], 1)} | {_f(dv.get('avg_funding_pct'))} | "
                 f"{_f(dv.get('avg_basis_pct'))} | {vv['n']} | {_f(vv['mean_net'])} | {r['verdict']} | {','.join(r.get('reasons', []))[:80]} |")
    L += ["", "## INCONCLUSIVE summary (item counts by reason)", ""]
    for k, v in inc_reasons.most_common(12):
        L.append(f"- {k}: {v}")
    L += ["", "What would resolve them: more independent trades (longer history, or more coins for the slow 4h / 1d timeframes) or "
          "higher-frequency information; the underpowered items cannot detect an edge as large as the whole 0.12 % cost.", ""]
    (results / "REPORT.md").write_text("\n".join(L) + "\n", "utf-8")
