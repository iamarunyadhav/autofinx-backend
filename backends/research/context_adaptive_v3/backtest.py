#!/usr/bin/env python3
"""Independent closed-bar port of AutoFinX Context Adaptive Research V3.

Reads the local Binance USD-M 5m parquet archive, recreates Pine's default
signals on several chart timeframes, and writes machine-readable and Markdown
results. It is deliberately isolated from every live AutoFinX decision path.
"""
from __future__ import annotations

import argparse
import json
import math
import importlib.util
import sys
from dataclasses import dataclass
from pathlib import Path

# The existing ML archive owns its Parquet reader in a lean venv. Reuse that
# local package when the workstation's default Python has no Parquet engine.
if importlib.util.find_spec("pyarrow") is None:
    local_packages = Path(r"D:\autofinx-ml-v4\venv\Lib\site-packages")
    if local_packages.exists():
        sys.path.append(str(local_packages))

import numpy as np
import pandas as pd

MODULES = ["EMA pullback", "Structure BOS", "Liquidity sweep", "FVG retest",
           "Volume breakout", "Candle at S/R", "Trendline break", "Range/AMD proxy", "Session range"]
MODES = ["Raw UT", "V2 Filtered", "Context Confirmed", "Context Independent"]


def rma(values: np.ndarray, length: int) -> np.ndarray:
    out = np.full(len(values), np.nan)
    valid = 0
    seed = 0.0
    prev = np.nan
    for i, value in enumerate(values):
        if np.isnan(value):
            continue
        if valid < length:
            seed += value
            valid += 1
            if valid == length:
                prev = seed / length
                out[i] = prev
        else:
            prev = (prev * (length - 1) + value) / length
            out[i] = prev
    return out


def ema(values: np.ndarray, length: int) -> np.ndarray:
    return pd.Series(values).ewm(span=length, adjust=False, min_periods=length).mean().to_numpy()


def indicators(df: pd.DataFrame) -> dict[str, np.ndarray]:
    o, h, l, c, v = (df[x].to_numpy(float) for x in ["open", "high", "low", "close", "volume"])
    pc = np.r_[np.nan, c[:-1]]
    tr = np.maximum(h - l, np.maximum(np.abs(h - pc), np.abs(l - pc)))
    atr = rma(tr, 10)
    up = h - np.r_[np.nan, h[:-1]]
    down = np.r_[np.nan, l[:-1]] - l
    plus_dm = np.where((up > down) & (up > 0), up, 0.0)
    minus_dm = np.where((down > up) & (down > 0), down, 0.0)
    atr14 = rma(tr, 14)
    plus_di = 100 * rma(plus_dm, 14) / atr14
    minus_di = 100 * rma(minus_dm, 14) / atr14
    denom = plus_di + minus_di
    dx = np.where(denom > 0, 100 * np.abs(plus_di - minus_di) / denom, 0.0)
    adx = rma(dx, 14)
    delta = c - pc
    gain = rma(np.where(delta > 0, delta, 0.0), 14)
    loss = rma(np.where(delta < 0, -delta, 0.0), 14)
    rs = gain / loss
    rsi = np.where(loss == 0, 100.0, 100 - 100 / (1 + rs))
    return {"o": o, "h": h, "l": l, "c": c, "v": v, "atr": atr,
            "ema20": ema(c, 20), "ema50": ema(c, 50), "ema200": ema(c, 200),
            "plus_di": plus_di, "minus_di": minus_di, "adx": adx, "rsi": rsi}


def load_5m(root: Path) -> pd.DataFrame:
    files = sorted((root / "BTCUSDT" / "5m" / "monthly").glob("*.parquet"))
    if not files:
        raise FileNotFoundError(f"No monthly parquet files under {root}")
    frames = [pd.read_parquet(path) for path in files]
    df = pd.concat(frames, ignore_index=True)
    rename = {name: name.lower().replace("open_time", "ts") for name in df.columns}
    df = df.rename(columns=rename)
    if "ts" not in df:
        for candidate in ["timestamp", "time", "open_timestamp"]:
            if candidate in df:
                df = df.rename(columns={candidate: "ts"})
                break
    df["ts"] = pd.to_datetime(df["ts"], unit="ms", utc=True) if np.issubdtype(df["ts"].dtype, np.number) else pd.to_datetime(df["ts"], utc=True)
    needed = ["ts", "open", "high", "low", "close", "volume"]
    df = df[needed].drop_duplicates("ts").sort_values("ts").set_index("ts")
    return df.astype(float)


def resample(df: pd.DataFrame, rule: str) -> pd.DataFrame:
    rule = {"5m": "5min", "15m": "15min"}.get(rule, rule)
    return df.resample(rule, origin="epoch", label="left", closed="left").agg(
        open=("open", "first"), high=("high", "max"), low=("low", "min"),
        close=("close", "last"), volume=("volume", "sum")).dropna()


def confirmed_htf(chart: pd.DataFrame, base: pd.DataFrame, rule: str) -> tuple[np.ndarray, np.ndarray]:
    higher = resample(base, rule)
    higher["ema"] = ema(higher.close.to_numpy(float), 50)
    lookup = higher[["close", "ema"]].shift(1)
    keys = chart.index.floor({"5m": "5min", "15m": "15min"}.get(rule, rule))
    return (lookup.close.reindex(keys).to_numpy(), lookup.ema.reindex(keys).to_numpy())


def crossed_up(a: float, b: float, pa: float, pb: float) -> bool:
    return not any(np.isnan(x) for x in [a, b, pa, pb]) and a > b and pa <= pb


def crossed_down(a: float, b: float, pa: float, pb: float) -> bool:
    return not any(np.isnan(x) for x in [a, b, pa, pb]) and a < b and pa >= pb


@dataclass
class Position:
    side: int
    qty: float
    entry: float
    sl: float
    tp1: float
    tp2: float
    remaining: float
    opened: pd.Timestamp
    gross: float = 0.0
    fees: float = 0.0


def analyze(chart: pd.DataFrame, base: pd.DataFrame, timeframe: str) -> tuple[pd.DataFrame, list[dict], dict]:
    x = indicators(chart)
    o, h, l, c, v, atr = (x[k] for k in ["o", "h", "l", "c", "v", "atr"])
    n = len(chart)
    htf_c, htf_e = confirmed_htf(chart, base, "4h")
    daily_c, daily_e = confirmed_htf(chart, base, "1D")
    vol_avg = pd.Series(v).rolling(20).mean().to_numpy()
    atr_avg = pd.Series(atr).rolling(20).mean().to_numpy()
    prior_high = pd.Series(h).shift(1).rolling(20).max().to_numpy()
    prior_low = pd.Series(l).shift(1).rolling(20).min().to_numpy()
    flags_l = np.zeros((n, 9), bool); flags_s = np.zeros((n, 9), bool)
    raw_buy = np.zeros(n, bool); raw_sell = np.zeros(n, bool)
    base_l = np.zeros(n, bool); base_s = np.zeros(n, bool)
    context_l = np.zeros(n, bool); context_s = np.zeros(n, bool)
    ut = np.full(n, np.nan); hi_tl = np.full(n, np.nan); lo_tl = np.full(n, np.nan)
    hi1 = hi2 = lo1 = lo2 = np.nan; hib1 = hib2 = lob1 = lob2 = None
    bull_top = bull_bottom = bear_top = bear_bottom = np.nan; bull_bar = bear_bar = None
    session_hi = session_lo = np.nan; session_ready = False; was_session = False
    for i in range(n):
        prior = 0.0 if i == 0 or np.isnan(ut[i-1]) else ut[i-1]
        loss_atr = 2.5 * atr[i]
        if not np.isnan(loss_atr):
            if c[i] > prior and i and c[i-1] > prior: ut[i] = max(prior, c[i]-loss_atr)
            elif c[i] < prior and i and c[i-1] < prior: ut[i] = min(prior, c[i]+loss_atr)
            else: ut[i] = c[i]-loss_atr if c[i] > prior else c[i]+loss_atr
        if i:
            raw_buy[i] = crossed_up(c[i], ut[i], c[i-1], ut[i-1])
            raw_sell[i] = crossed_down(c[i], ut[i], c[i-1], ut[i-1])
        p = i - 5
        if p >= 5 and p + 5 <= i:
            if h[p] == np.max(h[p-5:p+6]): hi2, hib2, hi1, hib1 = hi1, hib1, h[p], p
            if l[p] == np.min(l[p-5:p+6]): lo2, lob2, lo1, lob1 = lo1, lob1, l[p], p
        bull_struct = not np.isnan(hi2) and not np.isnan(lo2) and hi1 > hi2 and lo1 > lo2
        bear_struct = not np.isnan(hi2) and not np.isnan(lo2) and hi1 < hi2 and lo1 < lo2
        bos_up = i > 0 and crossed_up(c[i], hi1, c[i-1], hi1)
        bos_dn = i > 0 and crossed_down(c[i], lo1, c[i-1], lo1)
        rng = max(h[i]-l[i], 0.1); body = abs(c[i]-o[i]); low_wick=min(o[i],c[i])-l[i]; high_wick=h[i]-max(o[i],c[i]); loc=(c[i]-l[i])/rng
        bull_rej=c[i]>o[i] and low_wick>body*1.5 and loc>0.65; bear_rej=c[i]<o[i] and high_wick>body*1.5 and loc<0.35
        bull_eng=i>0 and c[i]>o[i] and c[i-1]<o[i-1] and c[i]>=o[i-1] and o[i]<=c[i-1]
        bear_eng=i>0 and c[i]<o[i] and c[i-1]>o[i-1] and c[i]<=o[i-1] and o[i]>=c[i-1]
        near_sup=not np.isnan(lo1) and not np.isnan(atr[i]) and abs(l[i]-lo1)<=atr[i]*.4
        near_res=not np.isnan(hi1) and not np.isnan(atr[i]) and abs(h[i]-hi1)<=atr[i]*.4
        flags_l[i,0]=i>=3 and x["ema20"][i]>x["ema50"][i]>x["ema200"][i] and x["ema20"][i]>x["ema20"][i-3] and l[i]<=x["ema20"][i] and c[i]>x["ema20"][i] and (bull_rej or bull_eng)
        flags_s[i,0]=i>=3 and x["ema20"][i]<x["ema50"][i]<x["ema200"][i] and x["ema20"][i]<x["ema20"][i-3] and h[i]>=x["ema20"][i] and c[i]<x["ema20"][i] and (bear_rej or bear_eng)
        flags_l[i,1]=bos_up and bull_struct; flags_s[i,1]=bos_dn and bear_struct
        flags_l[i,2]=not np.isnan(lo1) and l[i]<lo1 and c[i]>lo1 and bull_rej
        flags_s[i,2]=not np.isnan(hi1) and h[i]>hi1 and c[i]<hi1 and bear_rej
        flags_l[i,4]=bos_up and not np.isnan(vol_avg[i]) and vol_avg[i]>0 and v[i]/vol_avg[i]>=1.4 and loc>.7
        flags_s[i,4]=bos_dn and not np.isnan(vol_avg[i]) and vol_avg[i]>0 and v[i]/vol_avg[i]>=1.4 and loc<.3
        flags_l[i,5]=near_sup and (bull_rej or bull_eng); flags_s[i,5]=near_res and (bear_rej or bear_eng)
        if hib2 is not None and hib1 > hib2:
            slope=(hi1-hi2)/(hib1-hib2); hi_tl[i]=hi1+slope*(i-hib1)
            if i and slope<0: flags_l[i,6]=crossed_up(c[i],hi_tl[i],c[i-1],hi_tl[i-1])
        if lob2 is not None and lob1 > lob2:
            slope=(lo1-lo2)/(lob1-lob2); lo_tl[i]=lo1+slope*(i-lob1)
            if i and slope>0: flags_s[i,6]=crossed_down(c[i],lo_tl[i],c[i-1],lo_tl[i-1])
        compressed=not np.isnan(atr_avg[i]) and prior_high[i]-prior_low[i] < atr_avg[i]*5
        flags_l[i,7]=compressed and l[i]<prior_low[i] and c[i]>prior_low[i] and loc>.65
        flags_s[i,7]=compressed and h[i]>prior_high[i] and c[i]<prior_high[i] and loc<.35
        # Session module is disabled by the supplied defaults, but keep its columns neutral.
        if bull_bar is not None: flags_l[i,3]=i>bull_bar and i-bull_bar<=12 and l[i]<=bull_top and l[i]>=bull_bottom-atr[i]*.4 and c[i]>bull_top and c[i]>o[i]
        if bear_bar is not None: flags_s[i,3]=i>bear_bar and i-bear_bar<=12 and h[i]>=bear_bottom and h[i]<=bear_top+atr[i]*.4 and c[i]<bear_bottom and c[i]<o[i]
        if i>=2 and l[i]>h[i-2]: bull_top,bull_bottom,bull_bar=l[i],h[i-2],i
        if i>=2 and h[i]<l[i-2]: bear_top,bear_bottom,bear_bar=l[i-2],h[i],i
        if not np.isnan(bull_bottom) and c[i]<bull_bottom: bull_bar=None
        if not np.isnan(bear_top) and c[i]>bear_top: bear_bar=None
        lc=int(flags_l[i].sum()); sc=int(flags_s[i].sum()); context_l[i]=lc>=1 and lc>sc; context_s[i]=sc>=1 and sc>lc
        ema_up=i>=3 and c[i]>x["ema200"][i] and x["ema200"][i]>x["ema200"][i-3]
        ema_dn=i>=3 and c[i]<x["ema200"][i] and x["ema200"][i]<x["ema200"][i-3]
        base_l[i]=ema_up and htf_c[i]>htf_e[i] and x["adx"][i]>=20 and x["plus_di"][i]>x["minus_di"][i] and x["rsi"][i]<=75 and atr[i]/c[i]*100>=.15
        base_s[i]=ema_dn and htf_c[i]<htf_e[i] and x["adx"][i]>=20 and x["minus_di"][i]>x["plus_di"][i] and x["rsi"][i]>=25 and atr[i]/c[i]*100>=.15
    signals={"Raw UT":(raw_buy,raw_sell),"V2 Filtered":(raw_buy&base_l,raw_sell&base_s),
             "Context Confirmed":(raw_buy&base_l&context_l,raw_sell&base_s&context_s),
             "Context Independent":(context_l&base_l,context_s&base_s)}
    events=[]
    for i in range(n-12):
        for side, matrix in [(1,flags_l),(-1,flags_s)]:
            ids=np.flatnonzero(matrix[i])
            if not len(ids) or np.isnan(atr[i]): continue
            good=bad=False; outcome="ambiguous"
            for j in range(i+1,i+13):
                good = h[j]>=c[i]+atr[i] if side==1 else l[j]<=c[i]-atr[i]
                bad = l[j]<=c[i]-atr[i] if side==1 else h[j]>=c[i]+atr[i]
                if good or bad:
                    outcome="win" if good and not bad else "loss" if bad and not good else "ambiguous"; break
            events.append({"time":chart.index[i],"year":chart.index[i].year,"side":side,"modules":ids.tolist(),"outcome":outcome})
    trades=[]
    for mode,(longs,shorts) in signals.items(): trades.extend(simulate(chart,x,longs,shorts,raw_buy,raw_sell,mode))
    market_years=[]
    for year, annual in chart.groupby(chart.index.year):
        change=(annual.close.iloc[-1]/annual.open.iloc[0]-1)*100
        regime="bull" if change>=15 else "bear" if change<=-15 else "range/mixed"
        market_years.append({"year":int(year),"start":str(annual.index[0]),"end":str(annual.index[-1]),
                             "return_pct":round(float(change),2),"regime":regime})
    meta={"rows":n,"start":str(chart.index[0]),"end":str(chart.index[-1]),"timeframe":timeframe,"market_years":market_years}
    return pd.DataFrame(events), trades, meta


def simulate(df, x, longs, shorts, raw_buy, raw_sell, mode):
    c,h,l,atr=x["c"],x["h"],x["l"],x["atr"]
    equity=10000.0; pos=None; out=[]; last_entry=-10; slip=.1*150; fee_rate=.0005
    for i,t in enumerate(df.index):
        if pos is not None:
            stop_hit=l[i]<=pos.sl if pos.side==1 else h[i]>=pos.sl
            tp1_hit=h[i]>=pos.tp1 if pos.side==1 else l[i]<=pos.tp1
            tp2_hit=h[i]>=pos.tp2 if pos.side==1 else l[i]<=pos.tp2
            opposite=raw_sell[i] if pos.side==1 else raw_buy[i]
            fills=[]
            if stop_hit: fills=[(pos.remaining,pos.sl-pos.side*slip,"SL")]
            else:
                planned_remaining = pos.remaining
                if pos.remaining==pos.qty and tp1_hit:
                    fills.append((pos.qty*.5,pos.tp1,"TP1")); planned_remaining-=pos.qty*.5
                if planned_remaining>0 and tp2_hit: fills.append((planned_remaining,pos.tp2,"TP2"))
                elif planned_remaining>0 and opposite: fills.append((planned_remaining,c[i]-pos.side*slip,"Opposite UT"))
            for qty,px,reason in fills:
                if pos is None:
                    break
                gross=(px-pos.entry)*pos.side*qty; fees=abs(px*qty)*fee_rate
                pos.gross+=gross; pos.fees+=fees; pos.remaining-=qty
                if pos.remaining<=1e-12:
                    net=pos.gross-pos.fees; equity+=net
                    out.append({"mode":mode,"entry_time":str(pos.opened),"exit_time":str(t),"year":pos.opened.year,"side":pos.side,"gross":pos.gross,"fees":pos.fees,"net":net,"reason":reason,"equity":equity}); pos=None
        if pos is None and i-last_entry>=2 and not np.isnan(atr[i]):
            side=1 if longs[i] and not shorts[i] else -1 if shorts[i] and not longs[i] else 0
            if side and equity > 0:
                risk=atr[i]*1.5; qty=min(equity*.01/risk,equity/c[i]); entry=c[i]+side*slip
                if qty > 0:
                    pos=Position(side,qty,entry,c[i]-side*risk,c[i]+side*risk,c[i]+side*risk*2,qty,t); pos.fees=abs(entry*qty)*fee_rate; last_entry=i
    return out


def summarize(events: pd.DataFrame, trades: list[dict], meta: dict) -> dict:
    modules=[]
    for k,name in enumerate(MODULES):
        subset=events[events.modules.apply(lambda xs:k in xs)] if len(events) else events
        counts=subset.outcome.value_counts(); total=len(subset)
        modules.append({"module":name,"events":total,"wins":int(counts.get("win",0)),"losses":int(counts.get("loss",0)),
                        "ambiguous":int(counts.get("ambiguous",0)),"success_pct":round(100*counts.get("win",0)/total,2) if total else None})
    annual_modules=[]
    for year in sorted(events.year.unique()) if len(events) else []:
        sub=events[events.year==year]
        for k,name in enumerate(MODULES):
            s=sub[sub.modules.apply(lambda xs:k in xs)]; counts=s.outcome.value_counts(); total=len(s)
            annual_modules.append({"year":int(year),"module":name,"events":total,"success_pct":round(100*counts.get("win",0)/total,2) if total else None})
    trade_rows=[]; trade_overall=[]; td=pd.DataFrame(trades)
    if len(td):
        for (mode,year),s in td.groupby(["mode","year"]):
            wins=s[s.net>0].net; losses=s[s.net<0].net
            curve=10000+s.net.cumsum(); dd=(curve/curve.cummax()-1).min()*100
            trade_rows.append({"mode":mode,"year":int(year),"trades":len(s),"win_rate_pct":round(100*(s.net>0).mean(),2),
                "profit_factor":round(wins.sum()/abs(losses.sum()),3) if len(losses) else None,"net":round(s.net.sum(),2),
                "fees":round(s.fees.sum(),2),"expectancy":round(s.net.mean(),2),"max_drawdown_pct":round(float(dd),2)})
        for mode,s in td.groupby("mode"):
            wins=s[s.net>0].net; losses=s[s.net<0].net; curve=10000+s.net.cumsum(); dd=(curve/curve.cummax()-1).min()*100
            trade_overall.append({"mode":mode,"trades":len(s),"win_rate_pct":round(100*(s.net>0).mean(),2),
                "profit_factor":round(wins.sum()/abs(losses.sum()),3) if len(losses) else None,"net":round(s.net.sum(),2),
                "fees":round(s.fees.sum(),2),"expectancy":round(s.net.mean(),2),"max_drawdown_pct":round(float(dd),2),
                "ending_equity":round(10000+s.net.sum(),2)})
    return {"meta":meta,"module_outcomes":modules,"annual_module_outcomes":annual_modules,
            "strategy_overall":trade_overall,"strategy_annual":trade_rows}


def markdown(all_results: list[dict]) -> str:
    lines=["# Context Adaptive Research V3 — local Binance backtest", "", "Generated from Binance USD-M Futures BTCUSDT closed candles. Default Pine parameters; session module disabled.", ""]
    for result in all_results:
        m=result["meta"]; lines += [f"## {m['timeframe']} ({m['start']} to {m['end']}, {m['rows']:,} bars)", "", "### Module outcome rate", "", "| Module | Events | Wins | Losses | Ambiguous | Success % |", "|---|---:|---:|---:|---:|---:|"]
        for r in result["module_outcomes"]: lines.append(f"| {r['module']} | {r['events']} | {r['wins']} | {r['losses']} | {r['ambiguous']} | {r['success_pct']} |")
        lines += ["", "### Market periods", "", "| Year | Observed return % | Regime |", "|---:|---:|---|"]
        for r in m["market_years"]: lines.append(f"| {r['year']} | {r['return_pct']} | {r['regime']} |")
        lines += ["", "### Strategy overall", "", "| Mode | Trades | Win % | PF | Net $ | Ending equity $ | Fees $ | Expectancy $ | Max DD % |", "|---|---:|---:|---:|---:|---:|---:|---:|---:|"]
        for r in result["strategy_overall"]: lines.append(f"| {r['mode']} | {r['trades']} | {r['win_rate_pct']} | {r['profit_factor']} | {r['net']} | {r['ending_equity']} | {r['fees']} | {r['expectancy']} | {r['max_drawdown_pct']} |")
        lines += ["", "### Strategy by entry year", "", "| Mode | Year | Trades | Win % | PF | Net $ | Fees $ | Expectancy $ | Max DD % |", "|---|---:|---:|---:|---:|---:|---:|---:|---:|"]
        for r in result["strategy_annual"]: lines.append(f"| {r['mode']} | {r['year']} | {r['trades']} | {r['win_rate_pct']} | {r['profit_factor']} | {r['net']} | {r['fees']} | {r['expectancy']} | {r['max_drawdown_pct']} |")
        lines.append("")
    lines += ["## Interpretation limits", "", "Module success is the supplied +1 ATR before -1 ATR within 12 bars metric; ambiguous is a non-win. Strategy simulation uses conservative same-bar stop priority, BTCUSDT tick size 0.1, 150-tick adverse market slippage, and 0.05% commission. It is an independent port, not TradingView's broker emulator, and Pine compilation remains unverified.", ""]
    return "\n".join(lines)


def main():
    p=argparse.ArgumentParser(); p.add_argument("--data-root",type=Path,default=Path(r"D:\autofinx-ml-v4\data")); p.add_argument("--out",type=Path,default=Path(__file__).parent/"results"); p.add_argument("--timeframes",nargs="+",default=["5m","15m","1h","4h"]); args=p.parse_args()
    base=load_5m(args.data_root); args.out.mkdir(parents=True,exist_ok=True); results=[]
    for tf in args.timeframes:
        chart=base if tf=="5m" else resample(base,tf)
        events,trades,meta=analyze(chart,base,tf); result=summarize(events,trades,meta); results.append(result)
        events.assign(modules=events.modules.apply(json.dumps)).to_csv(args.out/f"events_{tf}.csv",index=False)
        pd.DataFrame(trades).to_csv(args.out/f"trades_{tf}.csv",index=False)
    (args.out/"summary.json").write_text(json.dumps(results,indent=2),encoding="utf-8")
    (args.out/"REPORT.md").write_text(markdown(results),encoding="utf-8")
    print(args.out/"REPORT.md")

if __name__ == "__main__": main()
