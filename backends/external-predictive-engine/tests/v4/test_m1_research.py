import numpy as np
from v4 import m1_research as m

def test_gap_stops():
    assert m.stop_fill(1,95,90,94,89)==90
    assert m.stop_fill(-1,105,110,112,109)==110
    assert m.stop_fill(1,95,100,101,98) is None

def test_future_candles_do_not_change_signals():
    rng=np.random.default_rng(17); c=100+np.cumsum(rng.normal(size=200))
    b=dict(high=c+2,low=c-2,close=c,volume=np.ones(200)*100)
    original=m.features(b)
    for key in b:b[key][120:]*=10
    mutated=m.features(b)
    for key in original:np.testing.assert_allclose(original[key][:120],mutated[key][:120],equal_nan=True)

def test_failed_break_next_open_and_actual_funding(monkeypatch):
    n=6; b=dict(open_time=np.arange(n)*900000,close=np.array([101,99,98,98,98,98.]),high=np.ones(n)*102,low=np.ones(n)*97,volume=np.ones(n))
    monkeypatch.setattr(m,'features',lambda b:dict(atr=np.ones(n)*10,up=np.ones(n)*100,lo=np.ones(n)*90,side=np.array([1,0,0,0,0,0]),exit_lo=np.ones(n)*80,exit_hi=np.ones(n)*120))
    g=dict(start=0,open=np.ones(90)*101,high=np.ones(90)*102,low=np.ones(90)*97)
    g['open'][30]=98
    f=dict(funding_time=np.array([900000,1200000]),funding_rate=np.array([.01,.001]))
    tr,signals,q,op=m.simulate(b,g,f,900000)
    assert len(tr)==1 and tr[0]['exit_reason']=='FAILED_BREAKOUT'
    assert tr[0]['exit_time']==1800000 and tr[0]['exit_price']==98
    assert tr[0]['funding_pct']==.1
    assert signals[0]['reversed_within_3_bars']
    assert not q and op is None
