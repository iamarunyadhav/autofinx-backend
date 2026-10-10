"""Isolation proof for Experiment F: a manifest of every protected existing file (relative path, size, mtime_ns, sha256 for files <= 4 MB)
taken BEFORE any Experiment F work and compared AFTER. New files are allowed only inside the allow-list (experiment_f/, the experiment_f_v1 result
folder, and the five tracking docs + EXPERIMENT_F docs). Any changed / deleted / unexpected-new protected file is reported.

    python -I isolation.py snapshot <out.json>
    python -I isolation.py verify <manifest.json>
"""

from __future__ import annotations

import hashlib
import json
import os
import sys
from pathlib import Path

BACKENDS = Path(__file__).resolve().parents[2]
ROOT = BACKENDS.parents[1]                      # ...\auto_finx
FRONT = ROOT / "autofinx-front" / "frontends"
D_WORK = Path(os.environ.get("AUTOFINX_V4_WORK", r"D:\autofinx-ml-v4"))
SKIP_DIRS = {"node_modules", "__pycache__", ".pytest_cache", "vendor", ".git", "dist", "storage", "tmp", "logs", "pip-cache", "venv", "pycache"}
HASH_MAX = 4 * 1024 * 1024
TRACKING_DOCS = {"CHANGELOG.md", "RESULTS.md", "DECISIONS.md", "PROGRESS.md"}      # the only existing files the agent pack allows editing


def protected_roots() -> list[tuple[str, Path]]:
    roots = [("backends", BACKENDS), ("front", FRONT), ("dwork", D_WORK)]
    return roots


def _skip_dir(rel: Path, label: str) -> bool:
    p = rel.as_posix()
    if label == "backends":
        if p == "external-predictive-engine/experiment_f" or p.startswith("external-predictive-engine/experiment_f/"):
            return True
        if p == "external-predictive-engine/collector" or p.startswith("storage") or p.startswith("vendor"):
            return p.startswith("storage") or p.startswith("vendor")
    if label == "dwork" and (p == "experiment_f_v1" or p.startswith("experiment_f_v1/")):
        return True
    return False


def _is_new_allowed(label: str, rel: str) -> bool:
    if label == "backends":
        if rel.startswith("external-predictive-engine/experiment_f/"):
            return True
        name = rel.split("/")[-1]
        if rel.startswith("docs/ml-engine-v1/") and (name.startswith("EXPERIMENT_F") or name in TRACKING_DOCS):
            return True
    if label == "dwork" and rel.startswith("experiment_f_v1/"):
        return True
    return False


def _entry(path: Path) -> dict | None:
    try:
        st = path.stat()
    except OSError:
        return None
    e = {"size": st.st_size, "mtime_ns": st.st_mtime_ns}
    if st.st_size <= HASH_MAX:
        try:
            e["sha256"] = hashlib.sha256(path.read_bytes()).hexdigest()
        except OSError:
            pass
    return e


def snapshot() -> dict:
    out: dict = {}
    for label, root in protected_roots():
        if not root.exists():
            continue
        for dp, dns, fns in os.walk(root):
            rel_dir = Path(dp).relative_to(root)
            dns[:] = [d for d in dns if d not in SKIP_DIRS and not _skip_dir(rel_dir / d, label)]
            for fn in fns:
                rel = (rel_dir / fn).as_posix()
                if label == "backends" and rel.startswith("docs/ml-engine-v1/") and rel.split("/")[-1] in TRACKING_DOCS:
                    continue
                if label == "dwork" and fn.endswith((".log", ".tmp")):
                    continue
                e = _entry(Path(dp) / fn)
                if e is not None:
                    out[f"{label}:{rel}"] = e
    return out


def compare(before: dict, after: dict) -> dict:
    changed, deleted, new_bad = [], [], []
    for k, e in before.items():
        a = after.get(k)
        if a is None:
            deleted.append(k)
        elif a["size"] != e["size"] or a["mtime_ns"] != e["mtime_ns"] or a.get("sha256") != e.get("sha256"):
            changed.append(k)
    for k in after:
        if k not in before:
            label, rel = k.split(":", 1)
            if not _is_new_allowed(label, rel):
                new_bad.append(k)
    return {"changed": changed, "deleted": deleted, "unexpected_new": new_bad, "n_before": len(before), "n_after": len(after),
            "ok": not (changed or deleted or new_bad)}


def main(argv=None) -> int:
    argv = argv or sys.argv[1:]
    if len(argv) < 2 or argv[0] not in ("snapshot", "verify"):
        print(__doc__)
        return 3
    if argv[0] == "snapshot":
        s = snapshot()
        Path(argv[1]).write_text(json.dumps(s), "utf-8")
        print(f"manifest written: {len(s)} files")
        return 0
    before = json.loads(Path(argv[1]).read_text("utf-8"))
    r = compare(before, snapshot())
    print(json.dumps({k: (v[:20] if isinstance(v, list) else v) for k, v in r.items()}, indent=1))
    return 0 if r["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
