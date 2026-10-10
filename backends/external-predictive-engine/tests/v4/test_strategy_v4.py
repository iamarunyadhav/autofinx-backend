import numpy as np
from v4 import strategy_v4 as v4

def test_filtered_adapter_has_causal_shapes():
    n=150; c=np.linspace(100,120,n)
    b={"open":c,"high":c+1,"low":c-1,"close":c,"volume":np.ones(n),"open_time":np.arange(n)*300000}
    x=v4.compute(b)
    assert x["setup_long"].shape==(n,) and x["setup_short"].shape==(n,)
