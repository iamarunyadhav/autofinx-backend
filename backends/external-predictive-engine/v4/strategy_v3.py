"""AutoFinX TF-1 Donchian Trend v1 research adapter.

Closed-bar breakout, next-bar-open entry, shifted Donchian exit and ATR stop.
Webhook/dashboard code is intentionally excluded; this module is research-only.
"""
from __future__ import annotations
from pathlib import Path
import numpy as np
from . import strategy_v13 as base
from .targets import MINUTE

TRANSLATION_STATUS = "Donchian TF-1 causal research translation"
CHART_MS = {"4h": 14_400_000, "1d": 86_400_000}

def load_sealed(work: Path, symbol: str) -> dict:
    return base.load_sealed(work, symbol)

def _bars(data, tf):
    b = base.resample(data["base"], CHART_MS[tf])
    whole = b["count"] == CHART_MS[tf] // 300_000
    return {k:v[whole] for k,v in b.items()}

def compute(bars):
    h,l,c = (np.asarray(bars[k], float) for k in ("high","low","close"))
    atr = base.rma(base.true_range(h,l,c), 14)
    ema = base.ema(c, 200)
    upper = base.shift(base.highest(h,55)); lower = base.shift(base.lowest(l,55))
    exit_lo = base.shift(base.lowest(l,20)); exit_hi = base.shift(base.highest(h,20))
    long_sig = (c > upper) & (c > ema)
    short_sig = (c < lower) & (c < ema)
    return {"atr":atr,"upper":upper,"lower":lower,"exit_lo":exit_lo,"exit_hi":exit_hi,
            "setup_long":long_sig,"setup_short":short_sig}

def s0_trades(data: dict, symbol: str, tf: str):
    bars = _bars(data, tf); ind = compute(bars)
    t=np.asarray(bars["open_time"],np.int64); close=np.asarray(bars["close"],float)
    grid=data["grid"]; go,gh,gl=grid["open"],grid["high"],grid["low"]; start=int(grid["start"])
    ft=data["funding"]["funding_time"]; fr=data["funding"]["funding_rate"]
    trades=[]; pos=None
    def funding(a,b):
        if len(ft)==0:return 0.0
        return float(fr[(ft>a)&(ft<=b)].sum()*100.0)
    def finish(side, et, ep, xt, xp, reason):
        gross=side*(xp-ep)/ep*100.0; fund=side*funding(et,xt)
        trades.append({"entry_time":et,"side":side,"exit_time":xt,"gross_pct":gross,"funding_pct":fund,
                       "net_pct":gross-0.12-fund,"net_tv_cost_pct":gross-0.12,
                       "reason_a":reason,"reason_b":reason,"entry_price":ep,"exit_price_a":xp,"exit_price_b":xp})
    for i in range(len(t)-1):
        bar_end=int(t[i]+CHART_MS[tf]); entry_min=(bar_end-start)//MINUTE
        if pos is not None:
            side,ep,entry_time,next_min,stop=pos
            for m in range(max((int(t[i])-start)//MINUTE, pos[3]), min(entry_min,len(go))):
                if not np.isfinite(go[m]): continue
                o,h,l=go[m],gh[m],gl[m]; when=start+m*MINUTE
                if side>0 and l<=stop: finish(side,entry_time,ep,when,stop,"STOP"); pos=None; break
                if side<0 and h>=stop: finish(side,entry_time,ep,when,stop,"STOP"); pos=None; break
            if pos is not None:
                if (side>0 and close[i]<ind["exit_lo"][i]) or (side<0 and close[i]>ind["exit_hi"][i]):
                    finish(side,entry_time,ep,bar_end,close[i],"CHAN_EXIT"); pos=None
                else: pos=(side,ep,entry_time,entry_min,stop)
        if pos is None and entry_min < len(go) and np.isfinite(go[entry_min]) and np.isfinite(ind["atr"][i]):
            side=1 if ind["setup_long"][i] else -1 if ind["setup_short"][i] else 0
            if side:
                ep=float(go[entry_min]); sd=2.0*float(ind["atr"][i]); stop=ep-side*sd
                pos=(side,ep,bar_end,entry_min,stop)
    return bars,ind,trades
