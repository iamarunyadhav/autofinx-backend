from __future__ import annotations

from typing import Iterable, Mapping

from .contracts import EventOutcome, EventTrade


def simulate_event_trade(
    trade: EventTrade,
    candles: Iterable[Mapping[str, float | int]],
    funding_rates: Iterable[tuple[int, float]],
    fee_bps_per_side: float = 5.0,
    slippage_bps_per_side: float = 1.0,
    conservative_ambiguous: bool = True,
) -> EventOutcome:
    """Simulate variable holding using closed OHLC bars; never resolves TP/SL favourably.

    Candle rows require ``ts_ms, open, high, low, close`` and must begin at/after entry. If one
    candle touches both TP and SL, the result is explicitly ambiguous and the conservative policy
    resolves it as SL. No future high/low is used to choose the entry policy.
    """
    rows = list(candles)
    if not rows:
        return EventOutcome("OPEN_CENSORED", None, None, None, 0.0, 0.0, None, None, None, None, None, False, True)
    e = trade.entry_price
    sign = 1.0 if trade.side == "LONG" else -1.0
    tp = e * (1 + sign * trade.policy.tp_fraction)
    sl = e * (1 - sign * trade.policy.sl_fraction)
    liq = e * (1 - sign * trade.policy.liquidation_fraction)
    peak = e
    trough = e
    mfe = 0.0
    mae = 0.0
    hit = None
    exit_price = None
    ambiguous = False
    for row in rows:
        high, low = float(row["high"]), float(row["low"])
        favourable = (high - e) / e if sign > 0 else (e - low) / e
        adverse = (e - low) / e if sign > 0 else (high - e) / e
        mfe = max(mfe, favourable)
        mae = max(mae, adverse)
        peak = max(peak, high) if sign > 0 else peak
        trough = min(trough, low) if sign < 0 else trough
        tp_hit = high >= tp if sign > 0 else low <= tp
        sl_hit = low <= sl if sign > 0 else high >= sl
        liq_hit = low <= liq if sign > 0 else high >= liq
        if tp_hit and sl_hit:
            ambiguous = True
            hit, exit_price = ("SL", sl) if conservative_ambiguous else ("AMBIGUOUS_INTRABAR", None)
        elif liq_hit:
            hit, exit_price = "LIQUIDATION", liq
        elif sl_hit:
            hit, exit_price = "SL", sl
        elif tp_hit:
            hit, exit_price = "TP", tp
        if hit:
            exit_ts = int(row["ts_ms"])
            gross = sign * (exit_price / e - 1)
            funding = sign * sum(rate for ts, rate in funding_rates if trade.entry_ts_ms < ts <= exit_ts)
            fee = -2 * fee_bps_per_side / 10_000
            slip = -2 * slippage_bps_per_side / 10_000
            return EventOutcome(hit, exit_ts, exit_price, exit_ts - trade.entry_ts_ms, mfe, -mae, gross, fee, slip, -funding, gross + fee + slip - funding, ambiguous, False)
    return EventOutcome("OPEN_CENSORED", None, None, None, mfe, -mae, None, None, None, None, None, ambiguous, True)
