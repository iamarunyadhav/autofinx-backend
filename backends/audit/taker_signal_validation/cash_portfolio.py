import argparse,json
from pathlib import Path
import pandas as pd
def run(d,model):
 cash=1000.; pos=[]; ledger=[]; eq=[]
 for _,r in d[d.model==model].sort_values(['timestamp','symbol']).iterrows():
  t=pd.Timestamp(r.timestamp)
  for p in pos[:]:
   if t>=p['due']: cash+=p['alloc']*(1+p['ret']); pos.remove(p)
  if r.outcome=='AMBIGUOUS': ledger.append({'status':'SKIP_AMBIGUOUS','timestamp':r.timestamp,'symbol':r.symbol}); continue
  if r.symbol in [p['symbol'] for p in pos]: ledger.append({'status':'SKIP_OVERLAP','timestamp':r.timestamp,'symbol':r.symbol}); continue
  if len(pos)>=5 or cash<100: ledger.append({'status':'SKIP_CASH','timestamp':r.timestamp,'symbol':r.symbol}); continue
  ret=float(r.net_return); pos.append({'symbol':r.symbol,'due':t+pd.Timedelta(hours=24),'alloc':100,'ret':ret}); cash-=100; ledger.append({'status':'OPEN','timestamp':r.timestamp,'symbol':r.symbol,'net_return':ret}); eq.append({'timestamp':r.timestamp,'cash':cash,'equity':cash+sum(p['alloc']*(1+p['ret']) for p in pos)})
 return ledger,eq,cash+sum(p['alloc']*(1+p['ret']) for p in pos)
def main():
 ap=argparse.ArgumentParser(); ap.add_argument('--input',required=True); ap.add_argument('--out',required=True); a=ap.parse_args(); d=pd.DataFrame(json.load(open(a.input))['rows']); o=[]
 for m in ['baseline','taker_combined']:
  l,e,f=run(d,m); o.append({'model':m,'initial':1000,'final':f,'ledger':l,'equity':e})
 Path(a.out).write_text(json.dumps(o)); print([(x['model'],x['final'],sum(z['status']=='OPEN' for z in x['ledger'])) for x in o])
if __name__=='__main__': main()
