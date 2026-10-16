from __future__ import annotations

from dataclasses import dataclass
from typing import Literal, Optional

Side = Literal["LONG", "SHORT"]
ExitEvent = Literal["TP", "SL", "TRAIL_1", "TRAIL_2", "LIQUIDATION", "MANUAL", "OPEN_CENSORED", "AMBIGUOUS_INTRABAR"]


@dataclass(frozen=True)
class ExitPolicy:
    tp_fraction: float
    sl_fraction: float
    trail_activation_fraction: float
    trail_distance_fraction: float
    liquidation_fraction: float

    def __post_init__(self) -> None:
        if not (0 < self.sl_fraction < 1 and self.tp_fraction > 0):
            raise ValueError("invalid TP/SL policy")
        if not (0 < self.trail_activation_fraction < 1 and 0 < self.trail_distance_fraction < 1):
            raise ValueError("invalid trailing policy")
        if not (self.liquidation_fraction > self.sl_fraction):
            raise ValueError("liquidation must be beyond the hard stop")


@dataclass(frozen=True)
class EventTrade:
    symbol: str
    signal_ts_ms: int
    entry_ts_ms: int
    entry_price: float
    side: Side
    policy: ExitPolicy
    model_version: str
    feature_snapshot_hash: str
    execution_authorized: bool = False

    def __post_init__(self) -> None:
        if self.execution_authorized:
            raise ValueError("research trade can never authorize execution")
        if self.entry_price <= 0 or self.entry_ts_ms < self.signal_ts_ms:
            raise ValueError("invalid causal entry")


@dataclass(frozen=True)
class EventOutcome:
    event: ExitEvent
    exit_ts_ms: Optional[int]
    exit_price: Optional[float]
    holding_ms: Optional[int]
    mfe_fraction: float
    mae_fraction: float
    gross_return: Optional[float]
    fee_return: Optional[float]
    slippage_return: Optional[float]
    funding_return: Optional[float]
    net_return: Optional[float]
    ambiguous: bool
    open_censored: bool
    execution_authorized: bool = False

    def __post_init__(self) -> None:
        if self.execution_authorized:
            raise ValueError("research outcome can never authorize execution")
        if self.open_censored and self.event != "OPEN_CENSORED":
            raise ValueError("censored outcome must use OPEN_CENSORED")
