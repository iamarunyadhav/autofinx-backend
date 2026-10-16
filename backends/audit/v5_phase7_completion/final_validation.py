from collections import defaultdict
import random

def ece(rows,bins=10):
 data=[]
 for r in rows:
  p=r.get('predicted_class_probabilities')
  if p is None: continue
  i=max(range(len(p)),key=lambda j:p[j]); data.append((p[i],i==({'DOWN':0,'SIDEWAYS':1,'UP':2}[r['actual_direction']])))
 if not data:return None
 out=0.0
 for b in range(bins):
  x=[z for z in data if b/bins<=z[0]<(b+1)/bins or (b==bins-1 and z[0]==1)]
  if x: out += len(x)/len(data)*abs(sum(int(z[1]) for z in x)/len(x)-sum(z[0] for z in x)/len(x))
 return out

def block_ci(rows,n=1000):
 groups=defaultdict(list)
 for r in rows: groups[(r['symbol'],r['fold_id'],r['decision_timestamp']//86400000)].append(r)
 blocks=list(groups.values()); rng=random.Random(20261016); vals=[]
 for _ in range(n):
  flat=[rng.choice(blocks) for _ in blocks]; flat=[r for b in flat for r in b]
  vals.append(sum(r['predicted_direction']==r['actual_direction'] for r in flat)/len(flat))
 vals.sort(); return vals[int(.025*n)],vals[int(.975*n)]
