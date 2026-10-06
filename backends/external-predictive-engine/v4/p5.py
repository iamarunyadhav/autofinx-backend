"""P5 building blocks (SPEC §103, D-028). Pure functions so every rule is unit-tested; the orchestration
(training, nested selection runs, freeze record) composes them. Development data only; holdout sealed.

WAIT reason codes (every anchor that does not trade gets exactly one, first failing gate wins):
  REWARD_FLOOR     predicted q25 MFE <= cost + edge
  SL_ABOVE_CAP     required SL > market SL cap
  ACTIVATION_FLOOR activation <= cost + edge
  INCONSISTENT     non-positive level, reward <= activation, or q50 MFE / SL < MIN_RR
  UNCERTAIN        (q75 - q25) / q50 of MFE above its dev_train p90
  OOD              too many / too extreme feature z-scores vs dev_train
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

MIN_SL = 0.10
MIN_TRAIL = 0.05
MIN_RR = 1.0
CONFLICT_MARGIN = 0.02
OOD_SHARE, OOD_Z, OOD_MAX_Z = 0.10, 4.0, 8.0
SL_MULT = (1.0, 1.25)
ACT_MULT = (0.8, 0.6)                 # listed simplest/most conservative first (tie-break order)
TRAIL_RULES = ("vol", "pull_1.0", "pull_1.5")
GATE_LIFTS = (None, 1.25, 1.5, 2.0)
GATE_MARGINS = (0.0, 0.03, 0.06)
DEFAULT_GATE = (None, 0.03)
MIN_TRADES = {15: 40, 60: 30, 120: 20, 240: 15, 1440: 8}
MIN_PF, MIN_T = 1.05, 1.0
TIE_MARGIN = 0.02
WINDOWS = (0.40, 0.30, 0.30)          # P5-A, P5-B, P5-C shares of dev_val

WAIT_CODES = ("TRADE", "REWARD_FLOOR", "SL_ABOVE_CAP", "ACTIVATION_FLOOR", "INCONSISTENT", "UNCERTAIN", "OOD")


@dataclass(frozen=True)
class ExitPolicy:
    a: float
    b: float
    trail: str

    @property
    def complexity(self) -> tuple:
        """Lower = simpler / lower risk (tie-break order, SPEC §103.7)."""
        return (SL_MULT.index(self.a), ACT_MULT.index(self.b), TRAIL_RULES.index(self.trail))


def exit_family() -> list[ExitPolicy]:
    return [ExitPolicy(a, b, tr) for a in SL_MULT for b in ACT_MULT for tr in TRAIL_RULES]


def sl_cap(adverse_before_profit: np.ndarray, ambiguous: np.ndarray) -> float:
    """p90 of Layer A adverse-before-profit on rows that reached the threshold, ambiguity excluded."""
    v = adverse_before_profit[np.isfinite(adverse_before_profit) & ~(ambiguous == 1)]
    if len(v) == 0:
        raise ValueError("no profitable unambiguous rows for the SL cap")
    return float(np.percentile(v, 90))


def repair_crossing(q25: np.ndarray, q50: np.ndarray, q75: np.ndarray) -> tuple[np.ndarray, np.ndarray, np.ndarray, float]:
    """Sort the three quantiles per row; return them and the crossing rate before repair."""
    stacked = np.vstack([q25, q50, q75])
    crossed = float(np.mean((q25 > q50) | (q50 > q75))) if len(q25) else 0.0
    fixed = np.sort(stacked, axis=0)
    return fixed[0], fixed[1], fixed[2], crossed


def pinball(y: np.ndarray, pred: np.ndarray, q: float) -> float:
    d = y - pred
    return float(np.mean(np.maximum(q * d, (q - 1) * d)))


def quality_gate(folds: list[dict], q_used: float) -> dict:
    """One component (e.g. MFE, used at q25) on the P5-A sub-folds.
    folds: [{"y": actual, "q25": pred, "q50": pred, "q75": pred, "const": {0.25: c, 0.5: c, 0.75: c}}]
    (const = dev_train quantile of the target: the simple baseline)."""
    gains, coverage, crossing = [], [], []
    for f in folds:
        lo, mid, hi, crossed = repair_crossing(f["q25"], f["q50"], f["q75"])
        used = {0.25: lo, 0.5: mid, 0.75: hi}[q_used]
        gains.append(pinball(f["y"], np.full(len(f["y"]), f["const"][q_used]), q_used) - pinball(f["y"], used, q_used))
        coverage.append(float(np.mean((f["y"] >= lo) & (f["y"] <= hi)) * 100))
        crossing.append(crossed)
    result = {"pinball_gain": [round(g, 6) for g in gains], "coverage_pct": [round(c, 2) for c in coverage],
              "crossing_rate": round(float(np.mean(crossing)), 4)}
    reasons = []
    if sum(g > 0 for g in gains) < 2 or np.median(gains) <= 0:
        reasons.append("does not beat the constant baseline consistently")
    if not all(40.0 <= c <= 60.0 for c in coverage):
        reasons.append("q25-q75 coverage outside 40-60%")
    if result["crossing_rate"] > 0.05:
        reasons.append("quantile crossing rate above 5%")
    result["passed"] = not reasons
    result["reasons"] = reasons
    return result


def levels(policy: ExitPolicy, q25_mfe, q50_mfe, q75_mfe, q75_adv, q50_pull, sigma, cap: float, cost: float, edge: float,
           unc_limit: float, ood_wait: np.ndarray) -> dict[str, np.ndarray]:
    """Per-anchor dynamic SL / activation / trailing + the first failing WAIT reason (0 = trade)."""
    q25_mfe, q50_mfe, q75_mfe, q75_adv = (np.asarray(v, float) for v in (q25_mfe, q50_mfe, q75_mfe, q75_adv))
    n = len(q25_mfe)
    sl = np.maximum(policy.a * q75_adv, MIN_SL)
    act = policy.b * q25_mfe
    if policy.trail == "vol":
        trail = 0.5 * np.asarray(sigma, float)
    else:
        trail = float(policy.trail.split("_")[1]) * np.asarray(q50_pull, float)
    trail = np.clip(trail, MIN_TRAIL, np.maximum(act, MIN_TRAIL))
    width = np.where(q50_mfe > 0, (q75_mfe - q25_mfe) / np.maximum(q50_mfe, 1e-12), np.inf)
    reason = np.zeros(n, np.int8)

    def mark(cond, code):
        reason[(reason == 0) & cond] = code
    mark(q25_mfe <= cost + edge, 1)
    mark(sl > cap, 2)
    mark(act <= cost + edge, 3)
    mark(~(sl > 0) | ~(act > 0) | ~(trail > 0) | ~(q25_mfe > act) | ~(q50_mfe / sl >= MIN_RR), 4)
    mark(width > unc_limit, 5)
    mark(np.asarray(ood_wait, bool), 6)
    return {"sl": sl, "act": act, "trail": trail, "reason": reason}


def ood_flags(x: np.ndarray, mean: np.ndarray, std: np.ndarray) -> np.ndarray:
    z = np.abs((x - mean) / np.where(std > 0, std, 1.0))
    return (np.mean(z > OOD_Z, axis=1) > OOD_SHARE) | (np.max(z, axis=1) > OOD_MAX_Z)


def decide(p_buy, p_sell, ev_buy, ev_sell, base_buy: float, base_sell: float, lift, margin: float,
           ok_buy: np.ndarray, ok_sell: np.ndarray, edge: float) -> np.ndarray:
    """+1 / -1 / 0. A side needs its level gates passed, EV >= edge + margin, and (if lift) p >= lift x base."""
    pass_buy = ok_buy & (ev_buy >= edge + margin)
    pass_sell = ok_sell & (ev_sell >= edge + margin)
    if lift is not None:
        pass_buy &= p_buy >= lift * base_buy
        pass_sell &= p_sell >= lift * base_sell
    signal = np.zeros(len(p_buy), np.int8)
    signal[pass_buy & ~pass_sell] = 1
    signal[pass_sell & ~pass_buy] = -1
    both = pass_buy & pass_sell
    signal[both & (ev_buy - ev_sell >= CONFLICT_MARGIN)] = 1
    signal[both & (ev_sell - ev_buy >= CONFLICT_MARGIN)] = -1
    return signal


def nested_windows(anchors: np.ndarray, val_idx: np.ndarray, horizon: int) -> dict[str, np.ndarray]:
    """P5-A / P5-B / P5-C chronological windows of dev_val with a purge of H between them."""
    order = val_idx[np.argsort(anchors[val_idx])]
    n = len(order)
    cut_a = int(n * WINDOWS[0])
    cut_b = int(n * (WINDOWS[0] + WINDOWS[1]))
    a, b, c = order[:cut_a], order[cut_a:cut_b], order[cut_b:]
    purge = horizon * 60_000
    if len(a) and len(b):
        b = b[anchors[b] >= anchors[a].max() + purge]
    if len(b) and len(c):
        c = c[anchors[c] >= anchors[b].max() + purge]
    return {"A": a, "B": b, "C": c}


def sub_folds(anchors: np.ndarray, idx: np.ndarray, k: int = 3) -> list[np.ndarray]:
    order = idx[np.argsort(anchors[idx])]
    return [part for part in np.array_split(order, k) if len(part)]


def qualifies(fold_nets: list[np.ndarray], horizon: int) -> tuple[bool, float, str]:
    """Selection rule (§103.7) on executed-trade nets per sub-fold. Returns (ok, score, reason)."""
    allnet = np.concatenate(fold_nets) if fold_nets else np.zeros(0)
    if len(allnet) < MIN_TRADES.get(horizon, 20):
        return False, -np.inf, f"too few trades ({len(allnet)})"
    means = [float(f.mean()) if len(f) else -np.inf for f in fold_nets]
    if sum(m > 0 for m in means) < 2:
        return False, -np.inf, "net not positive in 2 of 3 sub-folds"
    if allnet.sum() <= 0:
        return False, -np.inf, "aggregate net not positive"
    gp, gl = allnet[allnet > 0].sum(), -allnet[allnet < 0].sum()
    if gl > 0 and gp / gl < MIN_PF:
        return False, -np.inf, f"profit factor {gp / gl:.3f} < {MIN_PF}"
    t = allnet.mean() / (allnet.std(ddof=1) / np.sqrt(len(allnet))) if len(allnet) > 1 and allnet.std(ddof=1) > 0 else 0.0
    if t < MIN_T:
        return False, -np.inf, f"t-stat {t:.2f} < {MIN_T}"
    return True, float(np.median([m for m in means if np.isfinite(m)])), "qualified"


def pick(candidates: list[tuple]) -> tuple | None:
    """candidates: [(score, complexity_tuple, ambiguity_rate, key)] of QUALIFIED candidates. Highest score
    wins unless the runner-up is within TIE_MARGIN and simpler / less ambiguous (§103.7)."""
    if not candidates:
        return None
    ranked = sorted(candidates, key=lambda c: -c[0])
    best = ranked[0]
    close = [c for c in ranked if best[0] - c[0] < TIE_MARGIN]
    return min(close, key=lambda c: (c[1], c[2]))


def forward_blocks(anchors: np.ndarray, idx: np.ndarray, horizon: int, k: int = 5) -> list[tuple[np.ndarray, np.ndarray]]:
    """[(train_rows, predict_rows)] for blocks 2..k: each block is predicted by models trained only on
    EARLIER blocks (purged by H). Block 1 is warm-up and gets no prediction (§103.9)."""
    order = idx[np.argsort(anchors[idx])]
    blocks = [part for part in np.array_split(order, k) if len(part)]
    out = []
    for j in range(1, len(blocks)):
        predict = blocks[j]
        train = np.concatenate(blocks[:j])
        train = train[anchors[train] < anchors[predict].min() - horizon * 60_000]
        out.append((train, predict))
    return out
