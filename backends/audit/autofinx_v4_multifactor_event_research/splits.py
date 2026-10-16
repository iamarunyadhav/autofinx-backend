from __future__ import annotations


def chronological_split(rows: list[dict], development_end_ms: int, validation_end_ms: int) -> dict[str, list[dict]]:
    out = {"development": [], "validation": [], "test": []}
    for row in rows:
        ts = int(row["ts_ms"])
        out["development" if ts <= development_end_ms else "validation" if ts <= validation_end_ms else "test"].append(row)
    return out
