from __future__ import annotations

import math
from collections import Counter


def confusion(rows):
    labels = ("UP", "DOWN", "SIDEWAYS")
    matrix = {a: {b: 0 for b in labels} for a in labels}
    for r in rows: matrix[r["actual"]][r["predicted"]] += 1
    return matrix


def metrics(rows):
    """Classification metrics; probabilities are optional and never invented."""
    n = len(rows)
    if not n: return {"samples": 0, "accuracy": None, "balanced_accuracy": None, "brier": None, "ece": None, "confusion": confusion([])}
    labels = ("UP", "DOWN", "SIDEWAYS")
    cm = confusion(rows)
    accuracy = sum(r["actual"] == r["predicted"] for r in rows) / n
    recalls = []
    for label in labels:
        total = sum(cm[label].values())
        recalls.append(cm[label][label] / total if total else None)
    valid = [x for x in recalls if x is not None]
    probs = [r.get("probabilities") for r in rows]
    brier = ece = None
    if all(p is not None for p in probs):
        brier = sum(sum((p.get(k, 0.0) - (1.0 if r["actual"] == k else 0.0)) ** 2 for k in labels) for r, p in zip(rows, probs)) / n
        bins = []
        for r, p in zip(rows, probs):
            top = max(labels, key=lambda k: p.get(k, 0.0)); bins.append((p.get(top, 0.0), top == r["actual"]))
        ece = sum(abs(sum(ok for _, ok in bins) / len(bins) - sum(v for v, _ in bins) / len(bins)) for _ in [0])
    return {"samples": n, "accuracy": accuracy, "balanced_accuracy": sum(valid) / len(valid) if valid else None, "brier": brier, "ece": ece, "confusion": cm}
