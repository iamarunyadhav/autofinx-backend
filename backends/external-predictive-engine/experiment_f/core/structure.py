"""Causal market-structure engine for ONE timeframe (plan section 3).

Every output array has one entry per bar and the value at index t is computed from bars <= t only (prefix invariance is unit-tested on synthetic and real
data). A swing (fractal) pivot at bar j with parameter k is OCCURRENCE time j and CONFIRMATION time j + k; it is invisible before the close of bar j + k.

Definitions (all fixed, no tuning):
* swing high j: h[j] strictly greater than the highs of the k bars before and the k bars after; swing low mirrored.
* label of a confirmed swing high vs the previous confirmed swing high: HH (>), LH (<), EH (=); swing low: HL (>), LL (<), EL (=).
* trend_raw: BULLISH = latest high is HH and latest low is HL; BEARISH = LH and LL; SIDEWAYS = mixed / equal; UNCERTAIN = fewer than two confirmed highs or lows.
* reference levels H* / L* = the latest confirmed swing high / low. BOS is CLOSE-based: close[t] > H* (first close beyond a not-yet-broken H*) while trend_raw is
  BULLISH = BOS up (mirror for down). A wick (high > H*, close <= H*) is NOT a BOS (recorded as `wick`). The same close-break against the trend is a CHoCH
  candidate; with a SIDEWAYS / UNCERTAIN trend it is a RANGE break (`rb`).
* CHoCH states: UNCONFIRMED = a wick breached the opposite reference level against the trend (close stayed inside); POTENTIAL = the first close beyond it, counting
  consecutive closes; CONFIRMED = CHOCH_CONFIRM_CLOSES consecutive closes beyond the level (a close back inside cancels it). While a CONFIRMED CHoCH is active against
  trend_raw the final `trend` is UNCERTAIN; it is cleared by the first swing confirmed after the confirmation.
"""

from __future__ import annotations

import numpy as np
from numpy.lib.stride_tricks import sliding_window_view as sw

from v4 import strategy_v13 as v13

from . import params as P


# ------------------------------------------------------------------------------------------ swings
def find_swings(h, l, k: int) -> dict:
    """All fractal swings (kind +1 high / -1 low) sorted by confirmation index. occ = occurrence bar j, conf = j + k, label vs the previous swing of the same kind."""
    h, l = np.asarray(h, float), np.asarray(l, float)
    n = len(h)
    z = np.zeros(0, np.int64)
    if n < 2 * k + 1:
        return {"kind": z.astype(np.int8), "occ": z, "conf": z, "price": np.zeros(0), "label": z.astype(np.int8)}
    Wh, Wl = sw(h, k), sw(l, k)
    j = np.arange(k, n - k)
    with np.errstate(invalid="ignore"):
        ph = (h[j] > Wh[j - k].max(axis=1)) & (h[j] > Wh[j + 1].max(axis=1))
        pl = (l[j] < Wl[j - k].min(axis=1)) & (l[j] < Wl[j + 1].min(axis=1))
    jh, jl = j[ph], j[pl]
    occ = np.r_[jh, jl]
    kind = np.r_[np.ones(len(jh), np.int8), -np.ones(len(jl), np.int8)]
    price = np.r_[h[jh], l[jl]]
    order = np.lexsort((-kind, occ + k))            # by confirmation, highs first on ties
    occ, kind, price = occ[order], kind[order], price[order]
    label = np.zeros(len(occ), np.int8)
    for kd in (1, -1):
        m = np.nonzero(kind == kd)[0]
        if len(m) > 1:
            d = np.sign(np.diff(price[m]))
            label[m[1:]] = d.astype(np.int8)            # +1 = higher (HH / HL), -1 = lower (LH / LL), 0 = equal
    return {"kind": kind, "occ": occ.astype(np.int64), "conf": (occ + k).astype(np.int64), "price": price, "label": label}


def label_name(kind: int, lab: int) -> str:
    if kind > 0:
        return {1: "HH", -1: "LH", 0: "EH"}[int(lab)]
    return {1: "HL", -1: "LL", 0: "EL"}[int(lab)]


# ------------------------------------------------------------------------------------------ structure state machine
def run(o, h, l, c, k: int, atr=None) -> dict:
    """Per-bar structure state for one timeframe. Inputs are closed-bar arrays (NaN = missing / incomplete bar)."""
    o, h, l, c = (np.asarray(x, float) for x in (o, h, l, c))
    n = len(c)
    sws = find_swings(h, l, k)
    kinds, confs, prices, labels, occs = (sws[x].tolist() for x in ("kind", "conf", "price", "label", "occ"))
    m = len(kinds)
    ptr = 0
    out = {key: np.zeros(n, np.int8) for key in ("trend_raw", "trend", "bos", "wick_up", "wick_dn", "rb", "ch_state", "ch_dir", "ch_conf", "ch_pot", "lab_h", "lab_l")}
    out["trend"][:] = P.UNCERTAIN
    out["trend_raw"][:] = P.UNCERTAIN
    Hs = np.full(n, np.nan)
    Ls = np.full(n, np.nan)
    Ho = np.full(n, -1, np.int64)
    Lo = np.full(n, -1, np.int64)
    ch_n = np.zeros(n, np.int8)
    hs_prices: list = []
    ls_prices: list = []
    hs_labels: list = []
    ls_labels: list = []
    H = L = np.nan
    Hocc = Locc = -1
    Hbroken = Lbroken = False
    ch_dir, ch_level, ch_count = 0, np.nan, 0
    act_dir, act_since = 0, -1
    NC = P.CHOCH_CONFIRM_CLOSES
    for t in range(n):
        while ptr < m and confs[ptr] <= t:                  # swings confirmed at the close of bar t (confs == t exactly, ascending)
            if kinds[ptr] > 0:
                H, Hocc, Hbroken = prices[ptr], occs[ptr], False
                hs_prices.append(prices[ptr])
                hs_labels.append(labels[ptr])
            else:
                L, Locc, Lbroken = prices[ptr], occs[ptr], False
                ls_prices.append(prices[ptr])
                ls_labels.append(labels[ptr])
            if act_dir != 0 and confs[ptr] > act_since:
                act_dir = 0                                 # first swing confirmed after the CHoCH confirmation clears the override
            ptr += 1
        Hs[t], Ls[t], Ho[t], Lo[t] = H, L, Hocc, Locc
        nh, nl = len(hs_prices), len(ls_prices)
        if nh >= 2 and nl >= 2:
            lh_, ll_ = hs_labels[-1], ls_labels[-1]
            raw = P.BULLISH if (lh_ > 0 and ll_ > 0) else (P.BEARISH if (lh_ < 0 and ll_ < 0) else P.SIDEWAYS)
        else:
            raw = P.UNCERTAIN
        out["trend_raw"][t] = raw
        if nh:
            out["lab_h"][t] = hs_labels[-1] if nh >= 2 else 0
        if nl:
            out["lab_l"][t] = ls_labels[-1] if nl >= 2 else 0
        ct = c[t]
        if ct != ct or h[t] != h[t] or l[t] != l[t]:       # missing bar: no event logic, a CHoCH in progress is cancelled
            ch_dir, ch_count = 0, 0
            out["trend"][t] = raw
            continue
        ch_state = P.CH_NONE
        # a CHoCH already counting closes beyond its level
        if ch_dir != 0:
            if (ch_dir > 0 and ct > ch_level) or (ch_dir < 0 and ct < ch_level):
                ch_count += 1
                if ch_count >= NC:
                    out["ch_conf"][t] = ch_dir
                    act_dir, act_since = ch_dir, t
                    ch_state = P.CH_CONFIRMED
                    out["ch_dir"][t] = ch_dir
                    ch_dir, ch_count = 0, 0
                else:
                    ch_state = P.CH_POTENTIAL
                    out["ch_dir"][t] = ch_dir
            else:
                ch_dir, ch_count = 0, 0                     # closed back inside: the potential CHoCH failed
        # structure breaks against the reference levels
        if H == H and not Hbroken:
            if ct > H:
                Hbroken = True
                if raw == P.BULLISH:
                    out["bos"][t] = 1
                elif raw == P.BEARISH:
                    if ch_dir == 0:
                        ch_dir, ch_level, ch_count = 1, H, 1
                        out["ch_pot"][t] = 1
                        out["ch_dir"][t] = 1
                        if ch_state == P.CH_NONE:
                            ch_state = P.CH_POTENTIAL
                else:
                    out["rb"][t] = 1
            elif h[t] > H:
                out["wick_up"][t] = 1
                if raw == P.BEARISH and ch_state == P.CH_NONE:
                    ch_state = P.CH_UNCONFIRMED
                    out["ch_dir"][t] = 1
        if L == L and not Lbroken:
            if ct < L:
                Lbroken = True
                if raw == P.BEARISH:
                    out["bos"][t] = -1
                elif raw == P.BULLISH:
                    if ch_dir == 0:
                        ch_dir, ch_level, ch_count = -1, L, 1
                        out["ch_pot"][t] = -1
                        out["ch_dir"][t] = -1
                        if ch_state == P.CH_NONE:
                            ch_state = P.CH_POTENTIAL
                else:
                    out["rb"][t] = -1
            elif l[t] < L:
                out["wick_dn"][t] = 1
                if raw == P.BULLISH and ch_state == P.CH_NONE:
                    ch_state = P.CH_UNCONFIRMED
                    out["ch_dir"][t] = -1
        if ch_state == P.CH_NONE and act_dir != 0:
            ch_state = P.CH_CONFIRMED                        # still the active (not yet cleared) confirmed CHoCH
            out["ch_dir"][t] = act_dir
        out["ch_state"][t] = ch_state
        ch_n[t] = ch_count
        out["trend"][t] = P.UNCERTAIN if (act_dir != 0 and raw == -act_dir) else raw
    out["ch_count"] = ch_n
    out["H"], out["L"], out["H_occ"], out["L_occ"] = Hs, Ls, Ho, Lo
    out["swings"] = sws
    out["k"] = k
    return out


# ------------------------------------------------------------------------------------------ S/R zones
def build_zones(sws: dict, atr, h, l, c, max_age: int) -> dict:
    """Causal support / resistance zones from CONFIRMED swings only. A zone is born at the swing's confirmation bar; half-width = ZONE_HALF_ATR x ATR14 at that bar.
    Resistance (from swing highs) is BROKEN at the first close above its upper edge, support at the first close below its lower edge; it expires `max_age` bars after birth.
    `touch_idx` lists the start bar of every contact episode (bar range overlaps the zone; consecutive contact bars = one episode) AFTER birth, up to and including the
    breaking bar. Everything about a zone at time t uses indices <= t (`status_at`)."""
    h, l, c, atr = (np.asarray(x, float) for x in (h, l, c, atr))
    n = len(c)
    ns = len(sws["kind"])
    kind, born, occ, center, lo, hi = [], [], [], [], [], []
    broken, touches = [], []
    for s in range(ns):
        b = int(sws["conf"][s])
        a = atr[b] if b < n else np.nan
        if not np.isfinite(a) or a <= 0:
            continue
        p = float(sws["price"][s])
        w = P.ZONE_HALF_ATR * a
        zl, zh = p - w, p + w
        end = min(b + max_age, n - 1)
        if b + 1 > end:
            seg = slice(0, 0)
        else:
            seg = slice(b + 1, end + 1)
        hs, ls, cs = h[seg], l[seg], c[seg]
        with np.errstate(invalid="ignore"):
            contact = (hs >= zl) & (ls <= zh)
            brk = (cs > zh) if sws["kind"][s] > 0 else (cs < zl)
        bi = int(np.argmax(brk)) if len(brk) and brk.any() else -1
        lim = bi + 1 if bi >= 0 else len(contact)
        cc = contact[:lim]
        starts = np.nonzero(cc & ~np.r_[False, cc[:-1]])[0] if len(cc) else np.zeros(0, np.int64)
        kind.append(int(sws["kind"][s]))
        born.append(b)
        occ.append(int(sws["occ"][s]))
        center.append(p)
        lo.append(zl)
        hi.append(zh)
        broken.append(b + 1 + bi if bi >= 0 else -1)
        touches.append((starts + b + 1).astype(np.int64))
    return {"kind": np.array(kind, np.int8), "born": np.array(born, np.int64), "occ": np.array(occ, np.int64), "center": np.array(center), "lo": np.array(lo),
            "hi": np.array(hi), "broken_at": np.array(broken, np.int64), "touch_idx": touches, "max_age": max_age}


def zones_at(z: dict, t: int) -> list[dict]:
    """Zones known at the close of bar t with their status AS OF t (ACTIVE / BROKEN / EXPIRED) and the interaction count up to t. Never reads past t."""
    out = []
    if not len(z["born"]):
        return out
    i1 = int(np.searchsorted(z["born"], t, side="right"))
    i0 = int(np.searchsorted(z["born"], t - z["max_age"] - 1, side="left"))
    for i in range(i0, i1):
        b = int(z["born"][i])
        ba = int(z["broken_at"][i])
        status = "ACTIVE"
        if ba >= 0 and ba <= t:
            status = "BROKEN"
        elif t > b + z["max_age"]:
            status = "EXPIRED"
        ti = z["touch_idx"][i]
        out.append({"id": i, "kind": "RESISTANCE" if z["kind"][i] > 0 else "SUPPORT", "price": float(z["center"][i]), "lo": float(z["lo"][i]), "hi": float(z["hi"][i]),
                    "born": b, "swing_occ": int(z["occ"][i]), "status": status, "broken_at": ba if (ba >= 0 and ba <= t) else None,
                    "touches": int(np.searchsorted(ti, t, side="right"))})
    return out


def merge_zones(zs: list[dict]) -> list[dict]:
    """Display merge of overlapping same-kind ACTIVE zones (union range, summed touches, `swings` = number merged)."""
    out = []
    for kd in ("RESISTANCE", "SUPPORT"):
        a = sorted((z for z in zs if z["kind"] == kd and z["status"] == "ACTIVE"), key=lambda z: z["lo"])
        cur = None
        for z in a:
            if cur is not None and z["lo"] <= cur["hi"]:
                cur["hi"] = max(cur["hi"], z["hi"])
                cur["touches"] += z["touches"]
                cur["swings"] += 1
                cur["born"] = min(cur["born"], z["born"])
                cur["price"] = (cur["lo"] + cur["hi"]) / 2
            else:
                if cur:
                    out.append(cur)
                cur = dict(z)
                cur["swings"] = 1
        if cur:
            out.append(cur)
    return out


# ------------------------------------------------------------------------------------------ one timeframe bundle
def analyze_tf(bars, k: int) -> dict:
    """Structure + zones + soft-confirmation inputs for a Bars object."""
    st = run(bars.o, bars.h, bars.l, bars.c, k)
    atr = bars.atr
    st["zones"] = build_zones(st["swings"], atr, bars.h, bars.l, bars.c, P.ZONE_MAX_AGE[bars.tf])
    st["atr"] = atr
    return st

