"""Frozen M1 research, independent of the v2 adapter. No optimization."""
from pathlib import Path
import json
import hashlib
import datetime as dt
import numpy as np
import pyarrow.parquet as pq
from . import strategy_v13 as ind
from . import splits
from .targets import dense_minutes

SYMBOLS = ['BTCUSDT','ETHUSDT','BNBUSDT','SOLUSDT','XRPUSDT','SUIUSDT']
TFS = {'15m':900000,'1h':3600000,'4h':14400000}

def files(root):
    return sorted((root/'monthly').glob('*.parquet')) + sorted((root/'daily').glob('*.parquet')) + ([root/'tail.parquet'] if (root/'tail.parquet').exists() else [])

def read(root, key, cutoff=None, columns=None):
    parts = [pq.read_table(f, columns=columns, filters=[(key,'<',cutoff)] if cutoff else None).to_pydict() for f in files(root)]
    parts = [p for p in parts if len(p[key])]
    if not parts: raise ValueError(f'No data: {root}')
    out = {k:np.concatenate([np.asarray(p[k]) for p in parts]) for k in parts[0]}
    ix=np.argsort(out[key],kind='stable'); out={k:v[ix] for k,v in out.items()}
    keep=np.r_[True,np.diff(out[key])> (60000 if key=='funding_time' else 0)]
    return {k:v[keep] for k,v in out.items()}

def features(b):
    h,l,c,v=(b[k] for k in ('high','low','close','volume'))
    atr=ind.rma(ind.true_range(h,l,c),14)
    up=ind.shift(ind.highest(h,55)); lo=ind.shift(ind.lowest(l,55))
    rng=h-l
    common=(rng>0)&(rng>=2.5*atr)&(v>=2*ind.sma(v,20))
    side=np.where(common&(c>up)&(c>=h-.25*rng),1,np.where(common&(c<lo)&(c<=l+.25*rng),-1,0))
    return dict(atr=atr,up=up,lo=lo,side=side,exit_lo=ind.shift(ind.lowest(l,20)),exit_hi=ind.shift(ind.highest(h,20)))

def stop_fill(side, stop, o, h, l):
    if side==1 and l<=stop:return min(o,stop)
    if side==-1 and h>=stop:return max(o,stop)
    return None

def simulate(b,g,f,ms):
    x=features(b); t=b['open_time']; trades=[]; signals=[]; pos=None
    pending=None; start=int(g['start']); quality=[]
    def finish(price,when,reason):
        nonlocal pos
        s=pos['side']; ep=pos['entry_price']; et=pos['entry_time']
        mask=(f['funding_time']>et)&(f['funding_time']<=when)
        # Historical rates; same percent-return funding convention as v2.
        fund=float(s*f['funding_rate'][mask].sum()*100)
        gross=s*(price-ep)/ep*100
        trades.append({**pos,'exit_price':float(price),'exit_time':int(when),'exit_reason':reason,'gross_pct':float(gross),'cost_pct':.12,'funding_pct':fund,'net_pct':float(gross-.12-fund)})
        pos=None
    for i in range(len(t)):
        a=int((t[i]-start)//60000); z=a+ms//60000
        if a<0 or z>len(g['open']):continue
        if pending:
            kind,payload=pending; pending=None
            if np.isfinite(g['open'][a]):
                if kind=='exit' and pos:finish(g['open'][a],t[i],payload)
                elif kind=='entry' and pos is None:
                    pos={**payload,'entry_price':float(g['open'][a]),'entry_time':int(t[i]),'entry_bar':i}
            else:quality.append({'time':int(t[i]),'reason':'missing_fill'})
        if pos:
            for m in range(a,z):
                if not all(np.isfinite(g[k][m]) for k in ('open','high','low')):
                    quality.append({'time':start+m*60000,'reason':'missing_path'}); continue
                price=stop_fill(pos['side'],pos['stop'],g['open'][m],g['high'][m],g['low'][m])
                if price is not None:finish(price,start+m*60000,'STOP');break
        if pos:
            failed=(i-pos['entry_bar']<3) and pos['side']*(b['close'][i]-pos['breakout_level'])<0
            channel=b['close'][i]<x['exit_lo'][i] if pos['side']==1 else b['close'][i]>x['exit_hi'][i]
            if failed or channel:pending=('exit','FAILED_BREAKOUT' if failed else 'CHANNEL')
        s=int(x['side'][i])
        if s:
            level=float(x['up'][i] if s==1 else x['lo'][i])
            future=b['close'][i+1:i+4]
            complete=len(future)==3 and np.all(np.diff(t[i:i+4])==ms)
            reversal=bool(np.any(s*(future-level)<0)) if complete else None
            eligible=pos is None and pending is None and i+1<len(t) and t[i+1]==t[i]+ms
            signals.append({'signal_time':int(t[i]+ms),'side':s,'breakout_level':level,'entered':bool(eligible),'reversed_within_3_bars':reversal})
            if eligible:
                pending=('entry',{'side':s,'signal_time':int(t[i]+ms),'stop':float(b['close'][i]-s*2*x['atr'][i]),'breakout_level':level,'signal_atr':float(x['atr'][i])})
    open_position=pos.copy() if pos else None
    if pos:finish(b['close'][-1],t[-1]+ms,'DEV_WINDOW_END')
    return trades,signals,quality,open_position

def stats(tr):
    a=np.array([v['net_pct'] for v in tr]); gross=np.array([v['gross_pct'] for v in tr]); n=len(a)
    if not n:return dict(trades=0,avg_gross=None,avg_net=None,pf=None,t_stat=None,top5_profit_share=None,failed_exits=0)
    wins=a[a>0]; losses=-a[a<0].sum(); sd=a.std(ddof=1) if n>1 else 0
    return dict(trades=n,avg_gross=float(gross.mean()),avg_net=float(a.mean()),pf=float(wins.sum()/losses) if losses else None,t_stat=float(a.mean()*np.sqrt(n)/sd) if sd else None,top5_profit_share=float(np.sort(wins)[-5:].sum()/wins.sum()*100) if len(wins) else None,failed_exits=sum(v['exit_reason']=='FAILED_BREAKOUT' for v in tr))

def run(work,out):
    out.mkdir(parents=True,exist_ok=True)
    with (out/'run.lock').open('x') as f:f.write(dt.datetime.now(dt.timezone.utc).isoformat())
    bounds={}
    for sym in SYMBOLS:
        ts=read(work/'data'/sym/'5m','open_time',columns=['open_time'])['open_time']+300000
        bounds[sym]=dict(holdout_start=splits.holdout_start_ms(ts),dev_end=splits.dev_end_ms(ts))
    spec=dict(hypothesis='M1',timeframes=TFS,symbols=SYMBOLS,bounds=bounds,range_atr=2.5,volume_sma=2,atr=14,volume_window=20,donchian=55,outer_fraction=.25,cost_pct=.12,
        semantics=['ATR and volume SMA include closed signal bar; prior 55/20 bars for channels','Only requested M1 entry predicates; no EMA, HTF, ADX, chop, percentile or distance gates','One position, no reversals/pyramiding; ignore signals while occupied','Pine stop anchored to signal close +/- 2 signal ATR, active immediately at fill','Failed close through original breakout boundary on first 3 filled bars; exits next bar open','Stops gap-fill at worse open; stop tested before bar-close exit decisions','Open positions marked at last complete dev close, charged full costs; separately flagged','Funding actual rates times entry-notional convention, excludes entry instant/includes exit instant; no mark-price revaluation','All raw signals diagnostic: close back inside original boundary within next 3 bars; right-censored excluded','PF and t-stat on net trade returns; top5 / sum positive net returns; pooled per timeframe; 4/6 positive means most','Ordinary t-stat descriptive: cross-coin/time dependence not corrected; dev-only exploratory evidence'],
        pass_rules=dict(trades_gte=100,avg_gross_gt=.12,pf_gt=1.15,t_stat_gte=2,positive_coins_gte=4),source_sha256=hashlib.sha256(Path(__file__).read_bytes()).hexdigest())
    (out/'spec.json').write_text(json.dumps(spec,indent=2))
    rows=[]; pooled={tf:[] for tf in TFS}
    for sym in SYMBOLS:
        cut=bounds[sym]['dev_end']; root=work/'data'/sym
        base=read(root/'5m','open_time',cut-299999,columns=['open_time','open','high','low','close','volume'])
        m1=read(root/'1m','open_time',cut-59999,columns=['open_time','open','high','low','close'])
        g=dense_minutes(m1); fund=read(root/'funding','funding_time',cut)
        for tf,ms in TFS.items():
            b=ind.resample(base,ms); ok=(b['count']==ms//300000)&(b['open_time']+ms<=cut)
            b={k:v[ok] for k,v in b.items()}
            tr,sig,quality,op=simulate(b,g,fund,ms)
            for v in tr:v.update(symbol=sym,timeframe=tf)
            r={**stats(tr),'symbol':sym,'timeframe':tf,'signals':len(sig),'reversed_signals':sum(v['reversed_within_3_bars'] is True for v in sig),'censored_signals':sum(v['reversed_within_3_bars'] is None for v in sig),'quality_issues':len(quality),'window_end_exit':op is not None,'start_ms':int(b['open_time'][0]),'end_ms':int(b['open_time'][-1]+ms)}
            assert all(v['exit_time']<=cut for v in tr)
            (out/f'{sym}_{tf}.json').write_text(json.dumps(dict(summary=r,trades=tr,signals=sig,quality=quality,open_before_mark=op),indent=2))
            rows.append(r);pooled[tf]+=tr;print(json.dumps(r),flush=True)
    summary=[]
    for tf,tr in pooled.items():
        r=stats(tr); rr=[v for v in rows if v['timeframe']==tf]; positive=sum((v['avg_net'] or 0)>0 for v in rr)
        passed=r['trades']>=100 and r['avg_gross']>.12 and (r['pf'] or 0)>1.15 and (r['t_stat'] or 0)>=2 and positive>=4
        r.update(timeframe=tf,positive_coins=positive,verdict='INCONCLUSIVE' if r['trades']<100 or any(v['quality_issues'] for v in rr) else ('PASS' if passed else 'REJECTED'))
        summary.append(r)
    result=dict(rows=rows,pooled=summary,spec=spec)
    (out/'result.json').write_text(json.dumps(result,indent=2));print(json.dumps(summary),flush=True)
    lines=['# M1 frozen development-only report','No tuning; no v2 rerun. Read spec.json for execution conventions and limitations.','PF/t-stat use net returns. Top5 is share of positive net profit. All percentages are trade-notional returns, not account returns.','','|Coin|TF|N|Gross %|Net %|PF|t|Top5 %|Signals reversed/all|Failed exits|','|---|---|---:|---:|---:|---:|---:|---:|---:|---:|']
    fmt=lambda v:'NA' if v is None else f'{v:.4f}'
    for r in rows:lines.append('|'+ '|'.join([r['symbol'],r['timeframe'],str(r['trades'])]+[fmt(r[k]) for k in ['avg_gross','avg_net','pf','t_stat','top5_profit_share']]+[f"{r['reversed_signals']}/{r['signals']}",str(r['failed_exits'])])+'|')
    lines+=['','Pooled, separately by timeframe:',json.dumps(summary,indent=2),'','Failures count return inside the original breakout boundary; not all such raw signals were entered. End-of-window positions are explicitly marked and included. This is a development hypothesis test, not independent live validation.']
    (out/'REPORT.md').write_text('\n'.join(lines))

if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser();p.add_argument('--work',type=Path,required=True);p.add_argument('--out',type=Path,required=True);a=p.parse_args();run(a.work,a.out)
