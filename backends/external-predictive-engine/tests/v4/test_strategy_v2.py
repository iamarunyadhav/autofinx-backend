import numpy as np
from v4 import strategy_v2 as v2

def test_ut_cross_is_causal_and_has_no_future_signal():
    c=np.array([100,101,102,99,98,103.],float)
    atr=np.ones(len(c))
    stop,buy,sell=v2._ut(c,atr,1.0)
    assert len(stop)==len(c)
    late=c.copy(); late[-1]=1000
    stop2,buy2,sell2=v2._ut(late,atr,1.0)
    assert np.array_equal(buy[:4],buy2[:4]) and np.array_equal(sell[:4],sell2[:4])

def test_compute_exposes_research_setup():
    n=100; c=np.linspace(100,110,n); b={"open":c,"high":c+1,"low":c-1,"close":c,"volume":np.ones(n),"open_time":np.arange(n)*300000}
    x=v2.compute(b,b,"BTCUSDT")
    assert x["setup_long"].shape==(n,) and x["setup_short"].shape==(n,)
