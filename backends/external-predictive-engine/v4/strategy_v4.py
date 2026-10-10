"""AutoFinX TF-1 Donchian Trend v2 filtered research adapter."""
from __future__ import annotations
from pathlib import Path
import numpy as np
from . import strategy_v3 as base
from . import strategy_v13 as ind

TRANSLATION_STATUS = "Donchian TF-1 v2 filtered conservative research translation"
_base_compute = base.compute

def load_sealed(work: Path, symbol: str) -> dict: return base.load_sealed(work, symbol)

def compute(bars):
    x=_base_compute(bars); h,l,c,v=(np.asarray(bars[k],float) for k in ("high","low","close","volume"))
    plus,minus,adx=ind.dmi(h,l,c,14,14); tr=ind.true_range(h,l,c); atr=x["atr"]
    chop_rng=ind.highest(h,14)-ind.lowest(l,14); chop_sum=ind.sma(tr,14)*14
    chop=np.where((chop_rng>0)&(chop_sum>0),100*np.log10(chop_sum/chop_rng)/np.log10(14),100)
    rank=np.full(len(c),0.0)
    for i in range(99,len(c)):
        rank[i]=100*np.mean(atr[i-99:i+1] < atr[i])
    volavg=ind.sma(v,20); volok=v>volavg
    adxok=(adx>20)&(adx>ind.shift(adx))&(ind.shift(adx)>ind.shift(adx,2))&(ind.shift(adx,2)>ind.shift(adx,3))
    chopok=(chop<61.8)&(ind.shift(chop)<61.8)&(ind.shift(chop,2)<61.8)
    longdist=(c-x["upper"])>=0.25*atr; shortdist=(x["lower"]-c)>=0.25*atr
    # Confirmed daily EMA direction, equivalent to the v2 HTF gate.
    ht=ind.htf_trend(np.asarray(bars["open_time"],np.int64),bars,86_400_000)
    filt=adxok&chopok&(rank>40)&volok
    x["setup_long"] &= filt&longdist&(ht==1)
    x["setup_short"] &= filt&shortdist&(ht==-1)
    return x

def s0_trades(data: dict, symbol: str, tf: str):
    old=base.compute; base.compute=compute
    try: return base.s0_trades(data,symbol,tf)
    finally: base.compute=old
