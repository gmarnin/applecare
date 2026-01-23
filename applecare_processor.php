<?php

use munkireport\processors\Processor;
use CFPropertyList\CFPropertyList;

class Applecare_processor extends Processor
{
    /**
     * Force database reconnection
     */
    private function reconnectDatabase()
    {
        try {
            // Get the Eloquent connection and force reconnect
            /** @var \Illuminate\Database\Connection $connection */
            $connection = Applecare_model::getConnectionResolver()->connection();
            $connection->reconnect();
        } catch (\Exception $e) {
            // Log but don't throw - let the retry logic handle it
            error_log("AppleCare: Database reconnect attempt: " . $e->getMessage());
        }
    }

    public function run($data)
    {
        try {
            $modelData = ['serial_number' => $this->serial_number];
            $trigger_sync = false;

            // Check if we are processing a plist (new method) or text (legacy)
            if (!is_array($data)) {
                // Try to parse as plist first
                try {
                    $parser = new CFPropertyList();
                    $parser->parse($data);
                    $plist = $parser->toArray();
                    
                    // Check if sync should be triggered
                    // The client script updates the plist when next_sync_timestamp is 0 or has elapsed
                    // This causes a hash change, which triggers a check-in
                    // When the plist is sent, we should sync immediately
                    if (isset($plist['next_sync_timestamp'])) {
                        // Plist was sent, which means script just updated it = time to sync
                        $trigger_sync = true;
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

            // Also trigger sync on first check-in (when no record exists for this serial_number)
            if (!$trigger_sync) {
                // Check with retry logic for connection timeouts
                $max_retries = 3;
                $existing = false;
                for ($retry = 0; $retry < $max_retries; $retry++) {
                    try {
                        $existing = Applecare_model::where('serial_number', $this->serial_number)->exists();
                        break; // Success, exit retry loop
                    } catch (\Exception $e) {
                        $error_message = $e->getMessage();
                        if (strpos($error_message, 'server has gone away') !== false || 
                            strpos($error_message, 'Lost connection') !== false ||
                            strpos($error_message, '2006') !== false) {
                            if ($retry < $max_retries - 1) {
                                // Force reconnect before retry
                                $this->reconnectDatabase();
                                usleep(500000); // 0.5 seconds before retry
                                continue;
                            }
                        }
                        throw $e; // Rethrow if not a connection error or max retries reached
                    }
                }
                if (!$existing) {
                    // First check-in for this device - trigger sync
                    $trigger_sync = true;
                }
            }

            // Trigger sync if requested (same code path as Sync Now)
            // Note: We don't save client data to applecare table because it requires 'id' from API
            // The sync will fetch data from API and create records with proper 'id' values
            if ($trigger_sync) {
                try {
                    require_once __DIR__ . '/lib/applecare_helper.php';
                    $helper = new \munkireport\module\applecare\Applecare_helper();
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
