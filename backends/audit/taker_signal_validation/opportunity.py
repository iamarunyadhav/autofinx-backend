import argparse,json
from pathlib import Path
import pandas as pd, numpy as np
from validate import load
def main():
 ap=argparse.ArgumentParser(); ap.add_argument('--root',required=True); ap.add_argument('--pred',required=True); ap.add_argument('--out',required=True); a=ap.parse_args(); all=[]
 p=pd.DataFrame(json.load(open(a.pred))['rows']); p=p[p.model.isin(['baseline','taker_combined'])]
 for sym in p.symbol.unique():
  d=load(a.root,sym).set_index('ts'); q=p[p.symbol==sym]
  for _,r in q.iterrows():
   t=pd.Timestamp(r.timestamp); w=d[(d.index>t)&(d.index<=t+pd.Timedelta(hours=24))]
   if len(w)<24: continue
   entry=float(d.loc[t].close); atr=float(d.loc[t].atr); tp=max(.0012,.5*atr); sl=tp
   side=1 if r.pred==2 else -1 if r.pred==0 else 0
   if side==0: continue
   hi=(w.high.max()/entry-1); lo=(w.low.min()/entry-1)
   mfe=hi if side==1 else -lo; mae=-lo if side==1 else hi
   hit_tp=mfe>=tp; hit_sl=mae>=sl; outcome='AMBIGUOUS' if hit_tp and hit_sl else 'TP' if hit_tp else 'SL' if hit_sl else 'TIMEOUT'
   gross=tp if outcome=='TP' else -sl if outcome=='SL' else (float(w.close.iloc[-1])/entry-1)*side
   net=gross-.0012
   all.append({'model':r.model,'symbol':sym,'timestamp':r.timestamp,'side':side,'mfe':mfe,'mae':mae,'outcome':outcome,'net_return':net})
 out={'rows':all,'assumptions':{'tp_sl':'0.5*ATR clipped 0.12%','cost':'0.12% round trip fees+slippage','ambiguous':'excluded from TP/SL win rate; no intrabar ordering assumed'}}; Path(a.out).write_text(json.dumps(out),encoding='utf-8')
 print(pd.DataFrame(all).groupby('model').apply(lambda z: pd.Series({'n':len(z),'tp':(z.outcome=='TP').mean(),'sl':(z.outcome=='SL').mean(),'amb':(z.outcome=='AMBIGUOUS').mean(),'net_mean':z.net_return.mean()}),include_groups=False).round(5).to_string())
if __name__=='__main__': main()
