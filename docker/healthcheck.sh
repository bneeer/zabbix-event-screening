#!/bin/sh
# Healthy when the daemon touched its heartbeat file recently.
# Tolerance = 3 poll intervals (min 180s) so slow cycles are not flagged.
set -eu

FILE="${DAEMON_HEARTBEAT_FILE:-/var/lib/zabbix-event-screening/daemon.heartbeat}"
INTERVAL="${DAEMON_POLL_INTERVAL:-60}"
TOLERANCE=$(( INTERVAL * 3 ))
[ "$TOLERANCE" -lt 180 ] && TOLERANCE=180
# A ticket screening can legitimately take several minutes.
STALE="${DAEMON_HEALTH_MAX_AGE:-$(( TOLERANCE > 900 ? TOLERANCE : 900 ))}"

[ -f "$FILE" ] || { echo "heartbeat file missing: $FILE"; exit 1; }

LAST=$(cat "$FILE" 2>/dev/null || echo 0)
NOW=$(date +%s)
AGE=$(( NOW - LAST ))

if [ "$AGE" -gt "$STALE" ]; then
    echo "heartbeat stale: ${AGE}s > ${STALE}s"
    exit 1
fi

echo "ok (${AGE}s)"
