"""Optional research layers (SL10 tradeability + Strategy Lab S1/S4) — INDEPENDENT of the V4 pipeline.

Owner rule (2026-10-07): noise / chop / external-strategy layers are optional and toggleable. They never
change P4B / P5 code or selections; this module only READS their inputs and writes its own report.
A layer is switched ON later only if this evidence supports it AND the owner approves. No hard filter is
assumed: every layer is reported OFF vs ON side by side.

Layers (each a separate feature file keyed by anchor_time_ms):
  g7t   tradeability / noise features computed here (causal, 5m bars) -> features/<SYM>/g7t.parquet
  v13   owner Pine "AutoFinX v13" components (v4/strategy_v13.py)      -> features/<SYM>/v13.parquet

Data: dev_train ONLY (the D-025 ablation folds 55-70 / 70-85 / 85-100% with purge = H). dev_val stays
reserved for P5 and the holdout stays sealed. Labels: P3 REFERENCE policy, worst-case evaluation.

Per layer and fold, two roles are measured (pre-registered here, before any result):
  FEATURE  baseline classifier vs baseline + layer columns: AUC gain + Brier delta (same rule as P4B, D-025)
  WAIT     a TAKE/SKIP "tradeability" model on the layer columns only, trained on the fold's train rows to
           predict whether the always-take REFERENCE trade (per side) succeeds. ON = keep the anchors whose
           score is in the top KEEP_SHARES of the fold-train score distribution, the rest become WAIT.
           Reported: loss avoidance, profit retention, trade retention, false-WAIT, avg net all vs kept, and
           executed (non-overlapping) avg net + t for OFF and ON.
  RULE     for v13 only, the script's own chop gate (chop <= 62) and its TREND regime as WAIT rules.
Verdict per role (pre-registered):
  FEATURE  USEFUL_AS_FEATURE if keep_group() (P4B rule) passes.
  WAIT     USEFUL_AS_WAIT_LAYER if ON beats OFF in executed avg net by >= MIN_IMPROVEMENT in >= 2 of 3 folds
           with profit retention >= MIN_PROFIT_RETENTION; PROFITABLE_WITH_LAYER additionally needs ON executed
           avg net > 0 in >= 2 of 3 folds. Otherwise NO_VALUE / OVER_FILTERED.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import binance_data as bd
from . import policy as pol
from . import splits
from . import strategy_v13 as v13
from .ablation import folds, keep_group, score
from .download import load_funding
from .lab import reference_levels
from .targets import MINUTE, VALID, anchors_from_5m, dense_minutes
from .train_v4 import COST, MIN_EDGE, TREE, _weights, auc
from .verify import conflict_buckets

KEEP_SHARES = (0.5, 0.3)
MIN_IMPROVEMENT = 0.01            # % per executed trade
MIN_PROFIT_RETENTION = 50.0       # % of gross winning net kept
G7T = ("g7t_eff_12", "g7t_eff_48", "g7t_chop_14", "g7t_chop_48", "g7t_flip_24", "g7t_overlap_12",
       "g7t_failed_bo_48", "g7t_ema_compress", "g7t_body_mean_12", "g7t_wick_mean_12", "g7t_path_ratio_48",
       "g7t_flip_48", "g7t_range_pos_48", "g7t_vol_confirm_12", "g7t_persistence_12")


# ---------------------------------------------------------------- G7-T tradeability features (causal)
def _rolling_sum(x: np.ndarray, n: int) -> np.ndarray:
    cs = np.r_[0.0, np.cumsum(np.nan_to_num(x))]
    out = np.full(len(x), np.nan)
    if len(x) >= n:
        out[n - 1:] = cs[n:] - cs[:-n]
    return out


def _chop(h, l, c, n):
    tr = v13.true_range(h, l, c)
    tr_sum = v13.sma(tr, n) * n
    rng = v13.highest(h, n) - v13.lowest(l, n)
    with np.errstate(divide="ignore", invalid="ignore"):
        out = np.where((rng > 0) & (tr_sum > 0), 100.0 * np.log10(tr_sum / rng) / np.log10(n), np.nan)
    return out


def tradeability_features(bars: dict) -> dict[str, np.ndarray]:
    """Noise / chop descriptors at each 5m bar close. Only bars <= T0 are read (tested). Overlaps with
    G4 (ATR / BB width percentiles) and G5 (MTF alignment) are deliberately excluded (SL review #5)."""
    o, h, l, c = (np.asarray(bars[k], float) for k in ("open", "high", "low", "close"))
    d = np.r_[np.nan, np.diff(c)]
    absd = np.abs(d)
    with np.errstate(divide="ignore", invalid="ignore"):
        eff = {n: np.abs(c - v13.shift(c, n)) / _rolling_sum(absd, n) for n in (12, 48)}
        sign = np.sign(d)
        flips = np.r_[np.nan, (sign[1:] * sign[:-1] < 0).astype(float)]
        flip_24 = _rolling_sum(flips, 24) / 24.0
        ph, pl = v13.shift(h), v13.shift(l)
        inter = np.clip(np.minimum(h, ph) - np.maximum(l, pl), 0, None)
        union = np.maximum(h, ph) - np.minimum(l, pl)
        overlap_12 = v13.sma(np.where(union > 0, inter / union, np.nan), 12)
        hh20, ll20 = v13.shift(v13.highest(h, 20)), v13.shift(v13.lowest(l, 20))
        failed = (((h > hh20) & (c <= hh20)) | ((l < ll20) & (c >= ll20))).astype(float)
        failed_48 = _rolling_sum(failed, 48) / 48.0
        atr = v13.rma(v13.true_range(h, l, c), 14)
        e9, e21, e50 = v13.ema(c, 9), v13.ema(c, 21), v13.ema(c, 50)
        compress = (np.abs(e9 - e21) + np.abs(e21 - e50)) / atr
        rng = h - l
        body = np.where(rng > 0, np.abs(c - o) / rng, np.nan)
        wick = np.where(rng > 0, (rng - np.abs(c - o)) / rng, np.nan)
        path_ratio = _rolling_sum(rng, 48) / (v13.highest(h, 48) - v13.lowest(l, 48))
        flip_48 = _rolling_sum(flips, 48) / 48.0
        hi48, lo48 = v13.highest(h, 48), v13.lowest(l, 48)
        range_pos = (c - lo48) / (hi48 - lo48)
        net12 = np.sign(c - v13.shift(c, 12))
        up, dn = (d > 0).astype(float), (d < 0).astype(float)
        if "volume" in bars:
            v = np.asarray(bars["volume"], float)
            v_up = _rolling_sum(v * up, 12) / _rolling_sum(up, 12)
            v_dn = _rolling_sum(v * dn, 12) / _rolling_sum(dn, 12)
            ratio = np.log(v_up / v_dn)                          # >0: up-bars carry more volume
            vol_confirm = np.where(np.isfinite(ratio), ratio * net12, np.nan)
        else:
            vol_confirm = np.full(len(c), np.nan)
        # share of the last 12 bars that moved WITH the 12-bar net direction
        persistence = (_rolling_sum(up, 12) * (net12 > 0) + _rolling_sum(dn, 12) * (net12 < 0)) / 12.0
        persistence = np.where(np.isfinite(net12), persistence, np.nan)
    return {"g7t_eff_12": eff[12], "g7t_eff_48": eff[48], "g7t_chop_14": _chop(h, l, c, 14), "g7t_chop_48": _chop(h, l, c, 48),
            "g7t_flip_24": flip_24, "g7t_overlap_12": overlap_12, "g7t_failed_bo_48": failed_48, "g7t_ema_compress": compress,
            "g7t_body_mean_12": v13.sma(body, 12), "g7t_wick_mean_12": v13.sma(wick, 12), "g7t_path_ratio_48": path_ratio,
            "g7t_flip_48": flip_48, "g7t_range_pos_48": range_pos, "g7t_vol_confirm_12": vol_confirm,
            "g7t_persistence_12": persistence}


def export_g7t(work: Path, symbol: str) -> Path:
    m5 = bd.load_klines(work, symbol, "5m")
    cols = {"anchor_time_ms": anchors_from_5m(m5)}
    cols.update(tradeability_features(m5))
    path = work / "features" / symbol / "g7t.parquet"
    bd.write_parquet(path, cols)
    return path


# ---------------------------------------------------------------- evaluation
def _load_layer(path: Path, anchors: np.ndarray) -> tuple[list[str], np.ndarray]:
    import pyarrow.parquet as pq
    t = pq.read_table(path).to_pydict()
    a = np.asarray(t["anchor_time_ms"], np.int64)
    names = [k for k in t if k != "anchor_time_ms"]
    pos = np.searchsorted(a, anchors)
    ok = (pos < len(a)) & (a[np.minimum(pos, len(a) - 1)] == anchors)
    x = np.full((len(anchors), len(names)), np.nan)
    for j, n in enumerate(names):
        col = np.asarray(t[n], float)
        x[ok, j] = col[pos[ok]]
    return names, x


def _baseline(work: Path, symbol: str, horizon: int, anchors: np.ndarray) -> tuple[str, list[str], np.ndarray]:
    """P4B-selected features if that report exists (read only), else the V3 base."""
    sel = work / "reports" / "p4b" / f"{symbol}_h{horizon}.json"
    if sel.exists() and (work / "features" / symbol / "v4.parquet").exists():
        groups = json.loads(sel.read_text("utf-8"))["selected_groups"]
        names, x = _load_layer(work / "features" / symbol / "v4.parquet", anchors)
        keep = [j for j, n in enumerate(names) if not n.startswith(("g1", "g2", "g3", "g4", "g5", "g6")) or n[:2] in groups]
        return f"v4 base + {groups or 'no groups'} (P4B)", [names[j] for j in keep], x[:, keep]
    names, x = _load_layer(work / "features" / symbol / "v3.parquet", anchors)
    return "v3 base (no P4B report)", names, x


def _executed(anchors, keep, net, exit_ms) -> np.ndarray:
    idx = pol.non_overlapping(anchors, keep, exit_ms)
    return net[idx]


def wait_metrics(net: np.ndarray, keep: np.ndarray, anchors: np.ndarray, exit_ms: np.ndarray) -> dict:
    """Per-opportunity filter economics + executed (non-overlapping) OFF vs ON."""
    win, lose = net > 0, net < 0
    gp, gl = net[win].sum(), -net[lose].sum()
    off = _executed(anchors, np.ones(len(net), bool), net, exit_ms)
    on = _executed(anchors, keep, net, exit_ms)

    def t(v):
        return round(float(v.mean() / (v.std(ddof=1) / np.sqrt(len(v)))), 3) if len(v) > 1 and v.std(ddof=1) > 0 else None
    return {
        "opportunities": int(len(net)), "kept": int(keep.sum()),
        "trade_retention_pct": round(float(keep.mean() * 100), 2),
        "loss_avoidance_pct": round(float(-net[lose & ~keep].sum() / gl * 100), 2) if gl > 0 else None,
        "profit_retention_pct": round(float(net[win & keep].sum() / gp * 100), 2) if gp > 0 else None,
        "false_wait_pct": round(float((win & ~keep).sum() / max(win.sum(), 1) * 100), 2),
        "avg_net_all_pct": round(float(net.mean()), 5), "avg_net_kept_pct": round(float(net[keep].mean()), 5) if keep.any() else None,
        "executed_off": {"trades": int(len(off)), "avg_net_pct": round(float(off.mean()), 5) if len(off) else None, "t": t(off)},
        "executed_on": {"trades": int(len(on)), "avg_net_pct": round(float(on.mean()), 5) if len(on) else None, "t": t(on)},
    }


def _wait_verdict(fold_metrics: list[dict]) -> str:
    # D-030 rev (2026-10-07): winners must be kept at least in proportion to the trades kept (a fixed 50 %
    # floor made OVER_FILTERED automatic for a 30 % keep share).
    def proportional(m):
        return (m["profit_retention_pct"] or 0) >= m.get("trade_retention_pct", MIN_PROFIT_RETENTION)
    better = [m for m in fold_metrics if m["executed_on"]["avg_net_pct"] is not None and m["executed_off"]["avg_net_pct"] is not None
              and m["executed_on"]["avg_net_pct"] - m["executed_off"]["avg_net_pct"] >= MIN_IMPROVEMENT and proportional(m)]
    if len(better) < 2:
        over = sum(1 for m in fold_metrics if not proportional(m))
        return "OVER_FILTERED" if over >= 2 else "NO_VALUE"
    positive = sum(1 for m in fold_metrics if (m["executed_on"]["avg_net_pct"] or -1) > 0)
    return "PROFITABLE_WITH_LAYER" if positive >= 2 else "USEFUL_AS_WAIT_LAYER"


def _tradeability_model(x, y, anchors, fit_rows):
    import lightgbm as lgb
    model = lgb.LGBMClassifier(**TREE)
    model.fit(x[fit_rows], y[fit_rows], sample_weight=_weights(anchors[fit_rows]))
    return model.booster_


def run(work: Path, symbol: str, horizon: int, layers=("g7t", "v13")) -> dict:
    import pyarrow.parquet as pq
    started = time.monotonic()
    t = {k: np.asarray(v) for k, v in pq.read_table(work / "targets" / symbol / f"h{horizon}.parquet").to_pydict().items()}
    anchors_all = t["anchor_time_ms"].astype(np.int64)
    train_m, _ = splits.dev_train_val(anchors_all)
    rows = np.nonzero((t["status"] == VALID) & train_m)[0]                       # dev_train ONLY
    assert anchors_all[rows].max() < splits.holdout_start_ms(anchors_all)
    anchors = anchors_all[rows]
    source, base_names, xb = _baseline(work, symbol, horizon, anchors)
    usable = np.isfinite(xb).any(axis=1)

    m1 = bd.load_klines(work, symbol, "1m"); m5 = bd.load_klines(work, symbol, "5m")
    grid, funding, conflicts = dense_minutes(m1), load_funding(work, symbol), conflict_buckets(m1, m5)
    y, clean, net, exit_ms = {}, {}, {}, {}
    for side in ("long", "short"):
        ref = reference_levels(t, rows, side, COST, MIN_EDGE)
        sim = pol.simulate(None, anchors, horizon, side, ref["sl"], ref["act"], ref["trail"], COST, funding, conflicts, grid=grid)
        sim = pol.apply_worst_case(sim, grid, anchors, horizon, side, ref["sl"], ref["act"], ref["trail"], COST, funding)
        net[side], exit_ms[side] = np.asarray(sim["net_pct"], float), np.asarray(sim["exit_time_ms"], np.int64)
        y[side] = (net[side] >= MIN_EDGE).astype(int)
        clean[side] = (sim["reason"] != pol.AMBIGUOUS_INTRABAR) & ~sim["same_bar_trail"] & usable
    fold_list = folds(anchors, np.arange(len(anchors)), horizon)
    base_auc, base_brier = score(xb, y, clean, anchors, fold_list)
    report = {"symbol": symbol, "horizon_minutes": horizon, "baseline": {"features": source, "count": len(base_names),
              "fold_auc": [round(a, 4) for a in base_auc], "brier": round(base_brier, 5)},
              "rules": {"keep_shares": KEEP_SHARES, "min_improvement_pct": MIN_IMPROVEMENT,
                        "min_profit_retention_pct": MIN_PROFIT_RETENTION, "data": "dev_train folds only"}, "layers": {}}

    for layer in layers:
        path = work / "features" / symbol / f"{layer}.parquet"
        if not path.exists():
            report["layers"][layer] = {"status": "MISSING", "path": str(path.name)}
            continue
        names, xl = _load_layer(path, anchors)
        out = {"columns": names}
        # FEATURE role (same rule as P4B)
        cand_auc, cand_brier = score(np.column_stack([xb, xl]), y, clean, anchors, fold_list)
        gains = [c - b for c, b in zip(cand_auc, base_auc)]
        ok, why = keep_group(gains, cand_brier - base_brier, len(base_names) + len(names))
        out["feature"] = {"fold_auc": [round(a, 4) for a in cand_auc], "fold_gain": [round(g, 4) for g in gains],
                          "brier_delta": round(cand_brier - base_brier, 6), "reason": why,
                          "verdict": "USEFUL_AS_FEATURE" if ok else "NO_VALUE_AS_FEATURE"}
        # WAIT role: tradeability model on the layer columns only, per side
        out["wait"] = {}
        for side in ("long", "short"):
            per_share = {s: [] for s in KEEP_SHARES}
            fold_auc = []
            for train_rows, test in fold_list:
                tr = train_rows[clean[side][train_rows]]
                booster = _tradeability_model(xl, y[side], anchors, tr)
                test = test[np.isfinite(net[side][test])]
                s_train, s_test = booster.predict(xl[tr]), booster.predict(xl[test])
                fold_auc.append(round(auc(y[side][test], s_test) or 0.5, 4))
                for share in KEEP_SHARES:
                    keep = s_test >= np.quantile(s_train, 1.0 - share)
                    per_share[share].append(wait_metrics(net[side][test], keep, anchors[test], exit_ms[side][test]))
            out["wait"][side] = {"tradeability_auc": fold_auc,
                                 **{f"keep_top_{int(s * 100)}pct": {"folds": m, "verdict": _wait_verdict(m)} for s, m in per_share.items()}}
        # RULE role (v13 only): the script's own gates as optional WAIT rules
        if layer == "v13":
            col = {n: xl[:, j] for j, n in enumerate(names)}
            rules = {"chop_le_62": col["v13_chop"] <= 62.0, "regime_trend": col["v13_regime"] == 1.0}
            out["rule"] = {}
            for rname, keep_all in rules.items():
                for side in ("long", "short"):
                    tests = [test[np.isfinite(net[side][test])] for _, test in fold_list]
                    m = [wait_metrics(net[side][te], keep_all[te], anchors[te], exit_ms[side][te]) for te in tests]
                    out["rule"][f"{rname}_{side}"] = {"folds": m, "verdict": _wait_verdict(m)}
        report["layers"][layer] = out
    report["seconds"] = round(time.monotonic() - started, 1)
    dest = work / "reports" / "optional-layers"
    dest.mkdir(parents=True, exist_ok=True)
    (dest / f"{symbol}_h{horizon}.json").write_text(json.dumps(report, indent=1), "utf-8")
    return report
