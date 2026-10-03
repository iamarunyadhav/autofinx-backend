"""Non-overlapping, fee- and funding-aware trade simulation (shadow evaluation only).

Every 5m anchor is a row, so counting each signal as a trade turns one price
move into dozens of overlapping "trades". This simulator holds at most ONE
position per symbol x horizon: it enters on a signal, exits at T+h, and
ignores every signal while the position is open.

Units: returns, costs and funding are in PERCENT of notional.
  BUY  net = +return - cost - funding_buy
  SELL net = -return - cost + funding_buy     (shorts receive what longs pay)
"""

from __future__ import annotations

from typing import Any, Sequence


def simulate(
    times_ms: Sequence[int],
    signals: Sequence[int],
    returns_pct: Sequence[float],
    funding_buy_pct: Sequence[float],
    cost_pct: float,
    horizon_ms: int,
) -> dict[str, Any]:
    """signals: +1 BUY, -1 SELL, 0 WAIT. Rows must be chronological."""
    open_until = None
    trades: list[dict[str, Any]] = []
    raw = 0
    for index, (t, signal) in enumerate(zip(times_ms, signals)):
        if signal == 0:
            continue
        raw += 1
        if open_until is not None and t < open_until:
            continue
        side = 1 if signal > 0 else -1
        net = side * returns_pct[index] - cost_pct - side * funding_buy_pct[index]
        trades.append({"index": index, "t": int(t), "side": side, "net_pct": float(net)})
        open_until = int(t) + int(horizon_ms)
    nets = [trade["net_pct"] for trade in trades]
    count = len(trades)
    # Per-trade spread and a one-sample t-statistic of the mean net (H0: mean <= 0).
    # A thin edge over few trades is indistinguishable from luck; the gate uses this.
    std = None
    t_stat = None
    if count >= 2:
        mean = sum(nets) / count
        std = (sum((net - mean) ** 2 for net in nets) / (count - 1)) ** 0.5
        t_stat = float(mean / (std / count ** 0.5)) if std > 0 else None
    return {
        "raw_signals": raw,
        "trades": count,
        "long_trades": sum(1 for trade in trades if trade["side"] > 0),
        "short_trades": sum(1 for trade in trades if trade["side"] < 0),
        "total_net_pct": float(sum(nets)),
        "average_net_pct": float(sum(nets) / count) if count else None,
        "net_win_rate_pct": float(sum(1 for net in nets if net > 0) / count * 100) if count else None,
        "std_net_pct": float(std) if std is not None else None,
        "t_stat": t_stat,
        "trade_list": trades,
    }


def baselines(times_ms, returns_pct, funding_buy_pct, cost_pct, horizon_ms) -> dict[str, dict[str, Any]]:
    """Never-trade, always-BUY and always-SELL on the exact same simulation."""
    n = len(times_ms)
    out = {
        "never_trade": simulate(times_ms, [0] * n, returns_pct, funding_buy_pct, cost_pct, horizon_ms),
        "always_buy": simulate(times_ms, [1] * n, returns_pct, funding_buy_pct, cost_pct, horizon_ms),
        "always_sell": simulate(times_ms, [-1] * n, returns_pct, funding_buy_pct, cost_pct, horizon_ms),
    }
    return {name: strip(result) for name, result in out.items()}


def strip(result: dict[str, Any]) -> dict[str, Any]:
    """The per-trade list is for analysis; keep metadata small."""
    return {key: value for key, value in result.items() if key != "trade_list"}


def effective_samples(times_ms: Sequence[int], step_ms: int, horizon_ms: int) -> float:
    """Independent outcomes in a span: (span / horizon). 1d => ~1 per day."""
    if len(times_ms) == 0:
        return 0.0
    span = int(times_ms[-1]) - int(times_ms[0]) + int(step_ms)
    return float(span / horizon_ms)
