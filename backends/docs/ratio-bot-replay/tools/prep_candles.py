"""Convert local Binance 1m parquet months into plain CSV + a coverage/gap report.
Read-only on the source data. Usage: python -I prep_candles.py <data_root> <out_dir> <start YYYY-MM> <end YYYY-MM> SYM [SYM...]
"""
import sys, json, os
import pyarrow.parquet as pq

root, out, start, end = sys.argv[1:5]
symbols = sys.argv[5:]

def months(a, b):
    y, m = map(int, a.split('-')); y2, m2 = map(int, b.split('-'))
    while (y, m) <= (y2, m2):
        yield f"{y:04d}-{m:02d}"
        m += 1
        if m == 13: y, m = y + 1, 1

report = {}
os.makedirs(out, exist_ok=True)
for sym in symbols:
    rep = report[sym] = {}
    for mo in months(start, end):
        p = f"{root}/{sym}/1m/monthly/{mo}.parquet"
        if not os.path.exists(p):
            rep[mo] = {"status": "MISSING_FILE"}
            continue
        t = pq.read_table(p, columns=["open_time", "open", "high", "low", "close", "volume"]).to_pydict()
        ot = t["open_time"]
        n = len(ot)
        y, m = map(int, mo.split('-'))
        import calendar
        expected = calendar.monthrange(y, m)[1] * 1440
        dups = n - len(set(ot))
        # gaps: consecutive diffs > 60000
        gaps = []
        for i in range(1, n):
            d = ot[i] - ot[i - 1]
            if d != 60000:
                gaps.append({"after_open_time": ot[i - 1], "missing_minutes": d // 60000 - 1})
        unsorted = any(ot[i] <= ot[i - 1] for i in range(1, n))
        bad = sum(1 for i in range(n) if not (t["low"][i] <= min(t["open"][i], t["close"][i]) and t["high"][i] >= max(t["open"][i], t["close"][i]) and t["low"][i] > 0))
        rep[mo] = {"status": "OK", "rows": n, "expected": expected, "dups": dups, "gap_count": len(gaps),
                   "missing_minutes": sum(g["missing_minutes"] for g in gaps), "unsorted": unsorted, "bad_ohlc_rows": bad,
                   "first": ot[0], "last": ot[-1], "gaps": gaps[:20]}
        with open(f"{out}/{sym}-{mo}.csv", "w", newline="") as f:
            for i in range(n):
                f.write(f"{ot[i]},{t['open'][i]!r},{t['high'][i]!r},{t['low'][i]!r},{t['close'][i]!r},{t['volume'][i]!r}\n")
json.dump(report, open(f"{out}/coverage.json", "w"), indent=1)
for sym, r in report.items():
    ok = [m for m, v in r.items() if v["status"] == "OK"]
    bad = {m: (v["rows"], v["expected"], v["gap_count"]) for m, v in r.items() if v["status"] != "OK" or v["rows"] != v["expected"] or v["gap_count"] or v["dups"] or v["bad_ohlc_rows"]}
    print(sym, "months ok:", len(ok), "of", len(r), "anomalies:", bad)
