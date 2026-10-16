from __future__ import annotations
import json
from pathlib import Path


def summarize(path: Path) -> dict:
    payload = json.loads(path.read_text(encoding='utf-8'))
    return {
        'source': str(path),
        'models': payload.get('models', []),
        'horizons': payload.get('horizons', []),
        'summary': payload.get('summary', {}),
        'probabilities': payload.get('probabilities', 'unavailable'),
        'execution_authorized': False,
    }


def dependency_status() -> dict:
    try:
        import sklearn  # type: ignore
        return {'scikit_learn': True, 'version': sklearn.__version__}
    except ImportError:
        return {'scikit_learn': False, 'version': None, 'reason': 'not installed in isolated research environment'}


if __name__ == '__main__':
    import argparse
    p = argparse.ArgumentParser(); p.add_argument('--phase3', required=True); p.add_argument('--out', required=True)
    a = p.parse_args(); out = summarize(Path(a.phase3)); out['dependency_status'] = dependency_status(); Path(a.out).write_text(json.dumps(out, indent=2), encoding='utf-8'); print(json.dumps(out, indent=2))
