import argparse,json,zipfile,glob
from pathlib import Path
import pandas as pd,numpy as np
from validate import load

def bars(root,sym):
 out=[]
 for f in glob.glob(str(Path(root)/f'{sym}-15m-2025-*.zip')):
  with zipfile.ZipFile(f) as z:
   with z.open(z.namelist()[0]) as h: out.append(pd.read_csv(h,header=None))
 d=pd.concat(out,ignore_index=True).iloc[:,:12]; d.columns=['open_time','open','high','low','close','volume','close_time','quote_volume','trade_count','taker_buy_volume','taker_buy_quote_volume','ignore']; d=d.apply(pd.to_numeric,errors='coerce').dropna().drop_duplicates('open_time').sort_values('open_time'); d['ts']=pd.to_datetime(d.open_time,unit='ms',utc=True); return d.set_index('ts')

def touch(w,entry,side,tp,sl):
 for ts,r in w.iterrows():
  ht=(r.high>=entry*(1+tp)) if side==1 else (r.low<=entry*(1-tp)); hs=(r.low<=entry*(1-sl)) if side==1 else (r.high>=entry*(1+sl))
  if ht and hs:return 'AMBIGUOUS',ts
  if ht:return 'TP_FIRST',ts
  if hs:return 'SL_FIRST',ts
 return 'TIMEOUT',None

def main():
 ap=argparse.ArgumentParser(); ap.add_argument('--root',required=True); ap.add_argument('--pred',required=True); ap.add_argument('--out',required=True); a=ap.parse_args(); p=pd.DataFrame(json.load(open(a.pred))['rows']); p=p[p.model.isin(['baseline','taker_combined'])]; rows=[]
 for sym in p.symbol.unique():
  h=load(a.root,sym).set_index('ts'); m=bars(a.root,sym)
  for _,r in p[p.symbol==sym].iterrows():
   t=pd.Timestamp(r.timestamp); side=1 if r.pred==2 else -1 if r.pred==0 else 0
   if side==0: rows.append({'model':r.model,'symbol':sym,'timestamp':r.timestamp,'outcome':'NO_TRADE','net_return':0}); continue
   if t not in h.index: continue
   entry=float(h.loc[t].close); d=max(.0012,.5*float(h.loc[t].atr)); w=m[(m.index>t)&(m.index<=t+pd.Timedelta(hours=24))]; out,ot=touch(w,entry,side,d,d); last=float(w.close.iloc[-1]); gross=d if out=='TP_FIRST' else -d if out=='SL_FIRST' else ((last/entry-1)*side if out=='TIMEOUT' else 0); net=gross-.0012 if out!='AMBIGUOUS' else 0
   rows.append({'model':r.model,'symbol':sym,'timestamp':r.timestamp,'outcome':out,'net_return':net,'entry':entry,'tp_sl':d})
 o={'rows':rows,'assumptions':{'entry':'1h close; first strictly later 15m candle','cost':'0.12% resolved trades; none ambiguous/no-trade','collision':'same 15m candle is AMBIGUOUS'}}; Path(a.out).write_text(json.dumps(o),encoding='utf-8'); print(pd.DataFrame(rows).groupby('model').apply(lambda z: pd.Series({'n':len(z),'tp':(z.outcome=='TP_FIRST').mean(),'sl':(z.outcome=='SL_FIRST').mean(),'amb':(z.outcome=='AMBIGUOUS').mean(),'timeout':(z.outcome=='TIMEOUT').mean(),'no_trade':(z.outcome=='NO_TRADE').mean(),'net_mean':z.net_return.mean()}),include_groups=False).round(5).to_string())
if __name__=='__main__': main()
