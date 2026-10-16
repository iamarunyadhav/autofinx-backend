from __future__ import annotations
import argparse,csv,io,json,zipfile
from pathlib import Path
import numpy as np
from sklearn.linear_model import LogisticRegression
from sklearn.ensemble import RandomForestClassifier, HistGradientBoostingClassifier
from sklearn.metrics import accuracy_score,balanced_accuracy_score,confusion_matrix,precision_recall_fscore_support

FEATURES=['ret4','atr','body','range','slope']
def load(p):
 with zipfile.ZipFile(p) as z:
  n=[x for x in z.namelist() if not x.endswith('/')][0]
  with z.open(n) as raw:
   return [{'open_ms':int(r[0]),'open':float(r[1]),'high':float(r[2]),'low':float(r[3]),'close':float(r[4])} for r in csv.reader(io.TextIOWrapper(raw)) if r and r[0].isdigit()]
def feat(b):
 c=np.array([x['close'] for x in b[-48:]],float); last=c[-1]; tr=[]
 for i in range(1,len(b[-21:])):
  w=b[-21:]; p=w[i-1]['close']; x=w[i]; tr.append(max(x['high']-x['low'],abs(x['high']-p),abs(x['low']-p)))
 return [last/c[-5]-1,sum(tr)/len(tr)/last,(b[-1]['close']-b[-1]['open'])/last,(b[-1]['high']-b[-1]['low'])/last,(c[-1]-c.mean())/last]
def run(root,out):
 syms=['BTCUSDT','ETHUSDT','SOLUSDT']; horizons={'1h':1,'4h':4,'24h':24}; rows=[]
 for s in syms:
  bars=[]
  for m in ('10','11','12'): bars+=load(Path(root)/f'{s}-1h-2025-{m}.zip')
  bars.sort(key=lambda x:x['open_ms'])
  for i in range(48,len(bars)-24,4):
   x=feat(bars[:i+1]); threshold=max(.0012, .5*abs(x[1]))
   for h,step in horizons.items():
    mv=bars[i+step]['close']/bars[i]['close']-1; y=2 if mv>threshold else 0 if mv < -threshold else 1
    rows.append((s,bars[i]['open_ms'],h,x,y))
 results={}
 for h in horizons:
  data=[r for r in rows if r[2]==h]; data.sort(key=lambda r:r[1]); cut=int(len(data)*.8); train,test=data[:cut],data[cut:]
  X=np.array([r[3] for r in train]); y=np.array([r[4] for r in train]); Xt=np.array([r[3] for r in test]); yt=np.array([r[4] for r in test])
  models={'logistic':LogisticRegression(max_iter=1000,class_weight='balanced'),'random_forest':RandomForestClassifier(n_estimators=150,random_state=20261016,class_weight='balanced_subsample',n_jobs=1),'hist_gradient_boosting':HistGradientBoostingClassifier(max_iter=150,random_state=20261016)}
  for name,m in models.items():
   m.fit(X,y); pred=m.predict(Xt); probs=m.predict_proba(Xt) if hasattr(m,'predict_proba') else None
   pr,rc,_,_=precision_recall_fscore_support(yt,pred,labels=[2,0,1],zero_division=0)
   results[f'{name}:{h}']={'train':len(train),'test':len(test),'accuracy':accuracy_score(yt,pred),'balanced_accuracy':balanced_accuracy_score(yt,pred),'confusion':confusion_matrix(yt,pred,labels=[2,0,1]).tolist(),'precision_up_down_sideways':pr.tolist(),'recall_up_down_sideways':rc.tolist(),'probabilities_available':probs is not None}
 Path(out).write_text(json.dumps({'models':list(results),'features':FEATURES,'results':results,'execution_authorized':False},indent=2),encoding='utf-8'); print(json.dumps(results,indent=2))
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',required=True);p.add_argument('--out',required=True);a=p.parse_args();run(a.root,a.out)
