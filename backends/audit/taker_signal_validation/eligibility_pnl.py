import argparse,json,zipfile,glob
from pathlib import Path
import pandas as pd,numpy as np
from validate import load
from first_touch import bars,touch
def main():
 ap=argparse.ArgumentParser(); ap.add_argument('--root',required=True); ap.add_argument('--pred',required=True); ap.add_argument('--out',required=True); a=ap.parse_args(); p=pd.DataFrame(json.load(open(a.pred))['rows']); out=[]
 for sym in p.symbol.unique():
  h=load(a.root,sym).set_index('ts'); m=bars(a.root,sym)
  for _,r in p[p.symbol==sym].iterrows():
   side=1 if r.pred==2 else -1 if r.pred==0 else 0; reason='SIDEWAYS' if side==0 else 'VALID'; t=pd.Timestamp(r.timestamp)
   if side==0 or t not in h.index: out.append({'model':r.model,'symbol':sym,'timestamp':r.timestamp,'signal':int(r.pred),'side':0,'status':'NO_TRADE','reason':reason,'net_return':0}); continue
   atr=float(h.loc[t].atr); dist=max(.0012,.5*atr); entry_time=t; entry=float(h.loc[t].close); w=m[(m.index>t)&(m.index<=t+pd.Timedelta(hours=24))]; outcome,ot=touch(w,entry,side,dist,dist); gross=dist if outcome=='TP_FIRST' else -dist if outcome=='SL_FIRST' else ((float(w.close.iloc[-1])/entry-1)*side if outcome=='TIMEOUT' else 0); fees=.0008; slip=.0004; net=gross-fees-slip if outcome!='AMBIGUOUS' else 0; out.append({'model':r.model,'symbol':sym,'timestamp':r.timestamp,'signal':int(r.pred),'side':side,'status':'EXECUTED' if outcome!='AMBIGUOUS' else 'AMBIGUOUS','entry_variant':'close','entry_time':str(entry_time),'entry_price':entry,'outcome':outcome,'gross_return':gross,'entry_fee':.0004,'exit_fee':.0004,'slippage':.0004,'net_return':net,'horizon_h':24})
 d=pd.DataFrame(out); d.to_json(a.out,orient='records'); print(d.groupby('model').apply(lambda z:pd.Series({'predictions':len(z),'trades':(z.status=='EXECUTED').sum(),'coverage':(z.status=='EXECUTED').mean(),'mean_per_prediction':z.net_return.mean(),'mean_per_trade':z.loc[z.status=='EXECUTED','net_return'].mean(),'tp_rate':(z.outcome=='TP_FIRST').mean(),'overlap_rows':len(z)}),include_groups=False).round(6).to_string())
if __name__=='__main__': main()
