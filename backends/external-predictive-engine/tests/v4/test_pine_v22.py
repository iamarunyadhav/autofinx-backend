import numpy as np
from v4 import pine_v22 as p

def test_ema_seed():
    np.testing.assert_allclose(p.ema(np.array([10.,20.,30.]),3),[10,15,22.5])

def fixture():
    t=np.arange(288*230)*300000;c=100+np.arange(len(t))*.001
    return dict(open_time=t,open=c,high=c+1,low=c-1,close=c,volume=np.ones(len(t))*100)

def test_default_daily_htf_blocks_daily():
    base=fixture();b=p.ind.resample(base,p.DAY)
    assert not np.any(p.compute(b,base,p.DAY)['side'])

def test_historical_htf_offset_and_causality():
    base=fixture();b=p.ind.resample(base,14400000);x=p.compute(b,base,14400000)
    assert x['ht'][5]==0 and x['ht'][11]==0 and x['ht'][17]==1
    for k in ['high','low','close']:base[k][288*200:]*=2
    y=p.compute(p.ind.resample(base,14400000),base,14400000)
    for k in x:np.testing.assert_allclose(x[k][:6*200],y[k][:6*200],equal_nan=True)

def test_sizing_fees_and_failed_exit():
    n=6;b=dict(open_time=np.arange(n)*900000,close=np.array([101,99,98,98,98,98.]))
    x=dict(side=np.array([1,0,0,0,0,0]),atr=np.ones(n)*10,upper=np.ones(n)*100,lower=np.ones(n)*90,exit_lo=np.ones(n)*80,exit_hi=np.ones(n)*120)
    g=dict(start=0,open=np.ones(90)*101,high=np.ones(90)*102,low=np.ones(90)*97);g['open'][30]=98
    f=dict(funding_time=np.array([1200000]),funding_rate=np.array([.001]))
    rows,d=p.simulate(b,g,f,x,900000);r=rows[0]
    assert r['size']==5 and r['reason']=='FAILED_BREAKOUT' and r['exit_time']==1800000
    assert abs(r['fees']-5*(101+98)*.0006)<1e-9
    assert abs(r['actual_funding_entry_notional']-.505)<1e-9
