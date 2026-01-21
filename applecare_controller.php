<?php 

use Symfony\Component\Yaml\Yaml;
use Illuminate\Database\Capsule\Manager as Capsule;

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
            $result = Capsule::table('munkiinfo')
                ->where('serial_number', $serial_number)
                ->where('munkiinfo_key', 'ClientIdentifier')
                ->value('munkiinfo_value');
            return $result ?: null;
        } catch (\Exception $e) {
            // Silently fail - ClientID is optional
        }
        return null;
    }

    /**
     * Get machine group key for a serial number
     * 
     * Machine group key is stored in munkireportinfo table in the passphrase column
     *
     * @param string $serial_number
     * @return string|null Machine group key or null if not found
     */
    private function getMachineGroupKey($serial_number)
    {
        try {
            $result = Capsule::table('munkireportinfo')
                ->where('serial_number', $serial_number)
                ->whereNotNull('passphrase')
                ->where('passphrase', '!=', '')
                ->value('passphrase');
            return $result ?: null;
        } catch (\Exception $e) {
            // Silently fail - machine group key is optional
        }
        return null;
    }

    /**
     * Get org-specific AppleCare config with fallback
     * 
     * Looks for org-specific env vars based on:
     * 1. Machine group name prefix (e.g., "6F730D13-451108" -> "6F730D13_APPLECARE_API_URL")
     * 2. ClientID prefix (e.g., "abcd-efg" -> "ABCD_APPLECARE_API_URL")
     * 3. Default config (APPLECARE_API_URL, APPLECARE_CLIENT_ASSERTION)
     *
     * @param string $serial_number
     * @return array ['api_url' => string, 'client_assertion' => string, 'rate_limit' => int] or null if not configured
     */
    private function getAppleCareConfig($serial_number)
    {
        $api_url = null;
        $client_assertion = null;
        $rate_limit = 40; // Default
        
        // Try machine group key prefix first
        $mg_key = $this->getMachineGroupKey($serial_number);
        if (!empty($mg_key)) {
            // Extract prefix from machine group key (e.g., "6F730D13-451108" -> "6F730D13")
            // Take everything before first hyphen or use entire key if no hyphen
            $parts = explode('-', $mg_key, 2);
            $prefix = strtoupper($parts[0]);
            
            // Try org-specific config based on machine group prefix
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
        
        // Fallback to ClientID prefix if machine group key not found or config vars are empty
        if (empty($api_url) || empty($client_assertion)) {
            $client_id = $this->getClientId($serial_number);
            if (!empty($client_id)) {
                // Extract prefix from ClientID (e.g., "abcd-efg" -> "ABCD")
                // Take everything before first hyphen or use entire ClientID if no hyphen
                $parts = explode('-', $client_id, 2);
                $prefix = strtoupper($parts[0]);
                
                // Try org-specific config based on ClientID prefix
                $org_api_url_key = $prefix . '_APPLECARE_API_URL';
                $org_assertion_key = $prefix . '_APPLECARE_CLIENT_ASSERTION';
                $org_rate_limit_key = $prefix . '_APPLECARE_RATE_LIMIT';
                
                if (empty($api_url)) {
                    $api_url = getenv($org_api_url_key);
                }
                if (empty($client_assertion)) {
                    $client_assertion = getenv($org_assertion_key);
                }
                $org_rate_limit = getenv($org_rate_limit_key);
                if (!empty($org_rate_limit)) {
                    $rate_limit = (int)$org_rate_limit;
                }
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
            $rate_limit = (int)$default_rate_limit ?: 40;
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
     * Normalize file path for cross-platform compatibility
     * Handles Windows backslashes and double slashes
     *
     * @param string $path File path to normalize
     * @return string Normalized path
     */
    private function normalizePath($path)
    {
        // Replace backslashes with forward slashes (Windows compatibility)
        $path = str_replace('\\', '/', $path);
        // Remove double slashes
        $path = preg_replace('#/+#', '/', $path);
        return $path;
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
        
        $config_path = $this->normalizePath(APP_ROOT . '/local/module_configs/applecare_resellers.yml');
        if (!file_exists($config_path)) {
            error_log('AppleCare: Reseller config file not found at: ' . $config_path);
            return $reseller_id;
        }
        
        if (!is_readable($config_path)) {
            error_log('AppleCare: Reseller config file is not readable: ' . $config_path);
            return $reseller_id;
        }
        
        try {
            $config = Yaml::parseFile($config_path);
            
            if (!is_array($config) || empty($config)) {
                error_log('AppleCare: Reseller config file is empty or invalid: ' . $config_path);
                return $reseller_id;
            }
            
            // Normalize keys to strings (handle case where YAML parser returns integer keys)
            $normalized_config = [];
            foreach ($config as $key => $value) {
                $normalized_config[(string)$key] = $value;
            }
            
            // Try exact match first
            if (isset($normalized_config[$reseller_id])) {
                return $normalized_config[$reseller_id];
            }
            
            // Try case-insensitive match
            $reseller_id_upper = strtoupper($reseller_id);
            foreach ($normalized_config as $key => $value) {
                if (strtoupper($key) === $reseller_id_upper) {
                    return $value;
                }
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Error loading reseller config from ' . $config_path . ': ' . $e->getMessage());
            error_log('AppleCare: Exception trace: ' . $e->getTraceAsString());
        }
        
        return $reseller_id;
    }

    /**
     * Get reseller config for client-side translation
     **/
    public function get_reseller_config()
    {
        $config_path = $this->normalizePath(APP_ROOT . '/local/module_configs/applecare_resellers.yml');
        $config = [];
        $error = null;
        
        if (!file_exists($config_path)) {
            $error = 'Config file not found at: ' . $config_path;
            error_log('AppleCare: ' . $error);
        } elseif (!is_readable($config_path)) {
            $error = 'Config file is not readable: ' . $config_path;
            error_log('AppleCare: ' . $error);
        } else {
            try {
                $parsed_config = Yaml::parseFile($config_path);
                
                if (!is_array($parsed_config)) {
                    $error = 'Config file is not a valid YAML mapping: ' . $config_path;
                    error_log('AppleCare: ' . $error);
                } else {
                    // Normalize keys to strings (handle case where YAML parser returns integer keys)
                    foreach ($parsed_config as $key => $value) {
                        $config[(string)$key] = $value;
                    }
                }
            } catch (\Exception $e) {
                $error = 'Error parsing config file: ' . $e->getMessage();
                error_log('AppleCare: ' . $error . ' (file: ' . $config_path . ')');
            }
        }
        
        if ($error) {
            jsonView(['error' => $error, 'config' => $config]);
        } else {
            jsonView($config);
        }
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
            // Check if we should exclude existing records
            $excludeExisting = isset($_GET['exclude_existing']) && $_GET['exclude_existing'] === '1';
            
            // Start keep-alive timer to prevent SSE connection timeout
            $last_keepalive = time();
            
            // Call sync logic directly
            $this->syncAll(function($message, $isError = false) use (&$last_keepalive) {
                if ($isError) {
                    $this->sendEvent('error', $message);
                } else {
                    $this->sendEvent('output', $message);
                }
                
                // Send keep-alive comment every 30 seconds to prevent SSE timeout
                $now = time();
                if ($now - $last_keepalive >= 30) {
                    $this->sendEvent('comment', 'keep-alive');
                    $last_keepalive = $now;
                }
            }, $excludeExisting);

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
            CURLOPT_HEADER => true, // Include headers in response
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
        
        // Get headers to check for Retry-After
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        curl_close($ch);

        // Temporary logging for all fetches
        // error_log("AppleCare FETCH: Token generation - HTTP {$http_code}");

        if ($curl_error) {
            throw new \Exception("cURL error: {$curl_error}");
        }

        if ($http_code === 429) {
            // Extract Retry-After header if available
            $retry_after = 30; // Default to 30 seconds
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $matches)) {
                $retry_after = (int)$matches[1];
            }
            throw new \Exception("Failed to get access token: HTTP 429 - Rate limit exceeded. Retry after {$retry_after}s - $body");
        }

        if ($http_code !== 200) {
            throw new \Exception("Failed to get access token: HTTP $http_code - $body");
        }
        
        // Use body instead of response for JSON parsing
        $response = $body;

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
    private function syncAll($outputCallback = null, $excludeExisting = false)
    {
        // Create helper instance once for reuse
        require_once __DIR__ . '/lib/applecare_helper.php';
        $helper = new \munkireport\module\applecare\Applecare_helper();
        
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
        
        // Wrap output callback to add elapsed time to relevant messages
        $originalCallback = $outputCallback;
        $outputCallback = function($message, $isError = false) use ($originalCallback, $start_time) {
            // Don't add time prefix to ESTIMATED_TIME control messages
            if (strpos($message, 'ESTIMATED_TIME:') === 0) {
                $originalCallback($message, $isError);
                return;
            }
            
            // Messages that should have elapsed time prefix
            $timePrefixMessages = [
                'Processing ',
                'Rate limit reached',
                'OK (',
                'SKIP (',
                'ERROR (',
                'Heartbeat:',
            ];
            
            // Check if this message should have elapsed time
            $shouldAddTime = false;
            foreach ($timePrefixMessages as $prefix) {
                if (strpos($message, $prefix) === 0) {
                    $shouldAddTime = true;
                    break;
                }
            }
            
            if ($shouldAddTime) {
                $elapsed = time() - $start_time;
                $minutes = floor($elapsed / 60);
                $seconds = $elapsed % 60;
                $timePrefix = sprintf('%02d:%02d ', $minutes, $seconds);
                $message = $timePrefix . $message;
            }
            
            $originalCallback($message, $isError);
        };

        $outputCallback("================================================");
        $outputCallback("AppleCare Sync Tool");
        $outputCallback("================================================");
        $outputCallback("");

        // Get default configuration from environment (for fallback)
        $default_api_base_url = getenv('APPLECARE_API_URL');
        $default_client_assertion = getenv('APPLECARE_CLIENT_ASSERTION');
        $default_rate_limit = (int)getenv('APPLECARE_RATE_LIMIT') ?: 40;

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

            // Generate access token for default config with retry logic
            $default_access_token = null;
            $max_retries = 3;
            $retry_count = 0;
            while ($retry_count < $max_retries && $default_access_token === null) {
                try {
                    $default_access_token = $this->generateAccessToken($default_client_assertion, $default_api_base_url, $outputCallback);
                    // Wait 3 seconds after token generation to respect rate limits
                    sleep(3);
                    $outputCallback("");
                    break; // Success, exit retry loop
                } catch (\Exception $e) {
                    $retry_count++;
                    // Check if it's a 429 error with retry-after
                    if (preg_match('/HTTP 429.*Retry after (\d+)s/i', $e->getMessage(), $matches)) {
                        $retry_after = (int)$matches[1];
                        if ($retry_count < $max_retries) {
                            $outputCallback("Rate limit hit during token generation. Waiting {$retry_after}s before retry ({$retry_count}/{$max_retries})...");
                            sleep($retry_after);
                            continue; // Retry
                        }
                    } elseif (preg_match('/HTTP 429/i', $e->getMessage())) {
                        // HTTP 429 without retry-after header, wait 30 seconds
                        if ($retry_count < $max_retries) {
                            $outputCallback("Rate limit hit during token generation. Waiting 30s before retry ({$retry_count}/{$max_retries})...");
                            sleep(30);
                            continue; // Retry
                        }
                    }
                    
                    // If we've exhausted retries or it's not a 429 error, show warning
                    if ($retry_count >= $max_retries) {
                        $outputCallback("WARNING: Failed to generate default access token after {$max_retries} retries: " . $e->getMessage());
                        $outputCallback("");
                    } else {
                        // Non-429 error, don't retry
                        $outputCallback("WARNING: Failed to generate default access token: " . $e->getMessage());
                        $outputCallback("");
                        break;
                    }
                }
            }
        }

        // Get all devices from database (exact copy from firmware/supported_os)
        $outputCallback("Fetching device list from database...");
        
        try {
            // Use Eloquent Query Builder for device list
            $query = Capsule::table('machine')
                ->leftJoin('reportdata', 'machine.serial_number', '=', 'reportdata.serial_number')
                ->select('machine.serial_number');
            
            // Apply machine group filter if applicable
            $filter = get_machine_group_filter();
            if (!empty($filter)) {
                // Extract the WHERE clause content (remove leading WHERE/AND)
                $filter_condition = preg_replace('/^\s*(WHERE|AND)\s+/i', '', $filter);
                if (!empty($filter_condition)) {
                    $query->whereRaw($filter_condition);
                }
            }

            // Loop through each serial number for processing
            $devices = [];
            foreach ($query->get() as $serialobj) {
                $devices[] = $serialobj->serial_number;
            }
        } catch (\Exception $e) {
            throw new \Exception('Database query failed: ' . $e->getMessage());
        }

        // Filter out devices that already have AppleCare records if requested
        // This includes devices with coverage data AND devices with only device info (no coverage)
        if ($excludeExisting) {
            $existingSerials = Applecare_model::select('serial_number')
                ->distinct()
                ->whereIn('serial_number', $devices)
                ->whereNotNull('serial_number') // Ensure we have a valid serial number
                ->pluck('serial_number')
                ->toArray();
            
            $devices = array_diff($devices, $existingSerials);
            $excludedCount = count($existingSerials);
            
            if ($excludedCount > 0) {
                $outputCallback("Excluding $excludedCount device(s) that already have AppleCare records");
            }
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
        $rate_limit_window = 60; // seconds - moving window size
        
        // Moving window: track timestamps of successful API requests
        // This provides smoother rate limiting than fixed windows
        $request_timestamps = [];
        
        // Dynamic rate limit tracking (will be updated from API headers if available)
        $current_rate_limit = $default_rate_limit;
        $rate_limit_remaining = null;
        $rate_limit_reset_time = null;

        $outputCallback("");
        $outputCallback("Starting sync...");
        $outputCallback("Initial rate limit: $default_rate_limit calls per minute (will adjust based on API headers)");
        
        // Calculate effective rate limit (80% of configured limit)
        $effective_rate_limit = (int)($default_rate_limit * 0.8);
        $outputCallback("Using 80% of rate limit ($effective_rate_limit calls/minute) to allow room for background updates");
        
        // Calculate devices per minute based on rate limit
        // Each device requires 2 API calls (device info + coverage)
        $requests_per_device = 2;
        $devices_per_minute = $effective_rate_limit / $requests_per_device;
        $estimated_minutes = ceil($total_devices / $devices_per_minute);
        $estimated_seconds = $estimated_minutes * 60;
        
        // Send initial estimated time to update header
        $outputCallback("ESTIMATED_TIME:" . $estimated_seconds . ":" . $total_devices);
        
        $outputCallback("");

        // Use the same sync logic as sync_serial but for all devices
        $device_index = 0;
        $last_heartbeat = time();
        $current_device_retries = 0;
        $max_device_retries = 3; // Max retries per device for 429 errors
        foreach ($devices as $serial) {
            $device_index++;

            // Skip invalid serials
            if (empty($serial) || strlen($serial) < 8) {
                $skipped++;
                // Update estimated time remaining based on configured rate limit
                $remaining_devices = $total_devices - $device_index;
                if ($remaining_devices > 0) {
                    $estimated_seconds_remaining = ceil(($remaining_devices / $devices_per_minute) * 60);
                    $outputCallback("ESTIMATED_TIME:" . $estimated_seconds_remaining . ":" . $remaining_devices);
                } else {
                    $outputCallback("ESTIMATED_TIME:0:0");
                }
                continue;
            }

            // Send heartbeat every 15 seconds to keep SSE connection alive
            // This helps prevent timeouts on servers with max_execution_time limits and SSE connection timeouts
            $now = time();
            if ($now - $last_heartbeat >= 15) {
                $outputCallback("Heartbeat: Processing device $device_index of $total_devices...");
                $last_heartbeat = $now;
                
                // Update estimated time remaining based on configured rate limit
                $remaining_devices = $total_devices - $device_index;
                $estimated_seconds_remaining = ceil(($remaining_devices / $devices_per_minute) * 60);
                $outputCallback("ESTIMATED_TIME:" . $estimated_seconds_remaining . ":" . $remaining_devices);
                
                // Flush output to keep connection alive
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }

            $outputCallback("Processing $serial... ");

            try {
                // Moving window rate limiting: check BEFORE making requests
                // Clean up timestamps older than window
                $now = time();
                $request_timestamps = array_filter($request_timestamps, function($timestamp) use ($now, $rate_limit_window) {
                    return ($now - $timestamp) < $rate_limit_window;
                });
                
                // Get org-specific config for this device (with fallback to default)
                $device_config = $this->getAppleCareConfig($serial);
                
                // Determine effective rate limit (use 80% to allow room for background updates)
                $base_rate_limit = $device_config ? $device_config['rate_limit'] : $default_rate_limit;
                if (isset($current_rate_limit) && $current_rate_limit > 0) {
                    $base_rate_limit = $current_rate_limit;
                }
                $effective_rate_limit = (int)($base_rate_limit * 0.8);
                
                // Check if making this request would exceed the limit
                // Account for the fact that we'll add 2 requests (device info + coverage)
                $requests_in_window = count($request_timestamps);
                $requests_per_device = 2; // Each device sync makes 2 API calls
                $projected_requests = $requests_in_window + $requests_per_device;
                
                if ($projected_requests > $effective_rate_limit) {
                    // Would exceed limit - wait for oldest request to expire
                    $oldest_timestamp = min($request_timestamps);
                    $time_until_oldest_expires = $rate_limit_window - ($now - $oldest_timestamp);
                    
                    if ($time_until_oldest_expires > 0) {
                        $outputCallback("Rate limit reached ({$requests_in_window}/{$effective_rate_limit}, would be {$projected_requests} with this device). Waiting {$time_until_oldest_expires}s for oldest request to expire...");
                        sleep($time_until_oldest_expires);
                        
                        // Clean up expired timestamps after waiting
                        $now = time();
                        $request_timestamps = array_filter($request_timestamps, function($timestamp) use ($now, $rate_limit_window) {
                            return ($now - $timestamp) < $rate_limit_window;
                        });
                    }
                }
                
                // Start timing for this device (includes API calls + DB save)
                $device_start_time = microtime(true);
                
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
                        // Wait 3 seconds after token generation to respect rate limits
                        sleep(3);
                    }
                    $device_access_token = $token_cache[$token_cache_key];
                    
                    // Use helper's syncSingleDevice method
                    $result = $helper->syncSingleDevice($serial, $device_api_url, $device_access_token, $outputCallback);
                } else {
                    // Use default config if available
                    if ($default_access_token && !empty($default_api_base_url)) {
                        $result = $helper->syncSingleDevice($serial, $default_api_base_url, $default_access_token, $outputCallback);
                    } else {
                        $outputCallback("SKIP (no config found)");
                        $skipped++;
                        // Update estimated time remaining based on configured rate limit
                        $remaining_devices = $total_devices - $device_index;
                        if ($remaining_devices > 0) {
                            $estimated_seconds_remaining = ceil(($remaining_devices / $devices_per_minute) * 60);
                            $outputCallback("ESTIMATED_TIME:" . $estimated_seconds_remaining . ":" . $remaining_devices);
                        } else {
                            $outputCallback("ESTIMATED_TIME:0:0");
                        }
                        continue;
                    }
                }
                
                // Handle rate limit (HTTP 429) - wait and retry (with limit)
                if (isset($result['retry_after']) && $result['retry_after'] > 0) {
                    $current_device_retries++;
                    
                    if ($current_device_retries <= $max_device_retries) {
                        $wait_time = $result['retry_after'];
                        $outputCallback("Rate limit hit (attempt {$current_device_retries}/{$max_device_retries}). Waiting {$wait_time}s before retrying...");
                        sleep($wait_time);
                        // Clean up old timestamps after waiting (they're now expired)
                        $now = time();
                        $request_timestamps = array_filter($request_timestamps, function($timestamp) use ($now, $rate_limit_window) {
                            return ($now - $timestamp) < $rate_limit_window;
                        });
                        // Retry this device
                        $device_index--; // Decrement to retry same device
                        continue;
                    } else {
                        // Max retries exceeded for this device - skip it
                        $outputCallback("Rate limit hit - max retries ({$max_device_retries}) exceeded. Skipping device.");
                        $skipped++;
                        $current_device_retries = 0; // Reset for next device
                        continue;
                    }
                }
                
                // Reset retry counter on successful request (no 429)
                $current_device_retries = 0;
                
                // Track requests AFTER they're made
                // Count ALL API calls that were made - they all consume rate limit quota
                // All devices except "no config found" make API calls:
                // - Success: 2 requests (device info + coverage)
                // - 404: 1-2 requests (device lookup fails at 1st or 2nd call)
                // - Other errors: 2 requests (both calls attempted)
                // - No config: 0 requests (never calls syncSingleDevice, handled above with continue)
                if (isset($result['requests']) && $result['requests'] > 0) {
                    // Add timestamp for each request made
                    // Note: syncSingleDevice typically makes 2 requests (device info + coverage)
                    // But 404s on device lookup only make 1 request
                    // We track them with the same timestamp since they happen almost simultaneously
                    $now = time();
                    for ($i = 0; $i < $result['requests']; $i++) {
                        $request_timestamps[] = $now;
                    }
                }
                
                if ($result['success']) {
                    $outputCallback("OK (" . $result['records'] . " coverage records)");
                    $synced++;
                } else {
                    $outputCallback($result['message']);
                    $skipped++;
                }
                
                // Calculate time taken for this device (API calls + DB save + processing)
                $device_end_time = microtime(true);
                $time_took = $device_end_time - $device_start_time;
                
                // Don't wait after the last device
                $remaining_devices = $total_devices - $device_index;
                if ($remaining_devices > 0) {
                    // Calculate ideal time per device: 60 seconds / devices_per_minute
                    // devices_per_minute = effective_rate_limit / requests_per_device
                    // e.g., 16 requests / 2 requests per device = 8 devices per minute
                    $devices_per_minute = $effective_rate_limit / $requests_per_device;
                    $ideal_time_per_device = $rate_limit_window / $devices_per_minute; // e.g., 60/8 = 7.5 seconds
                    
                    // Wait the remaining time to reach ideal spacing
                    $wait_time = $ideal_time_per_device - $time_took;
                    
                    // Only wait if we have positive wait time and we're not at the limit
                    // If we're at the limit, the moving window throttling above handles it
                    if ($wait_time > 0 && $wait_time < 60) {
                        usleep((int)($wait_time * 1000000)); // Convert to microseconds
                    }
                }
                
                // Update estimated time remaining based on configured rate limit
                $remaining_devices = $total_devices - $device_index;
                if ($remaining_devices > 0) {
                    $estimated_seconds_remaining = ceil(($remaining_devices / $devices_per_minute) * 60);
                    $outputCallback("ESTIMATED_TIME:" . $estimated_seconds_remaining . ":" . $remaining_devices);
                } else {
                    // All devices processed
                    $outputCallback("ESTIMATED_TIME:0:0");
                }
            } catch (\Exception $e) {
                $outputCallback("ERROR (" . $e->getMessage() . ")", true);
                $errors++;
                // On error, clean up timestamps and let moving window handle rate limiting
                $now = time();
                $request_timestamps = array_filter($request_timestamps, function($timestamp) use ($now, $rate_limit_window) {
                    return ($now - $timestamp) < $rate_limit_window;
                });
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
        // Handle purchase_source_name specially - need to translate purchase_source_id to names
        if ($column === 'purchase_source_name') {
            try {
                // Get distinct purchase_source_id values and translate them to names
                // Count distinct devices per reseller (one device counted once even if it has multiple coverage records)
                $filter = get_machine_group_filter('WHERE', 'reportdata');
                
                // Build WHERE clause - use AND if filter already has WHERE, otherwise use WHERE
                $where_clause = '';
                if (!empty($filter)) {
                    $where_clause = $filter . ' AND applecare.purchase_source_id IS NOT NULL';
                } else {
                    $where_clause = 'WHERE applecare.purchase_source_id IS NOT NULL';
                }
                
                // Get one purchase_source_id per device, then count devices per reseller
                $sql = "SELECT 
                            MAX(applecare.purchase_source_id) AS purchase_source_id
                        FROM applecare
                        LEFT JOIN reportdata ON applecare.serial_number = reportdata.serial_number
                        " . $where_clause . "
                        GROUP BY applecare.serial_number";

                // Aggregate by purchase_source_id
                $temp_results = [];
                $results = Capsule::select($sql);
                foreach ($results as $obj) {
                    if (!empty($obj->purchase_source_id)) {
                        $resellerId = $obj->purchase_source_id;
                        if (!isset($temp_results[$resellerId])) {
                            $temp_results[$resellerId] = 0;
                        }
                        $temp_results[$resellerId]++;
                    }
                }

                // Translate IDs to names and format output
                $out = [];
                foreach ($temp_results as $resellerId => $count) {
                    // Translate reseller ID to name
                    $resellerName = $this->getResellerName($resellerId);
                    // Use translated name if available, otherwise use ID
                    $displayName = ($resellerName && $resellerName !== $resellerId) 
                        ? $resellerName 
                        : $resellerId;
                    
                    // Use name as label for display (hash will contain name)
                    // The filter function will convert the name to ID for searching
                    $out[] = [
                        'label' => $displayName,  // Name for display and hash
                        'count' => $count
                    ];
                }
                
                // Sort by count descending
                usort($out, function($a, $b) {
                    return $b['count'] - $a['count'];
                });

                jsonView($out);
                return;
            } catch (\Exception $e) {
                error_log('AppleCare get_binary_widget error for purchase_source_name: ' . $e->getMessage());
                error_log('AppleCare get_binary_widget error trace: ' . $e->getTraceAsString());
                jsonView(['error' => 'Failed to retrieve reseller data: ' . $e->getMessage()]);
                return;
            }
        }

        // Handle device_assignment_status specially - need to check released_from_org_date too
        if ($column === 'device_assignment_status') {
            $filter = get_machine_group_filter('WHERE', 'reportdata');
            
            // Get one value per device (using MAX to handle cases where device has multiple records)
            // Then count devices by their device_assignment_status
            // This ensures we count each device only once, even if it has multiple coverage records
            // If device_assignment_status is NULL or 'DEVICE_ASSIGNMENT_UNKNOWN' and released_from_org_date is set, infer 'RELEASED'
            $sql = "SELECT 
                        CASE 
                            WHEN MAX(applecare.released_from_org_date) IS NOT NULL 
                                 AND (MAX(applecare.device_assignment_status) IS NULL 
                                      OR MAX(applecare.device_assignment_status) = 'DEVICE_ASSIGNMENT_UNKNOWN') 
                            THEN 'RELEASED'
                            WHEN MAX(applecare.device_assignment_status) IS NOT NULL 
                            THEN MAX(applecare.device_assignment_status)
                            ELSE 'UNKNOWN'
                        END AS status,
                        COUNT(DISTINCT applecare.serial_number) AS count
                    FROM applecare
                    LEFT JOIN reportdata ON applecare.serial_number = reportdata.serial_number
                    " . $filter . "
                    GROUP BY applecare.serial_number";

            // Now aggregate by status
            $temp_results = [];
            foreach (Capsule::select($sql) as $obj) {
                $status = strtoupper($obj->status);
                if (!isset($temp_results[$status])) {
                    $temp_results[$status] = 0;
                }
                $temp_results[$status] += (int)$obj->count;
            }

            // Convert to expected format with title case labels
            // Map status values to display labels
            $status_labels = [
                'ASSIGNED' => 'Assigned',
                'UNASSIGNED' => 'Unassigned',
                'RELEASED' => 'Released',
                'UNKNOWN' => 'Unknown'
            ];
            
            $out = [];
            foreach ($temp_results as $status => $count) {
                $label = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst(strtolower($status));
                $out[] = [
                    'label' => $label,
                    'count' => $count
                ];
            }
            
            // Sort by count descending
            usort($out, function($a, $b) {
                return $b['count'] - $a['count'];
            });

            jsonView($out);
            return;
        }

        // Handle enrolled_in_dep from mdm_status table
        if ($column === 'enrolled_in_dep') {
            try {
                $filter = get_machine_group_filter('WHERE', 'reportdata');
                
                // Build WHERE clause - use AND if filter already has WHERE, otherwise use WHERE
                $where_clause = '';
                if (!empty($filter)) {
                    $where_clause = $filter . ' AND mdm_status.enrolled_in_dep IS NOT NULL';
                } else {
                    $where_clause = 'WHERE mdm_status.enrolled_in_dep IS NOT NULL';
                }
                
                // Count distinct devices by enrolled_in_dep status
                // Join with applecare to respect machine group filter and only show devices with AppleCare data
                $sql = "SELECT 
                            mdm_status.enrolled_in_dep AS label,
                            COUNT(DISTINCT mdm_status.serial_number) AS count
                        FROM mdm_status
                        LEFT JOIN reportdata ON mdm_status.serial_number = reportdata.serial_number
                        LEFT JOIN applecare ON mdm_status.serial_number = applecare.serial_number
                        " . $where_clause . "
                        AND applecare.device_assignment_status IS NOT NULL
                        GROUP BY mdm_status.enrolled_in_dep
                        ORDER BY count DESC";

                $results = [];
                foreach (Capsule::select($sql) as $obj) {
                    $results[] = [
                        'label' => (string)$obj->label,
                        'count' => (int)$obj->count
                    ];
                }
                
                jsonView($results);
                return;
            } catch (\Exception $e) {
                error_log('AppleCare get_binary_widget error for enrolled_in_dep: ' . $e->getMessage());
                error_log('AppleCare get_binary_widget error trace: ' . $e->getTraceAsString());
                jsonView(['error' => 'Failed to retrieve enrolled_in_dep data: ' . $e->getMessage()]);
                return;
            }
        }

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
     * Counts DEVICES by their coverage_status (computed when is_primary is set)
     */
    public function get_stats()
    {
        $data = [
            'total_devices' => 0,
            'active' => 0,
            'inactive' => 0,
            'expiring_soon' => 0,
        ];

        try {
            // Count by coverage_status using simple GROUP BY query
            $counts = Applecare_model::filter()
                ->whereNotNull('device_assignment_status')
                ->where('is_primary', 1)
                ->selectRaw('coverage_status, COUNT(*) as count')
                ->groupBy('coverage_status')
                ->pluck('count', 'coverage_status');
            
            $data['active'] = $counts->get('active', 0);
            $data['expiring_soon'] = $counts->get('expiring_soon', 0);
            $data['inactive'] = $counts->get('inactive', 0);
            $data['total_devices'] = $data['active'] + $data['expiring_soon'] + $data['inactive'];

        } catch (\Throwable $e) {
            error_log('AppleCare get_stats error: ' . $e->getMessage());
        }

        jsonView($data);
    }

    /**
     * Get applecare information for serial_number
     * Returns the primary plan (is_primary=1), or falls back to latest end date if not set
     *
     * @param string $serial serial number
     **/
    public function get_data($serial_number = '')
    {
        // First try to get the primary plan (is_primary=1)
        $record = Applecare_model::select('applecare.*')
            ->whereSerialNumber($serial_number)
            ->filter()
            ->where('is_primary', 1)
            ->first();
        
        // Fallback to latest end date if no primary plan found (for records before migration)
        if (!$record) {
            $record = Applecare_model::select('applecare.*')
                ->whereSerialNumber($serial_number)
                ->filter()
                ->orderByRaw("COALESCE(endDateTime, '1970-01-01') DESC")
                ->first();
        }
        
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
            
            // Get enrolled_in_dep from mdm_status table
            $enrolled_in_dep = Capsule::table('mdm_status')
                ->where('serial_number', $serial_number)
                ->value('enrolled_in_dep');
            if ($enrolled_in_dep !== null) {
                $data['enrolled_in_dep'] = $enrolled_in_dep;
            }
            
            jsonView($data);
        } else {
            jsonView([]);
        }
    }

    /**
     * Recalculate is_primary and coverage_status for all devices
     * URL: /module/applecare/recalculate_primary
     * 
     * @return void JSON response with count of updated devices
     */
    public function recalculate_primary()
    {
        try {
            $now = date('Y-m-d');
            $thirtyDays = date('Y-m-d', strtotime('+30 days'));
            
            // Get all unique serial numbers
            $serials = Capsule::table('applecare')
                ->distinct()
                ->whereNotNull('serial_number')
                ->pluck('serial_number');
            
            $updated = 0;
            foreach ($serials as $serial) {
                if (empty($serial)) {
                    continue;
                }
                
                // Reset all plans for this device
                Capsule::table('applecare')
                    ->where('serial_number', $serial)
                    ->update(['is_primary' => 0, 'coverage_status' => null]);
                
                // Get all plans for this device, sorted by end date desc (latest first)
                $plans = Capsule::table('applecare')
                    ->where('serial_number', $serial)
                    ->orderByRaw("COALESCE(endDateTime, '1970-01-01') DESC")
                    ->get();
                
                if ($plans->isEmpty()) {
                    continue;
                }
                
                // Pick the first one (latest end date)
                $primary = $plans->first();
                
                // Determine coverage status
                $status = strtoupper($primary->status ?? '');
                $isCanceled = !empty($primary->isCanceled);
                $endDate = $primary->endDateTime;
                
                // Check if plan is active
                $isActive = $status === 'ACTIVE' 
                    && !$isCanceled 
                    && !empty($endDate) 
                    && $endDate >= $now;
                
                if ($isActive) {
                    $coverageStatus = ($endDate <= $thirtyDays) ? 'expiring_soon' : 'active';
                } else {
                    $coverageStatus = 'inactive';
                }
                
                // Update the primary plan
                Capsule::table('applecare')
                    ->where('id', $primary->id)
                    ->update(['is_primary' => 1, 'coverage_status' => $coverageStatus]);
                
                $updated++;
            }
            
            jsonView([
                'success' => true,
                'message' => "Recalculated is_primary and coverage_status for {$updated} devices"
            ]);
        } catch (\Exception $e) {
            jsonView([
                'success' => false,
                'error' => $e->getMessage()
            ]);
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
        // Get actual PHP max_execution_time (0 means unlimited)
        $max_execution_time = (int)ini_get('max_execution_time');
        
        $data = [
            'api_url_configured' => false,
            'client_assertion_configured' => false,
            'rate_limit' => 40,
            'default_api_url' => getenv('APPLECARE_API_URL') ?: '',
            'default_client_assertion' => getenv('APPLECARE_CLIENT_ASSERTION') ? 'Yes' : 'No',
            'default_rate_limit' => getenv('APPLECARE_RATE_LIMIT') ?: '40',
            'max_execution_time' => $max_execution_time,
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
            $data['rate_limit'] = (int)$rate_limit ?: 40;
        } else {
            // Check for org-specific rate limits
            foreach ($all_env as $key => $value) {
                if (is_string($key) && preg_match('/^[A-Z0-9]+_APPLECARE_RATE_LIMIT$/', $key) && !empty($value)) {
                    $data['rate_limit'] = (int)$value ?: 40;
                    break; // Use first found
                }
            }
        }
        
        // Check reseller config file status
        $config_path = $this->normalizePath(APP_ROOT . '/local/module_configs/applecare_resellers.yml');
        $data['reseller_config'] = [
            'exists' => file_exists($config_path),
            'readable' => is_readable($config_path),
            'path' => $config_path,
            'valid' => false,
            'entry_count' => 0,
            'error' => null
        ];
        
        if ($data['reseller_config']['exists'] && $data['reseller_config']['readable']) {
            try {
                $config = Yaml::parseFile($config_path);
                if (is_array($config)) {
                    $data['reseller_config']['valid'] = true;
                    $data['reseller_config']['entry_count'] = count($config);
                } else {
                    $data['reseller_config']['error'] = 'Config file is not a valid YAML mapping';
                }
            } catch (\Exception $e) {
                $data['reseller_config']['error'] = $e->getMessage();
            }
        } elseif (!$data['reseller_config']['exists']) {
            $data['reseller_config']['error'] = 'Config file not found';
        } elseif (!$data['reseller_config']['readable']) {
            $data['reseller_config']['error'] = 'Config file is not readable (check permissions)';
        }
        
        jsonView($data);
    }

    /**
     * Get device count for sync operations
     * 
     * @return void
     */
    public function get_device_count()
    {
        $excludeExisting = isset($_GET['exclude_existing']) && $_GET['exclude_existing'] === '1';
        
        try {
            // Use Eloquent Query Builder for device list
            $query = Capsule::table('machine')
                ->leftJoin('reportdata', 'machine.serial_number', '=', 'reportdata.serial_number')
                ->select('machine.serial_number');
            
            // Apply machine group filter if applicable
            $filter = get_machine_group_filter();
            if (!empty($filter)) {
                // Extract the WHERE clause content (remove leading WHERE/AND)
                $filter_condition = preg_replace('/^\s*(WHERE|AND)\s+/i', '', $filter);
                if (!empty($filter_condition)) {
                    $query->whereRaw($filter_condition);
                }
            }

            // Loop through each serial number for processing
            $devices = [];
            foreach ($query->get() as $serialobj) {
                $devices[] = $serialobj->serial_number;
            }

            // Filter out devices that already have AppleCare records if requested
            // This includes devices with coverage data AND devices with only device info (no coverage)
            if ($excludeExisting) {
                $existingSerials = Applecare_model::select('serial_number')
                    ->distinct()
                    ->whereIn('serial_number', $devices)
                    ->whereNotNull('serial_number') // Ensure we have a valid serial number
                    ->pluck('serial_number')
                    ->toArray();
                
                $devices = array_diff($devices, $existingSerials);
            }

            jsonView([
                'count' => count($devices),
                'exclude_existing' => $excludeExisting
            ]);
        } catch (\Exception $e) {
            jsonView([
                'count' => 0,
                'error' => $e->getMessage()
            ]);
        }
    }
} 
