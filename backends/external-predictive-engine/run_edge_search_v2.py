"""Launcher for Edge Search v2 (use `python -I run_edge_search_v2.py <cmd>`; isolated mode ignores the cwd and PYTHON* env)."""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from edge_search_v2.runner import main  # noqa: E402

if __name__ == "__main__":
    sys.exit(main())
