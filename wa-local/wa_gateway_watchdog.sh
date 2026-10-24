#!/bin/bash
# Old 15-minute watchdog of the main gateway, kept only so the existing crontab line does no harm.
# It no longer holds the gateway token and never starts or kills a gateway itself: watchdog.php (every minute, from
# run_minute.sh) is the one watchdog of the main number. It reads the token from the dashboard settings (wa-inbox/.data/config.json).
# To retire it: remove the "*/15 ... wa_gateway_watchdog.sh" line from crontab (owner's OK), then delete /home/iptvtest/wa_gateway_watchdog.sh.
DIR="$(cd "$(dirname "$0")" && pwd)"
# watchdog.php takes its own lock (a second copy exits at once)
exec /usr/local/bin/php "$DIR/watchdog.php" >/dev/null 2>&1
