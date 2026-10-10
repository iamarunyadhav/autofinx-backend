import numpy as np
from v4 import strategy_v3 as v3

def test_donchian_signals_use_previous_channel():
    n=80; c=np.ones(n)*100; c[-1]=110
    b={"open":c,"high":c+1,"low":c-1,"close":c,"volume":np.ones(n),"open_time":np.arange(n)*300000}
    x=v3.compute(b)
    assert x["upper"].shape==(n,) and not bool(x["setup_long"][0])

