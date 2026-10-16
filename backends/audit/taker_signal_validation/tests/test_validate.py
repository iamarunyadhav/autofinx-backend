import sys, pathlib, zipfile, tempfile
import pandas as pd
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
from validate import load
def test_mapping_and_causality():
    root='D:/autofinx-v5-phase6-data'; d=load(root,'BTCUSDT')
    assert {'taker_ratio','taker_imbalance','trade_intensity','y'}.issubset(d.columns)
    assert (d.taker_ratio.between(-.5,.5)).all()
    assert d.ts.is_monotonic_increasing and d.ts.is_unique
def test_label_is_forward():
    d=load('D:/autofinx-v5-phase6-data','BTCUSDT'); i=100
    assert abs(d.fwd.iloc[i] - (d.close.iloc[i+24]/d.close.iloc[i]-1)) < 1e-12
