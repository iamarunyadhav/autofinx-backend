"""Launcher for Experiment E v1 (use `python -I run_exp_e_v1.py <audit|dry|run|analyze>`; isolated mode ignores the cwd and PYTHON* env)."""
import os
import sys
from pathlib import Path

# OpenBLAS reserves ~1 GB of private memory at import with its default thread count on this PC, which has little free commit; two threads are plenty here.
os.environ.setdefault("OPENBLAS_NUM_THREADS", "2")
os.environ.setdefault("OMP_NUM_THREADS", "4")

sys.path.insert(0, str(Path(__file__).resolve().parent))

from exp_e_v1.runner import main  # noqa: E402

if __name__ == "__main__":
    sys.exit(main())
