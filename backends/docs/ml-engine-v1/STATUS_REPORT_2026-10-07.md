# AutoFinX ML V4 + Strategy Lab — full status report (2026-10-07, 17:50 SLST)

Owner-ku ezhudhinadhu. Vera AI-kitta (Claude / ChatGPT) kuduthu idea kekka use pannalaam. Source of truth:
`docs/ml-engine-v1/` (SPEC, DECISIONS D-001..D-031, RESULTS R-001..R-019, PROGRESS, CHANGELOG).

---

## 0. Oru line summary
5 varusha Binance data-la, 6 coins × 5 horizons-la (30 cells) "fees kazhichu profit tharra BUY/SELL edge"
**innum kidaikkala**. Pipeline sariyaa, nermaiyaa velai seiyudhu: no leakage, worst-case execution, holdout
sealed. Aana model-oda predictive power (AUC 0.50–0.72) fees-a (0.12% round trip) thaanda pothala.
Unga Pine v13 strategy-um thaniyaa 24/24 test-la loss.

---

## 1. Namma enna build pannom (goal)
- **Pazhaiya V3:** "15/60/... nimisham kazhichu vilai mela irukkumaa?" nu oru endpoint label vechu train
  aachu. 12 BTC/ETH models ellaam REJECTED (R-006).
- **Pudhu V4 (path-aware):** trade-oda muzhu path-aiyum paakkum:
  - MFE: evvalo uyaram pochu.
  - MAE: evvalo keezha vandhudhu.
  - Profit-ku munnaadi adverse move evvalo.
  - Continue aagura munnaadi pullback evvalo.
  - Idhai vechu dynamic SL, profit activation, trailing stop decide pannum.
- **Output:** BUY / SELL / WAIT. WAIT = fees kazhichu profit edhirpaarkka mudiyaadha neram.
- **Rules (SPEC-la LOCKED):**
  - Research / shadow mattum. Live trading illa, autopilot illa.
  - Holdout (newest 15%) sealed. P6-la oru thadava mattum open aagum. Adhukku appuram retune illa.
  - Owner approval illaama next phase-ku pogaadhu.

## 2. Data + infra
- **Data:** 6 coins, Binance futures 1m + 5m klines + funding, data.binance.vision-la irundhu checksum
  verify panni edutthadhu.
  - BTC/ETH/BNB: 60 maasam. SOL/XRP/SUI: 36 maasam.
  - Coverage 100%, gaps 0 (R-007).
- **Training enga nadakkudhu:** unga PC-la (`D:\autofinx-ml-v4`). Production server overload-la irukku
  (load 20–46, platform scheduler + MariaDB), adhanaala server ML pause pannirukkom (D-001).
- **Splits:**
  - Holdout = newest 15%, SEALED.
  - Dev = adhukku munnaadi irukkura data. Dev_train / dev_val = 70 / 30, nadula purge.
- **Execution simulation:**
  - 1m path vechu SL / activation / trailing simulate pannuvom.
  - Orae 1m bar-la SL-um profit target-um touch aanaa, worst-case-aa SL thaan hit aachu nu eduppom.
  - Gap-na open price-la fill.
  - Cost 0.12% round trip + actual funding.
- **aggTrades resolver check (R-014):** indha worst-case rule pessimistic-aa irukku. Ambiguous trades-a
  ~0.06–0.16% understate pannudhu. Adhaavadhu namma results konjam kadumai, rosy illa.

## 3. Phase-by-phase enna nadandhudhu
| Phase | Enna | Result |
|---|---|---|
| P0–P1 | Data download + verify | ✅ Done (R-007) |
| P2 | V3 features (PHP builder parity) + Layer A path targets | ✅ Done (R-008, R-010) |
| P3 | Label audit + policy lab | ✅ Done (R-009, R-013) |
| P4A | Classifiers + quantile models, 30 cells | ✅ Done (R-011/012/015): **no edge** |
| P4B | Pattern feature groups G1–G6 ablation | 🔄 23/30 done, 7 runs-um P5-um ippo odudhu |
| P5 | Exit policy + decision gate selection (nested A/B/C), freeze | 🟡 Code ready. BTC 15m/1h/4h smoke: ellaam REJECTED (R-016/017). Full 30 queue-la |
| P6 | Sealed holdout, oru thadava | ⏳ P5-la FROZEN aana cells-ku mattum. Code innum ezhudhala |
| P7 | Stress (cost +25/50%, SL/act/trail ±10%, 1m delay) | ⏳ P6 mela |
| P8 | Final reports + global table | ⏳ |

### P3 kandupidippu (R-009, R-013)
- V3 endpoint labels, real SL/trailing execution-oda poruthala. BTC 4h-la "profit" nu sonna trades-la
  48% mudhalla SL-a hit pannudhu.
- Ella trade-ayum eduthaa: h15–h240-la per trade −0.05 to −0.12%.
- h1440-la short side mattum positive (SOL/XRP/SUI). Andha period market keezha pogumbodhu, adhanaala
  idhu model edge illa.

### P4A (R-015), 30 cells
- **AUC:**
  - V3 labels: 0.50–0.71.
  - V4 path labels: 0.50–0.65.
  - Horizon perusaaga perusaaga AUC 0.5-ku pogudhu. 1 naal horizon = coin flip.
- **V4 arms:** 30-la ondru kooda "positive avg + t ≥ 1" illa.
- **V3 signal + pudhu SL/trailing exits:** 30/30 negative.
- **Edge maadhiri theriyura cells (luck):** SUI 1h/2h, SOL 2h, BTC 1h-la old V3 + endpoint exit konjam
  positive (t 1.3–2.3). Aana:
  - Avai SL illaadha exit.
  - Trades kammi.
  - 30 test-la 1–2 idhu maadhiri chance-laye varum.

### P4B (ippo varaikkum 23/30): select aana groups
| | 15m | 1h | 2h | 4h | 1d |
|---|---|---|---|---|---|
| BTC | G5 | G3+G5 | (rerun) | (rerun) | none |
| ETH | G4 | G5 | G2 | (rerun) | G1+G4 |
| BNB | G4 | none | (rerun) | (rerun) | G1 |
| SOL | G2 | none | none | (rerun) | none |
| XRP | none | none | G1 | none | G1+G2+G3 |
| SUI | G5 | none | none | G3+G5 | (rerun) |

Group legend: G1 structure (swings) · G2 candles · G3 momentum · G4 volatility/squeeze · G5 multi-timeframe ·
G6 volume/taker.

- G6 endha cell-layum keep aagala.
- Keep aana gains romba chinnadhu: +0.002–0.006 AUC.
- Common pattern illa. Ovvoru coin/horizon-kum vera vera group.
- 1d-la baseline AUC ≈ 0.50–0.54. Anga keep aana groups (example: XRP 1d G1+G2+G3) noise-aa irukka chance adhigam.

### P5 smoke (R-016, R-017): yen REJECT aagudhu
- **BTC 15m:**
  - Model "nalla trade" nu solra (q25 MFE > cost+edge 0.18%) rows-la, thevaiyaana SL market cap-ai
    (0.20%) thaandudhu. Adhanaala ellaam WAIT, 0 trades.
- **BTC 4h:**
  - 34k rows level gates pass pannudhu. Aana:
    - Win aagura trade avg +0.31%, loss aagura trade avg −0.27%.
    - Win rate ~24%.
  - EV positive aaga p ≈ 0.46 venum, aana model max ~0.25 thaan kudukkudhu. Max EV −0.12%.
  - Adhanaala 0 trades. Bug illa, nermaiyaana REJECT.
- **D-029 (KEPT):** probability-ai ovvoru candidate exit policy-kum re-calibrate pannom (mathematically
  correct). Verdict maaralai.

## 4. Training issues (technical) — enna problem vandhudhu, enna fix
1. **Server too loaded:** training PC-ku move pannom.
2. **PC C: drive full:** D:-la venv + data.
3. **Memory (32 GB RAM):**
   - Chrome (~10 GB) + VS Code + training orae neram odumbodhu "out of memory" / LightGBM
     "bad allocation" crash aachu.
   - Fix: ovvoru coin × horizon-kum thaniyaana process, start aagura munnaadi free-memory check.
4. **Windows power-off (06:55):** chain stop aachu. Resume script vechu thirumba start pannom.
5. **Ippo (17:06):** bash "fork: resource unavailable" vandhu runner stop aachu. Restart panniyaachu
   (`run-p4b-p5.sh`).
6. **Code fixes during review (D-023):** same-bar ambiguity worst-case, sklearn AUC, resolver funding,
   additive drawdown label ellaam correct pannom.

## 5. Yen ellaam REJECT aagudhu (root cause, nermaiyaa)
1. **Cost wall:**
   - 0.12% round trip + funding.
   - BTC 5m/15m natural moves-oda gross edge ≈ 0. Ex: v13 BTC 5m gross +0.003%/trade.
   - Fees thaan result-a decide pannudhu.
2. **Weak signal:** AUC 0.55–0.65 ranking pannudhu, aana calibrated probability base rate-ai vida romba
   mela pogala. EV-ku 2× base rate venum.
3. **Short horizon problem:** 15m-la profit target cost-ai thaandumbodhu, thevaiyaana SL market cap-ai
   thaandudhu.
4. **Long horizon problem:** 1d-la AUC ≈ 0.5, signal-e illa.
5. **Public OHLCV features** (indicators, patterns) ellaarukkum therinjadhu. Adhula edge irundhaalum
   romba chinnadhu.

## 6. Unga Pine Script v13 — enna aachu
- **Audit (static, run pannala):**
  - **CAUSAL**, future leak illa.
  - HTF `request.security(f()[1], lookahead_off)` → TradingView backtest-um live-um 1 HTF bar
    vithiyaasam irukkum.
  - 47 inputs + kai-yaala set panna score weights → **overfit risk HIGH**.
  - Webhook `/api/v1/future-trade/pine-webhook/` vazhiyaa **live Future Trade orders** anuppura maadhiri
    design pannirukku.
- **Translation:** `v4/strategy_v13.py`, 12 tests pass. Pine semantics: ema/rma SMA seed, Wilder
  ATR/DMI/RSI, supertrend, session VWAP, chop, Donchian latch.
- **S0 standalone test (R-019):**
  - 6 coins × 5m/15m × dev_train/dev_val = **24/24 negative**.
  - BTC 5m: 14,322 trades, −0.117%/trade, PF 0.52.
  - TradingView fee (0.08%) potta kooda negative.
  - Verdict: **REJECTED_NO_VALUE** as a standalone strategy.
- **S1 (components as soft features) + RULE (chop ≤ 62, TREND regime as WAIT):** queue-la. P4B
  mudinjadhum `layers` run odum, optional layer report varum.
- ⚠️ v13 live-aa Future Trade-la odittu irundhaa, evidence padi fees kazhichu loss thaan. Live-la
  odudhaa nu owner check pannanum.

## 7. Noise / Tradeability layer (D-030) — unga "WAIT-um champion thaan" idea
- **Independent optional layer.** ML pipeline-a thodaadhu, default OFF.
- **G7-T features:** trend efficiency, choppiness 14/48, direction flips, range overlap, failed
  breakouts, EMA compression, body/wick means, path ratio. 6 coins-kum build aayiduchu.
- **Moonu role-la test aagum:** FEATURE / WAIT layer (top 50% / 30% keep) / RULE.
- **Metrics:** loss avoidance, profit retention, false-WAIT, executed net OFF vs ON. Accuracy use pannala.
- **Verdict:** USEFUL_AS_WAIT_LAYER / PROFITABLE_WITH_LAYER / OVER_FILTERED / NO_VALUE. Status: queue-la.

## 8. Strategy Lab (D-031) — build status
| Part | Status |
|---|---|
| Backend: 4 tables, API 11 routes, PineAuditor, async job + runner, owner approval gates, idempotency | ✅ Built, tests 11/11; **server-la deploy + migrate DONE**, flag enabled |
| Python runner (`v4/lab_runner.py`): QUICK_V1 / DEEP_V1, scorecard, fixed verdict rule | ✅ 84/84 Python tests |
| Frontend (list, upload/paste, audit, quick/deep progress, scorecard, chart, approve/reject) | ✅ build + eslint clean; **production deploy pending**; live click-through pending |
| PC runner setup (`.env` WORK_DIR + PYTHON_BIN, queue worker) | ⏳ owner, P4B/P5 mudinja appuram |
| First real Quick Test (v13) | ⏳ |
| Safety | Uploaded code run aagaadhu; reviewed translation (sha256 match) irundhaa mattum test; order path illa (test irukku); dev_val / holdout use aagaadhu |

Production server-la data illa. Adhanaala anga Quick Test "RUNNER_UNAVAILABLE" nu sollum. Tests unga
PC-la odum.

## 9. Ippo enna odittu irukku (`D:\autofinx-ml-v4\run-p4b-p5.sh`, log `logs\p4b-p5.log`)
1. P4B meedhi 7: BTC 2h/4h, ETH 4h, BNB 2h/4h, SOL 4h, SUI 1d.
2. Optional layers h15 (G7-T + v13), 6 coins.
3. P5 arm3 (primary), 30 cells. Ovvoru cell ~10 min, mothamaa ~5–6 mani neram.
Ovvoru step-um thaniyaana process-la odum, ≥ 6 GB free memory irundhaa mattum start aagum.

## 10. Remaining — full list
**Agent (naan) pannanum:**
1. P6 holdout code: frozen artifacts + decision.json (P5 ippo save pannudhu) apply panni, holdout-a oru
   thadava mattum open pannanum. Open guard file + arms:
   - Arm 3 primary.
   - Arm 2 = V4 labels + V3 features.
   - Arm 1 = V3 baseline.
2. P7 stress code: L7 matrix-padi ROBUST / FRAGILE / REJECT.
3. P8 report: per-cell JSON + Markdown (§90), global table (§91), status (§89).
4. **P6 pass rule-a holdout open aaguradhukku munnaadi D-032-la freeze pannanum.** Adhukku owner
   approval venum.
5. R-018 (P4B full table), R-020 (optional layers), R-021 (P5 full) record pannanum.
6. P5-la ella cells-um REJECTED aanaa: holdout open panna vendaam, sealed-aave vechikkalaam (future
   experiments-ku). P8 = nermaiyaana "no edge" final report.

**Owner (neenga) pannanum:**
- [ ] Frontend production deploy (Strategy Lab page).
- [ ] PC `.env`: `AUTOFINX_STRATEGY_LAB_WORK_DIR=D:\autofinx-ml-v4`,
  `AUTOFINX_STRATEGY_LAB_PYTHON_BIN=D:\autofinx-ml-v4\venv\Scripts\python.exe`. Local migrate. Worker:
  `php artisan queue:work ml_training --queue=strategy_lab --timeout=2400`. Training mudinja appuram thaan.
- [ ] v13 live Future Trade-la odudhaa nu check pannunga.
- [ ] Security: leak aana Bitbucket token, GitHub PAT, testnet key rotate pannunga. Server-la
  `PasswordAuthentication no` set pannunga (92.118.39.67-la irundhu failed root logins vandhudhu).
- [ ] Optional: server `ml-server-pause.sh --free-disk` (~16 GB).
- [ ] PC: Chrome tabs kammi pannunga / page file perusaakkunga (training crash aagaama irukka).
- [ ] Uncommitted changes commit pannunga (frontend + latest P5 decision.json change + indha report).

## 11. Vera AI-kitta kekka vendiya kelvigal (idea-kaaga)
1. 0.12% cost wall-a thaanda: maker (limit) entries vechu fee kammi pannalaamaa? Avvalavu edge irukkura
   horizon edhu?
2. OHLCV-ku veliya irukkura data: order book imbalance, open interest change, liquidations, funding
   spikes, cross-exchange basis. Indha G7-metrics-a namma ignore pannom (L3). Idhula edge irukka chance
   adhigamaa?
3. Regime-conditional models: TREND-la mattum trade panna (tradeability layer result vandhadhum) edge
   varumaa?
4. Horizon 2h–4h-la, win/loss size asymmetry adhigamaa irukkura exits (wide trail, partial TP)?
5. Cross-sectional approach: 6 coins-la "relative strength" rank pannalaamaa (absolute direction-ku
   badhilaa)?
6. Ellaam fail aanaa: ML-a direction-ku use pannaama risk / position sizing / WAIT filter-aa mattum
   existing bots-ku (Ratio Bot, Robot Trade) use pannalaamaa?
