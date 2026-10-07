# Candidate autofinx-v13 — "AutoFinX v13 (Final)" Pine v6 strategy

- Uploaded by the owner 2026-10-07. Source `source.pine`, sha256
  `c39ac4e0a344dff64e5182841b13321a9ee9af362af4921f8767bedca0c8a7e9`. Never executed.
- Translation: `external-predictive-engine/v4/strategy_v13.py`, with 12 tests in
  `tests/v4/test_strategy_v13.py`. Run with `python -m v4.cli lab-v13`.
- Owner rule (2026-10-07): **no hard filter**. Optional evidence only; it is used only if proven.
- Parameters: script defaults, no tuning, 0 variants searched.

## Static audit
| Component | Purpose | Causal | Repaint risk | Existing V4 equivalent | Decision |
|---|---|---|---|---|---|
| EMA 9/21/50/200, slope | direction | yes | none | G3 ema slopes / spread (partial) | translated |
| VWAP (session, UTC day) | direction | yes | none | none | translated |
| RSI 14 bands | direction | yes | none | base rsi + G3 rsi slope | translated |
| DMI / ADX 14 | trend strength | yes | none | none (new) | translated |
| ATR 14, ATR expansion, vol regime | volatility | yes | none | G4 atr_pct / atr_change / vol_pctile | translated |
| Supertrend (3, 7) | direction / hard gate | yes | none (confirmed bars) | none (new) | translated |
| MACD 12/26/9 | momentum | yes | none | G3 macd | translated |
| Choppiness index 14 | noise / hard gate | yes | none | none (new; also a G7-T idea) | translated |
| Donchian latch 20/15/10 | breakout | yes (uses `[1]` highs/lows) | none | G1 structure (partial) | translated |
| HTF trend 1h / 4h / 1D via `request.security(f()[1], lookahead_off)` | MTF bias | yes | **historical ≠ realtime** (see below) | G5 tf ema gap / alignment (partial) | translated with realtime semantics |
| Candle body / engulf / burst / doji | trigger | yes | none | G2 candles (partial) | translated |
| Score (weighted sum) + adaptive threshold | decision | yes | none | none (new) | translated |
| Cooldown 8 bars, pyramiding 0, reversal | execution | yes | none | — | translated |
| SL = slMult × ATR at entry; 50% TP1 at 1.5 R; 50% ATR trail; flip exit at score 80 | exits | yes | intrabar fills | V4 policy (different) | translated, AutoFinX fills |
| Webhook / 3Commas JSON, dashboard, plots | live plumbing / display | — | — | — | **not translated (research never sends orders)** |

Findings:
1. **No lookahead.**
   - `barstate.isconfirmed` gates the signals.
   - The HTF reads use `[1]` with `lookahead_off`, and nothing reads a future bar.
   - **CAUSAL.**
2. **HTF historical vs realtime mismatch.**
   - With `f()[1]` + `lookahead_off`, TradingView's historical bars inside an HTF bar see the HTF value
     from two bars back. A live bar sees the value from one bar back.
   - So the TradingView backtest and live alerts differ slightly. We use the live (realtime) meaning:
     the last HTF bar completed at the chart bar's open.
3. **Live use.**
   - The dashboard prints `/api/v1/future-trade/pine-webhook/{workspace}`, and that route exists
     (`routes/api.php:114`). This script is built to drive **live Future Trade orders**.
   - The research here never touches that path.
4. **Overfit risk.**
   - About 40 inputs, plus hand-set score weights (25/14/15/10/8…), plus per-asset multipliers.
   - The script's own header notes PF 0.989 vs 1.386 on two windows.
5. **Execution differences** (ORIGINAL vs AUTOFINX_NORMALIZED):
   | Item | TradingView (script) | AutoFinX normalized |
   |---|---|---|
   | Entry | signal bar close (`process_orders_on_close`) | 1m open at the signal bar close (same instant) |
   | Exits | placed one bar after entry (exit calls only when position ≠ 0) | live from entry |
   | Intrabar | broker emulator OHLC heuristics | real 1m path; stop before target in the same minute; gaps fill at the open |
   | Cost | 0.04% per side = 0.08% round trip, no funding | 0.12% round trip + actual funding (0.08% result also reported) |

## Roles tested
- **S0 standalone:** a diagnostic.
- **S1 soft features:** 22 `v13_*` columns per 5m anchor, in `features/<SYM>/v13.parquet`.
  - They go into the fold-consistent ablation after P4B (same D-025 rule).
  - They are kept only if they improve the AUC consistently. Never as a hard filter.

Results: RESULTS R-019.
