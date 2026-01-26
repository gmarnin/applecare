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
     * Uses Eloquent's connection to query munkiinfo table
     * (munkiinfo_model uses legacy Model class, not Eloquent)
     *
     * @param string $serial_number
     * @return string|null ClientID or null if not found
     */
    private function getClientId($serial_number)
    {
        try {
            $result = Applecare_model::getConnectionResolver()
                ->connection()
                ->table('munkiinfo')
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
     * Performs a cross lookup between machine_group and reportdata tables
     * to determine the client's passphrase (machine group key)
     *
     * @param string $serial_number
     * @return string|null Machine group key or null if not found
     */
    private function getMachineGroupKey($serial_number)
    {
        try {
            // Cross lookup: join reportdata with machine_group to get the passphrase
            $result = Applecare_model::getConnectionResolver()
                ->connection()
                ->table('reportdata')
                ->join('machine_group', 'reportdata.machine_group', '=', 'machine_group.groupid')
                ->where('reportdata.serial_number', $serial_number)
                ->where('machine_group.property', 'key')
                ->whereNotNull('machine_group.value')
                ->where('machine_group.value', '!=', '')
                ->value('machine_group.value');
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
        // Normalize to OS-appropriate directory separator
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        // Remove duplicate separators
        $pattern = DIRECTORY_SEPARATOR === '\\' ? '#\\\\+#' : '#/+#';
        $path = preg_replace($pattern, DIRECTORY_SEPARATOR, $path);
        return $path;
    }

    /**
     * Get the local directory path (supports LOCAL_DIRECTORY_PATH env variable)
     *
     * @return string Local directory path
     */
    private function getLocalPath()
    {
        $local_path = getenv('LOCAL_DIRECTORY_PATH');
        if ($local_path) {
            return $this->normalizePath($local_path);
        }
        return $this->normalizePath(APP_ROOT . '/local');
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
        
        $config_path = $this->getLocalPath() . DIRECTORY_SEPARATOR . 'module_configs' . DIRECTORY_SEPARATOR . 'applecare_resellers.yml';
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
        $config_path = $this->getLocalPath() . DIRECTORY_SEPARATOR . 'module_configs' . DIRECTORY_SEPARATOR . 'applecare_resellers.yml';
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
        
        // Debug path to verify request reaches PHP without SSE
        if ($stream && isset($_GET['debug']) && $_GET['debug'] === '1') {
            jsonView([
                'success' => true,
                'message' => 'Debug OK: sync endpoint reached without SSE stream.',
                'timestamp' => time()
            ]);
            return;
        }
        
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
     * Stream sync output without query params (IIS-safe route)
     */
    public function sync_stream()
    {
        return $this->syncStream(false);
    }
    
    /**
     * Stream sync output excluding existing records (IIS-safe route)
     */
    public function sync_stream_exclude()
    {
        return $this->syncStream(true);
    }
    
    /**
     * Debug endpoint to verify sync handler is reachable
     */
    public function sync_debug()
    {
        jsonView([
            'success' => true,
            'message' => 'Debug OK: sync endpoint reached without SSE stream.',
            'timestamp' => time()
        ]);
    }
    
    /**
     * Simple test endpoint for polling sync (no auth for testing)
     */
    public function synctest()
    {
        jsonView([
            'success' => true,
            'message' => 'Sync test endpoint reached',
            'php_binary' => PHP_BINARY,
            'temp_dir' => sys_get_temp_dir(),
            'module_path' => $this->module_path,
            'timestamp' => time()
        ]);
    }
    
    /**
     * Start sync (chunked approach for IIS compatibility)
     * Initializes sync state - actual processing happens via syncchunk endpoint
     */
    public function startsync()
    {
        // Check if user is logged in via session (won't redirect)
        if (empty($_SESSION['user']) && empty($_SESSION['auth'])) {
            jsonView([
                'success' => false,
                'message' => 'Not authorized - please log in',
                'error' => 'auth_required'
            ]);
            return;
        }
        
        // Check if sync is already running
        $status = $this->getSyncStatus();
        if ($status && isset($status['running']) && $status['running']) {
            jsonView([
                'success' => false,
                'message' => 'Sync is already running',
                'running' => true
            ]);
            return;
        }
        
        // Clear any previous stop flag
        $this->clearStopFlag();
        
        // Get parameters (check both POST and GET for flexibility)
        $excludeExisting = (isset($_POST['exclude_existing']) && $_POST['exclude_existing'] === '1') ||
                           (isset($_GET['exclude_existing']) && $_GET['exclude_existing'] === '1');
        
        // Get device list
        $output = [];
        $output[] = "================================================";
        $output[] = "AppleCare Sync Tool";
        $output[] = "================================================";
        $output[] = "";
        
        // Check configuration
        $api_base_url = getenv('APPLECARE_API_URL');
        $client_assertion = getenv('APPLECARE_CLIENT_ASSERTION');
        $rate_limit = (int)getenv('APPLECARE_RATE_LIMIT') ?: 40;
        
        if (empty($client_assertion) || empty($api_base_url)) {
            jsonView([
                'success' => false,
                'message' => 'APPLECARE_API_URL or APPLECARE_CLIENT_ASSERTION not configured'
            ]);
            return;
        }
        
        $output[] = "✓ Configuration OK";
        $output[] = "✓ API URL: $api_base_url";
        $output[] = "✓ Rate Limit: $rate_limit calls per minute";
        
        // Get all devices
        $output[] = "";
        $output[] = "Fetching device list from database...";
        
        try {
            $devices = \Machine_model::select('serial_number')
                ->whereNotNull('serial_number')
                ->where('serial_number', '!=', '')
                ->distinct()
                ->pluck('serial_number')
                ->toArray();
        } catch (\Exception $e) {
            jsonView([
                'success' => false,
                'message' => 'Failed to fetch devices: ' . $e->getMessage()
            ]);
            return;
        }
        
        $total_devices = count($devices);
        
        // If excluding existing, filter out devices that already have AppleCare records
        if ($excludeExisting && $total_devices > 0) {
            $existingSerials = Applecare_model::select('serial_number')
                ->distinct()
                ->pluck('serial_number')
                ->toArray();
            
            $devices = array_values(array_diff($devices, $existingSerials));
            $excludedCount = $total_devices - count($devices);
            $total_devices = count($devices);
            
            $output[] = "✓ Excluding $excludedCount device(s) with existing records";
        }
        
        $output[] = "✓ Found $total_devices devices to process";
        $output[] = "";
        
        if ($total_devices === 0) {
            jsonView([
                'success' => true,
                'message' => 'No devices to sync',
                'output' => implode("\n", $output),
                'complete' => true
            ]);
            return;
        }
        
        // Generate access token
        $output[] = "Generating access token...";
        
        try {
            $access_token = $this->generateAccessToken($client_assertion, $api_base_url);
            $output[] = "✓ Access token generated successfully";
        } catch (\Exception $e) {
            jsonView([
                'success' => false,
                'message' => 'Failed to generate access token: ' . $e->getMessage(),
                'output' => implode("\n", $output)
            ]);
            return;
        }
        
        $output[] = "";
        $output[] = "Starting sync...";
        $output[] = "Rate limit: $rate_limit calls per minute";
        
        // Store sync state in cache
        $syncInfo = [
            'running' => true,
            'started_at' => time(),
            'devices' => $devices,
            'processed' => [],
            'current_index' => 0,
            'total' => $total_devices,
            'synced' => 0,
            'skipped' => 0,
            'errors' => 0,
            'exclude_existing' => $excludeExisting,
            'access_token' => $access_token,
            'api_base_url' => $api_base_url,
            'rate_limit' => $rate_limit,
            'output_buffer' => implode("\n", $output) . "\n",
            'last_request_time' => 0
        ];
        
        try {
            \munkireport\models\Cache::updateOrCreate(
                ['module' => 'applecare', 'property' => 'sync_status'],
                ['value' => json_encode($syncInfo), 'timestamp' => time()]
            );
        } catch (\Exception $e) {
            jsonView([
                'success' => false,
                'message' => 'Failed to save sync status: ' . $e->getMessage()
            ]);
            return;
        }
        
        jsonView([
            'success' => true,
            'message' => 'Sync initialized',
            'total' => $total_devices,
            'output' => implode("\n", $output)
        ]);
    }
    
    /**
     * Process next chunk of devices (called repeatedly by frontend polling)
     * Processes 1-3 devices per call to stay within request timeout
     */
    public function syncchunk()
    {
        // Get current sync status
        $status = $this->getSyncStatus();
        
        if (!$status || !isset($status['running']) || !$status['running']) {
            jsonView([
                'success' => true,
                'running' => false,
                'complete' => true,
                'output' => ''
            ]);
            return;
        }
        
        // Check for stop request
        if ($this->isStopRequested()) {
            $status['running'] = false;
            $output = "\n\nSync stopped by user.\n";
            $output .= $this->formatSyncSummary($status);
            $status['output_buffer'] = ($status['output_buffer'] ?? '') . $output;
            
            $this->saveSyncStatus($status);
            $this->clearStopFlag();
            
            jsonView([
                'success' => true,
                'running' => false,
                'complete' => true,
                'output' => $output,
                'progress' => [
                    'total' => $status['total'],
                    'processed' => $status['current_index'],
                    'synced' => $status['synced'],
                    'skipped' => $status['skipped'],
                    'errors' => $status['errors']
                ]
            ]);
            return;
        }
        
        $output = '';
        $devices = $status['devices'] ?? [];
        $current_index = $status['current_index'] ?? 0;
        $total = $status['total'] ?? count($devices);
        $access_token = $status['access_token'] ?? '';
        $api_base_url = $status['api_base_url'] ?? '';
        $rate_limit = $status['rate_limit'] ?? 40;
        
        // Check if we're done
        if ($current_index >= $total) {
            $status['running'] = false;
            $output = $this->formatSyncSummary($status);
            $status['output_buffer'] = ($status['output_buffer'] ?? '') . $output;
            
            $this->saveSyncStatus($status);
            
            jsonView([
                'success' => true,
                'running' => false,
                'complete' => true,
                'output' => $output,
                'progress' => [
                    'total' => $total,
                    'processed' => $current_index,
                    'synced' => $status['synced'],
                    'skipped' => $status['skipped'],
                    'errors' => $status['errors']
                ]
            ]);
            return;
        }
        
        // Rate limiting: ensure minimum time between requests
        // Each device makes ~3 API calls, so we need to pace accordingly
        $effective_rate_limit = (int)($rate_limit * 0.8);
        $requests_per_device = 3;
        $min_time_per_device = 60 / ($effective_rate_limit / $requests_per_device);
        
        $last_request_time = $status['last_request_time'] ?? 0;
        $time_since_last = time() - $last_request_time;
        
        if ($time_since_last < $min_time_per_device && $last_request_time > 0) {
            // Not enough time has passed, just return current status
            jsonView([
                'success' => true,
                'running' => true,
                'complete' => false,
                'output' => '',
                'waiting' => true,
                'wait_time' => ceil($min_time_per_device - $time_since_last),
                'progress' => [
                    'total' => $total,
                    'processed' => $current_index,
                    'synced' => $status['synced'],
                    'skipped' => $status['skipped'],
                    'errors' => $status['errors']
                ]
            ]);
            return;
        }
        
        // Process one device
        $serial = $devices[$current_index];
        $output .= "Processing $serial... ";
        
        try {
            $result = $this->syncSingleDevice($serial, $access_token, $api_base_url);
            
            if ($result['success']) {
                $output .= "OK (" . $result['records'] . " coverage records)\n";
                $status['synced']++;
            } else {
                $output .= $result['message'] . "\n";
                if (strpos($result['message'], 'SKIP') !== false) {
                    $status['skipped']++;
                } else {
                    $status['errors']++;
                }
            }
        } catch (\Exception $e) {
            $output .= "ERROR (" . $e->getMessage() . ")\n";
            $status['errors']++;
        }
        
        // Update status
        $status['current_index']++;
        $status['last_request_time'] = time();
        $status['output_buffer'] = ($status['output_buffer'] ?? '') . $output;
        
        $this->saveSyncStatus($status);
        
        jsonView([
            'success' => true,
            'running' => true,
            'complete' => false,
            'output' => $output,
            'progress' => [
                'total' => $total,
                'processed' => $status['current_index'],
                'synced' => $status['synced'],
                'skipped' => $status['skipped'],
                'errors' => $status['errors']
            ]
        ]);
    }
    
    /**
     * Format sync summary
     */
    private function formatSyncSummary($status)
    {
        $total = $status['total'] ?? 0;
        $synced = $status['synced'] ?? 0;
        $skipped = $status['skipped'] ?? 0;
        $errors = $status['errors'] ?? 0;
        $started_at = $status['started_at'] ?? time();
        
        $total_time = time() - $started_at;
        $minutes = floor($total_time / 60);
        $seconds = $total_time % 60;
        $time_display = $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";
        
        $output = "\n";
        $output .= "================================================\n";
        $output .= "Sync Complete\n";
        $output .= "================================================\n";
        $output .= "Total devices: $total\n";
        $output .= "Synced: $synced\n";
        $output .= "Skipped: $skipped\n";
        $output .= "Errors: $errors\n";
        $output .= "Total time: $time_display\n";
        $output .= "================================================\n";
        
        return $output;
    }
    
    /**
     * Sync a single device and return result
     */
    /**
     * Execute a cURL request with retry logic for chunked encoding errors
     */
    private function curlExecWithRetry($ch, $max_retries = 3)
    {
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $response = curl_exec($ch);
            $error = curl_error($ch);
            
            // If no error or not a chunked encoding error, return
            if (empty($error) || strpos($error, 'chunk') === false) {
                return ['response' => $response, 'error' => $error];
            }
            
            // Chunked encoding error - wait briefly and retry
            if ($attempt < $max_retries) {
                usleep(500000); // 0.5 second delay
                curl_reset($ch);
                // Re-apply options (they're lost after curl_reset)
                // This is handled by the caller recreating the handle
            }
        }
        
        return ['response' => $response, 'error' => $error];
    }
    
    private function syncSingleDevice($serial, $access_token, $api_base_url)
    {
        // Skip invalid serials
        if (empty($serial) || strlen($serial) < 8) {
            return ['success' => false, 'message' => 'SKIP (invalid serial)'];
        }
        
        $ssl_verify = getenv('APPLECARE_SSL_VERIFY');
        $ssl_verify = ($ssl_verify === 'false' || $ssl_verify === '0' || $ssl_verify === 'no') ? false : true;
        
        // Common cURL options for Apple API
        $curlOptions = function($url) use ($access_token, $ssl_verify) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYPEER => $ssl_verify,
                CURLOPT_SSL_VERIFYHOST => $ssl_verify ? 2 : 0,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FRESH_CONNECT => true, // New connection each time
                CURLOPT_FORBID_REUSE => true,  // Don't reuse connections
            ]);
            return $ch;
        };
        
        // First, fetch device information with retry
        $device_info = [];
        $device_url = $api_base_url . "orgDevices/{$serial}";
        
        $max_retries = 3;
        $device_response = null;
        $device_http_code = 0;
        $device_curl_error = '';
        
        for ($retry = 0; $retry < $max_retries; $retry++) {
            $ch = $curlOptions($device_url);
            $device_response = curl_exec($ch);
            $device_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $device_curl_error = curl_error($ch);
            curl_close($ch);
            
            // If success or not a chunked error, break
            if (empty($device_curl_error) || strpos($device_curl_error, 'chunk') === false) {
                break;
            }
            
            // Chunked error - wait and retry
            if ($retry < $max_retries - 1) {
                usleep(500000); // 0.5 second
            }
        }
        
        if ($device_curl_error && strpos($device_curl_error, 'chunk') !== false) {
            return ['success' => false, 'message' => 'SKIP (chunked encoding error after retries)'];
        }
        
        if ($device_curl_error) {
            return ['success' => false, 'message' => 'ERROR (curl: ' . $device_curl_error . ')'];
        }
        
        if ($device_http_code === 404) {
            return ['success' => false, 'message' => 'SKIP (device not found in ABM)'];
        }
        
        if ($device_http_code === 401) {
            return ['success' => false, 'message' => 'ERROR (token expired)'];
        }
        
        // Extract device info if available
        $device_attrs = [];
        $mdm_server_name = null;
        
        if ($device_http_code === 200) {
            $device_data = json_decode($device_response, true);
            if (isset($device_data['data']['attributes'])) {
                $device_attrs = $device_data['data']['attributes'];
                $device_id = $device_data['data']['id'] ?? null;
                
                // Fetch MDM server info
                if ($device_id) {
                    $mdm_url = $api_base_url . "orgDevices/{$device_id}/assignedServer";
                    
                    for ($mdm_retry = 0; $mdm_retry < $max_retries; $mdm_retry++) {
                        $mdm_ch = $curlOptions($mdm_url);
                        $mdm_response = curl_exec($mdm_ch);
                        $mdm_http_code = curl_getinfo($mdm_ch, CURLINFO_HTTP_CODE);
                        $mdm_error = curl_error($mdm_ch);
                        curl_close($mdm_ch);
                        
                        if (empty($mdm_error) || strpos($mdm_error, 'chunk') === false) {
                            break;
                        }
                        if ($mdm_retry < $max_retries - 1) {
                            usleep(500000);
                        }
                    }
                    
                    if ($mdm_http_code === 200 && empty($mdm_error)) {
                        $mdm_data = json_decode($mdm_response, true);
                        $mdm_server_name = $mdm_data['data']['attributes']['serverName'] ?? null;
                    }
                }
                
                // Build device info
                $device_info = [
                    'model' => $device_attrs['deviceModel'] ?? null,
                    'part_number' => $device_attrs['partNumber'] ?? null,
                    'product_family' => $device_attrs['productFamily'] ?? null,
                    'product_type' => $device_attrs['productType'] ?? null,
                    'color' => $device_attrs['color'] ?? null,
                    'device_capacity' => $device_attrs['deviceCapacity'] ?? null,
                    'device_assignment_status' => $device_attrs['status'] ?? null,
                    'mdm_server' => $mdm_server_name,
                    'purchase_source_type' => $device_attrs['purchaseSourceType'] ?? null,
                    'purchase_source_id' => $device_attrs['purchaseSourceId'] ?? null,
                    'order_number' => $device_attrs['orderNumber'] ?? null,
                    'order_date' => !empty($device_attrs['orderDateTime']) ? date('Y-m-d H:i:s', strtotime($device_attrs['orderDateTime'])) : null,
                    'added_to_org_date' => !empty($device_attrs['addedToOrgDateTime']) ? date('Y-m-d H:i:s', strtotime($device_attrs['addedToOrgDateTime'])) : null,
                    'released_from_org_date' => !empty($device_attrs['releasedFromOrgDateTime']) ? date('Y-m-d H:i:s', strtotime($device_attrs['releasedFromOrgDateTime'])) : null,
                ];
            }
        }
        
        // Fetch AppleCare coverage with retry
        $coverage_url = $api_base_url . "orgDevices/{$serial}/appleCareCoverage";
        
        $response = null;
        $http_code = 0;
        $curl_error = '';
        
        for ($cov_retry = 0; $cov_retry < $max_retries; $cov_retry++) {
            $ch = $curlOptions($coverage_url);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if (empty($curl_error) || strpos($curl_error, 'chunk') === false) {
                break;
            }
            if ($cov_retry < $max_retries - 1) {
                usleep(500000);
            }
        }
        
        if ($curl_error && strpos($curl_error, 'chunk') !== false) {
            return ['success' => false, 'message' => 'SKIP (chunked encoding error after retries)'];
        }
        
        if ($curl_error) {
            return ['success' => false, 'message' => 'ERROR (curl: ' . $curl_error . ')'];
        }
        
        if ($http_code === 404) {
            return ['success' => false, 'message' => 'SKIP (no coverage found)'];
        }
        
        if ($http_code !== 200) {
            return ['success' => false, 'message' => 'SKIP (HTTP ' . $http_code . ')'];
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['data']) || empty($data['data'])) {
            return ['success' => false, 'message' => 'SKIP (no coverage data)'];
        }
        
        // Save coverage records
        $fetch_timestamp = time();
        $records_saved = 0;
        
        foreach ($data['data'] as $coverage) {
            $attrs = $coverage['attributes'] ?? [];
            
            $last_updated = null;
            if (!empty($attrs['updatedDateTime'])) {
                $last_updated = strtotime($attrs['updatedDateTime']);
            }
            
            $coverage_data = array_merge($device_info, [
                'id' => $coverage['id'],
                'serial_number' => $serial,
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
                'last_fetched' => $fetch_timestamp,
            ]);
            
            Applecare_model::updateOrCreate(
                ['id' => $coverage['id']],
                $coverage_data
            );
            
            $records_saved++;
        }
        
        return ['success' => true, 'records' => $records_saved];
    }
    
    /**
     * Save sync status to cache
     */
    private function saveSyncStatus($status)
    {
        try {
            \munkireport\models\Cache::updateOrCreate(
                ['module' => 'applecare', 'property' => 'sync_status'],
                ['value' => json_encode($status), 'timestamp' => time()]
            );
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to save sync status: ' . $e->getMessage());
        }
    }
    
    /**
     * Start sync in background (polling-based approach for IIS compatibility)
     * Spawns CLI script and writes output to a log file
     */
    public function start_sync_background()
    {
        // Check authorization
        if (! $this->authorized('global')) {
            $this->jsonError('Not authorized - admin access required', 403);
            return;
        }
        
        // Check if sync is already running
        $status = $this->getSyncStatus();
        if ($status && $status['running']) {
            jsonView([
                'success' => false,
                'message' => 'Sync is already running',
                'running' => true
            ]);
            return;
        }
        
        // Clear any previous stop flag
        $this->clearStopFlag();
        
        // Get parameters
        $excludeExisting = isset($_GET['exclude_existing']) && $_GET['exclude_existing'] === '1';
        
        // Set up log file path
        $logDir = sys_get_temp_dir();
        $logFile = $logDir . '/applecare_sync_' . time() . '.log';
        
        // Get script and MR root paths
        $scriptPath = realpath($this->module_path . '/sync_applecare.php');
        if (!$scriptPath || !file_exists($scriptPath)) {
            $this->jsonError('sync_applecare.php not found', 500);
            return;
        }
        
        $mrRoot = defined('APP_ROOT') ? APP_ROOT : dirname(dirname(dirname(dirname(__FILE__))));
        if (!is_dir($mrRoot) || !file_exists($mrRoot . '/vendor/autoload.php')) {
            $this->jsonError('MunkiReport root not found: ' . $mrRoot, 500);
            return;
        }
        
        // Build command
        $phpBin = PHP_BINARY ?: 'php';
        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($scriptPath) . ' sync ' . escapeshellarg($mrRoot);
        if ($excludeExisting) {
            $cmd .= ' --exclude-existing';
        }
        
        // Redirect output to log file
        // On Windows, use 'start /B' for background; on Unix use '&'
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: use start /B and redirect output
            $cmd = 'start /B ' . $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1';
            pclose(popen($cmd, 'r'));
        } else {
            // Unix: use nohup and &
            $cmd = 'nohup ' . $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
            exec($cmd);
        }
        
        // Store sync status in cache
        $syncInfo = [
            'running' => true,
            'started_at' => time(),
            'log_file' => $logFile,
            'exclude_existing' => $excludeExisting,
            'last_read_position' => 0
        ];
        
        \munkireport\models\Cache::updateOrCreate(
            ['module' => 'applecare', 'property' => 'sync_status'],
            ['value' => json_encode($syncInfo), 'timestamp' => time()]
        );
        
        jsonView([
            'success' => true,
            'message' => 'Sync started in background',
            'log_file' => basename($logFile)
        ]);
    }
    
    /**
     * Get sync output for polling
     * Returns new lines since last poll
     */
    public function get_sync_output()
    {
        // Check authorization
        if (! $this->authorized('global')) {
            $this->jsonError('Not authorized - admin access required', 403);
            return;
        }
        
        $status = $this->getSyncStatus();
        
        if (!$status || !isset($status['log_file'])) {
            jsonView([
                'success' => true,
                'running' => false,
                'output' => '',
                'complete' => true
            ]);
            return;
        }
        
        $logFile = $status['log_file'];
        $lastPosition = $status['last_read_position'] ?? 0;
        $newOutput = '';
        $complete = false;
        $running = true;
        
        if (file_exists($logFile)) {
            $handle = fopen($logFile, 'r');
            if ($handle) {
                // Seek to last position
                fseek($handle, $lastPosition);
                
                // Read new content
                $newOutput = '';
                while (!feof($handle)) {
                    $newOutput .= fread($handle, 8192);
                }
                
                // Get new position
                $newPosition = ftell($handle);
                fclose($handle);
                
                // Update last read position
                $status['last_read_position'] = $newPosition;
                
                // Check if sync is complete (look for completion markers)
                if (strpos($newOutput, 'Sync Complete') !== false || 
                    strpos($newOutput, 'Exit code:') !== false) {
                    $complete = true;
                    $running = false;
                    $status['running'] = false;
                }
                
                // Also check if process is still running (file not modified in 30 seconds)
                $lastModified = filemtime($logFile);
                if (time() - $lastModified > 30 && $newOutput === '') {
                    // No new output and file not modified - likely process died
                    $complete = true;
                    $running = false;
                    $status['running'] = false;
                }
                
                // Save updated status
                \munkireport\models\Cache::updateOrCreate(
                    ['module' => 'applecare', 'property' => 'sync_status'],
                    ['value' => json_encode($status), 'timestamp' => time()]
                );
            }
        } else {
            // Log file doesn't exist yet - sync may still be starting
            $startedAt = $status['started_at'] ?? 0;
            if (time() - $startedAt > 10) {
                // If more than 10 seconds and no log file, something went wrong
                $complete = true;
                $running = false;
                $newOutput = "ERROR: Sync process failed to start. Log file not created.\n";
            }
        }
        
        // Parse progress from output
        $progress = $this->parseProgressFromOutput($newOutput);
        
        jsonView([
            'success' => true,
            'running' => $running,
            'output' => $newOutput,
            'complete' => $complete,
            'progress' => $progress
        ]);
    }
    
    /**
     * Parse progress information from sync output
     */
    private function parseProgressFromOutput($output)
    {
        $progress = [
            'total' => 0,
            'processed' => 0,
            'synced' => 0,
            'skipped' => 0,
            'errors' => 0
        ];
        
        // Look for "Found X devices"
        if (preg_match('/Found\s+(\d+)\s+devices/i', $output, $matches)) {
            $progress['total'] = (int)$matches[1];
        }
        
        // Count processing results
        $progress['synced'] += preg_match_all('/\bOK\s*\(/i', $output, $m);
        $progress['skipped'] += preg_match_all('/\bSKIP\s*\(/i', $output, $m);
        $progress['errors'] += preg_match_all('/\bERROR\s*\(/i', $output, $m);
        $progress['processed'] = $progress['synced'] + $progress['skipped'] + $progress['errors'];
        
        // Check for final summary
        if (preg_match('/Total devices:\s*(\d+)/i', $output, $matches)) {
            $progress['total'] = (int)$matches[1];
        }
        if (preg_match('/Synced:\s*(\d+)/i', $output, $matches)) {
            $progress['synced'] = (int)$matches[1];
        }
        if (preg_match('/Skipped:\s*(\d+)/i', $output, $matches)) {
            $progress['skipped'] = (int)$matches[1];
        }
        if (preg_match('/Errors:\s*(\d+)/i', $output, $matches)) {
            $progress['errors'] = (int)$matches[1];
        }
        
        return $progress;
    }
    
    /**
     * Get current sync status from cache
     */
    private function getSyncStatus()
    {
        try {
            $cache_value = \munkireport\models\Cache::select('value')
                ->where('module', 'applecare')
                ->where('property', 'sync_status')
                ->value('value');
            
            if ($cache_value) {
                return json_decode($cache_value, true);
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to get sync status: ' . $e->getMessage());
        }
        return null;
    }
    
    /**
     * Clear sync status
     */
    public function clear_sync_status()
    {
        // Check authorization
        if (! $this->authorized('global')) {
            $this->jsonError('Not authorized - admin access required', 403);
            return;
        }
        
        try {
            // Get status to find log file
            $status = $this->getSyncStatus();
            if ($status && isset($status['log_file']) && file_exists($status['log_file'])) {
                @unlink($status['log_file']);
            }
            
            \munkireport\models\Cache::where('module', 'applecare')
                ->where('property', 'sync_status')
                ->delete();
                
            jsonView(['success' => true]);
        } catch (\Exception $e) {
            $this->jsonError('Failed to clear sync status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Load sync progress from cache table
     */
    private function loadProgress()
    {
        $max_retries = 3;
        for ($retry = 0; $retry < $max_retries; $retry++) {
            try {
                $cache_value = \munkireport\models\Cache::select('value')
                    ->where('module', 'applecare')
                    ->where('property', 'sync_progress')
                    ->value('value');
                
                if ($cache_value) {
                    $progress = json_decode($cache_value, true);
                    if ($progress && is_array($progress)) {
                        return $progress;
                    }
                }
                return null;
            } catch (\Exception $e) {
                $error_message = $e->getMessage();
                // Check if it's a connection error
                if (strpos($error_message, 'server has gone away') !== false || 
                    strpos($error_message, 'Lost connection') !== false ||
                    strpos($error_message, '2006') !== false) {
                    if ($retry < $max_retries - 1) {
                        // Force reconnect before retry
                        $this->reconnectDatabase();
                        usleep(500000); // 0.5 seconds
                        continue;
                    }
                }
                // Silently fail - progress is optional
                error_log('AppleCare: Failed to load progress from cache (attempt ' . ($retry + 1) . '): ' . $e->getMessage());
            }
        }
        return null;
    }
    
    /**
     * Reconnect database connection
     */
    private function reconnectDatabase()
    {
        try {
            // Get the Eloquent connection and force reconnect
            /** @var \Illuminate\Database\Connection $connection */
            $connection = Applecare_model::getConnectionResolver()->connection();
            $connection->reconnect();
        } catch (\Exception $e) {
            // Log but don't throw - let retry logic handle it
            error_log("AppleCare: Database reconnect attempt: " . $e->getMessage());
        }
    }

    /**
     * Save sync progress to cache table
     */
    private function saveProgress($devices, $processed, $excludeExisting)
    {
        $max_retries = 3;
        for ($retry = 0; $retry < $max_retries; $retry++) {
            try {
                // Load existing progress to preserve started_at timestamp
                $existing = $this->loadProgress();
                $started_at = $existing && isset($existing['started_at']) ? $existing['started_at'] : time();
                
                $progress = [
                    'devices' => $devices,
                    'processed' => $processed,
                    'exclude_existing' => $excludeExisting,
                    'last_updated' => time(),
                    'started_at' => $started_at
                ];
                
                // Store in cache table instead of file system
                \munkireport\models\Cache::updateOrCreate(
                    ['module' => 'applecare', 'property' => 'sync_progress'],
                    ['value' => json_encode($progress, JSON_PRETTY_PRINT), 'timestamp' => time()]
                );
                return; // Success
            } catch (\Exception $e) {
                $error_message = $e->getMessage();
                // Check if it's a connection error
                if (strpos($error_message, 'server has gone away') !== false || 
                    strpos($error_message, 'Lost connection') !== false ||
                    strpos($error_message, '2006') !== false) {
                    if ($retry < $max_retries - 1) {
                        // Force reconnect before retry
                        $this->reconnectDatabase();
                        usleep(500000); // 0.5 seconds
                        continue;
                    }
                }
                // Log error but don't throw - progress saving is optional
                error_log('AppleCare: Failed to save progress to cache (attempt ' . ($retry + 1) . '): ' . $e->getMessage());
                return; // Give up after retries
            }
        }
    }

    /**
     * Clear sync progress from cache table
     */
    private function clearProgress()
    {
        $max_retries = 3;
        for ($retry = 0; $retry < $max_retries; $retry++) {
            try {
                \munkireport\models\Cache::where('module', 'applecare')
                    ->where('property', 'sync_progress')
                    ->delete();
                return; // Success
            } catch (\Exception $e) {
                $error_message = $e->getMessage();
                // Check if it's a connection error
                if (strpos($error_message, 'server has gone away') !== false || 
                    strpos($error_message, 'Lost connection') !== false ||
                    strpos($error_message, '2006') !== false) {
                    if ($retry < $max_retries - 1) {
                        // Force reconnect before retry
                        $this->reconnectDatabase();
                        usleep(500000); // 0.5 seconds
                        continue;
                    }
                }
                // Silently fail - progress clearing is optional
                error_log('AppleCare: Failed to clear progress from cache (attempt ' . ($retry + 1) . '): ' . $e->getMessage());
                return; // Give up after retries
            }
        }
    }
    
    /**
     * Check if stop has been requested
     */
    private function isStopRequested()
    {
        try {
            $stop_flag = \munkireport\models\Cache::select('value')
                ->where('module', 'applecare')
                ->where('property', 'stop_requested')
                ->value('value');
            return $stop_flag === '1';
        } catch (\Exception $e) {
            // On error, assume not stopped
            return false;
        }
    }
    
    /**
     * Set stop flag
     */
    private function setStopFlag($value = true)
    {
        try {
            \munkireport\models\Cache::updateOrCreate(
                ['module' => 'applecare', 'property' => 'stop_requested'],
                ['value' => $value ? '1' : '0', 'timestamp' => time()]
            );
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to set stop flag: ' . $e->getMessage());
        }
    }
    
    /**
     * Clear stop flag
     */
    private function clearStopFlag()
    {
        try {
            \munkireport\models\Cache::where('module', 'applecare')
                ->where('property', 'stop_requested')
                ->delete();
        } catch (\Exception $e) {
            // Silently fail
        }
    }
    
    /**
     * Stop the running sync (public endpoint for admin panel)
     * Sets a stop flag that the sync loop will check
     *
     * @return void
     * @author tuxudo
     **/
    public function stop_sync()
    {
        // Check authorization
        if (! $this->authorized('global')) {
            $this->jsonError('Not authorized - admin access required', 403);
            return;
        }
        
        try {
            $this->setStopFlag(true);
            
            // Also mark sync as not running so it can be restarted
            $status = $this->getSyncStatus();
            if ($status) {
                $status['running'] = false;
                $status['stopped_at'] = time();
                $this->saveSyncStatus($status);
            }
            
            jsonView([
                'success' => true,
                'message' => 'Stop signal sent. The sync will stop after processing the current device.'
            ]);
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to stop sync: ' . $e->getMessage());
            $this->jsonError('Failed to stop sync: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get current sync progress (public endpoint for admin panel)
     * Returns remaining device count if progress exists
     *
     * @return void
     * @author tuxudo
     **/
    public function get_progress()
    {
        // Check authorization
        if (! $this->authorized('global')) {
            $this->jsonError('Not authorized - admin access required', 403);
            return;
        }
        
        try {
            $progress = $this->loadProgress();
            if ($progress && isset($progress['devices']) && isset($progress['processed'])) {
                $total = count($progress['devices']);
                $processed = count($progress['processed']);
                $remaining = $total - $processed;
                
                jsonView([
                    'success' => true,
                    'has_progress' => true,
                    'total' => $total,
                    'processed' => $processed,
                    'remaining' => $remaining
                ]);
            } else {
                jsonView([
                    'success' => true,
                    'has_progress' => false,
                    'remaining' => 0
                ]);
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to get progress: ' . $e->getMessage());
            jsonView([
                'success' => true,
                'has_progress' => false,
                'remaining' => 0
            ]);
        }
    }
    
    /**
     * Reset sync progress (public endpoint for admin panel)
     * Clears the cached progress so next sync starts from beginning
     *
     * @return void
     * @author tuxudo
     **/
    public function reset_progress()
    {
        // Check authorization
        if (! $this->authorized('global')) {
            $this->jsonError('Not authorized - admin access required', 403);
            return;
        }
        
        try {
            $this->clearProgress();
            
            // Also clear sync_status (used by chunked sync)
            \munkireport\models\Cache::where('module', 'applecare')
                ->where('property', 'sync_status')
                ->delete();
            
            // Clear stop flag
            $this->clearStopFlag();
            
            jsonView([
                'success' => true,
                'message' => 'Sync progress has been reset. The next sync will start from the beginning.'
            ]);
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to reset progress: ' . $e->getMessage());
            $this->jsonError('Failed to reset progress: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Stream sync output in real-time using Server-Sent Events
     * Calls sync logic directly without proc_open
     */
    private function syncStream($excludeExisting = null)
    {
        // Disable PHP execution time limit for long-running sync
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        
        // Disable buffering/compression for IIS/SSE compatibility
        ini_set('output_buffering', 'off');
        ini_set('zlib.output_compression', '0');
        ini_set('implicit_flush', '1');
        
        // Release session lock to prevent blocking other requests
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        // Set headers for Server-Sent Events
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        header('Content-Encoding: none');

        // Flush output immediately
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        
        // Register shutdown handler to surface fatal errors in the UI
        $self = $this;
        register_shutdown_function(function() use ($self) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $message = sprintf(
                    'Fatal error: %s in %s on line %s',
                    $error['message'] ?? 'Unknown error',
                    $error['file'] ?? 'unknown file',
                    $error['line'] ?? 'unknown line'
                );
                $self->sendEvent('error', $message);
                $self->sendEvent('complete', [
                    'exit_code' => 1,
                    'success' => false
                ]);
            }
        });
        
        // Emit padding to force IIS to flush headers and start the stream
        echo ":" . str_repeat(' ', 2048) . "\n\n";
        flush();
        
        // Emit an initial message so the UI confirms the stream started
        $this->sendEvent('output', 'SSE stream initialized. Starting sync...');

        try {
            // Clear any existing stop flag when starting a new sync
            $this->clearStopFlag();
            
            // Check if we should exclude existing records
            if ($excludeExisting === null) {
                $excludeExisting = isset($_GET['exclude_existing']) && $_GET['exclude_existing'] === '1';
            }
            
            // Check for existing progress (resume from previous sync)
            $existingProgress = $this->loadProgress();
            $resuming = false;
            
            if ($existingProgress) {
                // Check if progress is recent (within last 2 hours) and matches current parameters
                $age = time() - $existingProgress['last_updated'];
                if ($age < 7200 && $existingProgress['exclude_existing'] == $excludeExisting) {
                    // Additional safety check: ensure there are devices remaining to process
                    $processed_count = isset($existingProgress['processed']) ? count($existingProgress['processed']) : 0;
                    $total_count = isset($existingProgress['devices']) ? count($existingProgress['devices']) : 0;
                    $remaining = $total_count - $processed_count;
                    
                    if ($remaining > 0) {
                        $resuming = true;
                        $this->sendEvent('output', "Resuming previous sync (interrupted " . round($age / 60) . " minutes ago, $remaining devices remaining)...");
                        // Send resume info to frontend for progress bar initialization
                        $this->sendEvent('resume', [
                            'total' => $total_count,
                            'processed' => $processed_count,
                            'remaining' => $remaining
                        ]);
                    } else {
                        // All devices already processed, clear progress and start fresh
                        $this->clearProgress();
                        $this->sendEvent('output', "Previous sync progress shows all devices completed. Starting fresh sync...");
                    }
                } else {
                    // Progress is too old or parameters changed, start fresh
                    $this->clearProgress();
                }
            }
            
            // Start keep-alive timer to prevent SSE connection timeout
            // Use a more frequent interval (20 seconds) to prevent 1-hour server timeouts
            $last_keepalive = time();
            $keepalive_interval = 20; // Send keep-alive every 20 seconds (more frequent than 30)
            
            // Create a shared reference for keep-alive that can be checked in syncAll
            $keepalive_ref = &$last_keepalive;
            
            // Call sync logic directly with enhanced keep-alive checking and progress tracking
            $this->syncAll(function($message, $isError = false) use (&$keepalive_ref, $keepalive_interval) {
                if ($isError) {
                    $this->sendEvent('error', $message);
                } else {
                    $this->sendEvent('output', $message);
                }
                
                // Send keep-alive comment every 20 seconds to prevent SSE timeout
                // This is critical to prevent 1-hour server/proxy timeouts
                $now = time();
                if ($now - $keepalive_ref >= $keepalive_interval) {
                    $this->sendEvent('comment', 'keep-alive');
                    $keepalive_ref = $now;
                }
            }, $excludeExisting, $keepalive_ref, $keepalive_interval, $existingProgress);

            // Clear progress on successful completion
            $this->clearProgress();

            // Send completion event
            $this->sendEvent('complete', [
                'exit_code' => 0,
                'success' => true
            ]);
        } catch (\Exception $e) {
            // Don't clear progress on error - allow manual resume
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

        $ssl_verify = getenv('APPLECARE_SSL_VERIFY');
        $ssl_verify = ($ssl_verify === 'false' || $ssl_verify === '0' || $ssl_verify === 'no') ? false : true;
        
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
            CURLOPT_SSL_VERIFYPEER => $ssl_verify,
            CURLOPT_SSL_VERIFYHOST => $ssl_verify ? 2 : 0,
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
     * @param bool $excludeExisting Whether to exclude devices with existing records
     * @param int|null $keepalive_ref Reference to last keep-alive time (for SSE streaming)
     * @param int $keepalive_interval Keep-alive interval in seconds (for SSE streaming)
     * @param array|null $existingProgress Existing progress data to resume from
     * @return void
     */
    private function syncAll($outputCallback = null, $excludeExisting = false, &$keepalive_ref = null, $keepalive_interval = 20, $existingProgress = null)
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
        
        // Helper function for interruptible sleep that sends keep-alives during long waits
        // This prevents 1-hour server timeouts by ensuring connection stays alive
        $interruptibleSleep = function($seconds) use (&$keepalive_ref, $keepalive_interval, $outputCallback) {
            if ($keepalive_ref === null) {
                // Not in streaming mode, use regular sleep
                sleep($seconds);
                return;
            }
            
            // Break long sleeps into chunks and send keep-alives
            $chunk_size = min($keepalive_interval - 2, 15); // Sleep in chunks, leaving 2s buffer for keep-alive
            $remaining = $seconds;
            
            while ($remaining > 0) {
                $sleep_time = min($remaining, $chunk_size);
                sleep($sleep_time);
                $remaining -= $sleep_time;
                
                // Send keep-alive if interval has passed
                $now = time();
                if ($now - $keepalive_ref >= $keepalive_interval) {
                    // Send keep-alive via comment (SSE format)
                    echo ": keep-alive\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    $keepalive_ref = $now;
                }
            }
        };
        
        // Wrap output callback to add elapsed time to relevant messages
        $originalCallback = $outputCallback;
        $outputCallback = function($message, $isError = false) use ($originalCallback, $start_time, &$keepalive_ref, $keepalive_interval) {
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
            
            // Send keep-alive if interval has passed (for SSE streaming)
            if ($keepalive_ref !== null) {
                $now = time();
                if ($now - $keepalive_ref >= $keepalive_interval) {
                    // Send keep-alive via comment (SSE format)
                    echo ": keep-alive\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    $keepalive_ref = $now;
                }
            }
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
                    $interruptibleSleep(3);
                    $outputCallback("");
                    break; // Success, exit retry loop
                } catch (\Exception $e) {
                    $retry_count++;
                    // Check if it's a 429 error with retry-after
                    if (preg_match('/HTTP 429.*Retry after (\d+)s/i', $e->getMessage(), $matches)) {
                        $retry_after = (int)$matches[1];
                        if ($retry_count < $max_retries) {
                            $outputCallback("Rate limit hit during token generation. Waiting {$retry_after}s before retry ({$retry_count}/{$max_retries})...");
                            $interruptibleSleep($retry_after);
                            continue; // Retry
                        }
                    } elseif (preg_match('/HTTP 429/i', $e->getMessage())) {
                        // HTTP 429 without retry-after header, wait 30 seconds
                        if ($retry_count < $max_retries) {
                            $outputCallback("Rate limit hit during token generation. Waiting 30s before retry ({$retry_count}/{$max_retries})...");
                            $interruptibleSleep(30);
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

        // Initialize progress tracking
        $processed_serials = [];
        $resuming = false;
        
        // Check if we're resuming from previous sync
        if ($existingProgress && isset($existingProgress['devices']) && isset($existingProgress['processed'])) {
            // Validate progress data
            $devices = $existingProgress['devices'];
            $processed_serials = $existingProgress['processed'];
            
            // Safety: Ensure devices and processed are arrays
            if (!is_array($devices) || !is_array($processed_serials)) {
                $outputCallback("WARNING: Invalid progress data format. Starting fresh sync...");
                $this->clearProgress();
                $existingProgress = null;
            } else {
                // Safety: Ensure processed devices are a subset of devices list
                $invalid_processed = array_diff($processed_serials, $devices);
                if (!empty($invalid_processed)) {
                    $outputCallback("WARNING: Progress file contains " . count($invalid_processed) . " invalid device(s). Cleaning up...");
                    $processed_serials = array_intersect($processed_serials, $devices);
                }
                
                // Safety: Check if all devices are already processed
                $remaining = count($devices) - count($processed_serials);
                if ($remaining <= 0) {
                    $outputCallback("All devices in progress file are already processed. Starting fresh sync...");
                    $this->clearProgress();
                    $existingProgress = null;
                } else {
                    $resuming = true;
                    $processed_count = count($processed_serials);
                    $total_count = count($devices);
                    $remaining = $total_count - $processed_count;
                    $outputCallback("Resuming sync: $processed_count devices already processed, $remaining remaining");
                    // Send resume info for progress tracking
                    $outputCallback("RESUME_INFO:$total_count:$processed_count:$remaining");
                }
            }
        }
        
        if (!$resuming) {
            // Get all devices from database (exact copy from firmware/supported_os)
            $outputCallback("Fetching device list from database...");
            
            try {
                // Use Eloquent Query Builder for device list via Machine_model
                $query = Machine_model::leftJoin('reportdata', 'machine.serial_number', '=', 'reportdata.serial_number')
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
            
            // Save initial progress
            $this->saveProgress($devices, $processed_serials, $excludeExisting);
        }

        $total_devices = count($devices);
        $remaining_devices = $total_devices - count($processed_serials);
        $outputCallback("✓ Found $total_devices devices" . ($resuming ? " ($remaining_devices remaining)" : ""));
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
        // Each device requires 3 API calls (device info + MDM server + coverage)
        $requests_per_device = 3;
        $devices_per_minute = $effective_rate_limit / $requests_per_device;
        
        // Calculate estimated time for remaining devices (not total)
        $remaining_devices = $total_devices - count($processed_serials);
        $estimated_minutes = ceil($remaining_devices / $devices_per_minute);
        $estimated_seconds = $estimated_minutes * 60;
        
        // Send initial estimated time to update header (for remaining devices)
        $outputCallback("ESTIMATED_TIME:" . $estimated_seconds . ":" . $remaining_devices);
        
        $outputCallback("");

        // Use the same sync logic as sync_serial but for all devices
        $device_index = 0;
        $last_heartbeat = time();
        $current_device_retries = 0;
        $max_device_retries = 3; // Max retries per device for 429 errors
        
        // Safety: Track last progress count to detect if we're stuck
        $last_progress_count = count($processed_serials);
        $last_progress_time = time();
        $max_stall_time = 3600; // Abort if no progress for 1 hour
        
        foreach ($devices as $serial) {
            $device_index++;

            // Check if stop has been requested
            if ($this->isStopRequested()) {
                $outputCallback("Stop requested by user. Saving progress and stopping...");
                $this->saveProgress($devices, $processed_serials, $excludeExisting);
                $this->clearStopFlag();
                throw new \Exception("Sync stopped by user. Progress saved. You can resume later.");
            }

            // Skip already-processed devices (when resuming)
            if (in_array($serial, $processed_serials)) {
                continue;
            }
            
            // Safety check: Abort if we've been stuck without making progress for too long
            $current_progress_count = count($processed_serials);
            $now = time();
            if ($current_progress_count > $last_progress_count) {
                // We made progress, reset the timer
                $last_progress_count = $current_progress_count;
                $last_progress_time = $now;
            } elseif (($now - $last_progress_time) > $max_stall_time) {
                // No progress for 1 hour - abort to prevent infinite loop
                throw new \Exception("Sync stalled: No progress made in the last hour. Aborting to prevent infinite loop.");
            }

            // Skip invalid serials
            if (empty($serial) || strlen($serial) < 8) {
                $skipped++;
                // Mark as processed even if invalid (to avoid reprocessing)
                $processed_serials[] = $serial;
                $this->saveProgress($devices, $processed_serials, $excludeExisting);
                // Update estimated time remaining based on configured rate limit
                $remaining_devices = $total_devices - count($processed_serials);
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
                $processed_count = count($processed_serials);
                $current_position = $processed_count + 1; // Next device to process (1-indexed)
                $outputCallback("Heartbeat: Processing device $current_position of $total_devices...");
                $last_heartbeat = $now;
                
                // Update estimated time remaining based on configured rate limit
                $remaining_devices = $total_devices - $processed_count;
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
                // Account for the fact that we'll add 3 requests (device info + MDM server + coverage)
                $requests_in_window = count($request_timestamps);
                $requests_per_device = 3; // Each device sync makes 3 API calls (device info + MDM server + coverage)
                $projected_requests = $requests_in_window + $requests_per_device;
                
                if ($projected_requests > $effective_rate_limit) {
                    // Would exceed limit - wait for oldest request to expire
                    $oldest_timestamp = min($request_timestamps);
                    $time_until_oldest_expires = $rate_limit_window - ($now - $oldest_timestamp);
                    
                    if ($time_until_oldest_expires > 0) {
                        $outputCallback("Rate limit reached ({$requests_in_window}/{$effective_rate_limit}, would be {$projected_requests} with this device). Waiting {$time_until_oldest_expires}s for oldest request to expire...");
                        $interruptibleSleep($time_until_oldest_expires);
                        
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
                        $interruptibleSleep(3);
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
                        // Mark as processed and save progress
                        $processed_serials[] = $serial;
                        $this->saveProgress($devices, $processed_serials, $excludeExisting);
                        // Update estimated time remaining based on configured rate limit
                        $remaining_devices = $total_devices - count($processed_serials);
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
                    
                    // Safety: Prevent infinite retry loops
                    if ($current_device_retries > $max_device_retries) {
                        // Max retries exceeded for this device - skip it
                        $outputCallback("Rate limit hit - max retries ({$max_device_retries}) exceeded. Skipping device.");
                        $skipped++;
                        // Mark as processed and save progress
                        $processed_serials[] = $serial;
                        $this->saveProgress($devices, $processed_serials, $excludeExisting);
                        $current_device_retries = 0; // Reset for next device
                        continue;
                    }
                    
                    // Retry with exponential backoff (capped at reasonable limit)
                    $wait_time = min($result['retry_after'], 300); // Cap at 5 minutes
                    $outputCallback("Rate limit hit (attempt {$current_device_retries}/{$max_device_retries}). Waiting {$wait_time}s before retrying...");
                    $interruptibleSleep($wait_time);
                    // Clean up old timestamps after waiting (they're now expired)
                    $now = time();
                    $request_timestamps = array_filter($request_timestamps, function($timestamp) use ($now, $rate_limit_window) {
                        return ($now - $timestamp) < $rate_limit_window;
                    });
                    // Retry this device by decrementing index (will be incremented again on next iteration)
                    $device_index--; // Decrement to retry same device
                    continue;
                }
                
                // Reset retry counter on successful request (no 429)
                $current_device_retries = 0;
                
                // Track requests AFTER they're made
                // Count ALL API calls that were made - they all consume rate limit quota
                // All devices except "no config found" make API calls:
                // - Success: 3 requests (device info + MDM server + coverage)
                // - 404: 1 request (device lookup fails at 1st call)
                // - Other errors: 1-3 requests (depending on where error occurs)
                // - No config: 0 requests (never calls syncSingleDevice, handled above with continue)
                if (isset($result['requests']) && $result['requests'] > 0) {
                    // Add timestamp for each request made
                    // Note: syncSingleDevice typically makes 3 requests (device info + MDM server + coverage)
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
                
                // Mark device as processed and save progress (for resumable sync)
                $processed_serials[] = $serial;
                $this->saveProgress($devices, $processed_serials, $excludeExisting);
                
                // Calculate time taken for this device (API calls + DB save + processing)
                $device_end_time = microtime(true);
                $time_took = $device_end_time - $device_start_time;
                
                // Don't wait after the last device
                $remaining_devices = $total_devices - count($processed_serials);
                if ($remaining_devices > 0) {
                    // Calculate ideal time per device: 60 seconds / devices_per_minute
                    // devices_per_minute = effective_rate_limit / requests_per_device
                    // e.g., 24 requests / 3 requests per device = 8 devices per minute
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
                $remaining_devices = $total_devices - count($processed_serials);
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
                // Mark as processed even on error (to avoid infinite retry loops) and save progress
                $processed_serials[] = $serial;
                $this->saveProgress($devices, $processed_serials, $excludeExisting);
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
                // Use Eloquent with selectRaw for MAX aggregation
                // Add error handling for database connection issues
                try {
                    $results = Applecare_model::selectRaw('MAX(applecare.purchase_source_id) AS purchase_source_id')
                        ->whereNotNull('applecare.purchase_source_id')
                        ->filter()
                        ->groupBy('applecare.serial_number')
                        ->get();
                } catch (\Exception $e) {
                    // Log error and return empty array
                    error_log('AppleCare get_binary_widget error for purchase_source_name (query failed): ' . $e->getMessage());
                    error_log('AppleCare get_binary_widget error trace: ' . $e->getTraceAsString());
                    jsonView([]);
                    return;
                }

                // Aggregate by purchase_source_id
                $temp_results = [];
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
            // Get one value per device (using MAX to handle cases where device has multiple records)
            // Then count devices by their device_assignment_status
            // This ensures we count each device only once, even if it has multiple coverage records
            // If device_assignment_status is NULL or 'DEVICE_ASSIGNMENT_UNKNOWN' and released_from_org_date is set, infer 'RELEASED'
            // Use Eloquent with selectRaw for CASE statement and aggregation
            $results = Applecare_model::selectRaw("
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
                    ")
                    ->filter()
                    ->groupBy('applecare.serial_number')
                    ->get();

            // Now aggregate by status
            $temp_results = [];
            foreach ($results as $obj) {
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
                // Count distinct devices by enrolled_in_dep status
                // Join with applecare to respect machine group filter and only show devices with AppleCare data
                // Use Eloquent query builder starting from mdm_status table
                $query = Applecare_model::getConnectionResolver()
                    ->connection()
                    ->table('mdm_status')
                    ->select('mdm_status.enrolled_in_dep AS label')
                    ->selectRaw('COUNT(DISTINCT mdm_status.serial_number) AS count')
                    ->leftJoin('reportdata', 'mdm_status.serial_number', '=', 'reportdata.serial_number')
                    ->leftJoin('applecare', 'mdm_status.serial_number', '=', 'applecare.serial_number')
                    ->whereNotNull('mdm_status.enrolled_in_dep')
                    ->whereNotNull('applecare.device_assignment_status');
                
                // Apply machine group filter
                $filter = get_machine_group_filter();
                if (!empty($filter)) {
                    // Extract the WHERE clause content (remove leading WHERE/AND)
                    $filter_condition = preg_replace('/^\s*(WHERE|AND)\s+/i', '', $filter);
                    if (!empty($filter_condition)) {
                        $query->whereRaw($filter_condition);
                    }
                }
                
                $results = $query->groupBy('mdm_status.enrolled_in_dep')
                    ->orderBy('count', 'desc')
                    ->get()
                    ->map(function($item) {
                        return [
                            'label' => (string)$item->label,
                            'count' => (int)$item->count
                        ];
                    })
                    ->toArray();
                
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
        } catch (\Throwable $e) {
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
        try {
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
        } catch (\Throwable $e) {
            error_log('AppleCare sync_serial error: ' . $e->getMessage());
            error_log('AppleCare sync_serial trace: ' . $e->getTraceAsString());
            return $this->jsonError('Sync failed: ' . $e->getMessage(), 500);
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
            $enrolled_in_dep = Applecare_model::getConnectionResolver()
                ->connection()
                ->table('mdm_status')
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
            $serials = Applecare_model::distinct()
                ->whereNotNull('serial_number')
                ->pluck('serial_number');
            
            $updated = 0;
            foreach ($serials as $serial) {
                if (empty($serial)) {
                    continue;
                }
                
                // Reset all plans for this device
                Applecare_model::where('serial_number', $serial)
                    ->update(['is_primary' => 0, 'coverage_status' => null]);
                
                // Get all plans for this device, sorted by end date desc (latest first)
                $plans = Applecare_model::where('serial_number', $serial)
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
                Applecare_model::where('id', $primary->id)
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
        $config_path = $this->getLocalPath() . DIRECTORY_SEPARATOR . 'module_configs' . DIRECTORY_SEPARATOR . 'applecare_resellers.yml';
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
        // Check both POST and GET for flexibility
        $excludeExisting = (isset($_POST['exclude_existing']) && $_POST['exclude_existing'] === '1') ||
                           (isset($_GET['exclude_existing']) && $_GET['exclude_existing'] === '1');
        
        try {
            // Use Eloquent Query Builder for device list via Machine_model
            $query = Machine_model::leftJoin('reportdata', 'machine.serial_number', '=', 'reportdata.serial_number')
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

    /**
     * Get data for scroll widget
     *
     * @param string $column Column name (e.g., 'mdm_server')
     * @return void
     * @author tuxudo
     **/
    public function get_scroll_widget($column)
    {
        // Sanitize input - remove non-column name characters
        $column = preg_replace("/[^A-Za-z0-9_\-]/", '', $column);
        
        // Whitelist allowed columns to prevent column injection
        $allowed_columns = ['mdm_server'];
        
        if (empty($column) || !in_array($column, $allowed_columns)) {
            jsonView([]);
            return;
        }

        try {
            // Use Eloquent query builder with filter() for machine group filtering
            // Only count devices with primary plans (is_primary = 1)
            // Use COUNT(DISTINCT) because a device can have multiple coverage records
            // Column is whitelisted and sanitized above, safe to use in selectRaw
            $results = Applecare_model::selectRaw('applecare.' . $column . ' AS label')
                ->selectRaw('COUNT(DISTINCT applecare.serial_number) AS count')
                ->where('applecare.is_primary', 1)
                ->whereNotNull('applecare.' . $column)
                ->where('applecare.' . $column, '!=', '')
                ->filter()
                ->groupBy('applecare.' . $column)
                ->orderBy('count', 'desc')
                ->get()
                ->map(function($item) {
                    return [
                        'label' => (string)$item->label,
                        'count' => (int)$item->count
                    ];
                })
                ->toArray();
            
            jsonView($results);
        } catch (\Exception $e) {
            error_log("AppleCare: Error in get_scroll_widget for {$column}: " . $e->getMessage());
            error_log("AppleCare: Error trace: " . $e->getTraceAsString());
            jsonView([]);
        }
    }
} 
