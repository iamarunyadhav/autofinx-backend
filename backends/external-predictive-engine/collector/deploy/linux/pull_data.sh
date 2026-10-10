#!/usr/bin/env bash
# OWNER-RUN, on the OWNER'S PC (Git Bash / WSL / Linux / macOS). Pulls the server data to a local folder.
# Usage:  bash pull_data.sh user@server /d/autofinx-collector-server [remote_base_dir]
# Safe to repeat daily: rsync only transfers new/changed files. The newest hour file is still being written;
# it is re-copied on the next pull. Never run analysis on the last hour present.
# Needs rsync on both sides. If your PC has no rsync, use the tar alternative in README_DEPLOY.md.
set -euo pipefail
REMOTE="${1:?usage: pull_data.sh user@server /local/dir [remote_base]}"
LOCAL="${2:?usage: pull_data.sh user@server /local/dir [remote_base]}"
RBASE="${3:-autofinx-collector}"          # relative to the remote home dir
mkdir -p "$LOCAL"
rsync -av --partial --human-readable --info=progress2 \
  --exclude 'logs/' --exclude 'collector.lock' --exclude 'STOP' --exclude '*.tmp' \
  -e "ssh -o ServerAliveInterval=30" \
  "$REMOTE:$RBASE/data/" "$LOCAL/"
echo "done. Local copy: $LOCAL   (raw/, gaps.jsonl, clock.jsonl, stats/, reports/, heartbeat.json)"
