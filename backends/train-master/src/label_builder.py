from __future__ import annotations

import pandas as pd


def build_labels(
    features_df: pd.DataFrame,
    minimum_profit_threshold: float,
    negative_threshold: float,
) -> pd.DataFrame:
    labeled = features_df.copy()
    labeled["label_win"] = (labeled["net_pnl"] > 0).astype(int)
    labeled["label_good_trade"] = (labeled["net_pnl"] > minimum_profit_threshold).astype(int)
    labeled["label_bad_trade"] = (labeled["net_pnl"] < negative_threshold).astype(int)

    def bucketize(value: float) -> str:
        if value <= negative_threshold * 2:
            return "strong_loss"
        if value < 0:
            return "small_loss"
        if value == 0:
            return "breakeven"
        if value < minimum_profit_threshold * 2:
            return "small_win"
        return "strong_win"

    labeled["profit_bucket"] = labeled["net_pnl"].map(bucketize)
    return labeled
