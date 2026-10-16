from __future__ import annotations

import argparse, json, statistics, sys, zipfile, csv, io
from pathlib import Path

from v5_phase2.ingestion import _sha256


def load(path: Path):
    with zipfile.ZipFile(path) as z:
        name = [n for n in z.namelist() if not n.endswith('/')][0]
        rows = []
        with z.open(name) as raw:
            for r in csv.reader(io.TextIOWrapper(raw)):
                if r and r[0].isdigit() and len(r) >= 7:
                    rows.append({'open_ms': int(r[0]), 'open': float(r[1]), 'high': float(r[2]), 'low': float(r[3]), 'close': float(r[4]), 'volume': float(r[5]), 'close_ms': int(r[6])})
        return rows


def atr_pct(bars):
    w = bars[-21:]; trs=[]
    for i in range(1,len(w)):
        p=w[i-1]['close']; b=w[i]; trs.append(max(b['high']-b['low'], abs(b['high']-p), abs(b['low']-p)))
    return sum(trs)/len(trs)/w[-1]['close'] if trs else 0.0


def trend_side(ctx):
    scores=[]
    for count, weight in ((12,.5),(24,.3),(48,.2)):
        w=ctx[-count:]; move=w[-1]['close']/w[0]['open']-1; ref=max(.0005,atr_pct(w)); scores.append(max(-1,min(1,move/ref))*weight)
    c=round(sum(scores),4)
    return 'UP' if c >= .15 else 'DOWN' if c <= -.15 else 'SIDEWAYS'


def baseline(ctx, kind):
    if kind == 'naive': return 'SIDEWAYS'
    ret=ctx[-1]['close']/ctx[-5]['close']-1
    return 'UP' if ret > 0 else 'DOWN' if ret < 0 else 'SIDEWAYS'


def label(move, threshold):
    return 'UP' if move > threshold else 'DOWN' if move < -threshold else 'SIDEWAYS'


def metrics(rows):
    labels=('UP','DOWN','SIDEWAYS'); n=len(rows)
    cm={a:{b:0 for b in labels} for a in labels}
    for r in rows: cm[r['actual']][r['predicted']]+=1
    recalls=[]
    for a in labels:
        total=sum(cm[a].values()); recalls.append(cm[a][a]/total if total else None)
    vals=[x for x in recalls if x is not None]
    return {'predictions':n,'accuracy':sum(r['actual']==r['predicted'] for r in rows)/n if n else None,'balanced_accuracy':sum(vals)/len(vals) if vals else None,'coverage':sum(r['predicted']!='SIDEWAYS' for r in rows)/n if n else None,'confusion':cm}


def main(root: str, out: str):
    base=Path(root); symbols=('BTCUSDT','ETHUSDT','SOLUSDT'); models=('naive','persistence','autofinx_existing'); horizons={'1h':1,'4h':4,'24h':24}; rows=[]
    for s in symbols:
        bars=[]
        for m in ('10','11','12'): bars += load(base/f'{s}-1h-2025-{m}.zip')
        bars.sort(key=lambda x:x['open_ms'])
        for i in range(48, len(bars)-24, 4):
            decision=bars[i]['close_ms'];
            split='development' if decision < 1763251200000 else 'validation' if decision < 1764547200000 else 'test'
            ctx=bars[:i+1]
            for horizon, step in horizons.items():
                if i+step >= len(bars): continue
                move=bars[i+step]['close']/bars[i]['close']-1; threshold=max(.0012, .5*atr_pct(ctx))
                actual=label(move,threshold)
                sides={'naive':baseline(ctx,'naive'),'persistence':baseline(ctx,'persistence'),'autofinx_existing':trend_side(ctx)}
                for model in models: rows.append({'symbol':s,'decision_ms':decision,'horizon':horizon,'split':split,'model':model,'predicted':sides[model],'actual':actual,'move':move,'threshold':threshold,'model_version':'v5-phase3-source-replay-1','calibrated':False})
    summary={}
    for model in models:
        for horizon in horizons:
            for split in ('development','validation','test'):
                subset=[r for r in rows if r['model']==model and r['horizon']==horizon and r['split']==split]
                summary[f'{model}:{horizon}:{split}']=metrics(subset)
    payload={'schema':'autofinx.v5.direction-validation.v1','symbols':symbols,'source_root':str(base),'horizons':list(horizons),'models':models,'rows':rows,'summary':summary,'chronos2':'not_run_in_this_offline_replay','probabilities':'unavailable','execution_authorized':False}
    Path(out).write_text(json.dumps(payload,indent=2),encoding='utf-8')
    print(json.dumps(summary,indent=2))

if __name__=='__main__':
    p=argparse.ArgumentParser(); p.add_argument('--root',required=True); p.add_argument('--out',required=True); a=p.parse_args(); main(a.root,a.out)
