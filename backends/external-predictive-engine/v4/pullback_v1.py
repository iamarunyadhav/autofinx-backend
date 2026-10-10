"""Research translation of AutoFinx_Trend_Pullback_v1. Dev/forward only."""
from pathlib import Path
import json, datetime as dt, hashlib
import numpy as np
from . import m1_research as io
from . import strategy_v13 as ind
from .targets import dense_minutes

SYMS=io.SYMBOLS; TF=3600000; HTF=14400000; DAY=86400000

def ema(x,n):
    out=np.full(len(x),np.nan); p=np.nan
    for i,v in enumerate(x):
        if np.isfinite(v): p=v if not np.isfinite(p) else 2/(n+1)*v+(1-2/(n+1))*p
        out[i]=p
    return out

def indicators(b,base):
    o,h,l,c,v=[b[k] for k in ('open','high','low','close','volume')]
    atr=ind.rma(ind.true_range(h,l,c),14); fast=ema(c,20); slow=ema(c,50); adx=ind.dmi(h,l,c,14,14)[2]
    hb=ind.resample(base,HTF); good=hb['count']==HTF//300000; hb={k:x[good] for k,x in hb.items()}
    he=ema(hb['close'],200); # Pine uses previous confirmed HTF close and EMA; use biasLen=200.
    prev_close=np.searchsorted(hb['open_time']+HTF,b['open_time']+TF,side='right')-2
    valid=prev_close>=0; ix=np.maximum(prev_close,0)
    hc=np.where(valid,hb['close'][ix],np.nan); he0=np.where(valid,he[ix],np.nan); heprev=np.where(valid, np.r_[np.nan,he[:-1]][ix],np.nan)
    up=valid&(hc>he0)&(he0>heprev)&(fast>slow)&(slow>ind.shift(slow,3))
    down=valid&(hc<he0)&(he0<heprev)&(fast<slow)&(slow<ind.shift(slow,3))
    quality=np.isfinite(adx)&(adx>=20)&(atr/c*100>=.30)
    volavg=ind.sma(v,20)
    return dict(atr=atr,fast=fast,slow=slow,adx=adx,up=up,down=down,quality=quality,volok=v>volavg,plus=ind.dmi(h,l,c,14,14)[0],minus=ind.dmi(h,l,c,14,14)[1],htf_ema=he0)

def run_one(b,base,g,f,start,end):
    z=indicators(b,base); t=b['open_time']; c=b['close']; pos=None; touchL=None;touchS=None;last_exit=-10**9; pending=None; rows=[]; cash=1000.; peak=cash; dd=0; missing=0
    def close(price,when,why):
        nonlocal cash,pos,last_exit,peak,dd
        nt=pos['qty']*pos['entry_price']; gross=pos['side']*pos['qty']*(price-pos['entry_price']); fee=(pos['entry_fee']+pos['qty']*price*.0006); mask=(f['funding_time']>pos['entry_time'])&(f['funding_time']<=when);fund=pos['side']*nt*f['funding_rate'][mask].sum(); net=gross-fee-fund;cash+=gross-fee;peak=max(peak,cash);dd=max(dd,(peak-cash)/peak*100);rows.append({**pos,'exit_time':int(when),'exit_price':float(price),'reason':why,'gross_pct':gross/nt*100,'fees':fee,'funding_pct':fund/nt*100,'net_pct':net/nt*100});pos=None;last_exit=int(when)
    for i,t0 in enumerate(t):
        if t0<start or t0>=end:continue
        a=int((t0-int(g['start']))//60000); zmin=a+60
        if a<0 or zmin>len(g['open']):continue
        if pending:
            typ,p=pending;pending=None;o=float(g['open'][a])
            if not np.isfinite(o):missing+=1;continue
            if typ=='entry':
                if pos:close(o,t0,'REVERSE')
                fee=p['qty']*o*.0006;cash-=fee;pos={**p,'entry_price':o,'entry_time':int(t0),'entry_bar':i,'entry_fee':fee}
            elif pos:close(o,t0,p)
        if pos:
            for m in range(a,zmin):
                o,h,l=[float(g[k][m]) for k in ('open','high','low')]
                if not np.isfinite([o,h,l]).all():missing+=1;continue
                s=pos['side'];stop=pos['stop'];target=pos['target'];
                px=io.stop_fill(s,stop,o,h,l)
                if px is not None:close(px,int(g['start'])+m*60000,'STOP');break
                if s==1 and h>=target:close(target,int(g['start'])+m*60000,'TARGET');break
                if s==-1 and l<=target:close(target,int(g['start'])+m*60000,'TARGET');break
        if pos:
            s=pos['side'];close_trend=(s==1 and c[i]<z['slow'][i] and b['close'][i-1]<z['slow'][i-1]) or (s==-1 and c[i]>z['slow'][i] and b['close'][i-1]>z['slow'][i-1])
            if close_trend:pending=('exit','TREND_LOST')
            entry=pos['entry_price'];rd=pos['risk_dist'];best=max(pos.get('best',entry),c[i]) if s==1 else min(pos.get('best',entry),c[i]);pos['best']=best
            if (s==1 and best>=entry+1.5*rd) or (s==-1 and best<=entry-1.5*rd):pos['trail']=True
            if pos.get('trail'):
                ns=best-2*z['atr'][i] if s==1 else best+2*z['atr'][i]
                pos['stop']=max(pos['stop'],ns) if s==1 else min(pos['stop'],ns)
        if not np.isfinite(z['atr'][i]) or not z['quality'][i]:continue
        if not z['up'][i] or (touchL is not None and i-touchL>5):touchL=None
        if not z['down'][i] or (touchS is not None and i-touchS>5):touchS=None
        can=pos is None and pending is None and (last_exit<0 or (t0-last_exit)>3*TF) and i+1<len(t)
        longsig=can and z['up'][i] and z['plus'][i]>z['minus'][i] and touchL is not None and i>touchL and c[i]>z['fast'][i] and c[i]>b['high'][i-1] and c[i]>b['open'][i] and c[i]-z['fast'][i]<=z['atr'][i]
        shortsig=can and z['down'][i] and z['minus'][i]>z['plus'][i] and touchS is not None and i>touchS and c[i]<z['fast'][i] and c[i]<b['low'][i-1] and c[i]<b['open'][i] and z['fast'][i]-c[i]<=z['atr'][i]
        if longsig or shortsig:
            s=1 if longsig else -1;rd=2*z['atr'][i];eq=max(cash,1);qty=min(eq*.005/(rd+ c[i]*.0016),eq*.90/c[i]);pending=('entry',dict(side=s,qty=qty,stop=c[i]-s*rd,target=c[i]+s*3*rd,risk_dist=rd,best=c[i],trail=False,signal_time=int(t0+TF)));touchL=None;touchS=None
        elif can:
            if z['up'][i] and b['low'][i]<=z['fast'][i] and c[i]>z['slow'][i]:touchL=i
            if z['down'][i] and b['high'][i]>=z['fast'][i] and c[i]<z['slow'][i]:touchS=i
    if pos:close(float(b['close'][-1]),int(b['open_time'][-1]+TF),'WINDOW_END')
    return rows,dict(max_drawdown_pct=dd,missing_minutes=missing)

def stats(rows):
    x=np.array([r['net_pct'] for r in rows]);g=np.array([r['gross_pct'] for r in rows]);n=len(x);w=x[x>0];l=-x[x<0].sum();sd=x.std(ddof=1) if n>1 else 0
    return dict(trades=n,avg_gross=float(g.mean()) if n else None,avg_net=float(x.mean()) if n else None,pf=float(w.sum()/l) if l else None,t_stat=float(x.mean()*np.sqrt(n)/sd) if sd else None,max_dd_pct=None,positive_profit_share_top5=float(np.sort(w)[-5:].sum()/w.sum()*100) if len(w) else None)

def main(work,out,source):
    out.mkdir(parents=True,exist_ok=True);(out/'run.lock').open('x').close();spec={'source_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'periods':{'dev':['2022-01-01','2025-01-01'],'forward':['2025-01-01','2026-10-10']},'chart':'1h','htf':'4h','cost':'0.06% each side; actual funding; sizing 0.5% risk and 90% cap','translation_notes':'Pine causal HTF previous confirmed bar; next-open fills; 1m path stop/target; target/trailing checked intraminute; no parameter tuning; forward untouched.'};(out/'spec.json').write_text(json.dumps(spec,indent=2));all=[]
    for s in SYMS:
        root=work/'data'/s; m5=io.read(root/'5m','open_time',columns=['open_time','open','high','low','close','volume']);m1=io.read(root/'1m','open_time',columns=['open_time','open','high','low','close']);g=dense_minutes(m1);f=io.read(root/'funding','funding_time')
        b=ind.resample(m5,TF);ok=b['count']==12;b={k:v[ok] for k,v in b.items()}
        for name,a,bnd in [('dev',1640995200000,1735689600000),('forward',1735689600000,1791590400000)]:
            rows,d=run_one(b,m5,g,f,a,bnd);r={**stats(rows),**d,'symbol':s,'period':name};(out/f'{s}_{name}.json').write_text(json.dumps({'summary':r,'trades':rows},indent=2));all.append(r);print(json.dumps(r),flush=True)
    pooled=[]
    for name in ('dev','forward'):
        rs=[r for r in all if r['period']==name];tr=[]
        for s in SYMS:
            tr+=json.loads((out/f'{s}_{name}.json').read_text())['trades']
        r={**stats(tr),'period':name,'positive_coins':sum(v['avg_net']>0 for v in rs if v['avg_net'] is not None)};pooled.append(r)
    (out/'result.json').write_text(json.dumps({'spec':spec,'rows':all,'pooled':pooled},indent=2));print(json.dumps(pooled),flush=True)
if __name__=='__main__':
 import argparse
 p=argparse.ArgumentParser();p.add_argument('--work',type=Path,required=True);p.add_argument('--out',type=Path,required=True);p.add_argument('--source',type=Path,required=True);a=p.parse_args();main(a.work,a.out,a.source)
