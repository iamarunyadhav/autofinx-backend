"""Edge Search v2: streaming aggregation of Binance aggTrades into closed 5-minute order-flow buckets.

Plan: docs/ml-engine-v1/EDGE_SEARCH_V2_PILOT.md section 2.  Pure numpy; batches of trades are fed in file order and the
state (per-bucket accumulators + the carry needed across batch boundaries) lives in FlowAggregator.  Nothing here knows
about the network or the disk.
"""

from __future__ import annotations

import numpy as np

M5 = 300_000
N_BINS = 80
EDGES = np.logspace(-4, 4, N_BINS + 1)          # base-size bin edges, identical for every coin


class FlowAggregator:
    """Accumulates trades into [t0, t1) 5-minute buckets. Trades must arrive in file (agg id) order."""

    def __init__(self, t0_ms: int, t1_ms: int):
        assert (t1_ms - t0_ms) % M5 == 0 and t1_ms > t0_ms
        self.t0, self.t1 = int(t0_ms), int(t1_ms)
        self.nb = (self.t1 - self.t0) // M5
        z = lambda: np.zeros(self.nb)
        self.n_buy, self.n_sell = z(), z()
        self.qty_buy, self.qty_sell = z(), z()
        self.qv_buy, self.qv_sell = z(), z()
        self.s1, self.s2, self.s3, self.q_max = z(), z(), z(), z()
        self.p_first, self.p_last = np.full(self.nb, np.nan), np.full(self.nb, np.nan)
        self.p_high, self.p_low = np.full(self.nb, np.nan), np.full(self.nb, np.nan)
        self.rv = z()
        self.gap_missing = z()                                  # missing agg ids attributed to the bucket after the gap
        self._bad = np.zeros(self.nb + 1, np.int64)             # difference array: buckets touched by an id gap
        self.h_buy = np.zeros((self.nb, N_BINS))                # quote volume per size bin, buy side
        self.h_sell = np.zeros((self.nb, N_BINS))
        self.h_cnt = np.zeros((self.nb, N_BINS))
        self.rows = 0
        self.out_of_range = 0
        self.non_monotone_ts = 0
        self.first_ts = None
        self.last_ts = None
        self._last_id = None
        self._last_bucket = None
        self._pend_sec = None                                   # not yet finalised (sec, px)
        self._fin_px = None                                     # last finalised per-second price

    # ------------------------------------------------------------------ batch
    def add(self, agg_id, ts, price, qty, is_sell) -> None:
        agg_id = np.asarray(agg_id, np.int64)
        ts = np.asarray(ts, np.int64)
        price = np.asarray(price, float)
        qty = np.asarray(qty, float)
        is_sell = np.asarray(is_sell, bool)
        if len(ts) == 0:
            return
        inr = (ts >= self.t0) & (ts < self.t1)
        if not inr.all():
            self.out_of_range += int((~inr).sum())
            agg_id, ts, price, qty, is_sell = agg_id[inr], ts[inr], price[inr], qty[inr], is_sell[inr]
            if len(ts) == 0:
                return
        self.rows += len(ts)
        if self.first_ts is None:
            self.first_ts = int(ts[0])
        if (np.diff(ts) < 0).any() or (self.last_ts is not None and ts[0] < self.last_ts):
            self.non_monotone_ts += 1
        self.last_ts = int(max(ts[-1], self.last_ts or 0))
        b = ((ts - self.t0) // M5).astype(np.int64)
        qv = price * qty
        nb = self.nb
        buy = ~is_sell
        bc = lambda m, w=None: np.bincount(b[m], weights=None if w is None else w[m], minlength=nb)
        self.n_buy += bc(buy)
        self.n_sell += bc(is_sell)
        self.qty_buy += bc(buy, qty)
        self.qty_sell += bc(is_sell, qty)
        self.qv_buy += bc(buy, qv)
        self.qv_sell += bc(is_sell, qv)
        allm = np.ones(len(b), bool)
        self.s1 += bc(allm, qty)
        self.s2 += bc(allm, qty * qty)
        self.s3 += bc(allm, qty ** 3)
        # size histogram (flattened bincount)
        k = np.clip(np.searchsorted(EDGES, qty, side="right") - 1, 0, N_BINS - 1)
        flat = b * N_BINS + k
        size = nb * N_BINS
        self.h_buy += np.bincount(flat[buy], weights=qv[buy], minlength=size).reshape(nb, N_BINS)
        self.h_sell += np.bincount(flat[is_sell], weights=qv[is_sell], minlength=size).reshape(nb, N_BINS)
        self.h_cnt += np.bincount(flat, minlength=size).reshape(nb, N_BINS)
        # per-bucket first / last / high / low / max size (file order = id order within a bucket)
        order = np.arange(len(b)) if (np.diff(b) >= 0).all() else np.argsort(b, kind="stable")
        bs, ps, qs = b[order], price[order], qty[order]
        starts = np.r_[0, np.nonzero(np.diff(bs))[0] + 1]
        ub = bs[starts]
        ends = np.r_[starts[1:], len(bs)] - 1
        first_p, last_p = ps[starts], ps[ends]
        hi, lo = np.maximum.reduceat(ps, starts), np.minimum.reduceat(ps, starts)
        qmx = np.maximum.reduceat(qs, starts)
        newb = np.isnan(self.p_first[ub])
        self.p_first[ub[newb]] = first_p[newb]
        self.p_last[ub] = last_p
        self.p_high[ub] = np.where(np.isnan(self.p_high[ub]), hi, np.maximum(self.p_high[ub], hi))
        self.p_low[ub] = np.where(np.isnan(self.p_low[ub]), lo, np.minimum(self.p_low[ub], lo))
        self.q_max[ub] = np.maximum(self.q_max[ub], qmx)
        self._ids(agg_id, b)
        self._rv(ts, price)

    def _ids(self, agg_id, b) -> None:
        prev_id = np.r_[self._last_id if self._last_id is not None else agg_id[0] - 1, agg_id[:-1]]
        prev_b = np.r_[self._last_bucket if self._last_bucket is not None else b[0], b[:-1]]
        miss = agg_id - prev_id - 1
        for i in np.nonzero(miss != 0)[0]:
            self.gap_missing[b[i]] += abs(float(miss[i]))
            lo_b, hi_b = sorted((int(prev_b[i]), int(b[i])))
            self._bad[lo_b] += 1
            self._bad[hi_b + 1] -= 1
        self._last_id, self._last_bucket = int(agg_id[-1]), int(b[-1])

    def _rv(self, ts, price) -> None:
        sec = ts // 1000
        last = np.r_[np.nonzero(np.diff(sec))[0], len(sec) - 1]          # last trade of every second in this batch
        s_u, p_u = sec[last], price[last]
        if self._pend_sec is not None and s_u[0] != self._pend_sec[0]:
            s_u, p_u = np.r_[self._pend_sec[0], s_u], np.r_[self._pend_sec[1], p_u]
        # (same second as the pending one: the new batch value is later in the file and wins)
        self._pend_sec = (int(s_u[-1]), float(p_u[-1]))
        s_f, p_f = s_u[:-1], p_u[:-1]                                   # finalised seconds
        if len(s_f):
            self._take_returns(s_f, p_f)

    def _take_returns(self, s_f, p_f) -> None:
        base = np.r_[self._fin_px if self._fin_px is not None else p_f[0], p_f]
        r = np.diff(np.log(base))
        bk = ((s_f * 1000 - self.t0) // M5).astype(np.int64)
        ok = (bk >= 0) & (bk < self.nb)
        self.rv += np.bincount(bk[ok], weights=(r * r)[ok], minlength=self.nb)
        self._fin_px = float(p_f[-1])

    # ------------------------------------------------------------------ result
    def finish(self) -> dict:
        if self._pend_sec is not None:
            self._take_returns(np.array([self._pend_sec[0]]), np.array([self._pend_sec[1]]))
            self._pend_sec = None
        bad = np.cumsum(self._bad)[: self.nb] > 0
        covered = (self.first_ts is not None and self.first_ts < self.t0 + M5 and self.last_ts >= self.t1 - M5)
        scalars = {
            "bucket_open_ms": self.t0 + np.arange(self.nb, dtype=np.int64) * M5,
            "n_buy": self.n_buy, "n_sell": self.n_sell, "qty_buy": self.qty_buy, "qty_sell": self.qty_sell,
            "qv_buy": self.qv_buy, "qv_sell": self.qv_sell, "s1": self.s1, "s2": self.s2, "s3": self.s3, "q_max": self.q_max,
            "p_first": self.p_first, "p_last": self.p_last, "p_high": self.p_high, "p_low": self.p_low,
            "rv": self.rv, "id_gap": self.gap_missing, "gap_touched": bad.astype(np.int8),
            "complete": (~bad & covered).astype(np.int8),
        }
        hist = {"h_buy": self.h_buy.astype(np.float32), "h_sell": self.h_sell.astype(np.float32),
                "h_cnt": self.h_cnt.astype(np.int32)}
        info = {"rows": self.rows, "out_of_range": self.out_of_range, "non_monotone_ts": self.non_monotone_ts,
                "covered": bool(covered), "id_missing_total": float(self.gap_missing.sum()),
                "first_ts": self.first_ts, "last_ts": self.last_ts, "buckets": self.nb}
        return {"scalars": scalars, "hist": hist, "info": info}
