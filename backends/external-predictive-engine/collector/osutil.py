"""Small OS helpers (Windows first, POSIX fallback)."""
from __future__ import annotations

import os
import sys


def pid_alive(pid: int) -> bool:
    if not pid or pid <= 0:
        return False
    if sys.platform == "win32":
        import ctypes
        k32 = ctypes.windll.kernel32
        h = k32.OpenProcess(0x1000, False, int(pid))  # PROCESS_QUERY_LIMITED_INFORMATION
        if not h:
            return False
        try:
            code = ctypes.c_ulong()
            if not k32.GetExitCodeProcess(h, ctypes.byref(code)):
                return False
            return code.value == 259  # STILL_ACTIVE
        finally:
            k32.CloseHandle(h)
    try:
        os.kill(pid, 0)
        return True
    except PermissionError:
        return True  # process exists but belongs to another user
    except OSError:
        return False


def set_low_priority(mode: str = "below_normal") -> str:
    """Lower this process's CPU priority. Never raises.
    modes: below_normal | idle | unchanged (also "none"). On POSIX below_normal=nice+10, idle=nice+19;
    a service manager that already sets Nice= can use "unchanged"."""
    try:
        if mode in ("unchanged", "none"):
            return "unchanged"
        if sys.platform == "win32":
            import ctypes
            cls = {"below_normal": 0x4000, "idle": 0x40}.get(mode)
            if cls is None:
                return "unchanged"
            ctypes.windll.kernel32.SetPriorityClass(ctypes.windll.kernel32.GetCurrentProcess(), cls)
            return mode
        inc = {"below_normal": 10, "idle": 19}.get(mode)
        if inc is None:
            return "unchanged"
        os.nice(inc)  # increments, clamped at 19 by the kernel
        return f"nice+{inc}"
    except Exception as e:  # pragma: no cover
        return f"failed:{e!r}"


def install_stop_signals(callback) -> list:
    """Call callback() on SIGTERM/SIGINT (POSIX: systemd/supervisor stop) and SIGBREAK (Windows).
    Must be called from the main thread, inside the running event loop for POSIX. Never raises.
    Returns the list of signal names installed."""
    import signal
    done = []
    names = ["SIGTERM", "SIGINT"] + (["SIGBREAK"] if sys.platform == "win32" else [])
    for n in names:
        sig = getattr(signal, n, None)
        if sig is None:
            continue
        try:
            signal.signal(sig, lambda *_a: callback())
            done.append(n)
        except (ValueError, OSError):  # not main thread / unsupported
            pass
    return done
