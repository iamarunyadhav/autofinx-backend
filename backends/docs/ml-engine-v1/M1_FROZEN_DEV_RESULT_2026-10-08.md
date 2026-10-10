# M1 frozen development-only report
No tuning; no v2 rerun. Read spec.json for execution conventions and limitations.
PF/t-stat use net returns. Top5 is share of positive net profit. All percentages are trade-notional returns, not account returns.

|Coin|TF|N|Gross %|Net %|PF|t|Top5 %|Signals reversed/all|Failed exits|
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
|BTCUSDT|15m|653|0.0345|-0.0898|0.8443|-1.3335|16.5608|243/751|130|
|BTCUSDT|1h|196|0.5446|0.3921|1.4178|1.3923|33.4689|55/248|28|
|BTCUSDT|4h|47|1.2723|1.0630|1.6310|1.0037|71.1666|11/57|7|
|ETHUSDT|15m|654|0.1304|0.0051|1.0073|0.0563|17.3022|196/748|117|
|ETHUSDT|1h|192|0.6841|0.5366|1.4798|1.4504|37.6325|59/238|46|
|ETHUSDT|4h|40|2.2070|1.9471|1.9160|1.1855|77.0005|6/46|5|
|BNBUSDT|15m|556|-0.0299|-0.1532|0.7756|-2.0508|14.4990|176/645|91|
|BNBUSDT|1h|154|0.0452|-0.0768|0.9335|-0.2679|42.8255|35/185|20|
|BNBUSDT|4h|41|-0.8788|-0.9189|0.6614|-0.9470|90.6010|7/51|4|
|SOLUSDT|15m|271|-0.0937|-0.2220|0.7734|-1.4415|27.1105|77/290|43|
|SOLUSDT|1h|84|0.7574|0.5960|1.3673|0.9088|48.4017|20/104|15|
|SOLUSDT|4h|16|3.3492|3.0352|1.9957|0.8163|100.0000|4/17|4|
|XRPUSDT|15m|291|-0.1083|-0.2323|0.7461|-1.4239|37.7138|98/340|52|
|XRPUSDT|1h|92|0.7959|0.6500|1.3532|0.4080|83.5150|25/103|11|
|XRPUSDT|4h|22|-1.1631|-1.3836|0.5843|-0.8461|100.0000|10/29|5|
|SUIUSDT|15m|258|-0.1093|-0.2329|0.8066|-1.1536|29.5536|78/290|43|
|SUIUSDT|1h|74|1.2892|1.1459|1.6536|1.2725|53.9388|21/82|17|
|SUIUSDT|4h|19|6.0749|5.8003|2.4269|0.9748|99.9652|6/20|3|

Pooled, separately by timeframe:
[
  {
    "trades": 2683,
    "avg_gross": 0.002267925633716074,
    "avg_net": -0.12236650000922093,
    "pf": 0.83996633605845,
    "t_stat": -2.7929471026462935,
    "top5_profit_share": 6.377346418023576,
    "failed_exits": 476,
    "timeframe": "15m",
    "positive_coins": 1,
    "verdict": "REJECTED"
  },
  {
    "trades": 792,
    "avg_gross": 0.6026307933082344,
    "avg_net": 0.457969435985002,
    "pf": 1.3584762426313317,
    "t_stat": 1.8425270295157972,
    "top5_profit_share": 20.290897379771533,
    "failed_exits": 137,
    "timeframe": "1h",
    "positive_coins": 5,
    "verdict": "REJECTED"
  },
  {
    "trades": 185,
    "avg_gross": 1.3809134423736316,
    "avg_net": 1.1810794639952529,
    "pf": 1.4602865557209463,
    "t_stat": 1.3606177782453945,
    "top5_profit_share": 38.850786008146464,
    "failed_exits": 28,
    "timeframe": "4h",
    "positive_coins": 4,
    "verdict": "REJECTED"
  }
]

Failures count return inside the original breakout boundary; not all such raw signals were entered. End-of-window positions are explicitly marked and included. This is a development hypothesis test, not independent live validation.

Validation: 118 Python V4 tests passed, including 3 new M1 tests.
Execution differs from legacy v2 Python: includes requested failed-breakout exits, next-open market exits, signal-close anchored stops and gap-aware fills. No v2 rerun or modification.
Holdout OHLCV not provided to evaluation: timestamp-only columns determine the existing per-symbol split, then Parquet predicates restrict OHLCV/funding to development before indicator computation.
Funding uses observed rates with entry-notional normalization, not historical mark-price funding amounts. Existing archive 1m/5m source discrepancies were not repaired in this run.
Plain pooled t-stat does not adjust for correlated coin signals, serial dependence, or multiple hypotheses. No independent validation claim.
Artifacts: D:/autofinx-ml-v4/reports/strategy-lab/m1-frozen-dev/ (spec, source reference, 18 cell files with all trade/signal rows, result, REPORT).
