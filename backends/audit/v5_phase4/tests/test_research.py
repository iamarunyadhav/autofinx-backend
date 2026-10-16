import json, tempfile, unittest
from pathlib import Path
from v5_phase4.research import dependency_status, summarize

class Phase4Test(unittest.TestCase):
    def test_summary_is_research_only(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'x.json'; p.write_text(json.dumps({'models':['x'],'summary':{}}))
            self.assertFalse(summarize(p)['execution_authorized'])
    def test_dependency_status_is_explicit(self):
        self.assertIn('scikit_learn', dependency_status())
