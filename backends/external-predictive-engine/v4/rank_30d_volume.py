"""Resumable Binance 30-closed-day USDT-M perp volume ranking."""
from __future__ import annotations
import argparse,json,time,urllib.error,urllib.request
from pathlib import Path
from datetime import datetime,timezone

BASE='https://fapi.binance.com'; SPEC=Path('D:/autofinx-ml-v4/reports/strategy-lab/part2_expansion_spec.json')
def get(url):
    req=urllib.request.Request(url,headers={'User-Agent':'AutoFinX-research/1.0'})
    with urllib.request.urlopen(req,timeout=20) as r:return r.status,r.headers, json.loads(r.read().decode())
def main(out):
    out.mkdir(parents=True,exist_ok=True); log=out/'ranking.log'; ck=out/'symbols.json'
    def lg(s): log.open('a',encoding='utf8').write(s+'\n'); print(s,flush=True)
    _,_,info=get(BASE+'/fapi/v1/exchangeInfo'); now=int(time.time()*1000); cut=now-2*365*86400000
    stable={'USDC','USDT','BUSD','DAI','TUSD','FDUSD','USDE','USD1'}; excluded={'BTCUSDT','ETHUSDT','BNBUSDT','SOLUSDT','XRPUSDT','SUIUSDT'}
    syms=[s for s in info['symbols'] if s['contractType']=='PERPETUAL' and s['quoteAsset']=='USDT' and s['status']=='TRADING']
    done=json.loads(ck.read_text()) if ck.exists() else {}; first_failure=None
    for s in syms:
        sym=s['symbol'];
        if sym in done: continue
        if sym in excluded or s['baseAsset'] in stable: done[sym]={'status':'excluded','reason':'existing-six-or-stablecoin'}; continue
        if int(s.get('onboardDate',0))>cut: done[sym]={'status':'excluded','reason':'listed-less-than-two-years'}; continue
        url=f'{BASE}/fapi/v1/klines?symbol={sym}&interval=1d&limit=31'; ok=None
        for attempt,wait in enumerate((0,2,4,8,16,32)):
            if wait: time.sleep(wait)
            try:
                status,headers,data=get(url); closed=data[-30:]
                if len(closed)<30: raise ValueError(f'only {len(closed)} daily candles')
                ts=[int(x[0]) for x in closed]
                if len(set(ts))!=30: raise ValueError('duplicate daily timestamps')
                ok={'status':'ok','sum_quote_volume':sum(float(x[7]) for x in closed),'closed_count':30,'last_open_time':ts[-1]}; lg(f'{sym} ok sum={ok["sum_quote_volume"]:.2f}'); break
            except urllib.error.HTTPError as e:
                text=e.read().decode(errors='replace')[:500]; first_failure=first_failure or {'symbol':sym,'http_code':e.code,'error':text}; lg(f'{sym} retry http={e.code} error={text}')
                if attempt==5: ok={'status':'failed','http_code':e.code,'error':text}
            except Exception as e:
                first_failure=first_failure or {'symbol':sym,'http_code':None,'error':str(e)}; lg(f'{sym} retry error={e}')
                if attempt==5: ok={'status':'failed','http_code':None,'error':str(e)}
        done[sym]=ok; ck.write_text(json.dumps(done,indent=2)); time.sleep(.25)
    ck.write_text(json.dumps(done,indent=2)); valid=[(s,v) for s,v in done.items() if v.get('status')=='ok']
    failed=[(s,v) for s,v in done.items() if v.get('status')=='failed']
    report={'ranking_timestamp_utc':datetime.now(timezone.utc).isoformat(),'eligible_count':len(syms),'valid_count':len(valid),'failed':dict(failed),'first_failure':first_failure,'all_sums':{s:v['sum_quote_volume'] for s,v in valid}}
    if failed: report['freeze_status']='REFUSED_INCOMPLETE'
    else:
        final=[s for s,_ in sorted(valid,key=lambda z:z[1]['sum_quote_volume'],reverse=True) if s not in excluded and s not in stable][:14]
        report['final_symbols']=final; report['freeze_status']='FROZEN'
    (out/'snapshot.json').write_text(json.dumps(report,indent=2)); print(json.dumps(report,indent=2))
if __name__=='__main__':
    p=argparse.ArgumentParser(); p.add_argument('--out',type=Path,required=True); main(p.parse_args().out)
