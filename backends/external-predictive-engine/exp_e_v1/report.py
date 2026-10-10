"""Experiment E v1 report writer: manifest.json, CSV tables and the human-readable reports B-G (report A is written by audit.py)."""

from __future__ import annotations

import csv
import datetime as dt
import hashlib
import json
from pathlib import Path

from . import data as D
from . import job as J

DISCLAIMER = "REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT (2023-2025 was viewed by earlier experiments; the sealed 2026 holdout was not read)."


def f(x, nd=4, pct=False):
    if x is None:
        return "-"
    try:
        if x != x:
            return "-"
    except Exception:
        return str(x)
    return f"{x * (100 if pct else 1):.{nd}f}" + ("%" if pct else "")


def ci(c, nd=4):
    return "-" if not c else f"[{c[0]:.{nd}f}, {c[1]:.{nd}f}]"


def table(rows, cols, heads=None):
    heads = heads or cols
    out = ["| " + " | ".join(heads) + " |", "|" + "---|" * len(cols)]
    for r in rows:
        out.append("| " + " | ".join(str(r.get(c, "-")) for c in cols) + " |")
    return "\n".join(out)


def write_csv(path: Path, rows: list, cols: list):
    with open(path, "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(cols)
        for r in rows:
            w.writerow([r.get(c) for c in cols])


def sha_dir(paths) -> str:
    h = hashlib.sha256()
    for p in sorted(paths):
        h.update(p.name.encode())
        h.update(p.read_bytes())
    return h.hexdigest()


def manifest(out: Path, summ: dict) -> dict:
    eng = Path(__file__).resolve().parents[1]
    folds = []
    for sym in D.LIVE_SYMBOLS:
        for y in J.YEARS:
            p = out / "oof" / sym / f"{y}.json"
            if p.exists():
                folds.append(json.loads(p.read_text("utf-8")))
    data_files = {}
    for sym in D.LIVE_SYMBOLS:
        mf = {}
        mp = out.parent / "data" / sym / "manifest.json"
        man = json.loads(mp.read_text("utf-8")) if mp.exists() else {}
        for tf in ("5m", "1m", "funding"):
            files = D.month_files(out.parent / "data" / sym / tf)
            mf[tf] = [{"file": p.name, "bytes": p.stat().st_size, "sha256_published": (man.get(f"{tf}:monthly:{p.stem}") or {}).get("sha256")} for p in files]
        data_files[sym] = mf
    return {
        "experiment": "EXPERIMENT_E_V1", "label": DISCLAIMER, "utc": dt.datetime.now(dt.timezone.utc).isoformat(), "plan_sha256": summ.get("plan_sha256"),
        "config": {"tree": J.TREE, "half_life_days": J.HALF_LIFE_DAYS, "embargo_hours": 24, "cal_window_months": 6, "presets": {"P1": "5m/3600s", "P2": "5m/1800s", "P3": "15m/3600s"},
                   "primary_cost_pct": 0.12, "costs_pct": {"meas": 0.12, "code": 0.14, "maker_code": 0.11, "maker_meas": 0.08}, "barrier": "SL = TP = 1.0 R", "bootstrap": "day blocks, 2000",
                   "seeds": {"lightgbm": J.TREE["random_state"], "bootstrap": "crc32(item key)"}, "min_fit_rows": J.MIN_FIT, "min_cal_rows": J.MIN_CAL},
        "boundaries": {"data_first_month": D.FIRST_MONTH, "data_last_month": D.LAST_MONTH, "cut_ms": D.CUT, "test_years": list(J.YEARS),
                       "folds": {str(y): {"cal_window": [J.windows_for(y)[0], J.windows_for(y)[1]], "test_window": [J.windows_for(y)[1], J.windows_for(y)[2]]} for y in J.YEARS}},
        "holdout": {"sealed": True, "reads": 0, "note": "feature / target parquet files are read with the predicate anchor_time_ms < 2026-01-01; kline / funding monthly files <= 2025-12 only"},
        "code_sha256": {"exp_e_v1": sha_dir((eng / "exp_e_v1").glob("*.py")), "v4": sha_dir((eng / "v4").glob("*.py")), "quick_trade_replay_v1": sha_dir((eng / "quick_trade_replay_v1").glob("*.py")),
                        "regime_v1": sha_dir((eng / "regime_v1").glob("*.py")), "edge_search": sha_dir((eng / "edge_search").glob("*.py"))},
        "folds": [{"coin": x["coin"], "test_year": x["test_year"], "windows": x["windows"], "models": {k: {kk: vv for kk, vv in v.items() if kk in ("sha256", "fit_rows", "n_trees", "test_auc", "skipped")}
                                                                                                         for k, v in x["models"].items()}, "v4_reference_levels": x.get("v4_reference_levels")} for x in folds],
        "data_files": data_files,
    }


def write(out: Path, results: Path, summ: dict) -> None:
    man = manifest(out, summ)
    (results / "manifest.json").write_text(json.dumps(man, indent=1, default=str), "utf-8")
    cal, V = summ["calibration"], summ["verdict"]
    # ------------------------------------------------------------------ B calibration
    pooled = [r for r in cal["tables"]["P1"]["pooled"]]
    write_csv(results / "calibration_pooled_P1.csv", pooled, ["model", "target", "method", "n", "base_rate", "mean_p", "brier", "logloss", "ece_mass", "ece_width", "citl", "intercept", "slope", "auc", "bss"])
    write_csv(results / "calibration_by_year_P1.csv", cal["tables"]["P1"]["by_year"], ["model", "target", "method", "year", "n", "brier", "logloss", "ece_mass", "slope", "auc", "bss"])
    write_csv(results / "calibration_by_coin_P1.csv", cal["tables"]["P1"]["by_coin"], ["model", "target", "method", "coin", "n", "brier", "logloss", "ece_mass", "slope", "auc"])
    f1 = cal["f1"]
    write_csv(results / "family_F1.csv", f1, ["key", "model", "target", "method", "preset", "effect", "se", "mde", "p", "q", "verdict", "logloss_delta", "n"])
    L = ["# Report B - calibration (E4)", "", DISCLAIMER, "",
         f"Verdict set: BTC + ETH + BNB, test years 2023 / 2024 / 2025, preset P1 (5m / 3600 s). SOL (2025 only) is in the CSVs by coin. Raw = model output; Platt (primary) and isotonic fit on the matured calibration window before each test year only. `bss` = 1 - Brier / Brier(calibration-window base rate).", ""]
    for model in ("Q", "V3", "V4"):
        rows = [r for r in pooled if r["model"] == model and r["method"] in ("raw", "platt", "iso", "base", "c50")]
        L += [f"## {model}: pooled test metrics", "", table([{**r, "n": r["n"], "base_rate": f(r["base_rate"], 4), "brier": f(r["brier"], 5), "logloss": f(r["logloss"], 5), "ece_mass": f(r["ece_mass"], 4),
                                                           "slope": f(r["slope"], 3), "intercept": f(r["intercept"], 3), "auc": f(r["auc"], 4), "bss": f(r["bss"], 5)} for r in rows],
                                                          ["target", "method", "n", "base_rate", "brier", "logloss", "ece_mass", "intercept", "slope", "auc", "bss"]), ""]
    L += ["## Calibration intercept / slope CI (day-block bootstrap 90 %) and CAL_CALIBRATED", "",
          table([{"item": x["item"], "ece": f(x["ece_mass"], 4), "slope": f(x["slope"], 3), "slope_ci": ci(x["slope_ci90"], 3), "intercept": f(x["intercept"], 3), "icpt_ci": ci(x["intercept_ci90"], 3),
                  "CAL_CALIBRATED": x["CAL_CALIBRATED"]} for x in cal["cal_flags"]["P1"]], ["item", "ece", "slope", "slope_ci", "intercept", "icpt_ci", "CAL_CALIBRATED"]), ""]
    L += ["## F1 - does a calibrated probability beat the base-rate probability (effect = Brier(base) - Brier(model), per event)?", "",
          table([{"item": i["key"].replace("F1|", ""), "effect": f(i["effect"], 6), "ci90": ci(i["ci90"], 6), "mde": f(i["mde"], 6), "q": f(i["q"], 4), "verdict": i["verdict"],
                  "yrs>0": sum(1 for v in i["year"].values() if v and v > 0), "coins>0": sum(1 for v in i["coin"].values() if v and v > 0), "dLogLoss": f(i["logloss_delta"], 6)} for i in f1],
                 ["item", "effect", "ci90", "mde", "q", "verdict", "yrs>0", "coins>0", "dLogLoss"]), "",
          f"F1 LEAD count: {sum(1 for i in f1 if i['verdict'] == 'LEAD')} of {len(f1)}; FAIL: {sum(1 for i in f1 if i['verdict'] == 'FAIL')}; INCONCLUSIVE: {sum(1 for i in f1 if i['verdict'] == 'INCONCLUSIVE')}.", ""]
    L += ["## Native labels of the EXISTING models (is the raw V3 / V4 probability calibrated for its own label?)", "",
          table([{"model": r["model"], "side": r["side"], "method": r["method"], "n": r["n"], "base_rate": f(r["base_rate"], 4), "mean_p": f(r["mean_p"], 4), "brier": f(r["brier"], 5), "ece": f(r["ece_mass"], 4),
                  "slope": f(r["slope"], 3), "auc": f(r["auc"], 4), "bss": f(r["bss"], 5)} for r in cal["native"]], ["model", "side", "method", "n", "base_rate", "mean_p", "brier", "ece", "slope", "auc", "bss"]), ""]
    L += ["## D (expected net return) regression diagnostics (Q, raw D-hat vs realised net %)", "",
          table([{"side": r["side"], "slice": r["slice"], "n": r["n"], "spearman": f(r["spearman"], 4), "slope": f(r["slope_realised_on_pred"], 3), "mse_model": f(r["mse_model"], 5),
                  "mse_const": f(r["mse_const_sample_mean"], 5)} for r in cal["d_regression"]], ["side", "slice", "n", "spearman", "slope", "mse_model", "mse_const"]), ""]
    for P in ("P2",):
        if P in cal["tables"]:
            rows = [r for r in cal["tables"][P]["pooled"] if r["method"] in ("raw", "platt", "iso", "base", "c50")]
            L += [f"## Q at {P} (5m / 1800 s)", "", table([{"target": r["target"], "method": r["method"], "n": r["n"], "base_rate": f(r["base_rate"], 4), "brier": f(r["brier"], 5), "ece": f(r["ece_mass"], 4),
                                                          "slope": f(r["slope"], 3), "auc": f(r["auc"], 4), "bss": f(r["bss"], 5)} for r in rows], ["target", "method", "n", "base_rate", "brier", "ece", "slope", "auc", "bss"]), ""]
    rows = [r for r in cal["p3_tables"]["pooled"] if r["method"] in ("raw", "platt", "iso", "base", "c50")]
    L += ["## Q at P3 (15m / 3600 s events, A / C only)", "", table([{"target": r["target"], "method": r["method"], "n": r["n"], "base_rate": f(r["base_rate"], 4), "brier": f(r["brier"], 5), "ece": f(r["ece_mass"], 4),
                                                              "slope": f(r["slope"], 3), "auc": f(r["auc"], 4), "bss": f(r["bss"], 5)} for r in rows], ["target", "method", "n", "base_rate", "brier", "ece", "slope", "auc", "bss"]), ""]
    L += ["## Reliability tables (deciles of predicted probability; Q, Platt)", ""]
    for k, rel in cal["tables"]["P1"]["reliability"].items():
        if k.startswith("Q|") and k.endswith("|platt"):
            L += [f"### {k}", "", table([{"bin": r["bin"], "n": r["n"], "p_range": f"{r['p_lo']:.4f}-{r['p_hi']:.4f}", "mean_p": f(r["mean_p"], 4), "observed": f(r["observed"], 4)} for r in rel], ["bin", "n", "p_range", "mean_p", "observed"]), ""]
    (results / "report_B_calibration.md").write_text("\n".join(L), "utf-8")

    # ------------------------------------------------------------------ C confidence buckets
    L = ["# Report C - confidence buckets (E5, E9, E13)", "", DISCLAIMER, "",
         "Bucket edges 50-60, 60-70, 70-75, 75-80, 80-90, 90-100 on the confidence of the chosen side (CONF_A = max(pA, 1 - pA); CONF_B / CONF_C = max over sides of the barrier / after-cost-success probability). `n_eff` = distinct (coin, 60-minute) blocks, `days` = distinct UTC days. Net / gross per trade in % at cost 0.12 % (CONF_B uses the 1R / 1R barrier exit).", ""]
    for method in ("platt", "raw", "iso"):
        for conf, rows in summ["buckets"][method].items():
            L += [f"## CONF_{conf} - {method} probabilities, Q, pooled BTC + ETH + BNB 2023-2025", "",
                  table([{"bucket": r["bucket"], "n": r["n"], "n_eff": r.get("n_eff_blocks", "-"), "days": r.get("n_days", "-"), "coverage": f(r["coverage"], 3, pct=True), "pred": f(r.get("mean_pred"), 4),
                          "observed": f(r.get("observed"), 4), "obs_ci90": ci(r.get("observed_ci90")), "gross": f(r.get("gross"), 4), "net": f(r.get("net"), 4), "net_ci90": ci(r.get("net_ci90")),
                          "win": f(r.get("win_rate"), 3), "avg_win": f(r.get("avg_win"), 3), "avg_loss": f(r.get("avg_loss"), 3), "pf(chain)": f(r.get("pf"), 3), "maxDD(chain)": f(r.get("max_dd"), 2)} for r in rows],
                         ["bucket", "n", "n_eff", "days", "coverage", "pred", "observed", "obs_ci90", "gross", "net", "net_ci90", "win", "avg_win", "avg_loss", "pf(chain)", "maxDD(chain)"]), ""]
    L += ["## CONF_A buckets by test year (Platt)", ""]
    for y, d in summ["buckets"]["by_year"].items():
        L += [f"### {y}", "", table([{"bucket": r["bucket"], "n": r["n"], "pred": f(r.get("mean_pred"), 4), "observed": f(r.get("observed"), 4), "net": f(r.get("net"), 4)} for r in d.get("A", [])], ["bucket", "n", "pred", "observed", "net"]), ""]
    for kd, d in summ["ev_deciles"].items():
        if d:
            L += [f"## E9 - realised net vs predicted EV ({kd}), deciles", "", f"slope of realised net on predicted EV: {f(d['slope_net_on_ev'], 4)}; Spearman: {f(d['spearman'], 4)}", "",
                  table([{"decile": r["decile"], "n": r["n"], "ev_pred": f(r["ev_pred"], 4), "net": f(r["net"], 4), "win": f(r["win_rate"], 3)} for r in d["deciles"]], ["decile", "n", "ev_pred", "net", "win"]), ""]
    s75 = summ["study75"]
    L += ["## E13 - the 75 % study (descriptive; confidence = max(p, 1 - p) for A, p for the side-specific targets)", ""]
    q75 = [r for r in s75 if r["model"] == "Q"]
    reached = [r for r in s75 if r["n_ge_75"] > 0]
    L += [f"Models x targets x probability types where at least one test event reaches 75 %: **{len(reached)} of {len(s75)}**.", "",
          table([{"model": r["model"], "target": r["target"], "method": r["method"], "max_conf": f(r["max_conf"], 4), "n>=60": r["n_ge_60"], "n>=70": r["n_ge_70"], "n>=75": r["n_ge_75"],
                  "observed@75": f(r.get("observed_rate"), 4), "net@75": f(r.get("mean_net_pct"), 4)} for r in s75], ["model", "target", "method", "max_conf", "n>=60", "n>=70", "n>=75", "observed@75", "net@75"]), ""]
    (results / "report_C_confidence_buckets.md").write_text("\n".join(L), "utf-8")

    # ------------------------------------------------------------------ D BUY / SELL / WAIT
    fam = summ["families"]
    L = ["# Report D - BUY / SELL / WAIT decision framework (E6, E7)", "", DISCLAIMER, "",
         "BUY if EV_L > 0 and EV_L >= EV_S, SELL if EV_S > 0 and EV_S > EV_L, otherwise WAIT (no sufficiently attractive net-EV trade). EV_s = p_C_s x mean win - (1 - p_C_s) x mean loss, p isotonic, win / loss means from the calibration window. Q-SIDE = better side at every event (no WAIT; AMENDMENT 1).", "",
         "## F2 - mean net % per trade at cost 0.12 % (verdict set, P1 unless stated)", "",
         table([{"item": i["key"].replace("F2|", ""), "trades": i["trades"], "coverage": f(i["coverage"], 4, pct=True), "n_eff": i["n_eff_blocks"], "gross": f(i["gross"], 4), "net": f(i["effect"], 4), "ci90": ci(i["ci90"]), "mde": f(i["mde"], 4), "q": f(i["q"], 4),
                 "verdict": i["verdict"], "yrs>0": sum(1 for v in i["year"].values() if v and v > 0), "coins>0": sum(1 for v in i["coin"].values() if v and v > 0), "net@0.14": f(i["effect_cost"].get("code_0.14"), 4)} for i in fam["F2"]],
                ["item", "trades", "coverage", "n_eff", "gross", "net", "ci90", "mde", "q", "verdict", "yrs>0", "coins>0", "net@0.14"]), "",
         "## F3 - paired: policy trade net minus comparator net on the same events", "",
         table([{"item": i["key"].replace("F3|", ""), "trades": i["trades"], "effect": f(i["effect"], 4), "ci90": ci(i["ci90"]), "mde": f(i["mde"], 4), "q": f(i["q"], 4), "verdict": i["verdict"]} for i in fam["F3"]],
               ["item", "trades", "effect", "ci90", "mde", "q", "verdict"]), ""]
    L += ["## Chain view (greedy non-overlapping, one position at a time, per coin) of the primary and the baselines", "",
          table([{"policy": i["policy"], "chain_n": i["chain"].get("n"), "net": f(i["chain"].get("net"), 4), "win": f(i["chain"].get("win_rate"), 3), "avg_win": f(i["chain"].get("avg_win"), 3), "avg_loss": f(i["chain"].get("avg_loss"), 3),
                  "pf": f(i["chain"].get("pf"), 3), "maxDD_worst_coin": f(i["chain"].get("max_dd_worst_coin"), 2), "max_year_share_pos": f(i["chain"].get("max_year_share_of_positive"), 3)} for i in fam["F2"] if i["key"].startswith("F2|P1|") and "CONF_A" not in i["key"]],
                 ["policy", "chain_n", "net", "win", "avg_win", "avg_loss", "pf", "maxDD_worst_coin", "max_year_share_pos"]), ""]
    (results / "report_D_buy_sell_wait.md").write_text("\n".join(L), "utf-8")

    # ------------------------------------------------------------------ E walk-forward
    wf = summ["walkforward"]
    write_csv(results / "walkforward.csv", wf, ["preset", "policy", "year", "coin", "rows", "trades", "net", "coverage"])
    L = ["# Report E - walk-forward by year (E11)", "", DISCLAIMER, "",
         "Folds: expanding fit window (2021-10 .. cal-start minus embargo), 6-month calibration window, annual test year, 24 h embargo (plan section 5). SOL: 2025 only. No fresh independent holdout exists besides the sealed 2026 data.", "",
         "## Fold windows", ""]
    for P, m in summ["preset_meta"].items():
        L += [f"### {P}", "", table(m["folds"], ["coin", "year", "cal_rows", "test_rows"]), ""]
    yr = [r for r in cal["tables"]["P1"]["by_year"] if r["method"] == "platt" and r["model"] == "Q"]
    L += ["## Q Platt calibration skill by test year (BSS vs the calibration-window base rate)", "", table([{"target": r["target"], "year": r["year"], "n": r["n"], "brier": f(r["brier"], 5), "ece": f(r["ece_mass"], 4), "slope": f(r["slope"], 3), "auc": f(r["auc"], 4), "bss": f(r["bss"], 5)} for r in yr], ["target", "year", "n", "brier", "ece", "slope", "auc", "bss"]), "",
          "## Net per trade by year and coin", "", table([{"preset": r["preset"], "policy": r["policy"], "year": r["year"], "coin": r["coin"], "trades": r["trades"], "coverage": f(r["coverage"], 4, pct=True), "net": f(r["net"], 4)} for r in wf if r["preset"] == "P1"],
                                                    ["preset", "policy", "year", "coin", "trades", "coverage", "net"]), ""]
    (results / "report_E_walk_forward.md").write_text("\n".join(L), "utf-8")

    # ------------------------------------------------------------------ F economics
    L = ["# Report F - Quick Trade economics (E8, E10, E12)", "", DISCLAIMER, "",
         "Costs: 0.12 % measured (primary, D-040), 0.14 % code, 0.11 % code maker entry (report-only), 0.08 % research maker (report-only), plus actual funding. Decisions are made at 0.12 %; the other costs are applied to the same trades. Gross = mean gross % per trade.", ""]
    for P, d in summ["economics"].items():
        rows = []
        for name, it in d.items():
            ec = it.get("effect_cost", {})
            rows.append({"policy": name, "trades": it.get("trades"), "coverage": f(it.get("coverage"), 4, pct=True), "gross": f(it.get("gross"), 4), "net@0.12": f(ec.get("meas_0.12"), 4), "net@0.14": f(ec.get("code_0.14"), 4),
                         "net@0.11": f(ec.get("maker_code_0.11"), 4), "net@0.08": f(ec.get("maker_meas_0.08"), 4), "chain_pf": f(it.get("chain", {}).get("pf"), 3) if it.get("chain") else "-",
                         "chain_maxDD": f(it.get("chain", {}).get("max_dd_worst_coin"), 2) if it.get("chain") else "-"})
        L += [f"## {P}", "", table(rows, ["policy", "trades", "coverage", "gross", "net@0.12", "net@0.14", "net@0.11", "net@0.08", "chain_pf", "chain_maxDD"]), ""]
    (results / "report_F_economics.md").write_text("\n".join(L), "utf-8")

    # ------------------------------------------------------------------ G final verdict
    pr, ps = V.get("primary") or {}, V.get("q_side") or {}
    L = ["# Report G - final verdict (EXPERIMENT E v1)", "", DISCLAIMER, "", f"## Verdict: **{V['final']}**", "",
         f"- calibration verdict: **{V['calibration_verdict']}**  (Q F1 LEADs: {V['f1_q_leads']}; calibrated LEADs: {V['f1_q_leads_calibrated']})",
         f"- economics verdict: **{V['economics_verdict']}**  (primary Q-EVp trades {V['primary_trades']}, WAIT-dominant: {V['wait_dominant']})",
         f"- primary Q-EVp: net {f(pr.get('effect'), 4)} % per trade, CI90 {ci(pr.get('ci90'))}, MDE {f(pr.get('mde'), 4)}, q {f(pr.get('q'), 4)}, {pr.get('verdict')}",
         f"- Q-SIDE (better side at every event): net {f(ps.get('effect'), 4)} %, CI90 {ci(ps.get('ci90'))}, {ps.get('verdict')}; vs random side {V.get('q_side_vs_random')}",
         f"- secondary policy LEADs (F2 + F3): {V['secondary_leads']}; F1 LEADs overall: {len(V['f1_leads_all'])}; F2 LEADs: {V['f2_leads']}; F3 LEADs: {V['f3_leads']}", "",
         "Verdict rules are in `docs/ml-engine-v1/EXPERIMENT_E_V1.md` section 9 + AMENDMENT 1 (frozen before any result). PASS needs ECON_PASS and a CALIBRATED F1 LEAD; FAIL is an adequately powered no-effect result; INCONCLUSIVE otherwise; BLOCKED if the data / OOF generation could not be completed.", ""]
    (results / "report_G_final_verdict.md").write_text("\n".join(L), "utf-8")
