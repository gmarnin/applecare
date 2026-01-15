<?php 

use Symfony\Component\Yaml\Yaml;

/**
 * applecare class
 *
 * @package munkireport
 * @author gmarnin
 **/
class Applecare_controller extends Module_controller
{
    public function __construct()
    {
        // Store module path
        $this->module_path = dirname(__FILE__);
    }
    
    /**
     * Get Munki ClientID for a serial number
     *
     * @param string $serial_number
     * @return string|null ClientID or null if not found
     */
    private function getClientId($serial_number)
    {
        try {
            $machine = new \Model();
            $sql = "SELECT munkiinfo_value 
                    FROM munkiinfo 
                    WHERE serial_number = ? 
                    AND munkiinfo_key = 'ClientIdentifier' 
                    LIMIT 1";
            $result = $machine->query($sql, [$serial_number]);
            if (!empty($result) && isset($result[0])) {
                return $result[0]->munkiinfo_value ?? null;
            }
        } catch (\Exception $e) {
            // Silently fail - ClientID is optional
        }
        return null;
    }

    /**
     * Get org-specific AppleCare config with fallback
     * 
     * Looks for org-specific env vars based on ClientID prefix:
     * - If ClientID = "abcd-efg", looks for ABCD_APPLECARE_API_URL and ABCD_APPLECARE_CLIENT_ASSERTION
     * - Falls back to APPLECARE_API_URL and APPLECARE_CLIENT_ASSERTION if not found
     *
     * @param string $serial_number
     * @return array ['api_url' => string, 'client_assertion' => string, 'rate_limit' => int] or null if not configured
     */
    private function getAppleCareConfig($serial_number)
    {
        // Get ClientID for this device
        $client_id = $this->getClientId($serial_number);
        
        $api_url = null;
        $client_assertion = null;
        $rate_limit = 20; // Default
        
        if (!empty($client_id)) {
            // Extract prefix from ClientID (e.g., "abcd-efg" -> "ABCD")
            // Take everything before first hyphen or use entire ClientID if no hyphen
            $parts = explode('-', $client_id, 2);
            $prefix = strtoupper($parts[0]);
            
            // Try org-specific config first
            $org_api_url_key = $prefix . '_APPLECARE_API_URL';
            $org_assertion_key = $prefix . '_APPLECARE_CLIENT_ASSERTION';
            $org_rate_limit_key = $prefix . '_APPLECARE_RATE_LIMIT';
            
            $api_url = getenv($org_api_url_key);
            $client_assertion = getenv($org_assertion_key);
            $org_rate_limit = getenv($org_rate_limit_key);
            
            if (!empty($org_rate_limit)) {
                $rate_limit = (int)$org_rate_limit;
            }
        }
        
        // Fallback to default config if org-specific not found
        if (empty($api_url)) {
            $api_url = getenv('APPLECARE_API_URL');
        }
        if (empty($client_assertion)) {
            $client_assertion = getenv('APPLECARE_CLIENT_ASSERTION');
        }
        $default_rate_limit = getenv('APPLECARE_RATE_LIMIT');
        if (!empty($default_rate_limit)) {
            $rate_limit = (int)$default_rate_limit ?: 20;
        }
        
        // Return null if still not configured
        if (empty($api_url) || empty($client_assertion)) {
            return null;
        }
        
        return [
            'api_url' => $api_url,
            'client_assertion' => $client_assertion,
            'rate_limit' => $rate_limit
        ];
    }

    /**
     * Load reseller config and translate ID to name
     *
     * @param string $reseller_id Reseller ID from purchase_source_id
     * @return string Reseller name or original ID if not found
     */
    private function getResellerName($reseller_id)
    {
        if (empty($reseller_id)) {
            return null;
        }
        
        $config_path = APP_ROOT . '/local/module_configs/applecare_resellers.yml';
        if (!file_exists($config_path)) {
            return $reseller_id;
        }
        
        try {
            $config = Yaml::parseFile($config_path);
            
            // Try exact match first
            if (isset($config[$reseller_id])) {
                return $config[$reseller_id];
            }
            
            // Try case-insensitive match
            $reseller_id_upper = strtoupper($reseller_id);
            foreach ($config as $key => $value) {
                if (strtoupper($key) === $reseller_id_upper) {
                    return $value;
                }
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Error loading reseller config: ' . $e->getMessage());
        }
        
        return $reseller_id;
    }

    /**
     * Admin page entrypoint
     *
     * Renders the AppleCare admin form (sync UI)
     */
    public function applecare_admin()
    {
        $obj = new View();
        $obj->view('applecare_admin', [], $this->module_path.'/views/');
    }


    /**
     * Run the sync script and return stdout/stderr
     */
    public function sync()
    {
        // Check if this is a streaming request (SSE)
        $stream = isset($_GET['stream']) && $_GET['stream'] === '1';
        
        if ($stream) {
            return $this->syncStream();
        }

        // Legacy JSON response for backward compatibility
        $scriptPath = realpath($this->module_path . '/sync_applecare.php');

        if (! $scriptPath || ! file_exists($scriptPath)) {
            return $this->jsonError('sync_applecare.php not found', 500);
        }

        // Get MunkiReport root directory
        $mrRoot = defined('APP_ROOT') ? APP_ROOT : dirname(dirname(dirname(dirname(__FILE__))));
        
        if (!is_dir($mrRoot) || !file_exists($mrRoot . '/vendor/autoload.php')) {
            return $this->jsonError('MunkiReport root not found: ' . $mrRoot, 500);
        }
        
        $phpBin = PHP_BINARY ?: 'php';
        // Pass MR root as second argument to the script (script expects $argv[2])
        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($scriptPath) . ' sync ' . escapeshellarg($mrRoot);

        $descriptorSpec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Run from MR root directory so script can find vendor/autoload.php
        $process = @proc_open($cmd, $descriptorSpec, $pipes, $mrRoot);

        if (! is_resource($process)) {
            $error = error_get_last();
            $errorMsg = 'Failed to start sync process';
            if ($error && isset($error['message'])) {
                $errorMsg .= ': ' . $error['message'];
            }
            return $this->jsonError($errorMsg, 500);
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        jsonView([
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ]);
    }

    /**
     * Stream sync output in real-time using Server-Sent Events
     * Calls sync logic directly without proc_open
     */
    private function syncStream()
    {
        // Disable PHP execution time limit for long-running sync
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        
        // Release session lock to prevent blocking other requests
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        // Set headers for Server-Sent Events
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        // Flush output immediately
        if (ob_get_level()) {
            ob_end_clean();
        }
        flush();

        try {
            // Call sync logic directly
            $this->syncAll(function($message, $isError = false) {
                if ($isError) {
                    $this->sendEvent('error', $message);
                } else {
                    $this->sendEvent('output', $message);
                }
            });

            // Send completion event
            $this->sendEvent('complete', [
                'exit_code' => 0,
                'success' => true
            ]);
        } catch (\Exception $e) {
            $this->sendEvent('error', 'Sync failed: ' . $e->getMessage());
            $this->sendEvent('complete', [
                'exit_code' => 1,
                'success' => false
            ]);
        }
    }

    /**
     * Generate access token from client assertion
     * 
     * @param string $client_assertion
     * @param string $api_base_url
     * @param callable $outputCallback Optional callback for output
     * @return string Access token
     */
    private function generateAccessToken($client_assertion, $api_base_url, $outputCallback = null)
    {
        if ($outputCallback === null) {
            $outputCallback = function($message, $isError = false) {};
        }
        
        // Clean up the client assertion
        $client_assertion = trim($client_assertion);
        $client_assertion = preg_replace('/\s+/', '', $client_assertion);
        $client_assertion = trim($client_assertion, '"\'');
        
        // Validate and extract client ID from assertion
        $parts = explode('.', $client_assertion);
        if (count($parts) !== 3) {
            throw new \Exception('Invalid client assertion format. Expected JWT token with 3 parts.');
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $client_id = $payload['sub'] ?? null;

        if (empty($client_id)) {
            throw new \Exception('Could not extract client ID from assertion.');
        }

        // Determine scope based on API URL
        $scope = 'business.api';
        if (strpos($api_base_url, 'api-school') !== false) {
            $scope = 'school.api';
        }

        $outputCallback("✓ Generating access token from client assertion...");
        
        // Generate access token
        $ch = curl_init('https://account.apple.com/auth/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Host: account.apple.com',
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $client_id,
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $client_assertion,
                'scope' => $scope
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            throw new \Exception("cURL error: {$curl_error}");
        }

        if ($http_code !== 200) {
            throw new \Exception("Failed to get access token: HTTP $http_code - $response");
        }

        $data = json_decode($response, true);

        if (!isset($data['access_token'])) {
            throw new \Exception("No access token in response: $response");
        }

        $access_token = $data['access_token'];
        $outputCallback("✓ Access token generated successfully");
        
        return $access_token;
    }

    /**
     * Sync all devices - can be called from web or CLI
     * 
     * @param callable $outputCallback Function to call for output (message, isError)
     * @return void
     */
    private function syncAll($outputCallback = null)
    {
        // Track start time for total duration calculation
        $start_time = time();
        
        // Disable PHP execution time limit for long-running sync
        // (Only if not already set, e.g., in syncStream)
        if (ini_get('max_execution_time') != 0) {
            set_time_limit(0);
            ini_set('max_execution_time', '0');
        }
        
        // Default output callback (for CLI)
        if ($outputCallback === null) {
            $outputCallback = function($message, $isError = false) {
                echo $message . "\n";
            };
        }

        $outputCallback("================================================");
        $outputCallback("AppleCare Sync Tool");
        $outputCallback("================================================");
        $outputCallback("");

        // Get default configuration from environment (for fallback)
        $default_api_base_url = getenv('APPLECARE_API_URL');
        $default_client_assertion = getenv('APPLECARE_CLIENT_ASSERTION');
        $default_rate_limit = (int)getenv('APPLECARE_RATE_LIMIT') ?: 20;

        if (empty($default_client_assertion) && empty($default_api_base_url)) {
            $outputCallback("WARNING: Default APPLECARE_API_URL and APPLECARE_CLIENT_ASSERTION not set.");
            $outputCallback("Multi-org mode: Will use org-specific configs only.");
            $outputCallback("");
        }

        // Generate default access token if default config exists
        $default_access_token = null;
        if (!empty($default_client_assertion) && !empty($default_api_base_url)) {
            // Ensure URL ends with /
            if (substr($default_api_base_url, -1) !== '/') {
                $default_api_base_url .= '/';
            }

            // Generate access token for default config
            try {
                $default_access_token = $this->generateAccessToken($default_client_assertion, $default_api_base_url, $outputCallback);
                $outputCallback("");
            } catch (\Exception $e) {
                $outputCallback("WARNING: Failed to generate default access token: " . $e->getMessage());
                $outputCallback("");
            }
        }

        // Get all devices from database (exact copy from firmware/supported_os)
        $outputCallback("Fetching device list from database...");
        
        try {
            // Use Model class (like firmware/supported_os) instead of Eloquent model
            // Eloquent models don't have the same query() method
            $machine = new \Model();
            $filter = get_machine_group_filter();

            $sql = "SELECT machine.serial_number
                    FROM machine
                    LEFT JOIN reportdata USING (serial_number)
                    $filter";

            // Loop through each serial number for processing
            $devices = [];
            foreach ($machine->query($sql) as $serialobj) {
                $devices[] = $serialobj->serial_number;
            }
        } catch (\Exception $e) {
            throw new \Exception('Database query failed: ' . $e->getMessage());
        }

        $total_devices = count($devices);
        $outputCallback("✓ Found $total_devices devices");
        $outputCallback("");

        if ($total_devices == 0) {
            throw new \Exception('No devices found in database. Devices must check in to MunkiReport first.');
        }

        // Sync counters
        $synced = 0;
        $errors = 0;
        $skipped = 0;
        $requests_made = 0;
        $window_start = time();
        $rate_limit_window = 60; // seconds - default window, may be adjusted by API headers
        
        // Dynamic rate limit tracking (will be updated from API headers if available)
        $current_rate_limit = $default_rate_limit;
        $rate_limit_remaining = null;
        $rate_limit_reset_time = null;

        $outputCallback("");
        $outputCallback("Starting sync...");
        $outputCallback("Initial rate limit: $default_rate_limit calls per minute (will adjust based on API headers)");
        $outputCallback("Estimated time: " . ceil($total_devices / $default_rate_limit) . " minutes");
        $outputCallback("");

        // Use the same sync logic as sync_serial but for all devices
        $device_index = 0;
        $last_heartbeat = time();
        foreach ($devices as $serial) {
            $device_index++;

            // Skip invalid serials
            if (empty($serial) || strlen($serial) < 8) {
                $skipped++;
                continue;
            }

            // Send heartbeat every 30 seconds to keep connection alive
            // This helps prevent timeouts on servers with max_execution_time limits
            $now = time();
            if ($now - $last_heartbeat >= 30) {
                $outputCallback("Heartbeat: Processing device $device_index of $total_devices...");
                $last_heartbeat = $now;
                
                // Flush output to keep connection alive
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }

            $outputCallback("Processing $serial... ");

            try {
                // Get org-specific config for this device (with fallback to default)
                $device_config = $this->getAppleCareConfig($serial);
                if ($device_config) {
                    // Use device-specific config
                    $device_api_url = $device_config['api_url'];
                    $device_rate_limit = $device_config['rate_limit'];
                    
                    // Ensure URL ends with /
                    if (substr($device_api_url, -1) !== '/') {
                        $device_api_url .= '/';
                    }
                    
                    // Generate or get cached token for this org
                    // Use org prefix as cache key
                    $client_id = $this->getClientId($serial);
                    $org_prefix = '';
                    if (!empty($client_id)) {
                        $parts = explode('-', $client_id, 2);
                        $org_prefix = strtoupper($parts[0]);
                    }
                    $token_cache_key = $org_prefix ? "applecare_token_{$org_prefix}" : 'applecare_token_default';
                    
                    // Check if we have a cached token (tokens typically last 1 hour)
                    static $token_cache = [];
                    if (!isset($token_cache[$token_cache_key])) {
                        $device_client_assertion = $device_config['client_assertion'];
                        $token_cache[$token_cache_key] = $this->generateAccessToken($device_client_assertion, $device_api_url, function($msg) {});
                    }
                    $device_access_token = $token_cache[$token_cache_key];
                    
                    // Use device-specific config
                    $result = $this->syncSingleDevice($serial, $device_api_url, $device_access_token, $outputCallback);
                } else {
                    // Use default config if available
                    if ($default_access_token && !empty($default_api_base_url)) {
                        $result = $this->syncSingleDevice($serial, $default_api_base_url, $default_access_token, $outputCallback);
                    } else {
                        $outputCallback("SKIP (no config found)");
                        $skipped++;
                        continue;
                    }
                }
                
                // Handle rate limit (HTTP 429) - wait and retry
                if (isset($result['retry_after']) && $result['retry_after'] > 0) {
                    $wait_time = $result['retry_after'];
                    $outputCallback("Rate limit hit. Waiting {$wait_time}s before continuing...");
                    sleep($wait_time);
                    // Reset rate limit window after waiting
                    $requests_made = 0;
                    $window_start = time();
                    // Retry this device
                    $device_index--; // Decrement to retry same device
                    continue;
                }
                
                // Only count requests towards rate limit if device was successfully synced
                // Skipped devices (404, no coverage, etc.) don't count towards rate limit
                if ($result['success']) {
                    $requests_made += $result['requests'];
                }
                
                // Proactive throttling: Only throttle if we're approaching the limit
                // Use device-specific rate limit if available, otherwise use default
                // Note: $current_rate_limit may have been updated from API headers
                $effective_rate_limit = $device_config ? $device_config['rate_limit'] : $default_rate_limit;
                
                // If we got rate limit info from headers, use that instead
                if (isset($current_rate_limit) && $current_rate_limit > 0) {
                    $effective_rate_limit = $current_rate_limit;
                }
                
                // Only throttle proactively if we're at 80% of limit (to avoid hitting 429)
                $throttle_threshold = (int)($effective_rate_limit * 0.8);
                if ($requests_made >= $throttle_threshold) {
                    $elapsed = time() - $window_start;
                    if ($elapsed < $rate_limit_window) {
                        // Account for the 1 second wait we'll do after the next fetch
                        // So we only need to wait the remaining time minus 1 second
                        $sleep_time = max(0, $rate_limit_window - $elapsed - 1);
                        if ($sleep_time > 0) {
                            $outputCallback("Approaching rate limit ({$requests_made}/{$effective_rate_limit}). Sleeping for {$sleep_time}s...");
                            sleep($sleep_time);
                        }
                    }
                    $requests_made = 0;
                    $window_start = time();
                }
                
                if ($result['success']) {
                    $outputCallback("OK (" . $result['records'] . " coverage records)");
                    $synced++;
                } else {
                    $outputCallback($result['message']);
                    $skipped++;
                }
                
                // Wait 1 second after each fetch (success, 404, or error), but not after "no config found"
                $should_wait = false;
                if ($result['success']) {
                    // Always wait after successful sync
                    $should_wait = true;
                } elseif (isset($result['message'])) {
                    // Wait for 404 and other API responses, but not for "no config found"
                    if (stripos($result['message'], 'SKIP (no config found)') === 0) {
                        $should_wait = false;
                    } else {
                        // Wait for 404, no coverage, and other API responses
                        $should_wait = true;
                    }
                }
                
                if ($should_wait) {
                    sleep(1);
                }
            } catch (\Exception $e) {
                $outputCallback("ERROR (" . $e->getMessage() . ")", true);
                $errors++;
                // Wait 1 second after errors too
                sleep(1);
            }

            // Flush output periodically for streaming
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }

        // Summary
        $end_time = time();
        $total_time = $end_time - $start_time;
        $minutes = floor($total_time / 60);
        $seconds = $total_time % 60;
        $time_display = $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";
        
        $outputCallback("");
        $outputCallback("================================================");
        $outputCallback("Sync Complete");
        $outputCallback("================================================");
        $outputCallback("Total devices: $total_devices");
        $outputCallback("Synced: $synced");
        $outputCallback("Skipped: $skipped");
        $outputCallback("Errors: $errors");
        $outputCallback("Total time: $time_display");
        $outputCallback("================================================");
    }

    /**
     * Sync a single device - extracted from sync_serial for reuse
     * 
     * @param string $serial_number
     * @param string $api_base_url
     * @param string $access_token
     * @param callable $outputCallback
     * @return array ['success' => bool, 'records' => int, 'requests' => int, 'message' => string, 'rate_limit' => int|null, 'rate_limit_remaining' => int|null]
     */
    private function syncSingleDevice($serial_number, $api_base_url, $access_token, $outputCallback = null)
    {
        if ($outputCallback === null) {
            $outputCallback = function($message, $isError = false) {};
        }

        $requests = 0;
        $device_info = [];
        $device_attrs = [];
        $detected_rate_limit = null;
        $detected_rate_limit_remaining = null;

        // First, fetch device information
        $device_url = $api_base_url . "orgDevices/{$serial_number}";
        
        $ch = curl_init($device_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in response
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $device_response = curl_exec($ch);
        $device_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $device_curl_error = curl_error($ch);
        
        // Extract body from response (since CURLOPT_HEADER is true)
        $device_header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $device_body = substr($device_response, $device_header_size);
        
        curl_close($ch);
        $requests++;

        // If device not found in ABM, skip immediately
        if ($device_http_code === 404) {
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => 'SKIP (HTTP 404) - Device not found in Apple Business/School Manager', 'rate_limit' => null, 'rate_limit_remaining' => null];
        }

        // Extract device information if available
        if ($device_http_code === 200) {
            if ($device_curl_error) {
                error_log("AppleCare: Device lookup cURL error for {$serial_number}: {$device_curl_error}");
            } else {
                $device_data = json_decode($device_body, true);
                
                if (isset($device_data['data']['attributes'])) {
                    $device_attrs = $device_data['data']['attributes'];
                    
                    // Map available fields from Apple Business Manager API
                    $device_info = [
                        'model' => $device_attrs['deviceModel'] ?? null,
                        'part_number' => $device_attrs['partNumber'] ?? null,
                        'product_family' => $device_attrs['productFamily'] ?? null,
                        'product_type' => $device_attrs['productType'] ?? null,
                        'color' => $device_attrs['color'] ?? null,
                        'device_capacity' => $device_attrs['deviceCapacity'] ?? null,
                        'device_assignment_status' => $device_attrs['status'] ?? null,
                        'purchase_source_type' => $device_attrs['purchaseSourceType'] ?? null,
                        'purchase_source_id' => $device_attrs['purchaseSourceId'] ?? null,
                        'order_number' => $device_attrs['orderNumber'] ?? null,
                        'order_date' => null,
                        'added_to_org_date' => null,
                        'released_from_org_date' => null,
                        'wifi_mac_address' => $device_attrs['wifiMacAddress'] ?? null,
                        'ethernet_mac_address' => null,
                        'bluetooth_mac_address' => $device_attrs['bluetoothMacAddress'] ?? null,
                    ];
                    
                    // Handle dates
                    if (!empty($device_attrs['orderDateTime'])) {
                        $device_info['order_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['orderDateTime']));
                    }
                    if (!empty($device_attrs['addedToOrgDateTime'])) {
                        $device_info['added_to_org_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['addedToOrgDateTime']));
                    }
                    if (!empty($device_attrs['releasedFromOrgDateTime'])) {
                        $device_info['released_from_org_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['releasedFromOrgDateTime']));
                    }
                    
                    // Handle array fields
                    if (!empty($device_attrs['ethernetMacAddress']) && is_array($device_attrs['ethernetMacAddress'])) {
                        $device_info['ethernet_mac_address'] = implode(', ', array_filter($device_attrs['ethernetMacAddress']));
                    }
                } else {
                    // HTTP 200 but unexpected JSON structure
                    error_log("AppleCare: Device lookup returned 200 for {$serial_number} but JSON structure unexpected. Response: " . substr($device_body, 0, 500));
                }
            }
        } else {
            // Non-200 response - log warning but continue to fetch coverage
            if ($device_http_code !== 404) {
                error_log("AppleCare: Device lookup failed for {$serial_number} with HTTP {$device_http_code}, but continuing to fetch coverage");
            }
        }

        // Call Apple API for AppleCare coverage
        $url = $api_base_url . "orgDevices/{$serial_number}/appleCareCoverage";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in response
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        
        // Get response headers for rate limit information
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        curl_close($ch);
        $requests++;

        if ($curl_error) {
            // HTTP/2 errors - retry once
            if ($curl_errno == 92 || $curl_errno == 16) {
                sleep(2);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json',
                ]);
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers = substr($response, 0, $header_size);
                $body = substr($response, $header_size);
                curl_close($ch);
                $requests++;

                if ($curl_error) {
                    throw new \Exception("cURL error after retry: {$curl_error}");
                }
            } else {
                throw new \Exception("cURL error: {$curl_error} (errno: {$curl_errno})");
            }
        }

        // Parse rate limit headers from successful responses to track limits dynamically
        if ($http_code === 200 && !empty($headers)) {
            $header_lines = explode("\r\n", $headers);
            foreach ($header_lines as $header_line) {
                // Check for various rate limit header formats
                if (stripos($header_line, 'X-RateLimit-Limit:') === 0) {
                    $current_rate_limit = (int)trim(substr($header_line, 18));
                } elseif (stripos($header_line, 'X-Rate-Limit-Limit:') === 0) {
                    $current_rate_limit = (int)trim(substr($header_line, 20));
                } elseif (stripos($header_line, 'X-RateLimit-Remaining:') === 0) {
                    $rate_limit_remaining = (int)trim(substr($header_line, 22));
                } elseif (stripos($header_line, 'X-Rate-Limit-Remaining:') === 0) {
                    $rate_limit_remaining = (int)trim(substr($header_line, 24));
                } elseif (stripos($header_line, 'X-RateLimit-Reset:') === 0) {
                    $rate_limit_reset_time = (int)trim(substr($header_line, 18));
                } elseif (stripos($header_line, 'X-Rate-Limit-Reset:') === 0) {
                    $rate_limit_reset_time = (int)trim(substr($header_line, 19));
                }
            }
        }

        // Handle HTTP 429 (Rate Limit) with Retry-After header
        if ($http_code === 429) {
            $retry_after = null;
            $rate_limit_reset = null;
            
            // Parse headers for rate limit information
            if (!empty($headers)) {
                $header_lines = explode("\r\n", $headers);
                foreach ($header_lines as $header_line) {
                    if (stripos($header_line, 'Retry-After:') === 0) {
                        $retry_after = (int)trim(substr($header_line, 12));
                    } elseif (stripos($header_line, 'X-RateLimit-Reset:') === 0) {
                        $rate_limit_reset = (int)trim(substr($header_line, 18));
                    } elseif (stripos($header_line, 'X-Rate-Limit-Reset:') === 0) {
                        $rate_limit_reset = (int)trim(substr($header_line, 19));
                    }
                }
            }
            
            // Use Retry-After if provided, otherwise default to 60 seconds
            $wait_time = $retry_after ?: 60;
            
            $error_msg = "SKIP (HTTP 429 - Rate limit exceeded)";
            if ($retry_after) {
                $error_msg .= " - Retry after {$retry_after}s";
            }
            if ($rate_limit_reset) {
                $reset_time = date('Y-m-d H:i:s', $rate_limit_reset);
                $error_msg .= " - Rate limit resets at {$reset_time}";
            }
            
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => $error_msg, 'retry_after' => $wait_time];
        }

        if ($http_code !== 200) {
            $error_msg = "SKIP (HTTP $http_code)";
            if ($http_code === 404) {
                $error_msg .= " - Device not found in Apple Business/School Manager or not enrolled";
            } elseif ($http_code === 401) {
                $error_msg .= " - Authentication failed (token may be expired)";
            } elseif ($http_code === 403) {
                $error_msg .= " - Access forbidden (check API permissions)";
            }

            if (!empty($body)) {
                $error_data = json_decode($body, true);
                if (isset($error_data['errors']) && is_array($error_data['errors'])) {
                    $error_details = [];
                    foreach ($error_data['errors'] as $error) {
                        if (isset($error['detail'])) {
                            $error_details[] = $error['detail'];
                        } elseif (isset($error['title'])) {
                            $error_details[] = $error['title'];
                        }
                    }
                    if (!empty($error_details)) {
                        $error_msg .= " - " . implode(", ", $error_details);
                    }
                }
            }

            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => $error_msg];
        }

        $data = json_decode($body, true);

        if (!isset($data['data']) || empty($data['data'])) {
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => 'SKIP (no coverage)'];
        }

        // Save coverage data with device information
        // Only update last_fetched when we actually fetch and save coverage data
        $fetch_timestamp = time();
        $records_saved = 0;
        foreach ($data['data'] as $coverage) {
            $attrs = $coverage['attributes'] ?? [];

            // Use API's updatedDateTime if available, otherwise set to NULL
            $last_updated = null;
            if (!empty($attrs['updatedDateTime'])) {
                $last_updated = strtotime($attrs['updatedDateTime']);
            } elseif (!empty($device_attrs['updatedDateTime'])) {
                $last_updated = strtotime($device_attrs['updatedDateTime']);
            }
            
            $coverage_data = array_merge($device_info, [
                'id' => $coverage['id'],
                'serial_number' => $serial_number,
                'description' => $attrs['description'] ?? '',
                'status' => $attrs['status'] ?? '',
                'agreementNumber' => $attrs['agreementNumber'] ?? '',
                'paymentType' => $attrs['paymentType'] ?? '',
                'isRenewable' => !empty($attrs['isRenewable']) ? 1 : 0,
                'isCanceled' => !empty($attrs['isCanceled']) ? 1 : 0,
                'startDateTime' => !empty($attrs['startDateTime']) ? date('Y-m-d', strtotime($attrs['startDateTime'])) : null,
                'endDateTime' => !empty($attrs['endDateTime']) ? date('Y-m-d', strtotime($attrs['endDateTime'])) : null,
                'contractCancelDateTime' => !empty($attrs['contractCancelDateTime']) ? date('Y-m-d', strtotime($attrs['contractCancelDateTime'])) : null,
                'last_updated' => $last_updated,
                'last_fetched' => $fetch_timestamp, // Use the timestamp we set earlier
            ]);

            // Normalize boolean fields
            foreach (['isRenewable', 'isCanceled'] as $field) {
                if (isset($coverage_data[$field])) {
                    $coverage_data[$field] = ($coverage_data[$field] === true ||
                         $coverage_data[$field] === 1 ||
                         $coverage_data[$field] === '1' ||
                         strtolower($coverage_data[$field]) === 'true') ? 1 : 0;
                }
            }

            // Translate reseller ID to name if config exists
            if (!empty($coverage_data['purchase_source_id'])) {
                $resellerName = $this->getResellerName($coverage_data['purchase_source_id']);
                // Only set purchase_source_name if we found a translation (not just the ID)
                if ($resellerName && $resellerName !== $coverage_data['purchase_source_id']) {
                    $coverage_data['purchase_source_name'] = $resellerName;
                    $coverage_data['purchase_source_id_display'] = $coverage_data['purchase_source_id'];
                }
            }

            // Insert or update
            Applecare_model::updateOrCreate(
                ['id' => $coverage['id']],
                $coverage_data
            );
            $records_saved++;
        }

        return [
            'success' => true, 
            'records' => $records_saved, 
            'requests' => $requests, 
            'message' => '',
            'rate_limit' => $detected_rate_limit,
            'rate_limit_remaining' => $detected_rate_limit_remaining
        ];
    }

    /**
     * Send a Server-Sent Event
     */
    private function sendEvent($event, $data)
    {
        if (is_array($data)) {
            $data = json_encode($data);
        } else {
            // Escape newlines and carriage returns for SSE format
            // Since we're sending line-by-line, this is mainly for safety
            $data = str_replace(["\n", "\r"], ['\\n', ''], $data);
        }
        
        echo "event: $event\n";
        echo "data: $data\n\n";
        
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    private function jsonError($message, $status = 500)
    {
        http_response_code($status);
        jsonView([
            'success' => false,
            'message' => $message,
        ]);
        exit;
    }

    /**
     * Get data for widgets
     *
     * @return void
     * @author tuxudo
     **/
    public function get_binary_widget($column = '')
    {
        jsonView(
            Applecare_model::select($column . ' AS label')
                ->selectRaw('count(*) AS count')
                ->whereNotNull($column)
                ->filter()
                ->groupBy($column)
                ->orderBy('count', 'desc')
                ->get()
                ->toArray()
        );
    }

    /**
     * Sync AppleCare data for a single serial number (internal method, no JSON output)
     * Can be called from processor or other internal code
     * 
     * @param string $serial_number Serial number to sync
     * @return array Result array with success, records, message
     */
    public function syncSerialInternal($serial_number)
    {
        if (empty($serial_number) || strlen($serial_number) < 8) {
            return ['success' => false, 'records' => 0, 'message' => 'Invalid serial number'];
        }

        try {
            require_once __DIR__ . '/lib/applecare_helper.php';
            $helper = new \munkireport\module\applecare\Applecare_helper();
            return $helper->syncSerial($serial_number);
        } catch (\Exception $e) {
            return ['success' => false, 'records' => 0, 'message' => 'Sync failed: ' . $e->getMessage()];
        }
    }

    /**
     * Sync AppleCare data for a single serial number (public API endpoint)
     * 
     * @param string $serial_number Serial number to sync
     */
    public function sync_serial($serial_number = '')
    {
        if (empty($serial_number)) {
            return $this->jsonError('Serial number is required', 400);
        }

        // Validate serial number
        if (strlen($serial_number) < 8) {
            return $this->jsonError('Invalid serial number', 400);
        }

        $result = $this->syncSerialInternal($serial_number);
        
        if ($result['success']) {
            jsonView([
                'success' => true,
                'message' => "Synced {$result['records']} coverage record(s)",
                'records' => $result['records']
            ]);
        } else {
            jsonView([
                'success' => false,
                'message' => $result['message']
            ]);
        }
    }

    /**
     * Get AppleCare statistics for dashboard widget
     * Loops through applecare table and totals unique devices by status
     */
    public function get_stats()
    {
        $data = [
            'total_devices' => 0,
            'active' => 0,
            'inactive' => 0,
            'expiring_soon' => 0,
            'expired' => 0,
        ];

        try {
            // Get all records from applecare table
            $records = Applecare_model::filter()->get();
            
            if ($records->isEmpty()) {
                jsonView($data);
                return;
            }

            // Track unique devices in each category
            $activeDevices = [];
            $expiringSoonDevices = [];
            $expiredDevices = [];
            $inactiveDevices = [];
            $allDevices = [];

            $now = new DateTime();
            $thirtyDays = clone $now;
            $thirtyDays->modify('+30 days');

            // Loop through all records in the applecare table
            foreach ($records as $record) {
                $serialNumber = $record->serial_number;
                
                if (empty($serialNumber)) {
                    continue;
                }

                // Track all unique devices
                $allDevices[$serialNumber] = true;

                $status = strtoupper(trim($record->status ?? ''));
                $isCanceled = !empty($record->isCanceled);

                // Check if device is inactive
                if ($status === 'INACTIVE' || empty($status) || $isCanceled) {
                    $inactiveDevices[$serialNumber] = true;
                    continue;
                }

                // Check endDateTime for active/expired/expiring status
                if (!empty($record->endDateTime)) {
                    try {
                        $endDate = new DateTime($record->endDateTime);

                        if ($endDate > $now) {
                            // Active coverage - end date is in the future
                            $activeDevices[$serialNumber] = true;

                            // Check if expiring within 30 days
                            if ($endDate <= $thirtyDays) {
                                $expiringSoonDevices[$serialNumber] = true;
                            }
                        } else {
                            // Expired - end date is in the past
                            $expiredDevices[$serialNumber] = true;
                        }
                    } catch (\Exception $e) {
                        // Skip invalid dates - treat as inactive
                        $inactiveDevices[$serialNumber] = true;
                        continue;
                    }
                } else {
                    // No endDateTime - check status
                    if ($status === 'ACTIVE') {
                        $activeDevices[$serialNumber] = true;
                    } else {
                        $inactiveDevices[$serialNumber] = true;
                    }
                }
            }

            // Count unique devices in each category
            $data['total_devices'] = count($allDevices);
            $data['active'] = count($activeDevices);
            $data['expiring_soon'] = count($expiringSoonDevices);
            $data['expired'] = count($expiredDevices);
            $data['inactive'] = count($inactiveDevices);

        } catch (\Throwable $e) {
            // Return zeros on error
            error_log('AppleCare get_stats error: ' . $e->getMessage());
        }

        jsonView($data);
    }

    /**
     * Get applecare information for serial_number
     * Returns the first coverage record with device information merged
     *
     * @param string $serial serial number
     **/
    public function get_data($serial_number = '')
    {
        $record = Applecare_model::select('applecare.*')
            ->whereSerialNumber($serial_number)
            ->filter()
            ->orderBy('last_fetched', 'desc')
            ->first();
        
        if ($record) {
            $data = $record->toArray();
            
            // Get the most recent last_fetched from all records for this serial
            $mostRecentFetched = Applecare_model::whereSerialNumber($serial_number)
                ->filter()
                ->max('last_fetched');
            
            // Use the most recent last_fetched if available
            if ($mostRecentFetched) {
                $data['last_fetched'] = $mostRecentFetched;
            }
            
            // Translate reseller ID to name if config exists
            if (!empty($data['purchase_source_id'])) {
                $resellerName = $this->getResellerName($data['purchase_source_id']);
                // Only set purchase_source_name if we found a translation (not just the ID)
                if ($resellerName && $resellerName !== $data['purchase_source_id']) {
                    $data['purchase_source_name'] = $resellerName;
                    $data['purchase_source_id_display'] = $data['purchase_source_id'];
                }
            }
            
            jsonView($data);
        } else {
            jsonView([]);
        }
    }

    /**
     * Get admin status data for configuration display
     * Similar to jamf_admin.php's get_admin_data
     *
     * @return void
     **/
    public function get_admin_data()
    {
        $data = [
            'api_url_configured' => false,
            'client_assertion_configured' => false,
            'rate_limit' => 20,
            'default_api_url' => getenv('APPLECARE_API_URL') ?: '',
            'default_client_assertion' => getenv('APPLECARE_CLIENT_ASSERTION') ? 'Yes' : 'No',
            'default_rate_limit' => getenv('APPLECARE_RATE_LIMIT') ?: '20',
        ];
        
        // Check if default config is set
        $default_api_url = getenv('APPLECARE_API_URL');
        $default_client_assertion = getenv('APPLECARE_CLIENT_ASSERTION');
        
        if (!empty($default_api_url)) {
            $data['api_url_configured'] = true;
        }
        if (!empty($default_client_assertion)) {
            $data['client_assertion_configured'] = true;
        }
        
        // Also check for org-specific configs (multi-org support)
        // Check $_ENV and $_SERVER for keys matching *_APPLECARE_API_URL pattern
        $all_env = array_merge($_ENV ?? [], $_SERVER ?? []);
        foreach ($all_env as $key => $value) {
            if (is_string($key) && !empty($value)) {
                // Check for org-specific API URL (e.g., ORG1_APPLECARE_API_URL)
                if (preg_match('/^[A-Z0-9]+_APPLECARE_API_URL$/', $key) && !$data['api_url_configured']) {
                    $data['api_url_configured'] = true;
                }
                // Check for org-specific Client Assertion (e.g., ORG1_APPLECARE_CLIENT_ASSERTION)
                if (preg_match('/^[A-Z0-9]+_APPLECARE_CLIENT_ASSERTION$/', $key) && !$data['client_assertion_configured']) {
                    $data['client_assertion_configured'] = true;
                }
            }
        }
        
        // Get rate limit (check default first, then look for any org-specific)
        $rate_limit = getenv('APPLECARE_RATE_LIMIT');
        if (!empty($rate_limit)) {
            $data['rate_limit'] = (int)$rate_limit ?: 20;
        } else {
            // Check for org-specific rate limits
            foreach ($all_env as $key => $value) {
                if (is_string($key) && preg_match('/^[A-Z0-9]+_APPLECARE_RATE_LIMIT$/', $key) && !empty($value)) {
                    $data['rate_limit'] = (int)$value ?: 20;
                    break; // Use first found
                }
            }
        }
        
        jsonView($data);
    }
} 
