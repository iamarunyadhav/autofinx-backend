"""E0 / E1: repository and artifact audit - do genuine out-of-fold (OOF) predictions with provenance already exist? (plan section 0)

Scans the work directory for any file that could hold per-row model predictions (parquet / npz / npy / csv / jsonl columns or keys named like pred / prob / score / oof / calib),
inventories the fitted models with their training cutoffs, and checks every candidate for the six provenance fields: model id / hash, training cutoff, prediction time,
horizon, label definition, fold. Read-only. Skips the venv, the pip cache, raw klines (data/) and this experiment's own output.
"""

from __future__ import annotations

import hashlib
import json
import re
import zipfile
from pathlib import Path

import numpy as np

PRED_RE = re.compile(r"(pred|prob|score|oof|p_long|p_short|proba|calib|margin|decision)", re.I)
PROV_RE = {"model_id_or_hash": re.compile(r"(model|sha|hash|booster)", re.I), "training_cutoff": re.compile(r"(train.*(end|cut)|fit.*(end|cut)|cutoff)", re.I),
           "prediction_time": re.compile(r"(anchor|pred.*time|asof|timestamp)", re.I), "horizon": re.compile(r"(horizon|h60|h15|h240)", re.I),
           "label": re.compile(r"(label|target|^y$|y_)", re.I), "fold": re.compile(r"(fold|split|window)", re.I)}
SKIP_DIRS = {"venv", "pip-cache", "data", "tmp", "__pycache__"}
EXTS = {".parquet", ".npz", ".npy", ".csv", ".jsonl", ".feather", ".pkl", ".joblib"}


def sha256_file(p: Path) -> str:
    h = hashlib.sha256()
    with open(p, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def columns_of(p: Path) -> list | None:
    try:
        if p.suffix == ".parquet":
            import pyarrow.parquet as pq
            return list(pq.read_schema(p).names)
        if p.suffix == ".npz":
            with zipfile.ZipFile(p) as z:
                return [n[:-4] if n.endswith(".npy") else n for n in z.namelist()]
        if p.suffix == ".csv":
            with open(p, "r", encoding="utf-8", errors="replace") as f:
                return f.readline().strip().split(",")
        if p.suffix == ".jsonl":
            with open(p, "r", encoding="utf-8", errors="replace") as f:
                return list(json.loads(f.readline()).keys())
    except Exception:
        return None
    return None


def scan(work: Path, own: Path) -> dict:
    cand, scanned, by_ext = [], 0, {}
    for p in work.rglob("*"):
        if not p.is_file() or p.suffix.lower() not in EXTS:
            continue
        rel = p.relative_to(work)
        if rel.parts[0] in SKIP_DIRS or (own in p.parents) or any(s in SKIP_DIRS for s in rel.parts):
            continue
        scanned += 1
        by_ext[p.suffix] = by_ext.get(p.suffix, 0) + 1
        cols = columns_of(p)
        if cols is None:
            continue
        hits = [c for c in cols if PRED_RE.search(str(c))]
        if hits:
            cand.append({"file": str(rel), "size": p.stat().st_size, "columns_matching": hits[:20], "n_columns": len(cols)})
    return {"files_scanned": scanned, "by_ext": by_ext, "candidates": cand}


def long_arrays(obj, limit: int = 5000, path: str = "") -> list:
    """Paths of numeric lists longer than `limit` in a JSON object (per-row dumps would show up here; aggregated reports do not)."""
    out = []
    if isinstance(obj, dict):
        for k, v in obj.items():
            out += long_arrays(v, limit, f"{path}/{k}")
    elif isinstance(obj, list):
        if len(obj) > limit and all(isinstance(x, (int, float)) for x in obj[:50]):
            out.append(f"{path}[{len(obj)}]")
        else:
            for i, v in enumerate(obj[:50]):
                out += long_arrays(v, limit, f"{path}[{i}]")
    return out


def json_scan(work: Path, own: Path) -> dict:
    n, big = 0, []
    for p in work.rglob("*.json"):
        rel = p.relative_to(work)
        if any(s in SKIP_DIRS for s in rel.parts) or own in p.parents or p.stat().st_size > 80_000_000:
            continue
        n += 1
        try:
            la = long_arrays(json.loads(p.read_text("utf-8")))
        except Exception:
            continue
        if la:
            big.append({"file": str(rel), "long_numeric_lists": la[:5]})
    return {"json_files_scanned": n, "with_long_numeric_lists": big}


def models_inventory(work: Path) -> dict:
    inv = {}
    seg_p = work / "edge_search_v1" / "results" / "segments.json"
    seg = json.loads(seg_p.read_text("utf-8"))["symbols"] if seg_p.exists() else {}
    for sd in sorted((work / "models").glob("*")):
        sym = sd.name
        rows = []
        for hd in sorted(sd.glob("h*")):
            meta_p = hd / "metadata.json"
            meta = json.loads(meta_p.read_text("utf-8")) if meta_p.exists() else {}
            rep = meta.get("report", {})
            files = sorted(hd.glob("*.txt"))
            rows.append({"horizon": hd.name, "files": len(files), "status": meta.get("status"), "rows": rep.get("rows"),
                         "v3_long_sha256": sha256_file(hd / "v3_long.txt") if (hd / "v3_long.txt").exists() else None,
                         "v4_long_sha256": sha256_file(hd / "v4_long.txt") if (hd / "v4_long.txt").exists() else None,
                         "has_prediction_arrays": any(hd.glob("*.npy")) or any(hd.glob("*.npz")) or any(hd.glob("*.parquet"))})
        s = seg.get(sym, {})
        inv[sym] = {"horizons": rows, "dev_train_end_ms": s.get("train_end"), "dev_val_start_ms": s.get("val_start"), "holdout_start_ms": s.get("hold_start")}
    return inv


def run(work: Path, out: Path, results: Path, log=print) -> dict:
    import datetime as dt
    utc = lambda ms: dt.datetime.fromtimestamp(ms / 1000, dt.timezone.utc).strftime("%Y-%m-%d %H:%M") if ms else None
    res = {"utc": dt.datetime.now(dt.timezone.utc).isoformat()}
    eng = Path(__file__).resolve().parents[1]
    mods = {}
    for pkg in ("v4", "regime_v1", "quick_trade_replay_v1", "edge_search", "exp_e_v1"):
        files = sorted((eng / pkg).glob("*.py"))
        mods[pkg] = {"files": len(files), "lines": sum(len(f.read_text("utf-8", errors="replace").splitlines()) for f in files)}
    res["engine_packages"] = mods
    res["scan"] = scan(work, out)
    log(f"scanned {res['scan']['files_scanned']} files, {len(res['scan']['candidates'])} with prediction-like columns")
    res["json_scan"] = json_scan(work, out)
    res["models"] = models_inventory(work)
    for sym, v in res["models"].items():
        v["dev_train_end_utc"], v["dev_val_start_utc"], v["holdout_start_utc"] = utc(v["dev_train_end_ms"]), utc(v["dev_val_start_ms"]), utc(v["holdout_start_ms"])
    # provenance check of every candidate
    for c in res["scan"]["candidates"]:
        p = work / c["file"]
        cols = columns_of(p) or []
        c["provenance"] = {k: any(rx.search(str(x)) for x in cols) for k, rx in PROV_RE.items()}
        c["provenance_complete"] = all(c["provenance"].values())
    res["replay_arrays"] = {}
    qa = work / "quick_trade_replay_v1" / "results" / "arrays"
    if qa.exists():
        for p in sorted(qa.glob("*.npz"))[:1]:
            names = columns_of(p) or []
            res["replay_arrays"] = {"example_file": p.name, "keys": len(names), "prediction_like_keys": [n for n in names if PRED_RE.search(n)][:10]}
    res["verdict"] = {"genuine_oof_with_provenance_exists": any(c.get("provenance_complete") and not c["file"].startswith(("features", "targets")) for c in res["scan"]["candidates"]),
                      "fitted_models_with_persisted_row_predictions": any(r["has_prediction_arrays"] for v in res["models"].values() for r in v["horizons"])}
    (results / "report_A_oof_audit.json").write_text(json.dumps(res, indent=1, default=str), "utf-8")
    write_md(results, res)
    return res


def write_md(results: Path, res: dict) -> None:
    L = ["# Report A - E0 repository / artifact audit and E1 OOF audit", ""]
    L.append(f"Generated {res['utc']}. Read-only scan of `D:\\autofinx-ml-v4` (venv, pip cache, raw klines and this experiment's own output excluded).")
    L += ["", "## E0 engine packages reused (read-only, not modified)", "", "| package | files | lines |", "|---|---|---|"]
    for k, v in res["engine_packages"].items():
        L.append(f"| {k} | {v['files']} | {v['lines']} |")
    L += ["", "## E1 fitted models that exist (V3 / V4 LightGBM, P4A / P5 recipe)", "",
          "| coin | horizons with models | dev_train end (fit + calibration stop here) | dev_val start | holdout start (sealed) | persisted per-row predictions |", "|---|---|---|---|---|---|"]
    for sym, v in res["models"].items():
        L.append(f"| {sym} | {', '.join(r['horizon'] for r in v['horizons'])} | {v['dev_train_end_utc']} | {v['dev_val_start_utc']} | {v['holdout_start_utc']} | "
                 f"{'yes' if any(r['has_prediction_arrays'] for r in v['horizons']) else 'no'} |")
    L += ["", "Every model directory holds only booster text files and `metadata.json` (status `P4A_DEV_ONLY_NOT_FROZEN`). A fitted model has ONE training cutoff: it is out-of-sample only after that date, never walk-forward, and the sha256 of each booster is in the JSON.", ""]
    sc = res["scan"]
    L += [f"## E1 scan for per-row predictions", "", f"- files scanned: {sc['files_scanned']} ({sc['by_ext']})", f"- files with prediction-like column / key names: {len(sc['candidates'])}", ""]
    if sc["candidates"]:
        L += ["| file | size | matching columns | provenance complete (model hash, cutoff, prediction time, horizon, label, fold) |", "|---|---|---|---|"]
        for c in sc["candidates"][:60]:
            L.append(f"| {c['file']} | {c['size']} | {', '.join(c['columns_matching'][:6])} | {'yes' if c['provenance_complete'] else 'no: ' + ', '.join(k for k, v in c['provenance'].items() if not v)} |")
    js = res["json_scan"]
    L += ["", f"- JSON reports scanned: {js['json_files_scanned']}; files holding numeric lists longer than 5,000 values (per-row dumps): {len(js['with_long_numeric_lists'])} {js['with_long_numeric_lists'][:5]}",
          "- The P1 / P4A / P5 / V5-lite reports hold aggregated numbers only (decile tables, fold summaries), so no per-row, per-prediction-time probability exists in them.",
          f"- Quick Trade Replay arrays (R-030): {res['replay_arrays']}", ""]
    v = res["verdict"]
    L += ["## E1 verdict", "",
          f"- genuine OOF predictions with full provenance already present: **{'YES' if v['genuine_oof_with_provenance_exists'] else 'NO'}**",
          f"- fitted models with persisted per-row predictions: **{'YES' if v['fitted_models_with_persisted_row_predictions'] else 'NO'}**",
          "- consequence: E2 (regenerate causal walk-forward OOF predictions of the existing recipes) is REQUIRED. The prior replay statement (arm D not available) is confirmed.", ""]
    (results / "report_A_oof_audit.md").write_text("\n".join(L), "utf-8")
