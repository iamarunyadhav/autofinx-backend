"""Frozen definitions of Experiment F (EXPERIMENT_F_V1.md). Nothing here is tuned; a change after the plan is frozen needs an AMENDMENT."""

from __future__ import annotations

ENGINE_VERSION = "mdb-research-1"
TFS = ("15m", "1h", "4h")                      # analysis timeframes (5m is only the execution / path grid)
PIVOT_K = {"15m": 3, "1h": 3, "4h": 2}         # fractal: strictly higher / lower than k bars each side; confirmed at the close of bar j + k
SENS_K = {"15m": 4, "1h": 4, "4h": 3}          # the ONE declared sensitivity (k + 1 on every timeframe)
ATR_N = 14
ZONE_HALF_ATR = 0.25                           # zone half-width = 0.25 x ATR14 at the confirmation bar
ZONE_MAX_AGE = {"15m": 500, "1h": 500, "4h": 300}      # bars; a zone older than this is EXPIRED (not drawn / not used)
CHOCH_CONFIRM_CLOSES = 3                       # CHoCH CONFIRMED after this many consecutive closes beyond the broken level
VOL_WIN, VOL_MULT = 20, 1.5                    # volume expansion: v[t] >= 1.5 x mean(v[t-20..t-1])
VOLA_RANK_N, VOLA_MIN_PCT = 100, 30.0          # ATR% percentile rank over 100 bars; arm D needs >= 30 (not compression)
RETEST_BARS, RETEST_ATR = 12, 0.25             # descriptive soft confirmation only
# trade geometry (the rule_baseline_v1 floor / cap, same cost argument)
FLOOR_PCT, CAP_PCT = 0.25, 1.50
TP_MULTS = {"TP1R": 1.0, "TP15R": 1.5, "TP2R": 2.0}
TIMEOUT_5M = 72                                # 6 h timeout on the 5m grid
SIGNAL_TF = "15m"
COSTS = {"meas": 0.12, "code": 0.14, "maker_code": 0.11, "maker_meas": 0.08}
FEE_PCT_SIDE, SLIP_PCT_SIDE = 0.05, 0.01       # measured 0.05 % fee + 0.01 % slippage per side = 0.12 % round trip
PRIMARY_COINS = ("BTCUSDT", "ETHUSDT", "SOLUSDT")
DIR_HORIZONS = {"15m": 1, "1h": 4, "4h": 16}   # forward return horizons in 15m bars
S_SEEDS = 100

# arms: name -> (use HTF context, use volume, use volatility, sensitivity pivots)
ARMS = {
    "A": {"htf": False, "vol": False, "vola": False, "sens": False, "role": "primary"},
    "B": {"htf": True, "vol": False, "vola": False, "sens": False, "role": "primary"},
    "C": {"htf": True, "vol": True, "vola": False, "sens": False, "role": "primary"},
    "D": {"htf": True, "vol": False, "vola": True, "sens": False, "role": "primary"},
    "BS": {"htf": True, "vol": False, "vola": False, "sens": True, "role": "sensitivity"},
}
VARIANTS = [f"{a}|{tp}" for a in ("A", "B", "C", "D") for tp in TP_MULTS] + [f"BS|{tp}" for tp in TP_MULTS]

# numeric codes
BULLISH, BEARISH, SIDEWAYS, UNCERTAIN = 1, -1, 0, 2
TREND_NAMES = {1: "BULLISH", -1: "BEARISH", 0: "SIDEWAYS", 2: "UNCERTAIN"}
CH_NONE, CH_UNCONFIRMED, CH_POTENTIAL, CH_CONFIRMED = 0, 1, 2, 3
CH_NAMES = {0: "NONE", 1: "UNCONFIRMED", 2: "POTENTIAL", 3: "CONFIRMED"}
# wait reasons
W_NONE, W_NO_EVENT, W_HTF, W_VOL, W_VOLA, W_BOTH = 0, 1, 2, 3, 4, 5
WAIT_NAMES = {0: "-", 1: "NO_STRUCTURE_EVENT", 2: "HTF_CONTEXT_BLOCK", 3: "VOLUME_BLOCK", 4: "VOLATILITY_BLOCK", 5: "SEVERAL_BLOCKS"}

LABEL = ("REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT (2023-2025 was examined by earlier experiments and this hypothesis was chosen after seeing "
         "those results; the sealed 2026 holdout is not read)")
