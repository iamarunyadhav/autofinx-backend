# Collector on a Linux server - deployment guide (simple English)

The collector records PUBLIC Binance futures market data for research. It uses no API keys, no .env, no orders, no database.
It only makes outbound connections. Format and fields: `docs/ml-engine-v1/COLLECTOR_V1.md`.
Everything below is run by YOU. The agent that prepared this bundle never had access to any server.

## WARNING - read first
**Do NOT run this on the production trading server if it is under load** (it was at load 27-29 earlier).
The collector is light (see numbers below), but one more always-on process, a few thousand small disk writes per minute
and 6 websocket streams can hurt a host that is already overloaded, and a slow host also gives bad data (late timestamps, gaps).
**Recommended: a separate small VPS: 1 vCPU / 1-2 GB RAM / 40 GB disk, Ubuntu/Debian 22.04+, Python 3.10+.**
If you insist on the production host: run it under a dedicated non-root user (e.g. `collector`), use the limits in the
systemd unit (Nice 19, CPUWeight 5, CPUQuota 60 %, IO class idle, MemoryMax 512 M), keep `process_priority: "idle"`,
and **watch `uptime` / `top` for the first full day**; if load goes up noticeably, stop it (`bash stop.sh`) and move to a VPS.

## Resource expectations (measured on the laptop, 2026-10-08)
- Disk: about 1.7 KB/s compressed = about 0.15 GB/day = about 4.5 GB per 30 days; in volatile markets up to about 15 GB per 30 days.
  A 40 GB disk is enough for the 30-day pilot. Logs are small (rotating, max about 30 MB).
- CPU: target well under 5 % of one core on average (gzip level 5 + JSON parsing of a few hundred small messages per second;
  bookTicker is aggregated to 1 s before storing). Check with `top -p $(cat ~/autofinx-collector/data/collector.lock)`.
- RAM: about 60-120 MB. The unit hard-caps it at 512 MB.
- Network: roughly 20-60 KB/s inbound (raw websocket traffic), almost nothing outbound.
- Disk guard: the collector pauses writing when free space is below `min_free_gb` (config; Linux default **8 GB**, laptop 20 GB)
  and resumes at `min_free_gb + resume_margin_gb`. It logs `disk_guard_stop` / `disk_guard_resume` in `gaps.jsonl`. Change the floor in
  `~/autofinx-collector/collector.config.json` (or `MIN_FREE_GB=12 bash install.sh` on a first install), then restart.

## Firewall
Outbound only. Allow HTTPS (tcp 443) to `fstream.binance.com` (websocket) and `fapi.binance.com` (REST: open interest, time).
Nothing needs to listen on any port. Some hosting regions are blocked by Binance (HTTP 451 / 403): pick a VPS region that can reach it
(the smoke test in step 3 tells you immediately).

## 1. Copy the code to the server (from your PC)
Only the `collector/` folder is needed. From the repo root on your PC (Git Bash / WSL):
```
ssh user@SERVER 'mkdir -p ~/autofinx-collector/app/external-predictive-engine'
scp -r autofinx-backend/backends/external-predictive-engine/collector user@SERVER:~/autofinx-collector/app/external-predictive-engine/
```
(Delete any `__pycache__` folders if you like; harmless.) Use a normal user, NOT root. Create one if needed: `sudo adduser collector`.

## 2. Install (no root)
```
ssh user@SERVER
cd ~/autofinx-collector/app/external-predictive-engine/collector/deploy/linux
bash install.sh
```
It creates `~/autofinx-collector/venv`, installs only `websockets==16.1.1` (+ pytest for the tests), writes
`~/autofinx-collector/collector.config.json` (data in `~/autofinx-collector/data`), runs the unit tests, then runs a 60 s smoke test in a
separate folder and prints PASSED/FAILED and `status`. It never reads `.env` or any secret and refuses to run as root.

## 3. Start it (pick ONE)
**A. Simplest, no root, no service** (survives logout, not reboot):
```
bash run_nohup.sh
```
**B. systemd (recommended, restarts on crash and at boot).** Root is needed ONLY to copy the unit file; the collector runs as your user:
```
sudo bash install_service.sh --user collector --systemd --start
```
**B2. systemd user-level** (no root for the service; one-time `sudo loginctl enable-linger <user>` for boot start): see the header of
`autofinx-collector.user.service`.
**C. Supervisor** (the production host uses Supervisor; still a non-root user):
```
sudo bash install_service.sh --user collector --supervisor --start
```
Both services have: Restart always, nice 19, low IO priority, memory cap (systemd), clean stop via the STOP file / SIGTERM.

## 4. Check status
```
cd ~/autofinx-collector/app/external-predictive-engine
~/autofinx-collector/venv/bin/python -m collector --config ~/autofinx-collector/collector.config.json status
systemctl status autofinx-collector        # (systemd)      journalctl -u autofinx-collector -f
supervisorctl status autofinx-collector    # (supervisor)
```
Good = `RUNNING`, heartbeat age under 15 s, `market=up`, `public=up`, every stream showing a small "last ... ago".

## 5. Verify the three silent-drop streams
The old Binance URL silently dropped `aggTrade`, `markPrice` and `forceOrder`; this collector uses the `/market` and `/public` URLs and
alarms on silence. Check them yourself after 5-10 minutes:
```
bash check_streams.sh
```
- `aggTrade@btcusdt` etc.: count growing, last message under a few seconds ago.
- `markPrice@...`: last message about 1 s ago.
- `forceOrder`: liquidations are rare; count can stay 0 for minutes. A silence alarm only fires after 1800 s. A value above 0 within the
  first hours is the proof it works. `grep forceOrder` in `status` and see files under `data/raw/forceOrder/`.
- `grep -c silent_stream ~/autofinx-collector/data/gaps.jsonl` should be 0 or very rare (the laptop had hundreds - those were sleep/network loss).

## 6. Check the clock
Timestamps use the exchange event time as the key, but the local receive time (`rt`) must also be good.
```
bash check_clock.sh
timedatectl                       # want:  System clock synchronized: yes
chronyc tracking                  # want:  System time within ~0.05 s
sudo apt install chrony           # if not synchronized (Debian/Ubuntu); then: sudo systemctl enable --now chrony
```
The collector measures its own offset vs Binance every 5 min into `data/clock.jsonl` (`offset_ms > 0` = this clock is BEHIND Binance;
the laptop was about 6.2 s behind; a synced server should be under 50 ms) and logs `clock_warning` when above 1 s.

## 7. Stop / restart
```
bash stop.sh                                 # clean stop (STOP file, waits up to 40 s) - works for every start method
sudo systemctl stop autofinx-collector       # systemd (runs the same clean stop)
sudo supervisorctl stop autofinx-collector   # supervisor (SIGTERM, handled cleanly)
```
After a crash or reboot just start it again; a stale STOP/lock file with no live process is cleaned automatically. Restarting in the
same hour is safe (a new gzip member is appended). Update the code = stop, copy the new `collector/` folder, start.

## 8. Daily pull to your PC (owner-run)
On your PC (Git Bash / WSL, needs rsync; Windows `rsync` comes with Git for Windows only in some builds - otherwise use the tar way):
```
bash pull_data.sh user@SERVER /d/autofinx-collector-server
```
Repeat any time (only new/changed files are copied). Do not analyse the newest hour (still open).
Tar alternative (no rsync; run on your PC, copies everything except logs; re-run copies it all again, so prefer rsync for daily use):
```
ssh user@SERVER 'cd ~/autofinx-collector/data && tar -cf - raw gaps.jsonl clock.jsonl stats reports' | tar -xf - -C /d/autofinx-collector-server
```
Verify a pull: compare sizes `du -sh` on both sides; test one file with `gzip -t file.jsonl.gz` (the newest hour may show an unexpected
end of file - that is only the open member).

**Retention / cleanup (server).** Keep everything for the 30-day pilot if the disk allows (about 4.5-15 GB). To free space AFTER you have
pulled and verified the data on your PC (and ideally backed it up): `bash cleanup_old.sh --days 14` is a dry run; add `--yes` to delete
raw day folders older than 14 days. It never deletes `gaps.jsonl`, `clock.jsonl`, `stats/`, `reports/`. If free space reaches the guard floor
the collector stops writing by itself (and logs it) - it never fills the disk.

## 9. Keeping the laptop dataset and joining it with the server data
- The laptop data in `D:\autofinx-collector\data` (about 2 days, 2026-10-08 to now, with gaps from sleep/network loss: see its
  `gaps.jsonl`) is **kept untouched** as dataset `laptop`. The server pull goes to a **separate folder** as dataset `server`. Never
  merge folders by copying files (same file names would collide). The format of both is identical.
- Stop the laptop collector when the server is confirmed good: first let both run in parallel for 1-2 hours (cross-check: aggTrade ids `a`
  must match for the overlap, and the server should have fewer/no gaps), then `python -m collector stop` on the laptop. The first server start
  time is the `collector_start` entry in the server `gaps.jsonl`.
- Analysis loads the two datasets and concatenates per kind and symbol with the **exchange event time `et` (ms) as the key** (for aggTrade
  also the trade id `a`, for dedupe in the overlap: keep one record per `a`; prefer the server copy). Receive time `rt` is NOT used for
  ordering across datasets; both datasets store `rt` and `clock.jsonl` (offset vs Binance), so latency = `rt + offset - et` can be corrected
  per dataset (laptop offset was about +6.2 s, so laptop `rt` is unreliable without that correction).
- The period between the last laptop record and the first server record, and every hole inside each dataset, is **marked as a gap** from the
  two `gaps.jsonl` files (`ws_disconnect`/`ws_reconnected`, `silent_stream`, `missing_agg_ids`, `collector_start/stop`) and the per-day
  `reports/*.json`. Gap periods are excluded from any statistic; they are never interpolated or filled.
- The D-045 pilot rule is unchanged: descriptive checks and execution-realism measurements only, no edge claim.

## Known limits (honest list)
- The deployment and shell scripts were written and syntax-checked on Windows. The code changes were tested on Windows only (SIGBREAK path).
  Real Linux behaviour (SIGTERM, systemd limits, supervisor, rsync) is **untested until you run it**; the smoke test in install.sh and
  `check_streams.sh` are the proof.
- A Linux VPS in some countries cannot reach Binance futures; the smoke test shows this at once.
