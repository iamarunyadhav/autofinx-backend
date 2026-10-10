"""AFX TF1 v2.2 source-default local Lab translation. Not a Pine runtime."""
from pathlib import Path
import json, hashlib, shutil
import numpy as np
from . import m1_research as io
from . import strategy_v13 as ind
from .targets import dense_minutes

DAY=86400000
TFS={'15m':900000,'1h':3600000,'4h':14400000,'1d':DAY}

def ema(x,n):
    out=np.full(len(x),np.nan); prev=np.nan
    for i,v in enumerate(x):
        if np.isfinite(v):prev=v if not np.isfinite(prev) else 2/(n+1)*v+(1-2/(n+1))*prev
        out[i]=prev
    return out

def compute(b,base,ms):
    h,l,c,v=[b[k] for k in ('high','low','close','volume')]
    atr=ind.rma(ind.true_range(h,l,c),14); trend=ema(c,200)
    upper=ind.shift(ind.highest(h,55)); lower=ind.shift(ind.lowest(l,55))
    _,_,adx=ind.dmi(h,l,c,14,14)
    rng=ind.highest(h,14)-ind.lowest(l,14); tr=ind.sma(ind.true_range(h,l,c),14)*14
    with np.errstate(divide='ignore',invalid='ignore'):chop=np.where((rng>0)&(tr>0),100*np.log10(tr/rng)/np.log10(14),100)
    rank=np.full(len(c),np.nan)
    for i in range(99,len(c)):
        w=atr[i-99:i+1]
        if np.isfinite(w).all():rank[i]=np.mean(w<=atr[i])*100
    daily=ind.resample(base,DAY); good=daily['count']==288; daily={k:v[good] for k,v in daily.items()}
    dc=daily['close']; de=ema(dc,50); direction=np.sign(dc-de)
    # Historical request.security(D, expression[1], gaps_off, lookahead_off):
    # publish shifted daily value only at the end of its receiving daily bar.
    ix=np.searchsorted(daily['open_time']+DAY,b['open_time']+ms,side='right')-2
    ht=np.where(ix>=0,direction[np.maximum(ix,0)],0)
    filt=(adx>20)&(adx>ind.shift(adx))&(ind.shift(adx)>ind.shift(adx,2))&(ind.shift(adx,2)>ind.shift(adx,3))
    filt&=(chop<61.8)&(ind.shift(chop)<61.8)&(ind.shift(chop,2)<61.8)&(rank>40)&(v>ind.sma(v,20))&(atr/c*100>=.48)&(ms<DAY)
    side=np.where(filt&(c>upper)&(c>trend)&(c-upper>=.25*atr)&(ht==1),1,np.where(filt&(c<lower)&(c<trend)&(lower-c>=.25*atr)&(ht==-1),-1,0))
    return dict(side=side,atr=atr,upper=upper,lower=lower,exit_lo=ind.shift(ind.lowest(l,20)),exit_hi=ind.shift(ind.highest(h,20)),ht=ht)

def simulate(b,g,f,x,ms):
    cash=10000.; pos=None; pending=None; rows=[]; peak=10000.; maxdd=0.; maxddpct=0.; missing=0; conflicts=0
    def mark(value):
        nonlocal peak,maxdd,maxddpct
        peak=max(peak,value);maxdd=max(maxdd,peak-value);maxddpct=max(maxddpct,(peak-value)/peak*100)
    def close(price,when,reason):
        nonlocal cash,pos
        q=pos['size']; ep=pos['entry_price']; s=pos['side']; nt=q*ep
        profit=s*q*(price-ep); fees=pos['entry_fee']+q*price*.0006
        net=profit-fees; cash+=profit-q*price*.0006
        hours=(when-pos['entry_time'])/3600000; est=nt*.0001*hours/8
        fm=(f['funding_time']>pos['entry_time'])&(f['funding_time']<=when)
        actual=float(s*nt*f['funding_rate'][fm].sum())
        rows.append({**pos,'exit_time':int(when),'exit_price':float(price),'reason':reason,'gross_pct':float(profit/nt*100),'fees':float(fees),'net_tv':float(net),'estimated_funding':float(est),'actual_funding_entry_notional':actual,'net_est':float(net-est),'net_actual':float(net-actual),'net_pct':float((net-est)/nt*100),'actual_net_pct':float((net-actual)/nt*100)})
        pos=None;mark(cash)
    for i,t in enumerate(b['open_time']):
        a=int((t-int(g['start']))//60000); z=a+ms//60000
        if a<0 or z>len(g['open']):continue
        if pending:
            typ,p=pending;pending=None;o=float(g['open'][a])
            if not np.isfinite(o):raise ValueError('Missing next-open fill')
            if typ=='entry':
                if pos:close(o,t,'REVERSE')
                fee=p['size']*o*.0006;cash-=fee
                pos={**p,'entry_time':int(t),'entry_price':o,'entry_fee':fee,'entry_bar':i}
                mark(cash)
            elif pos:close(o,t,p)
        if pos:
            for m in range(a,z):
                o,h,l=[float(g[k][m]) for k in ('open','high','low')]
                if not np.isfinite([o,h,l]).all():missing+=1;continue
                price=io.stop_fill(pos['side'],pos['stop'],o,h,l)
                if price is not None:close(price,int(g['start'])+m*60000,'STOP');break
                adverse=l if pos['side']==1 else h
                mark(cash+pos['side']*pos['size']*(adverse-pos['entry_price']))
                favorable=h if pos['side']==1 else l
                mark(cash+pos['side']*pos['size']*(favorable-pos['entry_price']))
        s=int(x['side'][i]);c=float(b['close'][i])
        if s and (pos is None or pos['side']!=s) and i+1<len(b['close']):
            eq=cash+(pos['side']*pos['size']*(c-pos['entry_price']) if pos else 0)
            sd=2*x['atr'][i];q=min(eq*.01/sd,eq*.95/c)
            if q>0:
                p=dict(side=s,size=float(q),stop=float(c-s*sd),breakout=float(x['upper'][i] if s==1 else x['lower'][i]),signal_time=int(t+ms))
                pending=('entry',p)
                if pos:
                    # Pine shared variables overwrite the existing position's stop/breakout
                    # during a reversal signal. Record overlapping market-close ambiguity.
                    pos['stop']=p['stop'];pos['breakout']=p['breakout'];pos['entry_bar']=i+1
        if pos:
            failed=i-pos['entry_bar']<3 and pos['side']*(c-pos['breakout'])<0
            channel=c<x['exit_lo'][i] if pos['side']==1 else c>x['exit_hi'][i]
            if failed or channel:
                if pending and pending[0]=='entry':conflicts+=1
                else:pending=('exit','FAILED_BREAKOUT' if failed else 'CHANNEL')
    open_position=pos
    unrealized=pos['side']*pos['size']*(b['close'][-1]-pos['entry_price']) if pos else 0
    return rows,dict(equity_tv_marked=float(cash+unrealized),unrealized=float(unrealized),open_position=open_position,max_drawdown_1m_estimate=maxdd,max_drawdown_pct_1m_estimate=maxddpct,missing_path_minutes=missing,reversal_order_conflicts=conflicts,signals=int(np.count_nonzero(x['side'])))

def stats(rows):
    r=io.stats([{**v,'exit_reason':v['reason']} for v in rows]); est=np.array([v['net_est'] for v in rows]);actual=np.array([v['actual_net_pct'] for v in rows])
    r.update(pf_cash_est=float(est[est>0].sum()/-est[est<0].sum()) if np.any(est<0) else None,net_cash_est=float(est.sum()),net_cash_actual=float(sum(v['net_actual'] for v in rows)),avg_net_actual=float(actual.mean()) if len(actual) else None)
    return r

def run(work,out,source,candidate):
    out.mkdir(parents=True,exist_ok=True);(out/'run.lock').open('x').close();candidate.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(source,candidate/'source.pine')
    bounds={s:io.splits.dev_end_ms(io.read(work/'data'/s/'5m','open_time',columns=['open_time'])['open_time']+300000) for s in io.SYMBOLS}
    spec=dict(candidate='AFX-TF1-v2.2-source-defaults',source_sha256=hashlib.sha256(source.read_bytes()).hexdigest(),adapter_sha256=hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),bounds=bounds,timeframes=TFS,main='4h',scope='Development only; 15m/1h diagnostics; 1d default HTF invalid',cost='0.06% each execution notional; estimated funding 0.01%/8h both sides; observed-rate entry-notional funding separate',sizing='10000 initial; 1% equity risk, 95% signal-close notional cap; funding not deducted from sizing equity, as Pine',limits=['Python translation, not a TradingView Pine runtime; export parity not verified','Historical HTF expression[1]+lookahead_off mapping preserved, may differ from live Pine HTF timing','1m stop path replaces default TradingView chart OHLC emulator; drawdown minute-order approximation','Reversal overlapping exit/order conflicts flagged; not claimed exact broker-emulator parity','Fractional size, no venue lot rounding or margin liquidation emulation','Existing 1m/5m archive source mismatches not repaired; missing execution minutes make verdict inconclusive','Open positions marked separately, not silently liquidated or counted as closed trades'],pass_rules='Per timeframe pooled N>=100, gross>0.12, estimated-funding cash PF>1.15, net t>=2, positive >=4/6; exploratory dev only')
    (out/'spec.json').write_text(json.dumps(spec,indent=2));(candidate/'spec.json').write_text(json.dumps(spec,indent=2))
    allrows=[];pool={tf:[] for tf in TFS}
    for s in io.SYMBOLS:
        root=work/'data'/s;cut=bounds[s]
        base=io.read(root/'5m','open_time',cut-299999,columns=['open_time','open','high','low','close','volume'])
        g=dense_minutes(io.read(root/'1m','open_time',cut-59999,columns=['open_time','open','high','low','close']))
        f=io.read(root/'funding','funding_time',cut)
        for tf,ms in TFS.items():
            b=ind.resample(base,ms);ok=(b['count']==ms//300000)&(b['open_time']+ms<=cut);b={k:v[ok] for k,v in b.items()}
            x=compute(b,base,ms);rows,diag=simulate(b,g,f,x,ms)
            r={**stats(rows),**diag,'coin':s,'tf':tf,'first_ms':int(b['open_time'][0]),'last_ms':int(b['open_time'][-1]+ms)}
            (out/f'{s}_{tf}.json').write_text(json.dumps(dict(summary=r,trades=rows),indent=2));allrows.append(r);pool[tf]+=rows
            print(json.dumps({k:v for k,v in r.items() if k!='open_position'}),flush=True)
    pooled=[]
    for tf,rr in pool.items():
        r=stats(rr);cc=[v for v in allrows if v['tf']==tf];positive=sum((v['avg_net'] or 0)>0 for v in cc)
        passed=r['trades']>=100 and r['avg_gross']>.12 and (r['pf_cash_est'] or 0)>1.15 and (r['t_stat'] or 0)>=2 and positive>=4
        r.update(tf=tf,positive_coins=positive,verdict='INCONCLUSIVE' if r['trades']<100 or any(v['missing_path_minutes'] or v['reversal_order_conflicts'] for v in cc) else ('PASS_DEV_ONLY' if passed else 'REJECTED'))
        pooled.append(r)
    result=dict(spec=spec,rows=allrows,pooled=pooled);(out/'result.json').write_text(json.dumps(result,indent=2));print(json.dumps(pooled),flush=True)

if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser()
    for name in ['work','out','source','candidate']:p.add_argument('--'+name,type=Path,required=True)
    a=p.parse_args();run(a.work,a.out,a.source,a.candidate)
