import json
from collections import Counter
from pathlib import Path

def main(src, out):
    rows=[json.loads(x) for x in Path(src).read_text().splitlines()]; result={}
    for model in sorted({r['model_name'] for r in rows}):
      for horizon in ('1h','4h','24h'):
        rs=[r for r in rows if r['model_name']==model and r['horizon']==horizon]
        errors=Counter('false_up' if r['predicted_direction']=='UP' and r['actual_direction']!='UP' else 'false_down' if r['predicted_direction']=='DOWN' and r['actual_direction']!='DOWN' else 'sideways_prediction' if r['predicted_direction']=='SIDEWAYS' else 'correct' for r in rs)
        by_symbol={}
        for s in sorted({r['symbol'] for r in rs}):
            q=[r for r in rs if r['symbol']==s]; by_symbol[s]={'n':len(q),'accuracy':sum(x['predicted_direction']==x['actual_direction'] for x in q)/len(q),'false_up':sum(x['predicted_direction']=='UP' and x['actual_direction']!='UP' for x in q),'false_down':sum(x['predicted_direction']=='DOWN' and x['actual_direction']!='DOWN' for x in q)}
        result[f'{model}:{horizon}']={'n':len(rs),'errors':dict(errors),'by_symbol':by_symbol}
    Path(out).write_text(json.dumps({'rows':len(rows),'analysis':result,'execution_authorized':False},indent=2)); print(len(rows),len(result))
if __name__=='__main__':
    import argparse; p=argparse.ArgumentParser(); p.add_argument('--src',required=True); p.add_argument('--out',required=True); a=p.parse_args(); main(a.src,a.out)
