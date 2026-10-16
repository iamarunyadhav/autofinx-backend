import sqlite3, tempfile, unittest
from pathlib import Path
from v5_phase2.calibration import metrics
from v5_phase2.ingestion import ensure_schema


class Phase2Test(unittest.TestCase):
    def test_schema_is_idempotent(self):
        db = sqlite3.connect(":memory:"); ensure_schema(db); ensure_schema(db)
        self.assertEqual(db.execute("select count(*) from sqlite_master where type='table'").fetchone()[0], 2)

    def test_metrics_without_probabilities_do_not_invent_brier(self):
        out = metrics([{"actual":"UP","predicted":"UP"}, {"actual":"DOWN","predicted":"UP"}])
        self.assertEqual(out["samples"], 2); self.assertEqual(out["accuracy"], .5); self.assertIsNone(out["brier"])

    def test_metrics_with_probabilities(self):
        rows = [{"actual":"UP","predicted":"UP","probabilities":{"UP":.8,"DOWN":.1,"SIDEWAYS":.1}}, {"actual":"DOWN","predicted":"DOWN","probabilities":{"UP":.1,"DOWN":.8,"SIDEWAYS":.1}}]
        self.assertIsNotNone(metrics(rows)["brier"])


if __name__ == "__main__": unittest.main()
