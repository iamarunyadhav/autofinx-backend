import argparse,json
from pathlib import Path
import pandas as pd,numpy as np
def run(d,model,amb):
 q=d[(d.model==model)].sort_values(['timestamp','symbol']); open_until={}; ledger=[]; equity=0; peak=0; dd=0
 for _,r in q.iterrows():
  t=pd.Timestamp(r.timestamp); sym=r.symbol
  if sym in open_until and t<open_until[sym]: ledger.append({'model':model,'symbol':sym,'timestamp':r.timestamp,'status':'SKIPPED_OVERLAP'}); continue
  out=r.outcome
  if out=='NO_TRADE': ledger.append({'model':model,'symbol':sym,'timestamp':r.timestamp,'status':'NO_TRADE'}); continue
  ret=float(r.net_return)
  if out=='AMBIGUOUS': ret=amb
  ledger.append({'model':model,'symbol':sym,'timestamp':r.timestamp,'status':'EXECUTED','outcome':out,'net_return':ret}); open_until[sym]=t+pd.Timedelta(hours=24); equity+=ret; peak=max(peak,equity); dd=min(dd,equity-peak)
 return ledger,equity,dd
def main():
 ap=argparse.ArgumentParser(); ap.add_argument('--input',required=True); ap.add_argument('--out',required=True); a=ap.parse_args(); d=pd.DataFrame(json.load(open(a.input))['rows']); all=[]
 for m in ['baseline','taker_combined']:
  for amb in [0,-.0012,.0012]:
   l,e,dd=run(d,m,amb); all.append({'model':m,'ambiguous_assumption':amb,'ledger':l,'total_net':e,'max_drawdown':dd,'executed':sum(x.get('status')=='EXECUTED' for x in l),'skipped':sum(x.get('status')=='SKIPPED_OVERLAP' for x in l)})
 print(pd.DataFrame([{k:v for k,v in x.items() if k!='ledger'} for x in all]).to_string(index=False)); Path(a.out).write_text(json.dumps(all),encoding='utf-8')
if __name__=='__main__': main()
