# AutoFinX Prime v6.1.7 Crypto Strategy Knowledge Base

## Strategy

`AutoFinX Prime v6.1.7 Crypto Stable Default`

`profile_id = autofinx_prime_v6_1_7_crypto`

Use for crypto futures only. Primary defaults are `ETHUSDT.P`, `SOLUSDT.P`, and `BTCUSDT.P`. Do not use this profile as a Forex, XAUUSD, Gold, stock, or unvalidated BNB default.

## Default Timeframe

`15m`

Backend scanner and TradingView webhook validation must enforce 15m for this Prime profile.

## Accepted Core

- HTF EMA trend confirmation
- EMA structure
- VWAP filter
- ADX + DI trend strength
- Relative volume
- ATR volatility bounds
- Donchian soft score
- Choppiness regime filter
- ATR-based risk model

## Rejected As Defaults

- Bayesian hard filter
- UT Bot hard filter
- ATR Expansion Regime filter
- Liquidity / FVG v7 engine
- Score gap filter
- Heavy anti-chop candle filter
- Momentum breakout hard filter
- MACD score dependency

These may remain as separate experiments, but must not affect the Prime default crypto execution path.

## Defaults To Preserve

```text
Market Profile = Crypto
Trade Direction = Both
Trade Score Threshold = 72
Watch Score Threshold = 58
HTF1 = 60
HTF2 = 240
EMA Fast = 9
EMA Slow = 21
EMA Trend = 50
EMA Major = 200
Donchian Length = 20
Use VWAP Filter = true
Use Donchian As Score Only = true
Use Relative Volume Filter = true
Use ATR Volatility Filter = true
Use Choppiness Regime Filter = true
Choppiness Length = 14
Crypto Max Choppiness = 61.8
Use Momentum Breakout Filter = false
RSI Length = 14
Long RSI Min = 48
Long RSI Max = 72
Short RSI Min = 28
Short RSI Max = 52
ADX DI Length = 14
ADX Smoothing = 14
Minimum ADX = 20
Relative Volume Length = 20
Crypto Min Relative Volume = 1.00
ATR Length = 14
ATR Regime Length = 50
Crypto Min ATR % = 0.06
Crypto Max ATR % = 3.00
Base Stop Loss ATR Multiplier = 1.4
TP1 Risk Reward = 1.0
TP2 Risk Reward = 2.0
Use ATR Trailing For Second Half = true
Trailing ATR Multiplier = 1.0
Cooldown Bars After Entry = 3
Exit On Strong Opposite Score = true
Opposite Exit Score = 78
```

## Entry Model

Long requires confirmed bar, cooldown OK, long side enabled, bullish HTF, bullish EMA structure, price above VWAP, ADX/DI bullish, relative volume OK, ATR volatility OK, choppiness OK, `longScore >= 72`, and `longScore > shortScore`.

Short requires confirmed bar, cooldown OK, short side enabled, bearish HTF, bearish EMA structure, price below VWAP, ADX/DI bearish, relative volume OK, ATR volatility OK, choppiness OK, `shortScore >= 72`, and `shortScore > longScore`.

## Score Model

Long and short score weights:

```text
HTF aligned = 20
HTF mixed = 5
EMA aligned = 15
Major EMA aligned = 10
Donchian strong = 10
Donchian mixed = 3
VWAP aligned = 10
RSI range = 5
ADX/DI aligned = 12
Relative volume OK = 5
ATR volatility OK = 3
Momentum OK = 10
```

Momentum breakout filter is OFF by default, so momentum OK is normally true.

## Choppiness

Use Pine-equivalent choppiness:

```text
TR sum = SMA(TR, chopLen) * chopLen
range = highest(high, chopLen) - lowest(low, chopLen)
chop = 100 * log10(TR sum / range) / log10(chopLen)
chopOk = chop <= 61.8
```

## Risk

```text
Risk distance = ATR(14) * dynamic SL multiplier
Base SL multiplier = 1.4
Add 0.20 if ADX < ADX min
Add 0.30 if crypto ATR % > 1.20
TP1 = 1R
TP2 = 2R
Runner uses ATR trailing, multiplier 1.0
```

## Live Preference

Use TradingView Pine webhook execution as the preferred live path until backend mirror CSV comparison is clean:

```text
TradingView Pine v6.1.7 signal -> webhook -> backend validation -> execution
```

Backend mirror remains validation/fallback until:

```text
Signal mismatch < 2%
Entry mismatch ideally 0
HTF mismatch ideally 0
Score mismatch <= 1-2 points
Choppiness mismatch near 0
VWAP mismatch near 0
```
