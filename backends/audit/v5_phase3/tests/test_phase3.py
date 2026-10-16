import unittest
from v5_phase3.experiment import label, metrics

class Phase3Test(unittest.TestCase):
    def test_neutral_threshold(self):
        self.assertEqual(label(.001, .002), 'SIDEWAYS'); self.assertEqual(label(-.003,.002),'DOWN')
    def test_metrics_confusion_and_coverage(self):
        out=metrics([{'actual':'UP','predicted':'UP'},{'actual':'DOWN','predicted':'SIDEWAYS'}])
        self.assertEqual(out['predictions'],2); self.assertEqual(out['coverage'],.5); self.assertEqual(out['confusion']['UP']['UP'],1)

if __name__=='__main__': unittest.main()
