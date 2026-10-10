"""Multi-timeframe context + arm decisions (plan sections 3.5-3.7). 4H / 1H / 15M structure runs independently per timeframe; a lower-timeframe bar only sees the
last COMPLETED higher-timeframe bar (close_time_hi <= close_time_lo)."""

from __future__ import annotations

import numpy as np

from regime_v1.features import htf_align

from . import params as P
from . import soft
from . import structure as S


class Brain:
    """Structure of every analysis timeframe for one coin (primary or sensitivity pivot parameters)."""

    def __init__(self, coin, sens: bool = False, tfs=P.TFS):
        self.coin, self.sens = coin, sens
        ks = P.SENS_K if sens else P.PIVOT_K
        self.k = {tf: ks[tf] for tf in tfs}
        self.bars = {tf: coin.bars[tf] for tf in tfs}
        self.st = {tf: S.analyze_tf(self.bars[tf], self.k[tf]) for tf in tfs}
        b = self.bars[P.SIGNAL_TF]
        self.b = b
        self.ct = b.close_time
        self.aligned = {}
        for tf in tfs:
            if tf == P.SIGNAL_TF:
                continue
            self.aligned[tf] = htf_align(b.close_time, self.bars[tf].close_time, self.st[tf]["trend"], fill=P.UNCERTAIN)
        self._soft()

    # ---------------------------------------------------------------------------------- soft confirmations on the signal timeframe
    def _soft(self):
        b, st = self.b, self.st[P.SIGNAL_TF]
        self.vol_ratio = soft.volume_ratio(b.v)
        self.vol_exp = soft.volume_expansion(b.v)
        self.vola_rank = soft.atr_pct_rank(b.atr, b.c)
        self.vola_ok = soft.volatility_ok(b.atr, b.c)
        self.ema_ctx = soft.ema_context(b.c)
        self.rsi = soft.rsi14(b.c)
        lvl = np.where(st["bos"] > 0, st["H"], st["L"])
        self.breakout_strength = soft.breakout_strength(b.c, lvl, b.atr)
        self.retest = soft.retest_flag(st["bos"], lvl, b.h, b.l, b.c, b.atr)

    # ---------------------------------------------------------------------------------- arm decisions
    def decisions(self, arm: str) -> dict:
        """Per 15m bar: side (+1 BUY / -1 SELL / 0 WAIT), wait reason, structural stop price, and the structure event that triggered it."""
        cfg = P.ARMS[arm]
        if cfg["sens"] != self.sens:
            raise ValueError(f"arm {arm} needs sens={cfg['sens']}")
        st = self.st[P.SIGNAL_TF]
        ev_up = (st["bos"] == 1) | (st["ch_conf"] == 1)
        ev_dn = (st["bos"] == -1) | (st["ch_conf"] == -1)
        n = len(self.ct)
        side = np.zeros(n, np.int8)
        wait = np.full(n, P.W_NO_EVENT, np.int8)
        ev_any = (ev_up ^ ev_dn)
        s0 = np.where(ev_up & ~ev_dn, 1, np.where(ev_dn & ~ev_up, -1, 0)).astype(np.int8)
        ok = np.ones(n, bool)
        blocks = np.zeros(n, np.int8)
        if cfg["htf"]:
            t1, t4 = self.aligned["1h"], self.aligned["4h"]
            htf_ok = np.where(s0 > 0, (t1 == P.BULLISH) & (t4 != P.BEARISH), (t1 == P.BEARISH) & (t4 != P.BULLISH))
            ok &= htf_ok
            blocks += (~htf_ok).astype(np.int8)
        if cfg["vol"]:
            ok &= self.vol_exp
            blocks += (~self.vol_exp).astype(np.int8)
        if cfg["vola"]:
            ok &= self.vola_ok
            blocks += (~self.vola_ok).astype(np.int8)
        fire = ev_any & ok
        side[fire] = s0[fire]
        wait[fire] = P.W_NONE
        blocked = ev_any & ~ok
        wait[blocked & (blocks > 1)] = P.W_BOTH
        if cfg["htf"]:
            t1, t4 = self.aligned["1h"], self.aligned["4h"]
            htf_ok = np.where(s0 > 0, (t1 == P.BULLISH) & (t4 != P.BEARISH), (t1 == P.BEARISH) & (t4 != P.BULLISH))
            wait[blocked & (blocks == 1) & ~htf_ok] = P.W_HTF
        if cfg["vol"]:
            wait[blocked & (blocks == 1) & ~self.vol_exp] = P.W_VOL
        if cfg["vola"]:
            wait[blocked & (blocks == 1) & ~self.vola_ok] = P.W_VOLA
        stop_px = np.where(side > 0, st["L"], np.where(side < 0, st["H"], np.nan))
        trig = np.where(side != 0, np.where(st["bos"] != 0, 1, 2), 0).astype(np.int8)       # 1 = BOS, 2 = confirmed CHoCH
        return {"side": side, "wait": wait, "stop_px": stop_px, "trigger": trig}

    # ---------------------------------------------------------------------------------- as-of lookups
    def idx_at(self, tf: str, ts_ms: int) -> int:
        """Index of the last bar of `tf` that is COMPLETE (close_time <= ts); -1 if none."""
        return int(np.searchsorted(self.bars[tf].close_time, ts_ms, side="right")) - 1
