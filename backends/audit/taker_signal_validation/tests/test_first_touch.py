import sys,pathlib,pandas as pd
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
from first_touch import touch
def w(rows): return pd.DataFrame(rows,index=pd.date_range('2025-01-01',periods=len(rows),freq='15min'))
def test_order_and_collision():
 assert touch(w([{'high':100.5,'low':99},{'high':103,'low':100}]),100,1,.02,.01)[0]=='SL_FIRST'
 assert touch(w([{'high':100.5,'low':99.6},{'high':103,'low':100}]),100,1,.02,.005)[0]=='TP_FIRST'
 assert touch(w([{'high':103,'low':99}]),100,1,.02,.01)[0]=='AMBIGUOUS'
 assert touch(w([{'high':100.2,'low':99.8}]),100,1,.02,.01)[0]=='TIMEOUT'
def test_short():
 assert touch(w([{'high':101,'low':99},{'high':97,'low':99}]),100,-1,.02,.01)[0]=='SL_FIRST'
