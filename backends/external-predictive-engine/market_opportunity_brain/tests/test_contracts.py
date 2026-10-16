"""Stdlib-only (unittest, no pytest needed) parity tests for market_opportunity_brain.contracts.

Run with:  python -m unittest external-predictive-engine.market_opportunity_brain.tests.test_contracts -v
(from the backend repo root, i.e. autofinx-backend/backends).

Mirrors tests/Unit/MarketOpportunityBrain/*ContractTest.php on the PHP side -- see
docs/market-opportunity-brain-v4/MASTER_SPEC.md Section M test #23.
"""

import json
import pathlib
import unittest

from market_opportunity_brain.contracts import (
    ContractViolation,
    DecisionRecord,
    DiscoveryRecord,
    ExperimentRecord,
    ForecastRecord,
)

CONTRACTS_DIR = pathlib.Path(__file__).resolve().parents[3] / "docs" / "market-opportunity-brain-v4" / "contracts"


def load_example(name: str) -> dict:
    path = CONTRACTS_DIR / name
    with open(path, "r", encoding="utf-8") as fh:
        return json.load(fh)


class DiscoveryRecordContractTest(unittest.TestCase):
    def test_round_trips_the_example_contract_exactly(self) -> None:
        example = load_example("discovery.v1.example.json")
        record = DiscoveryRecord.from_dict(example)
        self.assertEqual(example, record.to_dict())

    def test_execution_authorized_cannot_be_tampered(self) -> None:
        with self.assertRaises(ContractViolation):
            DiscoveryRecord.from_dict({"execution_authorized": True, "symbol": "FAKEUSDT"})


class ForecastRecordContractTest(unittest.TestCase):
    def test_round_trips_the_example_contract_exactly(self) -> None:
        example = load_example("forecast.v1.example.json")
        record = ForecastRecord.from_dict(example)
        self.assertEqual(example, record.to_dict())

    def test_rejects_out_of_order_quantiles(self) -> None:
        with self.assertRaises(ContractViolation):
            ForecastRecord(
                forecast_id="f1", workspace_id="w1", exchange="BINANCE", market="USDM_PERPETUAL",
                symbol="FAKEUSDT", timeframe="15m", horizon_bars=4, as_of_utc="2026-10-14T00:00:00Z",
                known_by_utc="2026-10-14T00:00:00Z", label_available_at_utc=None, model_name="naive_last",
                model_revision="v1", feature_schema_version="v1", dataset_hash_sha256=None,
                prediction_quantiles={"p10": 100.0, "p50": 90.0, "p90": 110.0},
                probabilities={"up": None, "neutral": None, "down": None},
                probability_calibrated=False, status="OK", reason=None,
            )


class DecisionRecordContractTest(unittest.TestCase):
    def test_round_trips_the_example_contract_exactly(self) -> None:
        example = load_example("decision.v1.example.json")
        record = DecisionRecord.from_dict(example)
        self.assertEqual(example, record.to_dict())

    def test_rejects_forbidden_order_fields(self) -> None:
        example = load_example("decision.v1.example.json")
        for field in ("quantity", "leverage", "api_key", "order_type"):
            tampered = dict(example)
            tampered[field] = "x"
            with self.assertRaises(ContractViolation):
                DecisionRecord.from_dict(tampered)

    def test_cannot_carry_both_realized_and_hypothetical_pnl(self) -> None:
        example = load_example("decision.v1.example.json")
        example["realized_live_pnl"] = 12.5
        example["hypothetical_net_return"] = 3.1
        with self.assertRaises(ContractViolation):
            DecisionRecord.from_dict(example)


class ExperimentRecordContractTest(unittest.TestCase):
    def test_round_trips_the_example_contract_exactly(self) -> None:
        example = load_example("experiment.v1.example.json")
        record = ExperimentRecord.from_dict(example)
        self.assertEqual(example, record.to_dict())


if __name__ == "__main__":
    unittest.main()
