"""REPORT.md writer for Rule Baseline v1 (machine report; the owner-facing document is docs/ml-engine-v1/RULE_BASELINE_V1_RESULT.md)."""

from __future__ import annotations

from pathlib import Path

from . import spec as S

LABEL = "REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT (2023-2025 was seen by earlier experiments; the hypothesis was chosen after that; holdout 2026 sealed, reads 0)"


def f(x, d=3, pct=False):
    if x is None:
        return "-"
    return f"{x:.{d}f}"


def write(results: Path, summ: dict) -> None:
    L = [f"# Rule Baseline v1 - machine report", "", f"**{LABEL}**", "",
         f"plan sha256 `{summ.get('plan_sha256')}`; pool = {', '.join(summ['primary_pool'])}; family size = {summ['family_size']} items per family (F1 profit, F2 vs random-side null).", "",
         f"## Verdict (PRIMARY rule set): **{summ['overall']}**  per preset: {summ['preset_verdicts']}", "",
         "## Primary rule set, pooled (cost 0.12 %, % per trade)", "",
         "| preset | n | gross | net | CI90 net | MDE | win % | avg win | avg loss | PF | maxDD (pp) | eff. day-blocks | mean R (gross) | stop % | q F1 | item | null net | net - null [CI90] | q F2 | p_dir | item |",
         "|" + "---|" * 21]
    for pname in S.PRESETS:
        pre = f"{pname}|PRIMARY|"
        if pre not in summ["items"]:
            continue
        it, m = summ["items"][pre], summ["metrics"][pre]["pool"]
        a, b = it["profit"], it["vs_null"]
        ci = a.get("ci90") or [None, None]
        cb = b.get("ci90") or [None, None]
        L.append(f"| {pname} | {m['n']} | {f(m.get('gross'),4)} | {f(m.get('net'),4)} | [{f(ci[0],4)}, {f(ci[1],4)}] | {f(a.get('mde'),4)} | {f(m.get('win_rate') and m['win_rate']*100,1)} | "
                 f"{f(m.get('avg_win'),3)} | {f(m.get('avg_loss'),3)} | {f(m.get('pf'),3)} | {f(m.get('max_dd'),1)} | {m.get('eff_blocks')} | {f(m.get('mean_R'),3)} | {f(m.get('mean_stop_pct'),3)} | "
                 f"{f(a['q'],3)} | {a['verdict_item']} | {f(it['null_mean_net'],4)} | {f(b['effect'],4)} [{f(cb[0],4)}, {f(cb[1],4)}] | {f(b['q'],3)} | {f(it['p_dir'],3)} | {b['verdict_item']} |")
    L += ["", "## All variants (pooled, 0.12 %): profit (F1) and difference to the matched random-side null (F2)", "",
          "| preset | variant | role | n | gross | net | CI90 net | net 0.14 | net 0.11 (maker) | net 0.08 (maker) | q F1 | F1 | null net | net - null | CI90 | q F2 | F2 | by year net (23/24/25) | by coin net |",
          "|" + "---|" * 19]
    for pre, it in summ["items"].items():
        pname, vname, _ = pre.split("|")
        m = summ["metrics"][pre]["pool"]
        a, b = it["profit"], it["vs_null"]
        ci = a.get("ci90") or [None, None]
        cb = b.get("ci90") or [None, None]
        yr = "/".join(f(v, 3) for v in a["by_year"])
        bc = ", ".join(f"{c[:3]} {f(v,3)}" for c, v in a["by_coin"].items())
        cs = it["costs"]
        L.append(f"| {pname} | {vname} | {it['role']} | {m.get('n')} | {f(m.get('gross'),4)} | {f(a['effect'],4)} | [{f(ci[0],4)}, {f(ci[1],4)}] | {f(cs['code']['effect'],4)} | "
                 f"{f(cs['maker_code']['effect'],4)} | {f(cs['maker_meas']['effect'],4)} | {f(a['q'],3)} | {a['verdict_item']} | {f(it['null_mean_net'],4)} | {f(b['effect'],4)} | "
                 f"[{f(cb[0],4)}, {f(cb[1],4)}] | {f(b['q'],3)} | {b['verdict_item']} | {yr} | {bc} |")
    L += ["", "Maker rows ignore missed fills and the code refuses maker entry in live: optimistic, report-only. Only the 0.12 % rows are in the FDR families.", "",
          "## Primary: per year and per coin (net % per trade at 0.12 %, n)", ""]
    for pname in S.PRESETS:
        pre = f"{pname}|PRIMARY|"
        if pre not in summ["metrics"]:
            continue
        mm = summ["metrics"][pre]
        L.append(f"**{pname}**  years: " + "; ".join(f"{y}: n {m.get('n')} gross {f(m.get('gross'),4)} net {f(m.get('net'),4)} win {f(m.get('win_rate') and m['win_rate']*100,1)} %"
                                                  for y, m in zip((2023, 2024, 2025), mm["by_year"])))
        L.append("")
        L.append("coins: " + "; ".join(f"{c}: n {m.get('n')} gross {f(m.get('gross'),4)} net {f(m.get('net'),4)} PF {f(m.get('pf'),2)} maxDD {f(m.get('max_dd'),1)}"
                                       for c, m in mm["by_coin"].items()))
        L.append("")
        L.append("exit reasons (pool): " + ", ".join(f"{k} {v*100:.1f} %" for k, v in mm["pool"].get("reasons", {}).items()))
        L.append("")
    L += ["## Candidate / trade funnel (per coin)", ""]
    for c, st in summ["stats"].items():
        L.append(f"- {c}: " + "; ".join(f"{k}: sig {v['signals']} -> window {v['window_ok']} -> dist {v['after_signal_distance']} -> cancel {v['gap_cancelled']} -> cand {v['candidates']} -> trades {v['trades']}"
                                         for k, v in st.items() if k.endswith("PRIMARY")))
    L.append("")
    Path(results, "REPORT.md").write_text("\n".join(L), "utf-8")
