import argparse,json,time,zipfile,glob
from pathlib import Path
import numpy as np,pandas as pd
from validate import load

def load15(root,s):
    frames=[]
    for f in glob.glob(str(Path(root)/f'{s}-15m-2025-*.zip')):
        with zipfile.ZipFile(f) as z:
            with z.open(z.namelist()[0]) as h: frames.append(pd.read_csv(h,header=None,usecols=[0,1,2,3,4]))
    d=pd.concat(frames,ignore_index=True); d[0]=pd.to_numeric(d[0],errors='coerce'); d=d.dropna().drop_duplicates(0).sort_values(0).to_numpy()
    return d[:,0].astype('int64'),d[:,1:].astype(float)

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--root',required=True); ap.add_argument('--pred',required=True); ap.add_argument('--out',required=True); ap.add_argument('--symbol'); ap.add_argument('--model'); ap.add_argument('--scenario',choices=['close','next_open','both'],default='both'); ap.add_argument('--limit',type=int); a=ap.parse_args(); t0=time.time()
    p=pd.DataFrame(json.load(open(a.pred))['rows'])
    if a.symbol: p=p[p.symbol==a.symbol]
    if a.model: p=p[p.model==a.model]
    if a.limit: p=p.sort_values('timestamp').head(a.limit)
    cache={}; hrs={}
    for s in p.symbol.unique(): cache[s]=load15(a.root,s); hrs[s]=load(a.root,s).set_index('ts'); print('loaded',s,flush=True)
    scenarios=[]; choices=('close','next_open') if a.scenario=='both' else (a.scenario,)
    for scenario in choices:
        rows=[]
        for n,r in enumerate(p.itertuples(),1):
            if n%500==0: print('progress',scenario,n,round(time.time()-t0,2),flush=True)
            side=1 if r.pred==2 else -1 if r.pred==0 else 0; ts=pd.Timestamp(r.timestamp); arr,vals=cache[r.symbol]; i=np.searchsorted(arr,ts.value//10**6); h=hrs[r.symbol]
            if side==0 or i>=len(arr): rows.append({'model':r.model,'symbol':r.symbol,'decision_ts':r.timestamp,'reason':'NO_TRADE'}); continue
            ei=i if scenario=='close' else i+1
            if ei>=len(arr): continue
            entry=float(h.loc[ts].close) if scenario=='close' else vals[ei,0]*(1.0002 if side==1 else .9998); dist=max(.0012,.5*float(h.loc[ts].atr)); end=min(len(arr),ei+97); reason='TIMEOUT'; xi=end-1
            for j in range(ei+1,end):
                hi,lo=vals[j,1],vals[j,2]; tp=hi>=entry*(1+dist) if side==1 else lo<=entry*(1-dist); sl=lo<=entry*(1-dist) if side==1 else hi>=entry*(1+dist)
                if tp and sl: reason='AMBIGUOUS'; xi=j; break
                if tp: reason='TP_FIRST'; xi=j; break
                if sl: reason='SL_FIRST'; xi=j; break
            xp=entry*(1+dist*side) if reason=='TP_FIRST' else entry*(1-dist*side) if reason=='SL_FIRST' else vals[xi,3] if reason=='TIMEOUT' else entry; gross=(xp/entry-1)*side if reason!='AMBIGUOUS' else 0.
            rows.append({'model':r.model,'symbol':r.symbol,'decision_ts':r.timestamp,'entry_ts':str(pd.to_datetime(arr[ei],unit='ms',utc=True)),'exit_ts':str(pd.to_datetime(arr[xi],unit='ms',utc=True)),'entry_price':entry,'reason':reason,'net_return':gross-.0012 if reason!='AMBIGUOUS' else 0.,'duration_h':(xi-ei)*.25})
        cash=[]
        for model in ['baseline','taker_combined']:
            c=1000.; pos=[]; led=[]; curve=[]
            for r in sorted([x for x in rows if x['model']==model],key=lambda x:(x.get('entry_ts',''),x['symbol'])):
                t=pd.Timestamp(r.get('entry_ts'))
                for q in pos[:]:
                    if pd.Timestamp(q['exit_ts'])<=t: c+=100*(1+q['net_return']); pos.remove(q)
                if r['reason']=='AMBIGUOUS': led.append({**r,'status':'SKIP_AMBIGUOUS'}); continue
                if any(q['symbol']==r['symbol'] for q in pos): led.append({**r,'status':'SKIP_OVERLAP'}); continue
                if len(pos)>=5 or c<100: led.append({**r,'status':'SKIP_CASH'}); continue
                pos.append(r); c-=100; led.append({**r,'status':'OPEN'}); curve.append({'timestamp':r['entry_ts'],'cash':c,'equity':c+sum(100*(1+q['net_return']) for q in pos)})
            cash.append({'model':model,'final_capital':c+sum(100*(1+q['net_return']) for q in pos),'ledger':led,'equity':curve})
        scenarios.append({'scenario':scenario,'cash':cash,'rows':rows})
    Path(a.out).write_text(json.dumps(scenarios)); print('DONE',round(time.time()-t0,2))
if __name__=='__main__': main()
