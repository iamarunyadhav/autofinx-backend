"""REPORT.md writer for Regime + Tradeability v1."""

from __future__ import annotations

import datetime as dt
import json
from pathlib import Path

from . import data as D
from . import trades as TR
from .features import NAMES
from .q1 import CELLS, YEARS


def f(x, nd=3):
    if x is None:
        return "-"
    if isinstance(x, bool):
        return "yes" if x else "no"
    if isinstance(x, float):
        if x != x:
            return "-"
        if x == float("inf"):
            return "inf"
        return f"{x:.{nd}f}"
    return str(x)


def md_table(head, rows):
    out = ["| " + " | ".join(head) + " |", "|" + "|".join("---" for _ in head) + "|"]
    for r in rows:
        out.append("| " + " | ".join(f(v) for v in r) + " |")
    return "\n".join(out)


def write(results: Path, verd: dict, rows: list, q1r: list) -> None:
    L = []
    A = L.append
    A("# Regime + Tradeability v1 - result report")
    A(f"\nGenerated {dt.datetime.now().strftime('%Y-%m-%d %H:%M')} (local). Plan sha256 `{verd['plan_sha256']}` (REGIME_TRADEABILITY_V1.md, frozen before any computation).")
    A("\nWindow 2023-01-01 .. 2025-12-31 (UTC), data cut 2026-01-01; the sealed holdout (2026-01-04 on) was never read (holdout reads = 0). "
      "Costs C0 = 0.12 % round trip + actual funding; GROSS and NET are shown side by side. DISC = BTC + ETH pooled (all verdicts); "
      "the other four coins are robustness only. All figures are % of notional per trade.\n")
    # ------------------------------------------------------------ verdicts
    A("## Verdicts (pre-registered rules)\n")
    q1v = verd["Q1"]["own"]
    vt = [["Q1 direction information (own-timeframe labels)", q1v["direction"]["verdict"],
           f"{len(q1v['direction']['leads'])} LEAD cells; {len(q1v['direction']['significant_not_lead'])} FDR-significant but not LEAD"],
          ["Q1 magnitude / volatility information", q1v["magnitude"]["verdict"],
           f"{len(q1v['magnitude']['leads'])} LEAD cells; {len(q1v['magnitude']['significant_not_lead'])} FDR-significant but not LEAD"],
          ["Q1 secondary (higher-timeframe labels) direction / magnitude", f"{verd['Q1']['htf']['direction']['verdict']} / {verd['Q1']['htf']['magnitude']['verdict']}",
           "separate family, descriptive"]]
    for key, name in (("Q2a", "Q2a tradeability filter helps the baselines (HELPS and PROFITABLE)"), ("Q2b", "Q2b any filtered baseline PROFITABLE"),
                      ("Q3a", "Q3a regime-matched gate helps (HELPS and PROFITABLE)"), ("Q3b", "Q3b any gated strategy / composite PROFITABLE"),
                      ("Q3_composites", "Q3 composites D_dir / D_meta PROFITABLE"), ("baselines_unfiltered", "reference: unfiltered baselines PROFITABLE")):
        v = verd[key]
        vt.append([name, v["verdict"], f"units {v['n_units']}; LEAD {v.get('n_lead', 0)}; INCONCLUSIVE {v.get('n_inconclusive', 0)}; "
                                       f"helps-but-not-profitable {v.get('n_helps_not_profitable', 0)}"])
    anyq4 = bool(q1v["direction"]["leads"] or q1v["magnitude"]["leads"] or verd["Q2a"]["n_lead"] or verd["Q2b"]["n_lead"] or verd["Q3a"]["n_lead"]
                 or verd["Q3b"]["n_lead"])
    dir_or_profit = bool(q1v["direction"]["leads"] or verd["Q2a"]["n_lead"] or verd["Q2b"]["n_lead"] or verd["Q3a"]["n_lead"] or verd["Q3b"]["n_lead"])
    q4v = ("LEAD" if dir_or_profit else ("LEAD (volatility / magnitude effects only); FAIL for every direction or profit effect" if anyq4 else "FAIL"))
    vt.append(["Q4 yearly stability (sign-stable in 2023, 2024 and 2025)", q4v,
               "sign stability is a condition of every LEAD rule above; only effects that passed it count"])
    vt.append(["Q5 probability semantics / calibration", verd["Q5"]["verdict"], verd["Q5"]["reason"]])
    A(md_table(["Question", "Verdict", "Detail"], vt))
    A("\nLEAD = survived BH-FDR + yearly stability + cost. INCONCLUSIVE = under-powered (not the same as FAIL). Nothing below is an in-sample "
      "'best cell' result unless it satisfies the full LEAD rule.\n")
    # ------------------------------------------------------------ what was tested
    A("## What was tested / not tested\n")
    A("Tested: fixed rule-based regime labels (9 states) and a fixed 7-measure chop / tradeability score with textbook parameters, on BTC / ETH "
      "(and four robustness coins) 15m / 1h / 4h, 2023-2025; forward return, hit rate, magnitude and realised volatility versus a circular-shift label null; "
      "ten fixed baseline strategies unfiltered vs filtered vs regime-matched with real costs and funding. Not tested / not proven: other regime definitions "
      "or thresholds, a fitted classifier, probabilities (Q5), order-flow / book inputs, 2026 data (sealed), live execution, sizing / pause rules in a portfolio. "
      "A FAIL means this combination of fixed rules did not show the pre-registered effect, not that regimes carry no information. "
      "The window lies inside the DEV+VAL range earlier experiments already looked at for other hypotheses; the rules here were never fitted on it.\n")
    # ------------------------------------------------------------ Q1
    A("## Q1 - does the regime label carry information about forward behaviour?\n")
    A("Own-timeframe labels, non-overlapping windows. dD = mean forward return in the regime minus the unconditional mean (long-signed %). "
      "R = mean |move| in the regime / unconditional mean |move|. q = BH-adjusted circular-shift-null p over BTC+ETH x 6 cells x 9 regimes (own family per test type).\n")
    for lab, title in (("own", "own-timeframe labels (primary)"),):
        for coin in D.PRIMARY:
            A(f"### {coin} - {title}\n")
            sub = [r for r in q1r if r["coin"] == coin and r["label"] == lab]
            A(md_table(["cell", "regime", "N", "share", "mean g", "hit", "|move|/C0", "R", "R vol", "dD", "q dir", "q mag", "MDE g"],
                       [[r["cell"], r["regime"], r["n"], r["share"], r["mean_g"], r["hit"], r["abs_over_c0"], r["R"], r["R_rv"], r["dD"],
                         r.get("q_dir"), r.get("q_mag"), r["mde_g"]] for r in sub]))
            A("")
    A("### FDR-significant effects (both coins, own labels)\n")
    for typ in ("direction", "magnitude"):
        v = q1v[typ]
        A(f"**{typ}**: verdict {v['verdict']}; tests {v['n_tests']}.")
        for name, lst in (("LEAD", v["leads"]), ("significant on both coins but not LEAD (sub-cost size or unstable sign)", v["significant_not_lead"])):
            if lst:
                A(f"- {name}: " + "; ".join(f"{c} {r} {vals} stable={s} cost-size/effect-size={b}" for c, r, vals, s, b in lst[:40]))
            else:
                A(f"- {name}: none")
    A("")
    # ------------------------------------------------------------ Q2 / Q3
    disc = [r for r in rows if r["pool"] == "DISC"]
    idx = {(r["unit"], r["tf"], r["exit"], r["variant"]): r for r in disc}
    A("## Q2 - tradeability filter on the baselines (DISC = BTC + ETH, subset of the unfiltered trades)\n")
    A("Columns: N = unfiltered trades, keep = retention, gross / net per trade unfiltered -> filtered, delta = net kept - net all, p = random-filter null, "
      "q = BH-adjusted (348 tests), PF and max drawdown (pp) filtered, skipped losers / winners = count of unfiltered trades the filter removed with net < 0 / > 0.\n")
    for tf in TR.TFS:
        for ex in TR.EXITS:
            A(f"### {tf}, exit {ex}, filter TM (T >= 0.5 and move gate) - primary filter\n")
            trs = []
            for u in TR.STRATS:
                a, k = idx.get((u, tf, ex, "ALL")), idx.get((u, tf, ex, "TM"))
                if not a or not k:
                    continue
                trs.append([u, a["all"]["n"], k["retention"], a["all"]["gross"], a["all"]["net"], k["kept"].get("gross"), k["kept"].get("net"),
                            k["delta"], k.get("p_help"), k.get("q_help"), a["all"]["pf"], k["kept"].get("pf"), k["kept"].get("mdd"),
                            k["skipped_losers"]["n"], k["skipped_winners"]["n"], k["flags"]["HELPS"], k["flags"]["PROFITABLE"]])
            A(md_table(["strategy", "N", "keep", "gross all", "net all", "gross kept", "net kept", "delta", "p", "q", "PF all", "PF kept", "MDD kept",
                        "skipped L", "skipped W", "HELPS", "PROFIT"], trs))
            A("")
    A("### Filter variants (net kept / delta), all strategies pooled by mean across strategies\n")
    vr = []
    for tf in TR.TFS:
        for ex in TR.EXITS:
            for var in ("T40", "T50", "T60", "M", "TM"):
                ks = [idx[(u, tf, ex, var)] for u in TR.STRATS if (u, tf, ex, var) in idx]
                ds = [k["delta"] for k in ks if k["delta"] is not None]
                nets = [k["kept"].get("net") for k in ks if k["kept"].get("net") is not None]
                vr.append([tf, ex, var, sum(1 for d in ds if d > 0), len(ds), sum(ds) / len(ds) if ds else None,
                           sum(nets) / len(nets) if nets else None, sum(1 for k in ks if k["flags"]["HELPS"])])
    A(md_table(["tf", "exit", "filter", "units delta>0", "units", "mean delta", "mean net kept", "units HELPS"], vr))
    A("\n### Unfiltered baselines, DISC (reference)\n")
    br = []
    for tf in TR.TFS:
        for u in TR.STRATS:
            a, b = idx.get((u, tf, "time", "ALL")), idx.get((u, tf, "atr", "ALL"))
            if a and b:
                br.append([tf, u, a["all"]["n"], a["all"]["gross"], a["all"]["net"], a["all"]["pf"], a["all"]["mdd"], a.get("q_profit"),
                           b["all"]["gross"], b["all"]["net"], b["all"]["pf"]])
    A(md_table(["tf", "strategy", "N", "gross (time)", "net (time)", "PF", "MDD", "q profit", "gross (atr)", "net (atr)", "PF (atr)"], br))
    A("\n## Q3 - regime-matched strategy choice\n")
    for tf in TR.TFS:
        for ex in TR.EXITS:
            A(f"### {tf}, exit {ex}: gated (matched) vs unfiltered, plus mismatch control and composites\n")
            trs = []
            for u in TR.STRATS:
                a, k, x = idx.get((u, tf, ex, "ALL")), idx.get((u, tf, ex, "G")), idx.get((u, tf, ex, "X"))
                if not a or not k or TR.STRATS[u][0] == "always":
                    continue
                trs.append([u, TR.STRATS[u][0], a["all"]["n"], k["retention"], a["all"]["gross"], a["all"]["net"], k["kept"].get("gross"),
                            k["kept"].get("net"), k["delta"], k.get("p_help"), k.get("q_help"), k["kept"].get("pf"), k["kept"].get("mdd"),
                            x["kept"].get("net") if x else None, x["kept"].get("n") if x else None, k["flags"]["HELPS"], k["flags"]["PROFITABLE"]])
            A(md_table(["strategy", "class", "N", "keep", "gross all", "net all", "gross gated", "net gated", "delta", "p", "q", "PF gated", "MDD gated",
                        "net mismatch (control)", "N mismatch", "HELPS", "PROFIT"], trs))
            cr = []
            for u in TR.COMPOSITES:
                a = idx.get((u, tf, ex, "ALL"))
                if a:
                    cr.append([u, a["all"]["n"], a["all"]["gross"], a["all"]["net"], a["all"]["pf"], a["all"]["mdd"], a.get("q_profit"), a["flags"]["PROFITABLE"]])
            A("\nComposites:\n")
            A(md_table(["composite", "N", "gross", "net", "PF", "MDD", "q profit", "PROFIT"], cr))
            A("")
    # ------------------------------------------------------------ Q4
    A("## Q4 - per-year view of the strongest candidates (descriptive; sign stability is also inside every LEAD rule)\n")
    cand = sorted([r for r in disc if r["variant"] in ("TM", "G", "T50", "M", "T40", "T60") and r.get("delta") is not None], key=lambda r: -(r["delta"] or -9))[:12]
    A("Top 12 filtered units by delta net (in-sample ranking, NOT results):\n")
    A(md_table(["unit", "tf", "exit", "filter", "N kept", "delta", "q help", "net kept"] + [f"net {y}" for y in YEARS] + [f"delta {y}" for y in YEARS],
               [[r["unit"], r["tf"], r["exit"], r["variant"], r["kept"].get("n"), r["delta"], r.get("q_help"), r["kept"].get("net")] +
                [r["by_year"][str(y)]["net_kept"] for y in YEARS] + [r["by_year"][str(y)]["delta"] for y in YEARS] for r in cand]))
    A("\nPer-year net of the unfiltered baselines (mean over strategies, DISC):\n")
    yr = []
    for tf in TR.TFS:
        for ex in TR.EXITS:
            ks = [idx[(u, tf, ex, "ALL")] for u in TR.STRATS if (u, tf, ex, "ALL") in idx]
            yr.append([tf, ex] + [sum(k["by_year"][str(y)]["net_all"] for k in ks if k["by_year"][str(y)]["net_all"] is not None) / max(1, len(ks)) for y in YEARS])
    A(md_table(["tf", "exit"] + [f"mean net {y}" for y in YEARS], yr))
    # ------------------------------------------------------------ robustness
    A("\n## Robustness coins (BNB, SOL, XRP, SUI; SOL/XRP/SUI history starts 2023-10) - descriptive, not in the verdict families\n")
    allp = [r for r in rows if r["pool"] == "ALL6"]
    rb = []
    for coin in D.SYMBOLS:
        for var in ("TM", "G"):
            ds, nets = [], []
            for r in allp:
                if r["variant"] != var:
                    continue
                v = r["by_coin"].get(coin)
                if v and v["n_kept"] >= 30 and v["n_all"] >= 30 and v["delta"] is not None:
                    ds.append(v["delta"])
                    nets.append(v["net_kept"])
            rb.append([coin, var, len(ds), sum(1 for d in ds if d > 0), sum(ds) / len(ds) if ds else None,
                       sum(nets) / len(nets) if nets else None, sum(1 for x in nets if x > 0)])
    A(md_table(["coin", "filter", "units (N>=30)", "units delta>0", "mean delta", "mean net kept", "units net kept>0"], rb))
    A("\nQ1 magnitude LEAD cells on all six coins (R = regime mean |move| / unconditional):\n")
    lr = []
    for cell, rn, vals, st, big in q1v["magnitude"]["leads"]:
        row = [cell, rn]
        for c in D.SYMBOLS:
            m = [r for r in q1r if r["coin"] == c and r["label"] == "own" and r["cell"] == cell and r["regime"] == rn]
            row.append(m[0]["R"] if m else None)
        lr.append(row)
    A(md_table(["cell", "regime"] + list(D.SYMBOLS), lr))
    h = verd["Q1"]["htf"]
    A(f"\nHigher-timeframe labels (secondary family): direction {h['direction']['verdict']} (FDR-significant-not-LEAD "
      f"{len(h['direction']['significant_not_lead'])}), magnitude {h['magnitude']['verdict']} (LEAD cells {len(h['magnitude']['leads'])}: "
      + "; ".join(f"{c} {r}" for c, r, *_ in h['magnitude']['leads']) + ").\n")
    A("\n## Files\n")
    A("`q1_regimes.csv`, `q23_units.csv` (every unit x variant, DISC and ALL6, gross / net / maker-proxy / per year / flags), `q23_by_coin.csv`, `q23_full.json`, `verdicts.json`, `feat/`, `trades/`, `q1/`.\n")
    (results / "REPORT.md").write_text("\n".join(L), "utf-8")
