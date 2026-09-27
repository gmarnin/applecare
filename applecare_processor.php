<?php

use munkireport\processors\Processor;
use CFPropertyList\CFPropertyList;

class Applecare_processor extends Processor
{
    public function run($data)
    {
        try {
            $payload_received = false;

            // Check if we are processing a plist (new method) or text (legacy)
            if (!is_array($data)) {
                // Try to parse as plist first
                try {
                    $parser = new CFPropertyList();
                    $parser->parse($data);
                    $plist = $parser->toArray();

                    // The client rewrites this plist about once an hour.
                    // next_sync_timestamp is the previous 10-14 day script.
                    if (isset($plist['checkin_timestamp']) || isset($plist['next_sync_timestamp'])) {
                        $payload_received = true;
                    }
                } catch (\Exception $e) {
                    // Not a plist - legacy text format not supported
                    // The applecare table requires 'id' from API, so we can't save client data directly
                    error_log("AppleCare: Error parsing plist for {$this->serial_number}: " . $e->getMessage());
                }
            } else {
                // Array data - not used in current implementation
                // The applecare table stores API data with 'id' as primary key
                // Client data cannot be saved directly without an 'id' from the API
            }

            // The server decides. A recent last_fetched or 404 waits out the interval.
            // A 429 or failed fetch leaves no stamp, so the next hourly upload tries again.
            if ($payload_received) {
                try {
                    require_once __DIR__ . '/lib/applecare_helper.php';
                    $helper = new \munkireport\module\applecare\Applecare_helper();
                    if ($helper->clientSyncDue($this->serial_number)) {
                        $result = $helper->syncSerial($this->serial_number);

                        if ($result && isset($result['success']) && $result['success']) {
                            // Success - no logging needed
                        } else {
                            $message = isset($result['message']) ? $result['message'] : 'Unknown error';
                            // Don't log "API not configured" - this is expected for devices without config
                            // Don't log "SKIP (HTTP 404)" - this is expected for devices not found in Apple Business/School Manager
                            if (strpos($message, 'AppleCare API not configured') === false &&
                                strpos($message, 'SKIP (HTTP 404)') === false) {
                                error_log("AppleCare: Sync failed for {$this->serial_number}: $message");
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("AppleCare: Exception during sync for {$this->serial_number}: " . $e->getMessage());
                }
            }

            return $this;
        } catch (\Exception $e) {
            // Log error but don't fail the check-in
            error_log("AppleCare: Error in processor run() for {$this->serial_number}: " . $e->getMessage());
            // Still return $this to allow check-in to complete
            return $this;
        }
    }
}
