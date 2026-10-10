"""Experiment identity (D-036 P0): every report records the exact code it ran with and a unique run id, so a
report can never be confused with one produced by a different working tree."""

from __future__ import annotations

import datetime as dt
import hashlib
import platform
import uuid
from pathlib import Path

_CACHE: dict[str, str] = {}


def code_sha() -> str:
    """sha256 over the sorted v4/*.py sources of the package that is actually imported."""
    if "sha" not in _CACHE:
        root = Path(__file__).resolve().parent
        h = hashlib.sha256()
        for p in sorted(root.glob("*.py")):
            h.update(p.name.encode())
            h.update(p.read_bytes())
        _CACHE["sha"] = h.hexdigest()
    return _CACHE["sha"]


def stamp() -> dict:
    import lightgbm
    import numpy
    import sklearn
    return {"run_id": uuid.uuid4().hex, "utc": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "code_sha256": code_sha(), "code_dir": str(Path(__file__).resolve().parent),
            "python": platform.python_version(), "lightgbm": lightgbm.__version__, "numpy": numpy.__version__,
            "sklearn": sklearn.__version__, "fees": __import__("v4.costs", fromlist=["rates"]).rates()}
