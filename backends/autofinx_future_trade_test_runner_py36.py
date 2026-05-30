#!/usr/bin/env python3
"""
AutoFinX Future Trade Test Runner

Purpose
-------
Run a safe, repeatable test against the Laravel AutoFinX backend Future Trade bot.
It can:
  1) log in to the API and discover the workspace,
  2) collect before/after status, config, logs, trades, live-feed and chart snapshots,
  3) optionally run local Laravel artisan commands such as future-trade:tick,
  4) calculate API latency, signal counts, execution latency, trade PnL summary,
  5) write JSON/CSV/Markdown/HTML reports.

Default behavior is SAFE: it runs scanner ticks with --no-execute.
To allow testnet order execution, pass --execute and keep Future Trade env mode as testnet.
Live execution is blocked unless --allow-live is explicitly passed.

Examples
--------
# API-only read test
python3 autofinx_future_trade_test_runner.py \
  --api-base https://your-domain.com \
  --email you@example.com --password 'YOUR_PASSWORD' \
  --workspace YOUR_WORKSPACE_UUID

# Local server-side safe scanner test from Laravel backend folder
python3 autofinx_future_trade_test_runner.py \
  --api-base https://your-domain.com \
  --email you@example.com --password 'YOUR_PASSWORD' \
  --workspace YOUR_WORKSPACE_UUID \
  --backend-dir /path/to/backend \
  --run-artisan --duration 120

# Testnet execution test. Use only after configuring Binance Futures TESTNET in the UI.
python3 autofinx_future_trade_test_runner.py \
  --api-base https://your-domain.com \
  --email you@example.com --password 'YOUR_PASSWORD' \
  --workspace YOUR_WORKSPACE_UUID \
  --backend-dir /path/to/backend \
  --run-artisan --execute --duration 180
"""

import argparse
import csv
import html
import json
import os
import shutil
import subprocess
import sys
import time
import traceback
from datetime import datetime, timezone
from pathlib import Path
from statistics import mean, median
from typing import Any, Dict, Iterable, List, Optional, Tuple
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlparse
from urllib.request import Request, urlopen


ISO_FMT = "%Y-%m-%dT%H:%M:%SZ"
SIGNAL_WORDS = {
    "BUY", "SELL", "LONG", "SHORT", "ENTRY_LONG", "ENTRY_SHORT",
    "OPEN_LONG", "OPEN_SHORT", "CLOSE_LONG", "CLOSE_SHORT",
}
BLOCK_WORDS = ("BLOCK", "SKIP", "MISSED", "REJECT", "GUARD", "COOLDOWN")


class ApiResult:
    def __init__(self, method, path, status, ok, elapsed_ms, body=None, error=None):
        self.method = method
        self.path = path
        self.status = status
        self.ok = ok
        self.elapsed_ms = elapsed_ms
        self.body = body
        self.error = error


class CommandResult:
    def __init__(self, command, cwd, returncode, elapsed_ms, stdout, stderr):
        self.command = command
        self.cwd = cwd
        self.returncode = returncode
        self.elapsed_ms = elapsed_ms
        self.stdout = stdout
        self.stderr = stderr


class TestContext:
    def __init__(self, run_id, started_at, api_base, workspace, mode, args):
        self.run_id = run_id
        self.started_at = started_at
        self.api_base = api_base
        self.workspace = workspace
        self.mode = mode
        self.args = args
        self.api_results = []
        self.commands = []
        self.warnings = []
        self.failures = []
        self.before = {}
        self.after = {}
        self.summary = {}

def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def stamp() -> str:
    return utcnow().strftime(ISO_FMT)


def parse_dt(value: Any) -> Optional[datetime]:
    if value is None:
        return None
    if isinstance(value, datetime):
        return value if value.tzinfo else value.replace(tzinfo=timezone.utc)
    if not isinstance(value, str):
        return None
    s = value.strip()
    if not s:
        return None
    # Python 3.6 has no datetime.fromisoformat(), so support common Laravel/API formats manually.
    candidates = []
    normalized = s
    if normalized.endswith("Z"):
        normalized = normalized[:-1] + "+0000"
    if " " in normalized and "T" not in normalized:
        normalized = normalized.replace(" ", "T")
    # Convert timezone +00:00 -> +0000 for strptime on older Python.
    if len(normalized) >= 6 and (normalized[-6] in ["+", "-"]) and normalized[-3] == ":":
        normalized_no_colon_tz = normalized[:-3] + normalized[-2:]
    else:
        normalized_no_colon_tz = normalized
    candidates.extend([
        (normalized_no_colon_tz, "%Y-%m-%dT%H:%M:%S%z"),
        (normalized_no_colon_tz, "%Y-%m-%dT%H:%M:%S.%f%z"),
        (normalized, "%Y-%m-%dT%H:%M:%S"),
        (normalized, "%Y-%m-%dT%H:%M:%S.%f"),
        (s, "%Y-%m-%d %H:%M:%S"),
        (s, "%Y-%m-%d %H:%M:%S.%f"),
        (s, "%Y-%m-%d"),
    ])
    for text_value, fmt in candidates:
        try:
            dt = datetime.strptime(text_value, fmt)
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=timezone.utc)
            return dt.astimezone(timezone.utc)
        except Exception:
            pass
    return None

def as_float(value: Any) -> Optional[float]:
    if value is None or value == "":
        return None
    try:
        return float(value)
    except Exception:
        return None


def safe_get(obj: Any, path: str, default: Any = None) -> Any:
    cur = obj
    for part in path.split("."):
        if isinstance(cur, dict):
            cur = cur.get(part, default)
        elif isinstance(cur, list):
            try:
                cur = cur[int(part)]
            except Exception:
                return default
        else:
            return default
    return cur


def normalize_base_url(value: str) -> str:
    value = value.strip().rstrip("/")
    if not value:
        return "http://127.0.0.1:8000"
    parsed = urlparse(value)
    if not parsed.scheme:
        value = "https://" + value
    return value.rstrip("/")


def json_preview(value: Any, max_chars: int = 1200) -> str:
    try:
        text = json.dumps(value, ensure_ascii=False, indent=2, default=str)
    except Exception:
        text = str(value)
    if len(text) > max_chars:
        return text[:max_chars] + "..."
    return text


class ApiClient:
    def __init__(self, base_url: str, token: str = "", timeout: int = 20):
        self.base_url = normalize_base_url(base_url)
        self.token = token.strip()
        self.timeout = timeout

    def request(self, method: str, path: str, payload: Optional[dict] = None, query: Optional[dict] = None) -> ApiResult:
        path = path if path.startswith("/") else "/" + path
        url = self.base_url + path
        if query:
            q = urlencode({k: v for k, v in query.items() if v is not None})
            if q:
                url += ("&" if "?" in url else "?") + q

        body_bytes = None
        headers = {
            "Accept": "application/json",
            "User-Agent": "AutoFinX-TestRunner/1.0",
        }
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        if payload is not None:
            body_bytes = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json"

        started = time.perf_counter()
        req = Request(url, data=body_bytes, method=method.upper(), headers=headers)
        try:
            with urlopen(req, timeout=self.timeout) as resp:
                raw = resp.read().decode("utf-8", errors="replace")
                elapsed = (time.perf_counter() - started) * 1000
                parsed = None
                if raw.strip():
                    try:
                        parsed = json.loads(raw)
                    except Exception:
                        parsed = raw
                status = int(resp.status)
                return ApiResult(method.upper(), path, status, 200 <= status < 300, elapsed, parsed)
        except HTTPError as e:
            elapsed = (time.perf_counter() - started) * 1000
            raw = e.read().decode("utf-8", errors="replace")
            try:
                parsed = json.loads(raw) if raw.strip() else None
            except Exception:
                parsed = raw
            return ApiResult(method.upper(), path, int(e.code), False, elapsed, parsed, str(e))
        except (URLError, TimeoutError, Exception) as e:
            elapsed = (time.perf_counter() - started) * 1000
            return ApiResult(method.upper(), path, 0, False, elapsed, None, repr(e))


def record_api(ctx: TestContext, client: ApiClient, method: str, path: str, payload: Optional[dict] = None, query: Optional[dict] = None) -> ApiResult:
    result = client.request(method, path, payload=payload, query=query)
    ctx.api_results.append(result)
    status = result.status if result.status else "ERR"
    print(f"[{stamp()}] API {method.upper()} {path} -> {status} ({result.elapsed_ms:.0f} ms)")
    return result


def login(ctx: TestContext, client: ApiClient) -> Optional[str]:
    if ctx.args.token:
        client.token = ctx.args.token.strip()
        return client.token
    if not ctx.args.email or not ctx.args.password:
        ctx.warnings.append("No --token or --email/--password provided; only public/API-token endpoints can be tested.")
        return None
    payload = {"email": ctx.args.email, "password": ctx.args.password}
    if ctx.args.otp:
        payload["code"] = ctx.args.otp
    res = record_api(ctx, client, "POST", "/api/v1/auth/login", payload=payload)
    token = safe_get(res.body, "token")
    if res.ok and token:
        client.token = str(token)
        return client.token
    ctx.failures.append("Login failed. Check API base URL, email/password, OTP, and backend auth config.")
    return None


def resolve_workspace(ctx: TestContext, client: ApiClient) -> Optional[str]:
    if ctx.workspace:
        return ctx.workspace
    res = record_api(ctx, client, "GET", "/api/v1/auth/me")
    workspaces = safe_get(res.body, "workspaces", [])
    if res.ok and isinstance(workspaces, list) and workspaces:
        workspace_id = str(workspaces[0].get("id"))
        ctx.workspace = workspace_id
        return workspace_id
    ctx.failures.append("Workspace ID not provided and could not be discovered from /auth/me.")
    return None


def api_snapshot(ctx: TestContext, client: ApiClient, label: str) -> Dict[str, Any]:
    workspace = ctx.workspace
    if not workspace:
        return {}
    endpoints: List[Tuple[str, str, Optional[dict]]] = [
        ("env_config", f"/api/v1/workspaces/{workspace}/future-trade/env-config", None),
        ("config", f"/api/v1/workspaces/{workspace}/future-trade/config", None),
        ("status", f"/api/v1/workspaces/{workspace}/future-trade/status", None),
        ("dashboard", f"/api/v1/workspaces/{workspace}/future-trade/dashboard", None),
        ("live_status", f"/api/v1/workspaces/{workspace}/future-trade/live-status", None),
        ("scanner_snapshot", f"/api/v1/workspaces/{workspace}/future-trade/scanner-snapshot", None),
        ("live_feed", f"/api/v1/workspaces/{workspace}/future-trade/live-feed", None),
        ("user_stream", f"/api/v1/workspaces/{workspace}/future-trade/user-stream", None),
        ("logs", f"/api/v1/workspaces/{workspace}/future-trade/logs", {"limit": 1000}),
        ("trades", f"/api/v1/workspaces/{workspace}/future-trade/trades", {"limit": 1000}),
        ("chart", f"/api/v1/workspaces/{workspace}/future-trade/chart", {"limit": 180}),
    ]
    snap: Dict[str, Any] = {}
    print(f"\n[{stamp()}] Collecting {label} API snapshot...")
    for key, path, query in endpoints:
        res = record_api(ctx, client, "GET", path, query=query)
        snap[key] = {
            "ok": res.ok,
            "status": res.status,
            "elapsed_ms": res.elapsed_ms,
            "body": res.body,
            "error": res.error,
        }
    return snap


def maybe_test_connection(ctx: TestContext, client: ApiClient) -> None:
    if not ctx.workspace or not ctx.args.test_connection:
        return
    res = record_api(ctx, client, "POST", f"/api/v1/workspaces/{ctx.workspace}/future-trade/test-connection")
    if not res.ok:
        ctx.warnings.append("Future Trade test-connection endpoint failed or was blocked by 2FA. This is okay for read-only tests; check report details.")


def endpoint_ok(snapshot: Dict[str, Any], key: str) -> bool:
    return bool(snapshot.get(key, {}).get("ok"))


def extract_body(snapshot: Dict[str, Any], key: str) -> Any:
    return snapshot.get(key, {}).get("body")


def as_records(value: Any) -> List[dict]:
    if isinstance(value, list):
        return [x for x in value if isinstance(x, dict)]
    if isinstance(value, dict):
        for key in ("data", "trades", "logs", "items", "results"):
            if isinstance(value.get(key), list):
                return [x for x in value[key] if isinstance(x, dict)]
    return []


def record_id(row: dict) -> str:
    for key in ("id", "uuid", "client_order_id", "entry_client_order_id"):
        value = row.get(key)
        if value is not None and str(value) != "":
            return str(value)
    return json.dumps(row, sort_keys=True, default=str)[:256]


def filter_new(before: List[dict], after: List[dict], run_started: datetime, date_keys: Iterable[str]) -> List[dict]:
    before_ids = {record_id(x) for x in before}
    out: List[dict] = []
    for row in after:
        rid = record_id(row)
        if rid not in before_ids:
            out.append(row)
            continue
        for key in date_keys:
            dt = parse_dt(row.get(key))
            if dt and dt >= run_started:
                out.append(row)
                break
    return out


def is_signal_log(row: dict) -> bool:
    signal = str(row.get("signal") or row.get("current_signal") or "").upper()
    event = str(row.get("event") or row.get("event_type") or "").upper()
    reason = str(row.get("reason") or "").upper()
    if signal in SIGNAL_WORDS:
        return True
    if "SIGNAL" in event and "WAIT" not in signal:
        return True
    if any(word in event or word in reason for word in ("ENTRY", "OPEN_LONG", "OPEN_SHORT")):
        return True
    return False


def is_blocked_log(row: dict) -> bool:
    event = str(row.get("event") or row.get("event_type") or "").upper()
    reason = str(row.get("reason") or "").upper()
    signal = str(row.get("signal") or "").upper()
    text = " ".join([event, reason, signal])
    return any(word in text for word in BLOCK_WORDS)


def side_matches(signal: str, side: str) -> bool:
    signal = signal.upper()
    side = side.upper()
    if side in {"LONG", "BUY"}:
        return signal in {"BUY", "LONG", "ENTRY_LONG", "OPEN_LONG"}
    if side in {"SHORT", "SELL"}:
        return signal in {"SELL", "SHORT", "ENTRY_SHORT", "OPEN_SHORT"}
    return False


def match_signal_to_trade(trade: dict, signal_logs: List[dict]) -> Optional[Tuple[dict, float]]:
    trade_time = parse_dt(trade.get("created_at") or trade.get("opened_at") or trade.get("updated_at"))
    if not trade_time:
        return None
    trade_symbol = str(trade.get("symbol") or "").upper()
    trade_side = str(trade.get("side") or trade.get("position_side") or "").upper()
    candidates: List[Tuple[float, dict]] = []
    for log in signal_logs:
        log_time = parse_dt(log.get("occurred_at") or log.get("created_at") or log.get("updated_at"))
        if not log_time:
            continue
        if log_time > trade_time:
            continue
        age = (trade_time - log_time).total_seconds()
        if age > 60 * 30:
            continue
        log_symbol = str(log.get("symbol") or "").upper()
        log_signal = str(log.get("signal") or "").upper()
        if trade_symbol and log_symbol and trade_symbol != log_symbol:
            continue
        if trade_side and log_signal and not side_matches(log_signal, trade_side):
            # Keep generic SIGNAL logs if there is no precise signal, otherwise skip mismatches.
            event = str(log.get("event") or "").upper()
            if log_signal or "SIGNAL" not in event:
                continue
        candidates.append((age, log))
    if not candidates:
        return None
    age, log = sorted(candidates, key=lambda x: x[0])[0]
    return log, age


def analyze(ctx: TestContext) -> Dict[str, Any]:
    before_logs = as_records(extract_body(ctx.before, "logs"))
    after_logs = as_records(extract_body(ctx.after, "logs"))
    before_trades = as_records(extract_body(ctx.before, "trades"))
    after_trades = as_records(extract_body(ctx.after, "trades"))
    new_logs = filter_new(before_logs, after_logs, ctx.started_at, ["occurred_at", "created_at", "updated_at"])
    new_trades = filter_new(before_trades, after_trades, ctx.started_at, ["created_at", "opened_at", "updated_at"])
    signal_logs = [x for x in new_logs if is_signal_log(x)]
    blocked_logs = [x for x in new_logs if is_blocked_log(x)]

    closed_trades = [t for t in after_trades if str(t.get("status") or "").upper() in {"CLOSED", "FILLED", "EXITED"} or t.get("exit_price") is not None]
    pnl_values = [as_float(t.get("net_pnl")) for t in closed_trades]
    pnl_values = [v for v in pnl_values if v is not None]
    wins = len([v for v in pnl_values if v > 0])
    losses = len([v for v in pnl_values if v < 0])

    latency_matches: List[Dict[str, Any]] = []
    latencies: List[float] = []
    for trade in new_trades:
        matched = match_signal_to_trade(trade, signal_logs + after_logs)
        if matched:
            sig, seconds = matched
            latencies.append(seconds)
            latency_matches.append({
                "trade_id": record_id(trade),
                "symbol": trade.get("symbol"),
                "trade_side": trade.get("side"),
                "trade_time": trade.get("created_at") or trade.get("opened_at"),
                "signal_event": sig.get("event"),
                "signal": sig.get("signal"),
                "signal_time": sig.get("occurred_at") or sig.get("created_at"),
                "latency_seconds": seconds,
            })

    endpoint_latencies = [r.elapsed_ms for r in ctx.api_results if r.ok]
    failed_endpoints = [f"{r.method} {r.path} -> {r.status} {r.error or json_preview(r.body, 250)}" for r in ctx.api_results if not r.ok]

    env = extract_body(ctx.after, "env_config") or extract_body(ctx.before, "env_config") or {}
    status = extract_body(ctx.after, "status") or {}
    live_status = extract_body(ctx.after, "live_status") or {}
    config = extract_body(ctx.after, "config") or {}

    summary = {
        "run_id": ctx.run_id,
        "started_at": ctx.started_at.isoformat(),
        "finished_at": utcnow().isoformat(),
        "api_base": ctx.api_base,
        "workspace": ctx.workspace,
        "environment": {
            "mode": env.get("mode"),
            "force_testnet": env.get("force_testnet"),
            "testnet_configured": env.get("testnet_configured"),
            "live_configured": env.get("live_configured"),
            "testnet_api_url": env.get("testnet_api_url"),
            "live_api_url": env.get("live_api_url"),
        },
        "bot_status": {
            "state": status.get("state") or live_status.get("state"),
            "status": status.get("status") or live_status.get("status"),
            "connection_status": status.get("connectionStatus") or status.get("connection_status") or live_status.get("connectionStatus"),
            "current_symbol": status.get("currentSymbol") or status.get("current_symbol") or live_status.get("currentSymbol"),
            "current_signal": status.get("currentSignal") or status.get("current_signal") or live_status.get("currentSignal"),
            "current_price": status.get("currentPrice") or status.get("current_price") or live_status.get("currentPrice"),
            "confidence": status.get("currentConfidence") or status.get("confidence") or live_status.get("currentConfidence"),
        },
        "counts": {
            "before_logs": len(before_logs),
            "after_logs": len(after_logs),
            "new_logs": len(new_logs),
            "new_signal_logs": len(signal_logs),
            "new_blocked_or_skipped_logs": len(blocked_logs),
            "before_trades": len(before_trades),
            "after_trades": len(after_trades),
            "new_trades": len(new_trades),
            "closed_trades_total_seen": len(closed_trades),
        },
        "api_latency_ms": {
            "count": len(endpoint_latencies),
            "avg": round(mean(endpoint_latencies), 2) if endpoint_latencies else None,
            "median": round(median(endpoint_latencies), 2) if endpoint_latencies else None,
            "max": round(max(endpoint_latencies), 2) if endpoint_latencies else None,
        },
        "execution_latency_seconds": {
            "matched_trades": len(latencies),
            "avg": round(mean(latencies), 3) if latencies else None,
            "median": round(median(latencies), 3) if latencies else None,
            "max": round(max(latencies), 3) if latencies else None,
            "matches": latency_matches[:50],
        },
        "pnl_summary_total_seen": {
            "closed_trades_with_pnl": len(pnl_values),
            "wins": wins,
            "losses": losses,
            "win_rate_percent": round((wins / len(pnl_values)) * 100, 2) if pnl_values else None,
            "net_pnl_sum": round(sum(pnl_values), 8) if pnl_values else None,
        },
        "failed_endpoints": failed_endpoints,
        "command_failures": [c.command for c in ctx.commands if c.returncode != 0],
        "new_logs_sample": new_logs[:20],
        "new_trades_sample": new_trades[:20],
        "config_settings_keys": sorted(list((config.get("settings") or {}).keys())) if isinstance(config, dict) else [],
    }

    # Warnings/failures based on evidence.
    if not endpoint_ok(ctx.after, "status") and not endpoint_ok(ctx.before, "status"):
        ctx.failures.append("Could not read Future Trade status endpoint.")
    if ctx.args.execute:
        mode = str(env.get("mode") or "").lower()
        force_testnet = bool(env.get("force_testnet"))
        if mode == "live" and not ctx.args.allow_live:
            ctx.failures.append("Execution requested but Future Trade env mode is LIVE. Blocked by script. Use testnet, or pass --allow-live only if you intentionally accept real-money risk.")
        elif mode not in {"testnet", "paper"}:
            ctx.warnings.append(f"Execution requested but env mode is '{mode}'. Expected testnet.")
        elif mode == "paper":
            ctx.warnings.append("Execution requested but env mode is paper; no Binance testnet orders will be placed.")
        if mode == "testnet" and not force_testnet:
            ctx.warnings.append("Mode says testnet but force_testnet is false. Verify config before trusting execution results.")
        if not env.get("testnet_configured") and mode == "testnet":
            ctx.failures.append("Testnet mode is selected but testnet API credentials are not configured.")
    if ctx.args.run_artisan and summary["counts"]["new_logs"] == 0:
        ctx.warnings.append("Artisan tick ran, but no new Future Trade logs were detected. Check if bot is enabled, scheduler/queue is blocked, or symbols/feed are not available.")
    if ctx.args.execute and summary["counts"]["new_trades"] == 0:
        ctx.warnings.append("Execution test completed but no new trades were detected. This can be normal if entry guards blocked signals; inspect blocked/skipped logs.")
    if failed_endpoints:
        ctx.warnings.append(f"{len(failed_endpoints)} API calls failed. See failed_endpoints in JSON report.")
    if any(c.returncode != 0 for c in ctx.commands):
        ctx.warnings.append("One or more artisan commands returned non-zero status. See command output in report.")

    summary["warnings"] = ctx.warnings
    summary["failures"] = ctx.failures
    return summary


def run_cmd(ctx: TestContext, command: List[str], cwd: Path, timeout: int = 120) -> CommandResult:
    cmd_str = " ".join(command)
    print(f"[{stamp()}] CMD {cmd_str}")
    started = time.perf_counter()
    try:
        proc = subprocess.run(
            command,
            cwd=str(cwd),
            universal_newlines=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=timeout,
            env=os.environ.copy(),
        )
        elapsed = (time.perf_counter() - started) * 1000
        result = CommandResult(cmd_str, str(cwd), proc.returncode, elapsed, proc.stdout[-12000:], proc.stderr[-12000:])
    except subprocess.TimeoutExpired as e:
        elapsed = (time.perf_counter() - started) * 1000
        result = CommandResult(cmd_str, str(cwd), 124, elapsed, e.stdout or "", e.stderr or f"Timed out after {timeout}s")
    except Exception as e:
        elapsed = (time.perf_counter() - started) * 1000
        result = CommandResult(cmd_str, str(cwd), 125, elapsed, "", repr(e))
    ctx.commands.append(result)
    print(f"[{stamp()}] CMD done rc={result.returncode} ({result.elapsed_ms:.0f} ms)")
    if result.stdout.strip():
        print(result.stdout.strip()[-2000:])
    if result.stderr.strip():
        print(result.stderr.strip()[-2000:], file=sys.stderr)
    return result


def run_artisan_workflow(ctx: TestContext) -> None:
    backend_dir = Path(ctx.args.backend_dir or ".").expanduser().resolve()
    artisan = backend_dir / "artisan"
    if not artisan.exists():
        ctx.failures.append(f"artisan not found at {artisan}. Pass --backend-dir pointing to Laravel backend folder.")
        return
    php = ctx.args.php_binary or shutil.which("php") or "php"
    workspace = ctx.workspace
    if not workspace:
        ctx.failures.append("Cannot run artisan workflow without workspace id.")
        return

    # Safety: if execution was requested, block live unless allow-live is true.
    env_body = extract_body(ctx.before, "env_config") or {}
    mode = str(env_body.get("mode") or "").lower()
    if ctx.args.execute and mode == "live" and not ctx.args.allow_live:
        ctx.failures.append("Refusing to run executable artisan tick because env mode is live. Switch UI config to testnet or pass --allow-live.")
        return

    run_cmd(ctx, [php, "artisan", "future-trade:diagnostics", workspace, "--persist"], cwd=backend_dir, timeout=90)

    if ctx.args.run_live_feed:
        max_runtime = str(max(10, int(ctx.args.duration)))
        symbols_arg = []
        if ctx.args.symbols:
            symbols_arg = [f"--symbols={ctx.args.symbols}"]
        cmd = [php, "artisan", "future-trade:live-feed", *symbols_arg, f"--max-runtime={max_runtime}"]
        if not ctx.args.allow_live:
            cmd.append("--testnet")
        # Run bounded live-feed before ticks, not infinite.
        run_cmd(ctx, cmd, cwd=backend_dir, timeout=int(ctx.args.duration) + 45)

    total_duration = max(1, int(ctx.args.duration))
    remaining = total_duration
    while remaining > 0:
        slice_seconds = min(58, remaining)
        cmd = [
            php,
            "artisan",
            "future-trade:tick",
            f"--workspace={workspace}",
            f"--duration={slice_seconds}",
            f"--sleep-ms={int(ctx.args.tick_sleep_ms)}",
        ]
        if not ctx.args.execute:
            cmd.append("--no-execute")
        run_cmd(ctx, cmd, cwd=backend_dir, timeout=slice_seconds + 45)
        remaining -= slice_seconds
        if remaining > 0:
            time.sleep(1)

    run_cmd(ctx, [php, "artisan", "future-trade:audit-missed-signals", f"--workspace={workspace}", "--limit=100"], cwd=backend_dir, timeout=90)
    if ctx.args.execute:
        reconcile_cmd = [php, "artisan", "future-trade:reconcile", f"--workspace={workspace}"]
        if ctx.args.symbols and "," not in ctx.args.symbols:
            reconcile_cmd.append(f"--symbol={ctx.args.symbols}")
        run_cmd(ctx, reconcile_cmd, cwd=backend_dir, timeout=120)

    if ctx.args.run_backtest:
        backtest_cmd = [
            php,
            "artisan",
            "future-trade:backtest",
            f"--workspace={workspace}",
            f"--capital={ctx.args.capital}",
        ]
        if ctx.args.backtest_from:
            backtest_cmd.append(f"--from={ctx.args.backtest_from}")
        if ctx.args.backtest_to:
            backtest_cmd.append(f"--to={ctx.args.backtest_to}")
        run_cmd(ctx, backtest_cmd, cwd=backend_dir, timeout=int(ctx.args.backtest_timeout))


def write_csv(path: Path, rows: List[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if not rows:
        path.write_text("", encoding="utf-8")
        return
    keys: List[str] = []
    for row in rows:
        for key in row.keys():
            if key not in keys:
                keys.append(key)
    with path.open("w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=keys, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow({k: json.dumps(v, ensure_ascii=False) if isinstance(v, (dict, list)) else v for k, v in row.items()})


def markdown_table(headers: List[str], rows: List[List[Any]]) -> str:
    def cell(v: Any) -> str:
        s = "" if v is None else str(v)
        return s.replace("|", "\\|").replace("\n", " ")
    out = ["| " + " | ".join(headers) + " |", "| " + " | ".join(["---"] * len(headers)) + " |"]
    for row in rows:
        out.append("| " + " | ".join(cell(v) for v in row) + " |")
    return "\n".join(out)


def render_markdown(ctx: TestContext) -> str:
    s = ctx.summary
    counts = s.get("counts", {})
    env = s.get("environment", {})
    bot = s.get("bot_status", {})
    api_latency = s.get("api_latency_ms", {})
    exec_latency = s.get("execution_latency_seconds", {})
    pnl = s.get("pnl_summary_total_seen", {})

    verdict = "PASS"
    if s.get("failures"):
        verdict = "FAIL"
    elif s.get("warnings"):
        verdict = "WARN"

    parts: List[str] = []
    parts.append(f"# AutoFinX Future Trade Test Report\n")
    parts.append(f"**Verdict:** `{verdict}`  ")
    parts.append(f"**Run ID:** `{s.get('run_id')}`  ")
    parts.append(f"**Started:** `{s.get('started_at')}`  ")
    parts.append(f"**Finished:** `{s.get('finished_at')}`  ")
    parts.append(f"**API Base:** `{s.get('api_base')}`  ")
    parts.append(f"**Workspace:** `{s.get('workspace')}`\n")

    parts.append("## Environment")
    parts.append(markdown_table(["Key", "Value"], [[k, v] for k, v in env.items()]))

    parts.append("\n## Bot Status")
    parts.append(markdown_table(["Key", "Value"], [[k, v] for k, v in bot.items()]))

    parts.append("\n## Counts")
    parts.append(markdown_table(["Metric", "Value"], [[k, v] for k, v in counts.items()]))

    parts.append("\n## Latency")
    parts.append(markdown_table(["Metric", "Value"], [
        ["API calls", api_latency.get("count")],
        ["API average ms", api_latency.get("avg")],
        ["API median ms", api_latency.get("median")],
        ["API max ms", api_latency.get("max")],
        ["Matched execution trades", exec_latency.get("matched_trades")],
        ["Execution avg seconds", exec_latency.get("avg")],
        ["Execution median seconds", exec_latency.get("median")],
        ["Execution max seconds", exec_latency.get("max")],
    ]))

    parts.append("\n## PnL Summary From Visible Closed Trades")
    parts.append(markdown_table(["Metric", "Value"], [[k, v] for k, v in pnl.items()]))

    if s.get("warnings"):
        parts.append("\n## Warnings")
        for w in s["warnings"]:
            parts.append(f"- {w}")

    if s.get("failures"):
        parts.append("\n## Failures")
        for f in s["failures"]:
            parts.append(f"- {f}")

    failed = s.get("failed_endpoints") or []
    if failed:
        parts.append("\n## Failed API Endpoints")
        for item in failed[:50]:
            parts.append(f"- `{item}`")

    matches = exec_latency.get("matches") or []
    if matches:
        parts.append("\n## Signal → Trade Latency Matches")
        rows = []
        for m in matches[:25]:
            rows.append([m.get("symbol"), m.get("trade_side"), m.get("signal"), m.get("latency_seconds"), m.get("trade_time")])
        parts.append(markdown_table(["Symbol", "Side", "Signal", "Latency sec", "Trade time"], rows))

    if ctx.commands:
        parts.append("\n## Artisan Commands")
        rows = [[c.returncode, round(c.elapsed_ms, 0), c.command] for c in ctx.commands]
        parts.append(markdown_table(["RC", "ms", "Command"], rows))
        for idx, c in enumerate(ctx.commands, start=1):
            parts.append(f"\n### Command {idx}: `{c.command}`")
            parts.append(f"Return code: `{c.returncode}`")
            if c.stdout.strip():
                parts.append("\n**stdout tail**")
                parts.append("```text\n" + c.stdout[-4000:] + "\n```")
            if c.stderr.strip():
                parts.append("\n**stderr tail**")
                parts.append("```text\n" + c.stderr[-4000:] + "\n```")

    parts.append("\n## Next Actions")
    if verdict == "PASS":
        parts.append("- Test runner completed without failures. Compare `new_logs.csv` and `new_trades.csv` for detailed signal/order behavior.")
    elif verdict == "WARN":
        parts.append("- Review warnings first. Most common causes are bot disabled, 2FA blocking write endpoints, no entry signal during the test window, or missing testnet credentials.")
    else:
        parts.append("- Fix failures before trusting testnet execution. Do not run with `--execute` until env mode is confirmed as `testnet` and credentials are configured.")

    return "\n".join(parts).strip() + "\n"


def render_html(markdown_text: str, ctx: TestContext) -> str:
    # Tiny markdown-to-HTML renderer for this report structure; avoids external dependencies.
    lines = markdown_text.splitlines()
    body: List[str] = []
    in_code = False
    in_ul = False
    in_table = False
    table_lines: List[str] = []

    def flush_ul() -> None:
        nonlocal in_ul
        if in_ul:
            body.append("</ul>")
            in_ul = False

    def flush_table() -> None:
        nonlocal in_table, table_lines
        if not in_table:
            return
        body.append("<table>")
        for i, line in enumerate(table_lines):
            if i == 1:
                continue
            cells = [c.strip() for c in line.strip("|").split("|")]
            tag = "th" if i == 0 else "td"
            body.append("<tr>" + "".join(f"<{tag}>{html.escape(c)}</{tag}>" for c in cells) + "</tr>")
        body.append("</table>")
        in_table = False
        table_lines = []

    for line in lines:
        if line.startswith("```"):
            flush_ul(); flush_table()
            if not in_code:
                body.append("<pre><code>")
                in_code = True
            else:
                body.append("</code></pre>")
                in_code = False
            continue
        if in_code:
            body.append(html.escape(line) + "\n")
            continue
        if line.startswith("|") and line.endswith("|"):
            flush_ul()
            in_table = True
            table_lines.append(line)
            continue
        flush_table()
        if not line.strip():
            flush_ul()
            body.append("")
        elif line.startswith("# "):
            flush_ul(); body.append(f"<h1>{html.escape(line[2:])}</h1>")
        elif line.startswith("## "):
            flush_ul(); body.append(f"<h2>{html.escape(line[3:])}</h2>")
        elif line.startswith("### "):
            flush_ul(); body.append(f"<h3>{html.escape(line[4:])}</h3>")
        elif line.startswith("- "):
            if not in_ul:
                body.append("<ul>")
                in_ul = True
            body.append(f"<li>{html.escape(line[2:])}</li>")
        else:
            flush_ul()
            text = html.escape(line)
            text = text.replace("**", "")
            body.append(f"<p>{text}</p>")
    flush_ul(); flush_table()

    verdict = "FAIL" if ctx.summary.get("failures") else ("WARN" if ctx.summary.get("warnings") else "PASS")
    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AutoFinX Future Trade Test Report - {html.escape(ctx.run_id)}</title>
<style>
body {{ font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; margin: 32px; line-height: 1.5; color: #18202a; }}
h1,h2,h3 {{ line-height: 1.2; }}
table {{ border-collapse: collapse; width: 100%; margin: 12px 0 24px; }}
th, td {{ border: 1px solid #d8dee7; padding: 8px 10px; text-align: left; vertical-align: top; }}
th {{ background: #f6f8fa; }}
pre {{ background: #0b1020; color: #e6edf3; padding: 14px; overflow-x: auto; border-radius: 10px; }}
code {{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }}
.badge {{ display: inline-block; padding: 6px 10px; border-radius: 999px; font-weight: 700; background: #eef2ff; }}
</style>
</head>
<body>
<div class="badge">Verdict: {html.escape(verdict)}</div>
{''.join(body)}
</body>
</html>
"""


def save_reports(ctx: TestContext) -> Dict[str, str]:
    backend_dir = Path(ctx.args.backend_dir or ".").expanduser().resolve()
    if ctx.args.out_dir:
        out_root = Path(ctx.args.out_dir).expanduser().resolve()
    elif (backend_dir / "public").is_dir():
        out_root = backend_dir / "public" / "autofinx-test-reports"
    else:
        out_root = Path.cwd() / "autofinx-test-reports"
    out_dir = out_root / ctx.run_id
    out_dir.mkdir(parents=True, exist_ok=True)

    before_logs = as_records(extract_body(ctx.before, "logs"))
    after_logs = as_records(extract_body(ctx.after, "logs"))
    before_trades = as_records(extract_body(ctx.before, "trades"))
    after_trades = as_records(extract_body(ctx.after, "trades"))
    new_logs = filter_new(before_logs, after_logs, ctx.started_at, ["occurred_at", "created_at", "updated_at"])
    new_trades = filter_new(before_trades, after_trades, ctx.started_at, ["created_at", "opened_at", "updated_at"])

    raw = {
        "summary": ctx.summary,
        "api_results": [r.__dict__ for r in ctx.api_results],
        "commands": [c.__dict__ for c in ctx.commands],
        "before": ctx.before,
        "after": ctx.after,
    }
    (out_dir / "report.json").write_text(json.dumps(raw, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    write_csv(out_dir / "new_logs.csv", new_logs)
    write_csv(out_dir / "new_trades.csv", new_trades)
    write_csv(out_dir / "all_after_logs.csv", after_logs)
    write_csv(out_dir / "all_after_trades.csv", after_trades)

    md = render_markdown(ctx)
    (out_dir / "report.md").write_text(md, encoding="utf-8")
    (out_dir / "report.html").write_text(render_html(md, ctx), encoding="utf-8")

    public_url = None
    if (backend_dir / "public").is_dir() and str(out_dir).startswith(str((backend_dir / "public").resolve())):
        rel = out_dir.relative_to((backend_dir / "public").resolve())
        public_url = normalize_base_url(ctx.api_base) + "/" + str(rel).replace(os.sep, "/") + "/report.html"

    return {
        "out_dir": str(out_dir),
        "report_md": str(out_dir / "report.md"),
        "report_html": str(out_dir / "report.html"),
        "report_json": str(out_dir / "report.json"),
        "new_logs_csv": str(out_dir / "new_logs.csv"),
        "new_trades_csv": str(out_dir / "new_trades.csv"),
        "public_url": public_url or "",
    }


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="AutoFinX Future Trade API/artisan test runner")
    p.add_argument("--api-base", default=os.getenv("AUTOFINX_API_BASE", "http://127.0.0.1:8000"), help="Backend base URL, e.g. https://example.com")
    p.add_argument("--email", default=os.getenv("AUTOFINX_EMAIL", ""), help="Login email")
    p.add_argument("--password", default=os.getenv("AUTOFINX_PASSWORD", ""), help="Login password")
    p.add_argument("--otp", default=os.getenv("AUTOFINX_OTP", ""), help="2FA/OTP code if login requires it")
    p.add_argument("--token", default=os.getenv("AUTOFINX_TOKEN", ""), help="Existing Sanctum bearer token")
    p.add_argument("--workspace", default=os.getenv("AUTOFINX_WORKSPACE", ""), help="Workspace UUID. If omitted, first /auth/me workspace is used.")
    p.add_argument("--backend-dir", default=os.getenv("AUTOFINX_BACKEND_DIR", os.getcwd()), help="Laravel backend directory containing artisan")
    p.add_argument("--php-binary", default=os.getenv("PHP_BINARY", ""), help="PHP binary path. Defaults to php from PATH")
    p.add_argument("--out-dir", default=os.getenv("AUTOFINX_TEST_OUT_DIR", ""), help="Output directory. Defaults to backend/public/autofinx-test-reports if available.")
    p.add_argument("--duration", type=int, default=int(os.getenv("AUTOFINX_TEST_DURATION", "120")), help="Total artisan tick duration in seconds")
    p.add_argument("--tick-sleep-ms", type=int, default=int(os.getenv("AUTOFINX_TICK_SLEEP_MS", "1000")), help="Sleep between tick loop iterations")
    p.add_argument("--run-artisan", action="store_true", help="Run local artisan diagnostics/tick/audit workflow")
    p.add_argument("--execute", action="store_true", help="Allow future-trade:tick to open/close positions. Use only in testnet unless --allow-live.")
    p.add_argument("--allow-live", action="store_true", help="Do not block live mode. DANGEROUS: may place real orders if config is live.")
    p.add_argument("--run-live-feed", action="store_true", help="Run bounded future-trade:live-feed before tick. Uses --testnet unless --allow-live.")
    p.add_argument("--symbols", default=os.getenv("AUTOFINX_SYMBOLS", ""), help="Optional comma-separated symbols for live-feed/reconcile")
    p.add_argument("--test-connection", action="store_true", help="Call API future-trade/test-connection endpoint")
    p.add_argument("--run-backtest", action="store_true", help="Run future-trade:backtest after runtime test")
    p.add_argument("--backtest-from", default=os.getenv("AUTOFINX_BACKTEST_FROM", ""), help="Backtest start date YYYY-MM-DD")
    p.add_argument("--backtest-to", default=os.getenv("AUTOFINX_BACKTEST_TO", ""), help="Backtest end date YYYY-MM-DD")
    p.add_argument("--capital", default=os.getenv("AUTOFINX_BACKTEST_CAPITAL", "1000"), help="Backtest capital USDT")
    p.add_argument("--backtest-timeout", default=os.getenv("AUTOFINX_BACKTEST_TIMEOUT", "360"), help="Backtest command timeout seconds")
    p.add_argument("--no-login", action="store_true", help="Skip login and only use token/public access")
    return p.parse_args()


def main() -> int:
    args = parse_args()
    run_id = "autofinx-ft-" + utcnow().strftime("%Y%m%d-%H%M%S")
    api_base = normalize_base_url(args.api_base)
    ctx = TestContext(run_id=run_id, started_at=utcnow(), api_base=api_base, workspace=args.workspace or None, mode="future-trade", args=args)
    client = ApiClient(api_base, token=args.token)

    print(f"[{stamp()}] AutoFinX Future Trade Test Runner started")
    print(f"Run ID: {run_id}")
    print(f"API base: {api_base}")

    try:
        if not args.no_login:
            login(ctx, client)
        resolve_workspace(ctx, client)
        if not ctx.workspace:
            print("Workspace is required. Use --workspace YOUR_UUID or valid login.", file=sys.stderr)
        ctx.before = api_snapshot(ctx, client, "BEFORE") if ctx.workspace else {}
        maybe_test_connection(ctx, client)
        if args.run_artisan:
            run_artisan_workflow(ctx)
        elif not args.run_artisan:
            ctx.warnings.append("--run-artisan not provided; report is API snapshot only. Add --run-artisan to actually run diagnostics/tick/audit on the server.")
        ctx.after = api_snapshot(ctx, client, "AFTER") if ctx.workspace else {}
        ctx.summary = analyze(ctx)
        paths = save_reports(ctx)
        verdict = "FAIL" if ctx.summary.get("failures") else ("WARN" if ctx.summary.get("warnings") else "PASS")
        print("\n=== AutoFinX Test Completed ===")
        print(f"Verdict: {verdict}")
        print(f"Report folder: {paths['out_dir']}")
        print(f"HTML report:   {paths['report_html']}")
        print(f"JSON report:   {paths['report_json']}")
        print(f"New logs CSV:  {paths['new_logs_csv']}")
        print(f"New trades CSV:{paths['new_trades_csv']}")
        if paths.get("public_url"):
            print(f"Public URL:    {paths['public_url']}")
        if ctx.summary.get("warnings"):
            print("\nWarnings:")
            for item in ctx.summary["warnings"]:
                print(f"- {item}")
        if ctx.summary.get("failures"):
            print("\nFailures:")
            for item in ctx.summary["failures"]:
                print(f"- {item}")
        return 1 if ctx.summary.get("failures") else 0
    except KeyboardInterrupt:
        print("Interrupted by user", file=sys.stderr)
        return 130
    except Exception:
        ctx.failures.append("Unhandled test-runner exception. See traceback below.")
        traceback.print_exc()
        try:
            ctx.summary = analyze(ctx)
            save_reports(ctx)
        except Exception:
            pass
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
