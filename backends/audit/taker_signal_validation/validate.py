import argparse, json, zipfile, glob
from pathlib import Path
import numpy as np, pandas as pd
from sklearn.linear_model import LogisticRegression
from sklearn.preprocessing import StandardScaler
from sklearn.pipeline import make_pipeline
from sklearn.metrics import accuracy_score, balanced_accuracy_score, confusion_matrix

SETS={'baseline':['ret4','atr','body','range'],'taker_ratio':['ret4','atr','body','range','taker_ratio'],'taker_imbalance':['ret4','atr','body','range','taker_imbalance'],'trade_intensity':['ret4','atr','body','range','trade_intensity'],'taker_combined':['ret4','atr','body','range','taker_ratio','trade_intensity']}
def load(root,symbol):
    fs=glob.glob(str(Path(root)/f'{symbol}-1h-2025-*.zip')); out=[]
    for f in fs:
        with zipfile.ZipFile(f) as z:
            with z.open(z.namelist()[0]) as h: out.append(pd.read_csv(h,header=None))
    d=pd.concat(out,ignore_index=True); d=d.iloc[:,:12]; d.columns=['open_time','open','high','low','close','volume','close_time','quote_volume','trade_count','taker_buy_volume','taker_buy_quote_volume','ignore']
    for c in d.columns: d[c]=pd.to_numeric(d[c],errors='coerce')
    d=d.dropna().drop_duplicates('open_time').sort_values('open_time'); d['ts']=pd.to_datetime(d.open_time,unit='ms',utc=True)
    d=d[d.close_time < int(pd.Timestamp.utcnow().timestamp()*1000)]
    d['ret4']=d.close.pct_change(4); d['tr']=d.high-d.low; d['atr']=d.tr.rolling(24).mean()/d.close; d['body']=(d.close-d.open)/d.open; d['range']=d.tr/d.close
    d['taker_ratio']=np.where(d.volume>0,d.taker_buy_volume/d.volume-.5,np.nan); d['taker_imbalance']=2*d.taker_ratio; d['trade_intensity']=np.where(d.volume>0,d.trade_count/d.volume,np.nan)
    d['fwd']=d.close.shift(-24)/d.close-1; d['y']=np.select([d.fwd>d.atr.clip(lower=.0012)*.5,d.fwd< -d.atr.clip(lower=.0012)*.5],[2,0],default=1)
    return d.dropna(subset=list(SETS['baseline'])+['fwd','y']).reset_index(drop=True)
def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--root',required=True); ap.add_argument('--out',required=True); a=ap.parse_args(); rows=[]
    for sym in ['BTCUSDT','ETHUSDT','SOLUSDT','BNBUSDT','XRPUSDT']:
      d=load(a.root,sym); n=len(d); bounds=[(.0,.45),(.2,.65),(.4,.85)]
      for fold,(lo,hi) in enumerate(bounds):
       tr_end=d.ts.iloc[int(n*hi-24)]; te0=d.ts.iloc[int(n*hi)]; te1=d.ts.iloc[min(n-1,int(n*(hi+.1)))]; train=d[d.ts<tr_end]; test=d[(d.ts>=te1 if False else d.ts>=te0)&(d.ts<te1)]
       # embargo: test starts at fold boundary; training ends 24h before it
       train=train[train.ts < te0-pd.Timedelta(hours=24)]
       for name,cols in SETS.items():
        if len(train)<100 or len(test)==0: continue
        m=make_pipeline(StandardScaler(),LogisticRegression(max_iter=500,multi_class='auto',random_state=7)); m.fit(train[cols],train.y); pred=m.predict(test[cols]); pro=m.predict_proba(test[cols])
        for i,(_,r) in enumerate(test.iterrows()): rows.append({'model':name,'symbol':sym,'timestamp':r.ts.isoformat(),'horizon':'24h','fold':fold,'y':int(r.y),'pred':int(pred[i]),'prob':pro[i].tolist()})
    out={'rows':rows,'metadata':{'sets':SETS,'threshold':'0.5*24h ATR clipped at 0.12%','folds':'three chronological folds; 24h train embargo'}}; Path(a.out).write_text(json.dumps(out),encoding='utf-8'); print(json.dumps({'rows':len(rows),'models':{k:sum(x['model']==k for x in rows) for k in SETS}},indent=2))
if __name__=='__main__': main()
