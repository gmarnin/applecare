#!/bin/sh
# AppleCare auto-sync with randomized intervals (10-14 days)
# Similar to supported_os and firmware modules

# Get the cache directory (same pattern as other modules)
DIR=$(/usr/bin/dirname $0)
PLIST="$DIR/cache/applecare.plist"
CURRENT_TIME=$(/bin/date +%s)

# Ensure cache directory exists
/bin/mkdir -p "$DIR/cache"

# Get next sync timestamp (defaults to 0 if plist doesn't exist or key is missing)
NEXT_SYNC=$(/usr/bin/defaults read "$PLIST" next_sync_timestamp 2>/dev/null || echo "0")

# If timestamp is missing/empty or has elapsed, update plist (this will trigger checkin via hash change)
if [ -z "$NEXT_SYNC" ] || [ "$NEXT_SYNC" = "0" ] || [ "$CURRENT_TIME" -ge "$NEXT_SYNC" ]; then
	# Calculate random seconds between 10 and 14 days (864000 to 1209600 seconds)
	# Using $RANDOM which gives 0-32767, multiply by 11 to get 0-360437, then modulo 345600 to get 0-345600 (4 day range)
	RANDOM_SECONDS=$((864000 + (($RANDOM * 11) % 345600)))
	NEXT_SYNC_TIMESTAMP=$((CURRENT_TIME + RANDOM_SECONDS))
	
	/usr/bin/defaults write "$PLIST" next_sync_timestamp "$NEXT_SYNC_TIMESTAMP"
fi
