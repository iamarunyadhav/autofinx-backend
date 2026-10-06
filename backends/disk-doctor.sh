#!/usr/bin/env bash
# Finds what fills the disk and (with --clean) removes only the safe things.
#   bash disk-doctor.sh           report only (nothing is deleted)
#   bash disk-doctor.sh --clean   delete the safe items listed below
# Safe = old ML export temp files (no trainer running), rotated/old logs, package caches,
# journal beyond 200M, /tmp files older than 3 days. Never touches: database files,
# models, .env, backups, other sites. Biggest items it cannot judge are only REPORTED.
set -uo pipefail
APP="${APP:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
CLEAN=0; [ "${1:-}" = "--clean" ] && CLEAN=1
[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 2; }
used() { df -P / | awk 'NR==2{print $5 " used, " $4/1024/1024 " GB free"}'; }
echo "Disk before: $(used)"
echo; echo "== Top folders (one level each, 60s limit per scan, low priority)"
for base in /home /var /var/lib /usr /opt /root /backup /tmp; do
  [ -d "$base" ] && timeout 60 nice -n 19 ionice -c3 du -xh --max-depth=1 "$base" 2>/dev/null | sort -rh | sed -n '2,5p'
done
echo; echo "== Files over 300 MB (90s limit)"
timeout 90 nice -n 19 ionice -c3 find / -xdev -type f -size +300M -printf '%s %p
' 2>/dev/null | sort -rn | head -12 | awk '{printf "  %6d MB  %s
", $1/1048576, $2}'
echo; echo "== Known big spots"
for d in /var/lib/mysql /var/log /var/lib/mysql/*binlog* /backup /home/backup /home/zcadmin /tmp "$APP/storage/logs" "$APP/storage/app/ml-shadow"; do
  [ -e "$d" ] && printf '  %8s  %s\n' "$(du -sh "$d" 2>/dev/null | cut -f1)" "$d"
done
echo; echo "== Biggest tables (MB)"
mysql -N -e "SELECT CONCAT(table_schema,'.',table_name,'  ',ROUND((data_length+index_length)/1024/1024),' MB') FROM information_schema.tables WHERE table_schema NOT IN ('mysql','information_schema','performance_schema','sys') ORDER BY (data_length+index_length) DESC LIMIT 8" 2>/dev/null
echo; echo "== Safe cleanup candidates"
trainer=$(pgrep -f 'ml:train|train_side_classifier' | wc -l)
exp="$APP/storage/app/ml-shadow/exports"
if [ -d "$exp" ]; then
  n=$(find "$exp" -type f -mmin +120 | wc -l); sz=$(find "$exp" -type f -mmin +120 -printf '%s\n' | awk '{s+=$1} END{printf "%d", s/1048576}')
  echo "  ML export temp files older than 2h: $n file(s), ${sz} MB $([ "$trainer" -gt 0 ] && echo '(a trainer is running: skipped)')"
  [ "$CLEAN" = 1 ] && [ "$trainer" -eq 0 ] && find "$exp" -type f -mmin +120 -delete && echo "    deleted."
fi
oldlogs=$(find "$APP/storage/logs" -type f -name '*.log' -mtime +7 ! -name laravel.log 2>/dev/null)
echo "  storage/logs files older than 7 days: $(echo -n "$oldlogs" | grep -c .)"
[ "$CLEAN" = 1 ] && [ -n "$oldlogs" ] && echo "$oldlogs" | xargs -r rm -f && echo "    deleted."
lsz=$(du -m "$APP/storage/logs/laravel.log" 2>/dev/null | cut -f1)
echo "  laravel.log: ${lsz:-0} MB $([ "${lsz:-0}" -gt 200 ] && echo '(big: set LOG_LEVEL=warning in .env)')"
if [ "${lsz:-0}" -gt 200 ] && [ "$CLEAN" = 1 ]; then tail -n 20000 "$APP/storage/logs/laravel.log" > /tmp/laravel.keep && cat /tmp/laravel.keep > "$APP/storage/logs/laravel.log" && chown zcadmin:zcadmin "$APP/storage/logs/laravel.log"; rm -f /tmp/laravel.keep; echo "    trimmed to the last 20000 lines."; fi
echo "  journal: $(journalctl --disk-usage 2>/dev/null | grep -o '[0-9.]*[MG]' | head -1)"
[ "$CLEAN" = 1 ] && journalctl --vacuum-size=200M >/dev/null 2>&1
echo "  package cache: $(du -sh /var/cache/dnf /var/cache/yum 2>/dev/null | awk '{printf "%s ", $1}')"
[ "$CLEAN" = 1 ] && { dnf clean all >/dev/null 2>&1 || yum clean all >/dev/null 2>&1; }
echo "  /tmp files older than 3 days: $(find /tmp -type f -mtime +3 2>/dev/null | wc -l)"
[ "$CLEAN" = 1 ] && find /tmp -type f -mtime +3 -delete 2>/dev/null
echo; echo "Disk after:  $(used)"
[ "$CLEAN" = 0 ] && echo "Nothing deleted. To apply the safe cleanup: bash disk-doctor.sh --clean"
