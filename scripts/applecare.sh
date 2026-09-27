#!/bin/sh
# AppleCare check-in. Rewrite the plist at most once an hour so MunkiReport uploads it.
# The server decides whether that upload should call Apple.

# Get the cache directory (same pattern as other modules)
DIR=$(/usr/bin/dirname $0)
PLIST="$DIR/cache/applecare.plist"
CURRENT_TIME=$(/bin/date +%s)

# Ensure cache directory exists
/bin/mkdir -p "$DIR/cache"

# Hourly stamp. A changed plist hash is what makes MunkiReport upload this module.
LAST_CHECKIN=$(/usr/bin/defaults read "$PLIST" checkin_timestamp 2>/dev/null || echo "0")

if [ -z "$LAST_CHECKIN" ] || [ "$LAST_CHECKIN" = "0" ] || [ "$CURRENT_TIME" -ge $((LAST_CHECKIN + 3600)) ]; then
	/usr/bin/defaults write "$PLIST" checkin_timestamp -int "$CURRENT_TIME"
	/usr/bin/defaults delete "$PLIST" next_sync_timestamp 2>/dev/null || true
fi
