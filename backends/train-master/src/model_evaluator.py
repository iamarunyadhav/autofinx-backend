from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd
from sklearn.metrics import accuracy_score, confusion_matrix, f1_score, precision_score, recall_score


def _extract_feature_importance(model: Any, feature_names: list[str]) -> list[dict[str, float]]:
    if hasattr(model, "feature_importances_"):
        raw = model.feature_importances_
    elif hasattr(model, "coef_"):
        raw = np.abs(model.coef_[0])
    else:
        return []

    importance = [
        {"feature": feature, "importance": float(score)}
        for feature, score in zip(feature_names, raw)
    ]
    importance.sort(key=lambda item: item["importance"], reverse=True)
    return importance[:15]


def evaluate_predictions(
    y_true: pd.Series,
    y_pred: np.ndarray,
    probabilities: np.ndarray,
    pnl_series: pd.Series,
    feature_names: list[str],
    model: Any,
    trade_threshold: float = 0.55,
) -> dict[str, Any]:
    trade_mask = probabilities >= trade_threshold
    wait_mask = ~trade_mask

    return {
        "accuracy": float(accuracy_score(y_true, y_pred)),
        "precision": float(precision_score(y_true, y_pred, zero_division=0)),
        "recall": float(recall_score(y_true, y_pred, zero_division=0)),
        "f1_score": float(f1_score(y_true, y_pred, zero_division=0)),
        "confusion_matrix": confusion_matrix(y_true, y_pred).tolist(),
        "win_prediction_accuracy": float(((y_pred == 1) & (y_true == 1)).sum() / max((y_pred == 1).sum(), 1)),
        "loss_avoidance_accuracy": float(((y_pred == 0) & (y_true == 0)).sum() / max((y_pred == 0).sum(), 1)),
        "avg_pnl_when_trade": float(pnl_series[trade_mask].mean()) if trade_mask.any() else 0.0,
        "avg_pnl_when_wait": float(pnl_series[wait_mask].mean()) if wait_mask.any() else 0.0,
        "false_positive_loss_count": int(((y_pred == 1) & (y_true == 0)).sum()),
        "false_negative_missed_win_count": int(((y_pred == 0) & (y_true == 1)).sum()),
        "feature_importance": _extract_feature_importance(model, feature_names),
    }


def render_evaluation_summary(metrics: dict[str, Any], model_name: str) -> str:
    lines = [
        "# Train Master Evaluation Summary",
        "",
        f"- Model: `{model_name}`",
        f"- Accuracy: `{metrics['accuracy']:.4f}`",
        f"- Precision: `{metrics['precision']:.4f}`",
        f"- Recall: `{metrics['recall']:.4f}`",
        f"- F1 Score: `{metrics['f1_score']:.4f}`",
        f"- Win Prediction Accuracy: `{metrics['win_prediction_accuracy']:.4f}`",
        f"- Loss Avoidance Accuracy: `{metrics['loss_avoidance_accuracy']:.4f}`",
        f"- Average PnL When Model Says TRADE: `{metrics['avg_pnl_when_trade']:.4f}`",
        f"- Average PnL When Model Says WAIT: `{metrics['avg_pnl_when_wait']:.4f}`",
        f"- False Positive Loss Count: `{metrics['false_positive_loss_count']}`",
        f"- False Negative Missed Win Count: `{metrics['false_negative_missed_win_count']}`",
        "",
        "## Feature Importance",
    ]
    if metrics["feature_importance"]:
        for item in metrics["feature_importance"]:
            lines.append(f"- `{item['feature']}`: `{item['importance']:.6f}`")
    else:
        lines.append("- Feature importance not available for this model.")

    lines.extend(
        [
            "",
            "## Note",
            "",
            "High classification accuracy alone does not imply a profitable trading strategy.",
        ]
    )
    return "\n".join(lines)
