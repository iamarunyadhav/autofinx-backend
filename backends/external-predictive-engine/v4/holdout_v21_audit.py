"""One-shot frozen v2.1 holdout audit. Refuses repeat execution by lock."""
from __future__ import annotations
import json, time
from pathlib import Path
import numpy as np
from . import binance_data as bd
from .download import load_funding
from . import splits
from .targets import anchors_from_5m, dense_minutes
from . import strategy_v4

CANDIDATE="AFX-TF1-v2.1"; VERSION="frozen-v2.1"; BOOT=1000

def stats(xs):
    x=np.asarray(xs,float); n=len(x)
    if n==0:return {"trades":0}
    wins=x[x>0]; loss=x[x<0]; gp=wins.sum(); gl=-loss.sum()
    eq=np.cumsum(x); dd=float((np.maximum.accumulate(np.r_[0.,eq])[1:]-eq).max())
    streak=cur=0
    for v in x:
        cur=cur+1 if v<0 else 0; streak=max(streak,cur)
    sd=x.std(ddof=1) if n>1 else 0
    top=float(wins[np.argsort(wins)[-5:]].sum()/gp*100) if gp>0 else None
    return {"trades":n,"avg_gross_pct":None,"avg_net_pct":round(float(x.mean()),6),"profit_factor":round(float(gp/gl),4) if gl>0 else None,
            "t_stat":round(float(x.mean()/(sd/np.sqrt(n))),4) if sd>0 else None,"max_drawdown_additive_pct":round(dd,5),
            "longest_losing_streak":streak,"top5_profit_share_pct":round(top,3) if top is not None else None}

def bootstrap(values, seed=20261008):
    rng=np.random.default_rng(seed); streak=[]; draw=[]; x=np.asarray(values,float)
    for _ in range(BOOT):
        y=rng.choice(x,size=len(x),replace=True); e=np.cumsum(y); draw.append(float((np.maximum.accumulate(np.r_[0.,e])[1:]-e).max()))
        cur=mx=0
        for v in y: cur=cur+1 if v<0 else 0; mx=max(mx,cur)
        streak.append(mx)
    q=lambda a:[round(float(np.percentile(a,p)),4) for p in (50,95,99)]
    return {"resamples":BOOT,"losing_streak_percentiles_50_95_99":q(streak),"drawdown_percentiles_50_95_99":q(draw)}

def load_full(work,sym):
    m5=bd.load_klines(work,sym,"5m"); anchors=anchors_from_5m(m5); hold=splits.holdout_start_ms(anchors)
    base={k:m5[k] for k in ("open_time","open","high","low","close","volume")}
    m1=bd.load_klines(work,sym,"1m"); grid=dense_minutes(m1)
    fund=load_funding(work,sym)
    return {"base":base,"grid":grid,"funding":fund,"holdout_start":hold,"dev_end":splits.dev_end_ms(anchors),"train_start":int(anchors[0]),"train_end":int(anchors[-1]),"val_start":hold}

def run(work:Path,out:Path):
    lock=out/"holdout_access.lock"; log=out/"holdout_access.log"
    if lock.exists(): raise RuntimeError(f"REFUSED_SECOND_RUN lock={lock}")
    out.mkdir(parents=True,exist_ok=True); lock.write_text(json.dumps({"candidate":CANDIDATE,"version":VERSION,"utc":time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())},indent=2))
    log.write_text(f"candidate={CANDIDATE}\nversion={VERSION}\nmode=one-shot holdout\naccess_utc={time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())}\n")
    syms=["BTCUSDT","ETHUSDT","BNBUSDT","SOLUSDT","XRPUSDT","SUIUSDT"]; rows=[]; allv={"4h":[],"1d":[]}
    for s in syms:
        data=load_full(work,s); h=int(data["holdout_start"])
        for tf in ("4h","1d"):
            _,_,tr=strategy_v4.s0_trades(data,s,tf); hold=[x for x in tr if x["entry_time"]>=h and x["exit_time"]<=max(y["exit_time"] for y in tr)]
            for x in hold: x["gross_only"]=x["gross_pct"]
            z=stats([x["net_pct"] for x in hold]); z["avg_gross_pct"]=round(float(np.mean([x["gross_pct"] for x in hold])),6) if hold else None
            z.update({"coin":s,"timeframe":tf,"buy_trades":sum(x["side"]>0 for x in hold),"sell_trades":sum(x["side"]<0 for x in hold),"buy_hold_return_pct":None})
            z["bootstrap"]=bootstrap([x["net_pct"] for x in hold]) if hold else None; rows.append(z); allv[tf]+=hold
    pooled=[]
    for tf in ("4h","1d"):
        z=stats([x["net_pct"] for x in allv[tf]]); z["avg_gross_pct"]=round(float(np.mean([x["gross_pct"] for x in allv[tf]])),6) if allv[tf] else None; z["coin"]="POOLED"; z["timeframe"]=tf; z["bootstrap"]=bootstrap([x["net_pct"] for x in allv[tf]]) if allv[tf] else None; pooled.append(z)
    report={"candidate":CANDIDATE,"version":VERSION,"holdout_only":True,"dev_rerun":False,"rows":rows,"pooled":pooled,"pass_rules":{"trades":100,"t_stat":2,"pf":1.15,"positive_coins":"most"}}
    (out/"result.json").write_text(json.dumps(report,indent=2)); return report

if __name__=="__main__":
    import argparse
    ap=argparse.ArgumentParser(); ap.add_argument("--work",type=Path,required=True); ap.add_argument("--out",type=Path,required=True); a=ap.parse_args(); print(json.dumps(run(a.work,a.out),indent=2))
