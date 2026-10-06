# Feature manifest — schema `qt-shadow-features-v4` (v3 + G1–G6)

Source of truth: `app/Domain/Ml/Shadow/ShadowFeatureBuilder.php` (v1–v3) and
`app/Domain/Ml/Shadow/PatternFeatures.php` (G1–G6). If this file and the code disagree, the code wins.

**Common rules for every feature**
- Input = the 865 CLOSED native 5m candles ending with the anchor candle; T0 = anchor close
  (open_time + 5m). Knowable at T0 for every feature below (no future candle exists in the input).
- Recursive indicators (EMA, RSI, ATR, MACD) run on FIXED 200-bar windows ending at the bar named,
  so the value never depends on how much history was loaded.
- Test status (backend `php artisan test`):
  K = `PatternFeaturesTest::test_K_future_candles_cannot_enter_v4_features` (all v4 features: future
  candles rewritten → every value identical) and `ShadowFeatureLabelTest::test_future_candles_cannot_enter_the_feature_vector` (v1–v3);
  L5 = `test_L5_higher_timeframe_features_never_read_the_bucket_still_forming`;
  L6 = `test_L6_a_swing_is_not_used_before_its_confirmation_bars_close`;
  A = `test_v4_is_v3_plus_pattern_features_with_identical_v3_values` (append-only, all finite).

## Base (V3, 37 features) — unchanged, tests K + A
| Feature | Meaning | Lookback (5m bars) |
|---|---|---|
| ret_1/3/6/12/24/48 | log return close/close n bars ago | 1–48 |
| vol_12, vol_48 | stdev of 5m log returns | 12, 48 |
| range_1 | (high−low)/close of the anchor bar | 1 |
| range_mean_12 | mean (high−low)/close | 12 |
| body_1 | (close−open)/open of the anchor bar | 1 |
| dist_high_48, dist_low_48 | log distance to max high / min low | 48 |
| volume_ratio_12, _48 | anchor volume / mean of prior n | 12, 48 |
| rsi_14 | RSI(14)/100 on the 64-bar window | 64 |
| ema_gap_12, ema_gap_48 | close/EMA − 1 on the 64-bar window | 64 |
| hour_sin/cos, dow_sin/cos | time of T0 (UTC) | 0 |
| taker_ratio_1/12/48, taker_ratio_shift | taker-buy share; shift = 12 − 48 | 1–48 |
| ret_12h/24h/72h | log return over 144/288/864 bars | 864 |
| vol_24h, vol_regime | day volatility; vol_12 / vol_24h | 288 |
| dist_high_24h, dist_low_24h, range_pos_72h | position vs day / 3-day range | 288–864 |
| vwap_gap_24h, taker_ratio_24h, volume_ratio_24h | day VWAP gap; day taker share; last hour vs day | 288 |

## G1 market structure (9) — swing = fractal k = 3, usable only when its 3 right-side bars have closed (lag 3 bars = 15 min); tests K + L6
| Feature | Formula |
|---|---|
| g1_hh_share | share of rising swing highs among the last 4 confirmed swing highs (0.5 if < 2) |
| g1_hl_share | share of rising swing lows among the last 4 confirmed swing lows |
| g1_dist_swing_high | log(last confirmed swing high / close) |
| g1_dist_swing_low | log(close / last confirmed swing low) |
| g1_break_up_age | consecutive closes above the last swing high, ending at T0, cap 48, /48 |
| g1_break_down_age | same below the last swing low |
| g1_failed_break_up | 1 if a high in the last 12 bars exceeded the swing high but the anchor close is below it |
| g1_failed_break_down | mirror |
| g1_swing_range_pos | (close − swing low) / (swing high − swing low), clipped [−1, 2] |

## G2 candle shape / sequence (14) — last 3 closed bars; tests K
| Feature | Formula |
|---|---|
| g2_upper_wick_b, g2_lower_wick_b, g2_body_b (b = 1, 2, 3; 1 = anchor) | wick/body as a share of that bar's range; body signed |
| g2_close_pos_1 | (close − low) / range of the anchor bar |
| g2_streak | signed run of same-colour candles ending at T0, cap 10, /10 |
| g2_inside_bar, g2_outside_bar | anchor bar inside / engulfing the previous bar's range |
| g2_range_expansion | anchor range / mean range of the 12 prior bars |

## G3 momentum derivatives (9) — fixed 200-bar windows ending at T0, T0−3, T0−6 bars; tests K
| Feature | Formula |
|---|---|
| g3_rsi_slope | RSI14(T0) − RSI14(T0−3), /100 |
| g3_rsi_accel | (RSI(T0)−RSI(T0−3)) − (RSI(T0−3)−RSI(T0−6)), /100 |
| g3_macd_line, g3_macd_hist | MACD(12,26,9) line / histogram, / close |
| g3_macd_hist_slope | histogram(T0) − histogram(T0−3), / close |
| g3_ema20_slope, g3_ema50_slope | EMA(T0)/EMA(T0−3) − 1 |
| g3_ema_spread | EMA20/EMA50 − 1 |
| g3_ema_spread_change | spread(T0) − spread(T0−3) |

## G4 volatility / squeeze (6) — tests K
| Feature | Formula |
|---|---|
| g4_atr_pct | ATR14 (Wilder, 200-bar window) / close |
| g4_atr_change | ATR14(T0) / ATR14(T0−12) − 1 |
| g4_bb_width | Bollinger(20, 2) (upper − lower) / middle |
| g4_bb_position | (close − lower) / (upper − lower) |
| g4_bb_width_ratio | width(T0) / mean width sampled every 12 bars over the last 288 bars |
| g4_vol_pctile | share of the 24 sampled 12-bar volatilities (last 288 bars) ≤ the current one |

## G5 multi-timeframe (16) — 15m / 1h / 4h bars built from UTC-aligned buckets that are COMPLETE at T0 (bucket end ≤ T0); the forming bucket is never read; last 16 complete bars; tests K + L5
| Feature (tf = 15m, 1h, 4h) | Formula |
|---|---|
| g5_tf_ret3 | log(close of last complete bar / close 3 bars earlier) |
| g5_tf_ema_gap | close / EMA8 of the last 16 complete closes − 1 |
| g5_tf_structure | +1 higher high and higher low vs the previous bar, −1 lower/lower, else 0 |
| g5_tf_body | (close − open) / open of the last complete bar |
| g5_tf_age | (T0 − end of the last complete bucket) / bucket length (0 = closed exactly at T0) |
| g5_alignment | mean sign of: 5m close vs EMA12, and the three tf ema gaps |

## G6 volume / taker (3) — tests K
| Feature | Formula |
|---|---|
| g6_volume_z | (log anchor volume − mean) / stdev of log volume of the 48 prior bars |
| g6_volume_trend | mean volume of the last 12 bars / mean of the 36 before |
| g6_taker_shift_12 | taker-buy share of the last 12 bars − share of the 12 before |

Total: 37 base + 57 pattern feature columns listed above = `FEATURES_V4` (exact list in
`PatternFeatures::NAMES`). Feature cap for any selected model: 80 TOTAL features (D-025).
