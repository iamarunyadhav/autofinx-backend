# LLM-analyst shadow comparison — DESIGN ONLY (v1, 2026-10-10)

Status: **design document. Nothing here was run.** No LLM API was called, no API key was read or used, no prompt was sent, no data was uploaded anywhere. Owner request D-050 point 3.
Nothing in this design touches Quick Trade, Ratio Bot, Future Trade or any live path; the output of an LLM analyst would be written to a local shadow log only and never reach an
order, a sizing rule or a user screen.

## 1. Question

Does an LLM "analyst" that reads the same point-in-time market information as the rule baseline (`RULE_BASELINE_V1.md`) produce decisions (BUY / SELL / WAIT with a stated
confidence) that (a) agree or disagree with the rule baseline in a way we can characterise, (b) are calibrated in their own stated confidence, and (c) have a positive NET
expectancy in forward paper trading under the same costs and exits? The expected prior is NO (the ML directional work, D-046 / D-049, found no after-cost edge in this data family);
the design exists so that if the owner wants to test it, it is tested without leakage and without self-deception.

## 2. Hard contamination warning (read first)

**A large language model has very probably seen 2023-2025 crypto price history, news, analyst commentary and possibly the exact candles in its training data.** A historical replay
(feeding it 2023-2025 bars and scoring its answers against what happened next) is therefore **NOT trustworthy evidence**: a correct call may be recall, not analysis, and even
an anonymised series (removed dates / ticker, rescaled prices) can be recognised by its shape and volatility signature. Any historical number is at best a *plumbing test*
("does the pipeline run, is the output parseable, are the costs right"), and is labelled so. The ONLY admissible performance evidence is **forward shadow logging**: decisions
made on data that did not exist when the model was trained (data with timestamps after the model's documented training cutoff, recorded live), logged BEFORE the outcome exists, and
scored later. The sealed 2026 holdout (2026-01-04+) is not used for this either: the model's cutoff may be later than 2026-01-04, so it is not clean for an LLM, and the holdout stays sealed.

## 3. Point-in-time input contract (no leakage)

The prompt for a decision at time T (a CLOSED bar of the chart timeframe; the order would be placed at the next bar's open) may contain ONLY:

- Closed candles up to and including T (OHLCV of the chart timeframe, last N bars, e.g. N = 120 for 5m and 15m; plus 1h and 4h summary bars that are already closed at T).
- Indicators computed from those bars only, by the same causal code the experiments use (EMA20 / 50 / 200, ATR14, the 20-bar highest high / lowest low, last CONFIRMED fractal swing
  high / low with its confirmation rule, realised volatility, funding rate known at T, distance to the rule baseline's stop in %).
- The Quick Trade preset (chart timeframe, expiry) and the fixed cost model (0.12 % round trip) as text constants.
- Optionally (separate arm) the rule baseline's own decision at T, to measure anchoring (arm B below).

It must NOT contain: any candle or value after T; the trade outcome; any news / headline / social text unless it is stored with a verified publication timestamp <= T (default: NO
news in v1, so a missing timestamp cannot leak); the ticker name and real dates are replaced by neutral tokens in the plumbing-test arm (not a contamination fix, see section 2);
the next bar's open; any ML model output (frozen per D-050, and its confidence is not a probability). A unit test (the same prefix-invariance pattern as the rule tests) must prove
the prompt builder gives identical text for data[:T] and data[:T+k]. Prompt construction is the only code allowed to touch market data; the LLM client has no data access.

## 4. Frozen protocol

- **Pre-registration before the first call**: prompt text (full), output schema, model id, model version / snapshot string, temperature 0, top_p 1, max output tokens, seed (if the API
  has one), the list of arms, the decision times, the scoring code and its sha256 in a `LLM_SHADOW_PLAN.md` + `PLAN_SHA256.txt` (same discipline as the other plans). A changed prompt or a
  model upgrade = a NEW experiment with a new id; the old log is never mixed.
- **Output schema (JSON only, parsed strictly)**: `{"action": "BUY|SELL|WAIT", "confidence": 0..100, "stop_pct": number|null, "rationale": "<=60 words"}`. Anything unparsable is logged as
  `PARSE_FAIL` and counted as WAIT (never retried with a changed prompt; one retry only for transport errors with the identical request).
- **Raw logging**: every request (exact prompt text and its sha256), every raw response, model id as returned by the API, latency, token counts, request time (UTC, exchange-corrected),
  the decision bar T, in an append-only JSONL with a hash chain (each line contains the sha256 of the previous line) so nothing can be edited after the outcome is known.
- **Decision clock**: decisions are produced right after bar T closes and written before bar T+1 opens; the outcome is attached later by a separate scoring script that reads the
  market data and the log, never the other way round.
- **Arms** (all shadow, same inputs except where noted): A = LLM analyst (candles + indicators); B = LLM analyst + the rule baseline's decision shown (anchoring test); C = the rule baseline
  (no LLM); D = random side at the same bars (null, many seeds); E = always WAIT. Exits and costs for any scored trade are exactly the rule baseline's engine (swing / ATR stop with the
  floor and cap, 2 R, expiry timeout, worst-case intrabar, 0.12 % cost, actual funding) so only the DIRECTION decision differs.
- **Decision times**: only bars where the rule baseline (or a declared superset) produces a candidate, plus a pre-declared random sample of other bars (for the WAIT rate), to limit cost.

## 5. Replay protocol (plumbing only) and forward protocol (evidence)

1. **Plumbing replay (historical, NOT evidence)**: 200-500 decisions on 2023-2025 bars (development window only) with neutralised tokens, to test the parser, the schema, the cost
   estimate, the hash chain and the scoring code. Results are never reported as performance and never used to choose a prompt (choosing a prompt on them = tuning on contaminated data).
2. **Forward shadow (evidence)**: start only after the plan hash is frozen, using live closed bars (the collector already records exchange data on the PC; a bar feed is available
   from the public Binance REST API with no key). Run for a pre-declared period and a pre-declared minimum number of trades. Score with the section 7 metrics; an interim look is
   allowed only for operational health (parse rate, latency), never for performance.
3. Minimum evidence before anything beyond shadow: the same bar the other experiments use: net per trade > 0 with a day-block CI90 lower bound > 0 at 0.12 % cost, beats the random-side null
   (arm D) and the rule baseline (arm C) by >= 0.03 % per trade, MDE <= 0.06 %, positive in each calendar month of the forward period, BH-corrected across the arms. Even then it is only
   a candidate for a PAPER (not live) trial; production use would need a separate owner decision.

## 6. Cost and latency estimate (formulae; price per token is a parameter, not an assumption of this document)

Assumptions to be re-measured in the plumbing replay: input about 3,000-4,000 tokens per decision (120 bars x ~25 tokens of compact OHLCV = ~3,000, plus indicators and instructions
~700), output about 80-150 tokens (JSON + short rationale), latency about 2-10 s per call (transport + generation), sequential is enough because decisions are per closed bar.

| decisions N | input tokens (3.5k each) | output tokens (120 each) | wall time at 5 s sequential |
|---|---|---|---|
| 500 (plumbing) | 1.75 M | 60 k | about 42 min |
| 5,000 | 17.5 M | 0.6 M | about 7 h |
| 50,000 | 175 M | 6 M | about 69 h (parallelise 4x = 17 h) |

Cost = (input tokens / 1e6) x price_in + (output tokens / 1e6) x price_out, with the prices of the chosen model at the time (to be read from the provider's price page by the owner;
prompt caching of the fixed instruction block can cut the input share). Volume guide: BTC alone has about 22 breakout candidates per day on 5m (24,128 raw signals in 3 years, BTC
5m PRIMARY signal count measured in the feasibility look of `RULE_BASELINE_V1.md` section 8), so a 4-coin, 3-preset forward run on candidate bars only is of the order of a few hundred decisions per day
(about 1-3 M input tokens per day); deciding on EVERY closed 5m bar would be 1,152 decisions per day for 4 coins (about 4 M input tokens per day), which is not recommended for v1.
Time to a statistically meaningful forward sample: at the rule baseline's trade rate a pooled sample with MDE <= 0.06 % per trade needs the order of several hundred to a few thousand
independent trades (the exact number comes from the rule baseline's measured SE); expect months, not days, of forward logging.

## 7. Metrics

1. **Agreement with the rule baseline** (arm A vs C at the candidate bars): confusion matrix of {BUY, SELL, WAIT} x {rule long, rule short, no signal}, Cohen's kappa, share of LLM WAIT on
   rule signals, and the net result of agree vs disagree sub-populations (descriptive, not selection).
2. **Calibration of the stated confidence**: reliability table in confidence buckets (50-59 ... 90-100) of observed direction hit rate and of observed after-cost win rate, Brier skill vs the
   base rate, ECE, calibration slope / intercept with day-block bootstrap CIs (same code and rules as `exp_e_v1`). Confidence is NEVER used as a sizing input and is NOT presented as a probability
   unless this table says it is calibrated (and see D-050: ML confidence % is not a success probability).
3. **Net expectancy in forward paper**: gross and net per trade, win rate, avg win / loss, profit factor, max drawdown, trade count, effective independent count, MDE, per-month and per-coin,
   day-block CI90 and BH-FDR across arms; always next to the random-side null and the rule baseline.
4. Operational: parse-fail rate, latency distribution, cost per decision, determinism check (re-sending 1 % of requests: identical output at temperature 0? log the mismatch rate).
5. Verdict vocabulary: LEAD / INCONCLUSIVE / FAIL with the v1 conventions; anything historical is labelled "plumbing, contaminated, not evidence".

## 8. What this design deliberately does not do

No ML model output as an input, no fine-tuning, no tools / web browsing for the model (it could fetch the future or the answer), no news in v1, no multi-agent debate, no prompt search on
past data, no automated trading hook, no use of API keys by this research code until the owner approves the plan and supplies a key under his own control (the agent does not handle keys).

## 9. Minimal decision checklist for the owner

1. Do you want to test an LLM analyst at all, given D-046 / D-049 (no directional edge found with ML) and the Rule Baseline v1 result? (If Rule Baseline v1 is FAIL, the prior for an LLM adding edge on the same inputs is low.)
2. Accept that historical replay proves nothing (contamination) and that only forward shadow logging counts, which takes months. [yes / no]
3. Choose the budget ceiling (USD per month) and the model (id + snapshot). Provider price per Mtoken must be read by you; the agent will compute the exact cost from the plumbing run.
4. Approve the data feed: closed bars from public Binance REST / the collector, no news. [yes / no]
5. Approve the pre-registration: frozen prompt, schema, temperature 0, arms A-E, decision times, scoring code, minimum sample and the pass rule (section 5.3). [yes / no]
6. Decide who holds the API key and where it lives (never in this repo, never in logs, never in the agent's environment without your explicit action). 
7. Confirm shadow-only: no order, no sizing, no user-facing display of LLM confidence. Any later step needs a new decision. [yes / no]
8. Set the stop rule: end the experiment at the pre-declared sample size or earlier on a parse / cost / determinism failure; no extension to "wait for it to turn positive".
