from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any

import joblib
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import GradientBoostingClassifier, RandomForestClassifier
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler

from .config import settings
from .model_evaluator import evaluate_predictions, render_evaluation_summary
from .utils import get_logger, save_json


logger = get_logger(__name__)


@dataclass
class TrainingArtifacts:
    model: Any
    feature_columns: list[str]
    metrics: dict[str, Any]
    model_name: str


def _build_preprocessor(frame: pd.DataFrame, feature_columns: list[str]) -> tuple[ColumnTransformer, list[str], list[str]]:
    categorical_features = [
        column for column in feature_columns if frame[column].dtype == "object"
    ]
    numeric_features = [column for column in feature_columns if column not in categorical_features]

    preprocessor = ColumnTransformer(
        transformers=[
            (
                "numeric",
                Pipeline(
                    steps=[
                        ("imputer", SimpleImputer(strategy="median")),
                        ("scaler", StandardScaler()),
                    ]
                ),
                numeric_features,
            ),
            (
                "categorical",
                Pipeline(
                    steps=[
                        ("imputer", SimpleImputer(strategy="most_frequent")),
                        ("encoder", OneHotEncoder(handle_unknown="ignore")),
                    ]
                ),
                categorical_features,
            ),
        ]
    )
    return preprocessor, numeric_features, categorical_features


def _candidate_models() -> dict[str, Any]:
    candidates: dict[str, Any] = {
        "logistic_regression": LogisticRegression(max_iter=2000, random_state=settings.random_state),
        "random_forest": RandomForestClassifier(
            n_estimators=300,
            max_depth=8,
            min_samples_leaf=3,
            random_state=settings.random_state,
        ),
        "gradient_boosting": GradientBoostingClassifier(random_state=settings.random_state),
    }
    try:
        from xgboost import XGBClassifier  # type: ignore

        candidates["xgboost"] = XGBClassifier(
            n_estimators=300,
            max_depth=5,
            learning_rate=0.05,
            subsample=0.9,
            colsample_bytree=0.9,
            random_state=settings.random_state,
            eval_metric="logloss",
        )
    except Exception:
        logger.info("XGBoost not installed; skipping optional model")
    return candidates


def train_quality_model(
    labeled_df: pd.DataFrame,
    feature_columns: list[str],
    output_dir: Path | None = None,
    model_name: str | None = None,
) -> TrainingArtifacts:
    if len(labeled_df) < 10:
        raise ValueError("At least 10 rows are required for training")

    output_dir = output_dir or settings.models_dir
    selected_model_name = model_name or settings.model_name

    X = labeled_df[feature_columns].copy()
    y = labeled_df["label_good_trade"].copy()
    pnl_series = labeled_df["net_pnl"].copy()

    X_train, X_test, y_train, y_test, pnl_train, pnl_test = train_test_split(
        X,
        y,
        pnl_series,
        test_size=settings.test_size,
        random_state=settings.random_state,
        stratify=y if y.nunique() > 1 else None,
    )

    best_pipeline = None
    best_metrics = None
    best_model_name = None
    best_score = -1.0

    models = _candidate_models()
    if selected_model_name != "auto":
        if selected_model_name not in models:
            raise ValueError(
                f"Unknown model '{selected_model_name}'. Available: {', '.join(sorted(models))}"
            )
        models = {selected_model_name: models[selected_model_name]}

    for candidate_name, model in models.items():
        preprocessor, _, _ = _build_preprocessor(X, feature_columns)
        pipeline = Pipeline(
            steps=[
                ("preprocessor", preprocessor),
                ("model", model),
            ]
        )
        pipeline.fit(X_train, y_train)
        y_pred = pipeline.predict(X_test)
        probabilities = pipeline.predict_proba(X_test)[:, 1]

        transformed_names = list(
            pipeline.named_steps["preprocessor"].get_feature_names_out(feature_columns)
        )
        metrics = evaluate_predictions(
            y_true=y_test,
            y_pred=y_pred,
            probabilities=probabilities,
            pnl_series=pnl_test.reset_index(drop=True),
            feature_names=transformed_names,
            model=pipeline.named_steps["model"],
        )
        if metrics["f1_score"] > best_score:
            best_score = metrics["f1_score"]
            best_pipeline = pipeline
            best_metrics = metrics
            best_model_name = candidate_name

    if best_pipeline is None or best_metrics is None or best_model_name is None:
        raise RuntimeError("No candidate model completed training")

    output_dir.mkdir(parents=True, exist_ok=True)
    model_path = output_dir / "trade_quality_model.joblib"
    joblib.dump(best_pipeline, model_path)

    save_json(output_dir / "feature_columns.json", feature_columns)
    save_json(
        output_dir / "model_metadata.json",
        {
            "model_name": best_model_name,
            "feature_columns": feature_columns,
        },
    )
    save_json(settings.reports_dir / "evaluation_report.json", best_metrics)
    (settings.reports_dir / "evaluation_summary.md").write_text(
        render_evaluation_summary(best_metrics, best_model_name),
        encoding="utf-8",
    )

    logger.info("Saved trained model to %s", model_path)
    return TrainingArtifacts(
        model=best_pipeline,
        feature_columns=feature_columns,
        metrics=best_metrics,
        model_name=best_model_name,
    )
