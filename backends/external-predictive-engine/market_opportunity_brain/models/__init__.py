"""Forecast model adapters (MASTER_SPEC.md Layer F). Each module exposes a single
``predict(...) -> ForecastRecord`` function. Heavy dependencies (torch/transformers/chronos) are
imported LAZILY inside the function body, never at module import time, so this package stays
importable -- and the naive baseline stays fully usable -- even on a machine with none of those
installed (Layer F: "If model incompatible/offline, the rest of the app must function").
"""
