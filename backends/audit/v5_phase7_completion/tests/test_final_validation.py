import unittest
from v5_phase7_completion.final_validation import ece

class FinalValidationTest(unittest.TestCase):
    def test_ece_missing_probabilities_is_none(self):
        self.assertIsNone(ece([{'actual_direction':'UP','predicted_class_probabilities':None}]))

if __name__ == '__main__': unittest.main()
