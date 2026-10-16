"""Typed Python mirror of the PHP Data Transfer Objects in
``app/Domain/MarketOpportunityBrain/Data`` -- see docs/market-opportunity-brain-v4/MASTER_SPEC.md
Section M test #23 ("schema contract snapshots PHP/Python/TS"). Pure stdlib (dataclasses only,
no numpy/pandas/pytest dependency) so it can be exercised without installing anything.

RESEARCH ONLY. ``execution_authorized`` is always False and cannot be constructed otherwise.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional

FORBIDDEN_DECISION_FIELDS = ("quantity", "leverage", "api_key", "order_type", "order_id", "exchange_order_id")


class ContractViolation(ValueError):
    """Raised when input data would violate a hard research-only safety invariant."""


@dataclass(frozen=True)
class DiscoveryRecord:
    SCHEMA = "mob.discovery.v1"

    snapshot_id: str
    exchange: str
    market: str
    symbol: str
    workspace_id: str
    as_of_utc: str
    last_closed_candle_utc: Optional[str]
    listed_as_of: bool
    eligible_for_analysis: bool
    rank: Optional[int]
    opportunity_score: Optional[float]
    direction: Optional[str]
    source_freshness_seconds: Optional[int]
    reason_codes: List[str]
    feature_schema_version: str
    feature_window_hash_sha256: Optional[str]

    @staticmethod
    def from_dict(data: Dict[str, Any]) -> "DiscoveryRecord":
        if data.get("execution_authorized", False) is not False:
            raise ContractViolation("DiscoveryRecord.execution_authorized must always be false.")
        return DiscoveryRecord(
            snapshot_id=str(data.get("snapshot_id", "")),
            exchange=str(data.get("exchange", "")),
            market=str(data.get("market", "")),
            symbol=str(data.get("symbol", "")),
            workspace_id=str(data.get("workspace_id", "")),
            as_of_utc=str(data.get("as_of_utc", "")),
            last_closed_candle_utc=data.get("last_closed_candle_utc"),
            listed_as_of=bool(data.get("listed_as_of", False)),
            eligible_for_analysis=bool(data.get("eligible_for_analysis", False)),
            rank=data.get("rank"),
            opportunity_score=data.get("opportunity_score"),
            direction=data.get("direction"),
            source_freshness_seconds=data.get("source_freshness_seconds"),
            reason_codes=list(data.get("reason_codes", [])),
            feature_schema_version=str(data.get("feature_schema_version", "")),
            feature_window_hash_sha256=data.get("feature_window_hash_sha256"),
        )

    def to_dict(self) -> Dict[str, Any]:
        return {
            "schema": self.SCHEMA,
            "snapshot_id": self.snapshot_id,
            "exchange": self.exchange,
            "market": self.market,
            "symbol": self.symbol,
            "workspace_id": self.workspace_id,
            "as_of_utc": self.as_of_utc,
            "last_closed_candle_utc": self.last_closed_candle_utc,
            "listed_as_of": self.listed_as_of,
            "eligible_for_analysis": self.eligible_for_analysis,
            "rank": self.rank,
            "opportunity_score": self.opportunity_score,
            "direction": self.direction,
            "source_freshness_seconds": self.source_freshness_seconds,
            "reason_codes": list(self.reason_codes),
            "feature_schema_version": self.feature_schema_version,
            "feature_window_hash_sha256": self.feature_window_hash_sha256,
            "execution_authorized": False,
        }


@dataclass(frozen=True)
class ForecastRecord:
    SCHEMA = "mob.forecast.v1"

    forecast_id: str
    workspace_id: str
    exchange: str
    market: str
    symbol: str
    timeframe: str
    horizon_bars: int
    as_of_utc: str
    known_by_utc: str
    label_available_at_utc: Optional[str]
    model_name: str
    model_revision: str
    feature_schema_version: str
    dataset_hash_sha256: Optional[str]
    prediction_quantiles: Dict[str, Optional[float]]
    probabilities: Dict[str, Optional[float]]
    probability_calibrated: bool
    status: str
    reason: Optional[str]

    def __post_init__(self) -> None:
        q = self.prediction_quantiles
        for key in ("p10", "p50", "p90"):
            if key not in q:
                raise ContractViolation(f"ForecastRecord.prediction_quantiles missing key: {key}")
        if q["p10"] is not None and q["p50"] is not None and q["p10"] > q["p50"]:
            raise ContractViolation("ForecastRecord.prediction_quantiles p10 must be <= p50.")
        if q["p50"] is not None and q["p90"] is not None and q["p50"] > q["p90"]:
            raise ContractViolation("ForecastRecord.prediction_quantiles p50 must be <= p90.")

        p = self.probabilities
        for key in ("up", "neutral", "down"):
            if key not in p:
                raise ContractViolation(f"ForecastRecord.probabilities missing key: {key}")
            val = p[key]
            if val is not None and (val < 0.0 or val > 1.0):
                raise ContractViolation(f"ForecastRecord.probabilities.{key} must be within [0,1] or null.")
        total = sum(v for v in p.values() if v is not None)
        if total > 1.0 + 1e-9:
            raise ContractViolation("ForecastRecord.probabilities must not sum above 1.0.")

    @staticmethod
    def from_dict(data: Dict[str, Any]) -> "ForecastRecord":
        if data.get("execution_authorized", False) is not False:
            raise ContractViolation("ForecastRecord.execution_authorized must always be false.")
        return ForecastRecord(
            forecast_id=str(data.get("forecast_id", "")),
            workspace_id=str(data.get("workspace_id", "")),
            exchange=str(data.get("exchange", "")),
            market=str(data.get("market", "")),
            symbol=str(data.get("symbol", "")),
            timeframe=str(data.get("timeframe", "")),
            horizon_bars=int(data.get("horizon_bars", 0)),
            as_of_utc=str(data.get("as_of_utc", "")),
            known_by_utc=str(data.get("known_by_utc", "")),
            label_available_at_utc=data.get("label_available_at_utc"),
            model_name=str(data.get("model_name", "")),
            model_revision=str(data.get("model_revision", "")),
            feature_schema_version=str(data.get("feature_schema_version", "")),
            dataset_hash_sha256=data.get("dataset_hash_sha256"),
            prediction_quantiles=dict(data.get("prediction_quantiles") or {"p10": None, "p50": None, "p90": None}),
            probabilities=dict(data.get("probabilities") or {"up": None, "neutral": None, "down": None}),
            probability_calibrated=bool(data.get("probability_calibrated", False)),
            status=str(data.get("status", "UNAVAILABLE")),
            reason=data.get("reason"),
        )

    def to_dict(self) -> Dict[str, Any]:
        return {
            "schema": self.SCHEMA,
            "forecast_id": self.forecast_id,
            "workspace_id": self.workspace_id,
            "exchange": self.exchange,
            "market": self.market,
            "symbol": self.symbol,
            "timeframe": self.timeframe,
            "horizon_bars": self.horizon_bars,
            "as_of_utc": self.as_of_utc,
            "known_by_utc": self.known_by_utc,
            "label_available_at_utc": self.label_available_at_utc,
            "model_name": self.model_name,
            "model_revision": self.model_revision,
            "feature_schema_version": self.feature_schema_version,
            "dataset_hash_sha256": self.dataset_hash_sha256,
            "prediction_quantiles": dict(self.prediction_quantiles),
            "probabilities": dict(self.probabilities),
            "probability_calibrated": self.probability_calibrated,
            "status": self.status,
            "reason": self.reason,
            "execution_authorized": False,
        }


@dataclass(frozen=True)
class DecisionRecord:
    SCHEMA = "mob.decision.v1"
    MODE = "RESEARCH_ONLY"

    decision_id: str
    workspace_id: str
    exchange: str
    market: str
    symbol: str
    base_timeframe: str
    horizon_bars: int
    decision_as_of_utc: str
    inputs_known_by_utc: str
    candidate_id: Optional[str]
    forecast_id: Optional[str]
    policy_id: str
    policy_version: str
    decision: str
    directional_bias: Optional[str]
    reason_codes: List[str]
    forecast_calibrated: bool
    data_quality: str
    simulation_cost_profile: str
    input_hash_sha256: Optional[str]
    realized_live_pnl: Optional[float] = None
    hypothetical_net_return: Optional[float] = None
    note: Optional[str] = None

    def __post_init__(self) -> None:
        valid_decisions = {"LONG_CANDIDATE", "SHORT_CANDIDATE", "WAIT", "REJECT"}
        if self.decision not in valid_decisions:
            raise ContractViolation(f"DecisionRecord.decision must be one of {valid_decisions}, got {self.decision!r}.")
        if self.realized_live_pnl is not None and self.hypothetical_net_return is not None:
            raise ContractViolation(
                "DecisionRecord cannot carry both realized_live_pnl and hypothetical_net_return."
            )

    @staticmethod
    def from_dict(data: Dict[str, Any]) -> "DecisionRecord":
        for forbidden in FORBIDDEN_DECISION_FIELDS:
            if forbidden in data:
                raise ContractViolation(f"DecisionRecord must never carry field: {forbidden}")
        if data.get("execution_authorized", False) is not False:
            raise ContractViolation("DecisionRecord.execution_authorized must always be false.")
        if data.get("mode", DecisionRecord.MODE) != DecisionRecord.MODE:
            raise ContractViolation("DecisionRecord.mode must always be RESEARCH_ONLY.")

        return DecisionRecord(
            decision_id=str(data.get("decision_id", "")),
            workspace_id=str(data.get("workspace_id", "")),
            exchange=str(data.get("exchange", "")),
            market=str(data.get("market", "")),
            symbol=str(data.get("symbol", "")),
            base_timeframe=str(data.get("base_timeframe", "")),
            horizon_bars=int(data.get("horizon_bars", 0)),
            decision_as_of_utc=str(data.get("decision_as_of_utc", "")),
            inputs_known_by_utc=str(data.get("inputs_known_by_utc", "")),
            candidate_id=data.get("candidate_id"),
            forecast_id=data.get("forecast_id"),
            policy_id=str(data.get("policy_id", "")),
            policy_version=str(data.get("policy_version", "")),
            decision=str(data.get("decision", "WAIT")),
            directional_bias=data.get("directional_bias"),
            reason_codes=list(data.get("reason_codes", [])),
            forecast_calibrated=bool(data.get("forecast_calibrated", False)),
            data_quality=str(data.get("data_quality", "UNVERIFIED_SAMPLE")),
            simulation_cost_profile=str(data.get("simulation_cost_profile", "")),
            input_hash_sha256=data.get("input_hash_sha256"),
            realized_live_pnl=data.get("realized_live_pnl"),
            hypothetical_net_return=data.get("hypothetical_net_return"),
            note=data.get("note"),
        )

    def to_dict(self) -> Dict[str, Any]:
        return {
            "schema": self.SCHEMA,
            "decision_id": self.decision_id,
            "workspace_id": self.workspace_id,
            "exchange": self.exchange,
            "market": self.market,
            "symbol": self.symbol,
            "base_timeframe": self.base_timeframe,
            "horizon_bars": self.horizon_bars,
            "decision_as_of_utc": self.decision_as_of_utc,
            "inputs_known_by_utc": self.inputs_known_by_utc,
            "candidate_id": self.candidate_id,
            "forecast_id": self.forecast_id,
            "policy_id": self.policy_id,
            "policy_version": self.policy_version,
            "decision": self.decision,
            "directional_bias": self.directional_bias,
            "reason_codes": list(self.reason_codes),
            "forecast_calibrated": self.forecast_calibrated,
            "data_quality": self.data_quality,
            "simulation_cost_profile": self.simulation_cost_profile,
            "input_hash_sha256": self.input_hash_sha256,
            "execution_authorized": False,
            "mode": self.MODE,
            "realized_live_pnl": self.realized_live_pnl,
            "hypothetical_net_return": self.hypothetical_net_return,
            "note": self.note,
        }


@dataclass(frozen=True)
class ExperimentRecord:
    SCHEMA = "mob.experiment.v1"

    experiment_id: str
    status: str
    workspace_id: str
    hypothesis: str
    registration_sha256: Optional[str]
    dataset_sha256: Optional[str]
    universe_policy: str
    timeframe: str
    horizon_bars: int
    arms: List[str]
    holdout_policy: str
    model_revision: Optional[str]
    cost_profile_version: Optional[str]
    sample_count: int
    metrics: Optional[Dict[str, Any]]
    verdict: str

    def __post_init__(self) -> None:
        if self.holdout_policy != "SEALED_NO_ACCESS" and "sealed" in self.holdout_policy.lower():
            raise ContractViolation(
                "ExperimentRecord.holdout_policy must be the exact literal SEALED_NO_ACCESS when sealed."
            )

    @staticmethod
    def from_dict(data: Dict[str, Any]) -> "ExperimentRecord":
        if data.get("execution_authorized", False) is not False:
            raise ContractViolation("ExperimentRecord.execution_authorized must always be false.")
        return ExperimentRecord(
            experiment_id=str(data.get("experiment_id", "")),
            status=str(data.get("status", "PREREGISTRATION_DRAFT")),
            workspace_id=str(data.get("workspace_id", "")),
            hypothesis=str(data.get("hypothesis", "")),
            registration_sha256=data.get("registration_sha256"),
            dataset_sha256=data.get("dataset_sha256"),
            universe_policy=str(data.get("universe_policy", "")),
            timeframe=str(data.get("timeframe", "")),
            horizon_bars=int(data.get("horizon_bars", 0)),
            arms=list(data.get("arms", [])),
            holdout_policy=str(data.get("holdout_policy", "SEALED_NO_ACCESS")),
            model_revision=data.get("model_revision"),
            cost_profile_version=data.get("cost_profile_version"),
            sample_count=int(data.get("sample_count", 0)),
            metrics=data.get("metrics"),
            verdict=str(data.get("verdict", "NOT_RUN")),
        )

    def to_dict(self) -> Dict[str, Any]:
        return {
            "schema": self.SCHEMA,
            "experiment_id": self.experiment_id,
            "status": self.status,
            "workspace_id": self.workspace_id,
            "hypothesis": self.hypothesis,
            "registration_sha256": self.registration_sha256,
            "dataset_sha256": self.dataset_sha256,
            "universe_policy": self.universe_policy,
            "timeframe": self.timeframe,
            "horizon_bars": self.horizon_bars,
            "arms": list(self.arms),
            "holdout_policy": self.holdout_policy,
            "model_revision": self.model_revision,
            "cost_profile_version": self.cost_profile_version,
            "sample_count": self.sample_count,
            "metrics": self.metrics,
            "verdict": self.verdict,
            "execution_authorized": False,
        }
