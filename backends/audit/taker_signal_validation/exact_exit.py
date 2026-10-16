import argparse,json
from pathlib import Path
import pandas as pd
from validate import load
from first_touch import bars,touch
MODELS=['baseline','taker_combined']
def make_rows(root,path,scenario):
 p=pd.DataFrame(json.load(open(path))['rows']); p=p[p.model.isin(MODELS)]; out=[]
 for sym in p.symbol.unique():
  h=load(root,sym).set_index('ts'); q=bars(root,sym)
  for _,s in p[p.symbol==sym].iterrows():
   t=pd.Timestamp(s.timestamp); side=1 if int(s.pred)==2 else -1 if int(s.pred)==0 else 0; b={'model':s.model,'symbol':sym,'decision_ts':s.timestamp,'side':side}
   if side==0: out.append({**b,'reason':'NO_TRADE'}); continue
   if t not in h.index: out.append({**b,'reason':'INVALID'}); continue
   if scenario=='next_open':
    av=q[q.index>t]
    if av.empty: continue
    et=av.index[0]; entry=float(av.iloc[0].open)*(1.0002 if side==1 else .9998)
   else: et=t; entry=float(h.loc[t].close)
   dist=max(.0012,.5*float(h.loc[t].atr)); w=q[(q.index>et)&(q.index<=t+pd.Timedelta(hours=24))]
   reason,xt=touch(w,entry,side,dist,dist); xp=entry*(1+dist*side) if reason=='TP_FIRST' else entry*(1-dist*side) if reason=='SL_FIRST' else float(w.iloc[-1].close) if reason=='TIMEOUT' else entry; gross=(xp/entry-1)*side if reason!='AMBIGUOUS' else 0.; net=gross-.0012 if reason!='AMBIGUOUS' else 0.; xt=xt or w.index[-1]
   out.append({**b,'entry_ts':str(et),'entry_price':entry,'exit_ts':str(xt),'exit_price':xp,'reason':reason,'gross_return':gross,'net_return':net,'duration_h':(xt-et).total_seconds()/3600})
 return out
def settle(rows):
 result=[]
 for model in MODELS:
  cash=1000.; pos=[]; led=[]; curve=[]
  for r in sorted([x for x in rows if x['model']==model],key=lambda x:(x.get('entry_ts',x['decision_ts']),x['symbol'])):
   t=pd.Timestamp(r.get('entry_ts',r['decision_ts']))
   for p in pos[:]:
    if pd.Timestamp(p['exit_ts'])<=t: cash+=100*(1+float(p['net_return'])); pos.remove(p)
   if r['reason'] in ('NO_TRADE','INVALID','AMBIGUOUS'): led.append({**r,'status':'SKIP_'+r['reason']}); continue
   if any(p['symbol']==r['symbol'] for p in pos): led.append({**r,'status':'SKIP_OVERLAP'}); continue
   if len(pos)>=5 or cash<100: led.append({**r,'status':'SKIP_CASH'}); continue
   pos.append(r); cash-=100; led.append({**r,'status':'OPEN'}); curve.append({'timestamp':str(t),'cash':cash,'equity':cash+sum(100*(1+float(p['net_return'])) for p in pos)})
  result.append({'model':model,'initial_capital':1000.,'final_capital':cash+sum(100*(1+float(p['net_return'])) for p in pos),'ledger':led,'equity':curve})
 return result
def main():
 ap=argparse.ArgumentParser(); ap.add_argument('--root',required=True); ap.add_argument('--pred',required=True); ap.add_argument('--out',required=True); a=ap.parse_args(); all=[]
 for s in ('close','next_open'):
  rows=make_rows(a.root,a.pred,s); all.append({'scenario':s,'rows':rows,'cash':settle(rows)})
 Path(a.out).write_text(json.dumps(all)); print([(x['scenario'],[(z['model'],round(z['final_capital'],4),sum(v['status']=='OPEN' for v in z['ledger'])) for z in x['cash']]) for x in all])
if __name__=='__main__': main()
