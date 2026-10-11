"""Local research comparison: supplied UT Bot v4 and supplied v6 trade-plan strategy."""
from pathlib import Path
import json,datetime as dt,hashlib
import numpy as np
from . import m1_research as io
from . import strategy_v13 as ind
from .targets import dense_minutes

SYMS=io.SYMBOLS; TF=3600000; HTF=14400000; START=1640995200000; END=1791590400000
def ema(x,n):
 o=np.full(len(x),np.nan);p=np.nan
 for i,v in enumerate(x):
  if np.isfinite(v):p=v if not np.isfinite(p) else 2/(n+1)*v+(1-2/(n+1))*p
  o[i]=p
 return o
def ut(b,key=3,period=10):
 c=b['close'];a=ind.rma(ind.true_range(b['high'],b['low'],c),period);loss=key*a;s=np.full(len(c),np.nan);prev=0.
 for i,v in enumerate(c):
  if i==0:s[i]=v-loss[i]
  elif v>prev and c[i-1]>prev:s[i]=max(prev,v-loss[i])
  elif v<prev and c[i-1]<prev:s[i]=min(prev,v+loss[i])
  elif v>prev:s[i]=v-loss[i]
  else:s[i]=v+loss[i]
  prev=s[i]
 buy=(c> s)&(ind.shift(c)<=ind.shift(s));sell=(c<s)&(ind.shift(c)>=ind.shift(s))
 return dict(atr=a,stop=s,buy=buy,sell=sell)
def v6sig(b,base):
 c=b['close'];h=b['high'];l=b['low'];a=ind.rma(ind.true_range(h,l,c),10);u=ut(b,2.5,10);ema200=ema(c,200);p,m,adx=ind.dmi(h,l,c,14,14);rsi=np.where(p+m>0,100*p/(p+m),50)
 hb=ind.resample(base,HTF);ok=hb['count']==48;hb={k:v[ok] for k,v in hb.items()};he=ema(hb['close'],50);ix=np.searchsorted(hb['open_time']+HTF,b['open_time']+TF,side='right')-2;valid=ix>=0;ix=np.maximum(ix,0);hc=np.where(valid,hb['close'][ix],np.nan);hv=np.where(valid,he[ix],np.nan)
 longraw=u['buy'];shortraw=u['sell'];long=longraw&(c>ema200)&(ema200>ind.shift(ema200,3))&(hc>hv)&(adx>=20)&(p>m)&(rsi<=75)&(a/c*100>=.15);short=shortraw&(c<ema200)&(ema200<ind.shift(ema200,3))&(hc<hv)&(adx>=20)&(m>p)&(rsi>=25)&(a/c*100>=.15)
 return dict(**u,v6long=long,v6short=short)
def simulate(b,g,f,sg,mode):
 t=b['open_time'];pos=None;pending=None;rows=[];start=int(g['start']);last=-10**9
 def close(px,when,why,qtyfrac=1):
  nonlocal pos
  p=pos;qty=p['qty']*qtyfrac;nt=qty*p['entry'];gross=p['side']*(px-p['entry'])/p['entry']*100;mask=(f['funding_time']>p['time'])&(f['funding_time']<=when);fund=p['side']*f['funding_rate'][mask].sum()*100;net=gross-.12-fund
  rows.append({'side':p['side'],'entry_time':p['time'],'entry':p['entry'],'exit_time':when,'exit':px,'reason':why,'gross_pct':gross,'funding_pct':fund,'net_pct':net,'qty':qty});
  if qtyfrac>=.999:pos=None
  else:pos={**p,'qty':p['qty']*(1-qtyfrac),'tp1':True}
 for i,t0 in enumerate(t):
  a=int((t0-start)//60000);z=a+60
  if a<0 or z>len(g['open']) or t0<START or t0>=END:continue
  if pending:
   typ,side=pending;pending=None;o=float(g['open'][a])
   if typ=='entry' and pos is None:
    pos={**side,'qty':1.,'entry':o,'time':int(t0),'tp1':False}
   elif typ=='exit' and pos:close(o,int(t0),'OPPOSITE')
  if pos and mode=='v6':
   for m in range(a,z):
    o,h,l=[float(g[k][m]) for k in ('open','high','low')]
    if pos and pos['side']==1 and l<=pos['stop']:close(pos['stop'],start+m*60000,'STOP');break
    if pos and pos['side']==-1 and h>=pos['stop']:close(pos['stop'],start+m*60000,'STOP');break
    if pos and not pos['tp1'] and ((pos['side']==1 and h>=pos['tp1p']) or (pos['side']==-1 and l<=pos['tp1p'])):close(pos['tp1p'],start+m*60000,'TP1',.5)
    if pos and ((pos['side']==1 and h>=pos['tp2p']) or (pos['side']==-1 and l<=pos['tp2p'])):close(pos['tp2p'],start+m*60000,'TP2')
  side=1 if sg['buy'][i] else -1 if sg['sell'][i] else 0
  if mode=='v6':
   side=1 if sg['v6long'][i] else -1 if sg['v6short'][i] else 0
  if side and pos is None and pending is None and t0-last>=2*TF:
   entry=float(c:=b['close'][i]);risk=1.5*sg['atr'][i] if mode=='v6' else 3*sg['atr'][i]
   if np.isfinite(risk) and risk>0:
    p={'side':side,'qty':1.,'entry':entry,'time':int(t0),'stop':entry-side*risk,'tp1p':entry+side*risk,'tp2p':entry+side*2*risk};pending=('entry',p);last=t0
  if pos and mode=='v4' and ((pos['side']==1 and sg['sell'][i]) or (pos['side']==-1 and sg['buy'][i])):pending=('exit',0);last=t0
 return rows
def stats(r):
 x=np.array([v['net_pct'] for v in r]);g=np.array([v['gross_pct'] for v in r]);n=len(x);w=x[x>0];l=-x[x<0].sum();sd=x.std(ddof=1) if n>1 else 0
 return {'trades':n,'avg_gross':float(g.mean()) if n else None,'avg_net':float(x.mean()) if n else None,'pf':float(w.sum()/l) if l else None,'t_stat':float(x.mean()*np.sqrt(n)/sd) if sd else None,'win_rate':float((x>0).mean()*100) if n else None}
def main(work,out):
 out.mkdir(parents=True,exist_ok=True);(out/'run.lock').open('x').close();result={}
 for s in SYMS:
  root=work/'data'/s;m5=io.read(root/'5m','open_time',columns=['open_time','open','high','low','close','volume']);m1=io.read(root/'1m','open_time',columns=['open_time','open','high','low','close']);g=dense_minutes(m1);f=io.read(root/'funding','funding_time');b=ind.resample(m5,TF);ok=b['count']==12;b={k:v[ok] for k,v in b.items()};sg=v6sig(b,m5)
  for mode in ('v4','v6'):
   r=simulate(b,g,f,sg,mode); result.setdefault(s,{})[mode]=stats(r);(out/f'{s}_{mode}.json').write_text(json.dumps({'summary':result[s][mode],'trades':r},indent=2));print(s,mode,result[s][mode],flush=True)
 (out/'result.json').write_text(json.dumps(result,indent=2))
if __name__=='__main__':
 import argparse;p=argparse.ArgumentParser();p.add_argument('--work',type=Path,required=True);p.add_argument('--out',type=Path,required=True);a=p.parse_args();main(a.work,a.out)
