"""Launcher for Quick Trade Replay v1 (use `python -I run_quick_trade_replay_v1.py <dry|run|analyze>`; isolated mode ignores the cwd and PYTHON* env)."""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from quick_trade_replay_v1.runner import main  # noqa: E402

if __name__ == "__main__":
    sys.exit(main())
