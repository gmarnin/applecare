<?php

namespace munkireport\module\applecare;

use Symfony\Component\Yaml\Yaml;

class Applecare_helper
{
    private $resellerConfigLoaded = false;
    private $resellerConfig = array();
    private $progressDirtyCount = 0;
    private $progressLastSave = 0;

    /**
     * Force database reconnection
     * 
     * Called when we detect "server has gone away" to ensure
     * the next query uses a fresh connection.
     */
    private function reconnectDatabase()
    {
        try {
            // Get the Eloquent connection and force reconnect
            /** @var \Illuminate\Database\Connection $connection */
            $connection = \Applecare_model::getConnectionResolver()->connection();
            $connection->reconnect();
        } catch (\Exception $e) {
            // If reconnect fails, log but don't throw - let the retry logic handle it
            error_log("AppleCare: Database reconnect attempt: " . $e->getMessage());
        }
    }

    /**
     * Get Munki ClientID for a serial number
     * 
     * Uses Eloquent's query builder through the connection (munkiinfo uses legacy Model)
     *
     * @param string $serial_number
     * @return string|null ClientID or null if not found
     */
    private function getClientId($serial_number)
    {
        try {
            // Use Eloquent's connection to query munkiinfo table
            // (munkiinfo_model uses legacy Model class, not Eloquent)
            $result = \Applecare_model::getConnectionResolver()
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
            $result = \Applecare_model::getConnectionResolver()
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
     * 1. Machine group key prefix (e.g., "6F730D13-451108" -> "6F730D13_APPLECARE_API_URL")
     * 2. ClientID prefix (e.g., "abcd-efg" -> "ABCD_APPLECARE_API_URL")
     * 3. Default config (APPLECARE_API_URL, APPLECARE_CLIENT_ASSERTION)
     *
     * @param string $serial_number
     * @return array|null
     */
    private function getAppleCareConfig($serial_number)
    {
        $api_url = null;
        $client_assertion = null;
        $rate_limit = 25;
        $sync_interval_days = 7;
        $org_rate_set = false;
        $org_interval_set = false;
        $matched_prefix = 'default';

        // Try machine group key prefix first.
        // No hyphen means the whole key is the prefix. Unsafe characters fall through.
        $mg_key = $this->getMachineGroupKey($serial_number);
        $mg_prefix = $this->envPrefixFromValue($mg_key);
        if ($mg_prefix !== null) {
            $org_api_url_key = $mg_prefix . '_APPLECARE_API_URL';
            $org_assertion_key = $mg_prefix . '_APPLECARE_CLIENT_ASSERTION';
            $org_rate_limit_key = $mg_prefix . '_APPLECARE_RATE_LIMIT';
            $org_interval_key = $mg_prefix . '_APPLECARE_SYNC_INTERVAL_DAYS';

            $api_url = getenv($org_api_url_key);
            $client_assertion = getenv($org_assertion_key);
            $org_rate_limit = getenv($org_rate_limit_key);
            $org_interval = getenv($org_interval_key);

            if ($org_rate_limit !== false && $org_rate_limit !== '' && (int)$org_rate_limit > 0) {
                $rate_limit = (int)$org_rate_limit;
                $org_rate_set = true;
            }
            if ($org_interval !== false && $org_interval !== '' && (int)$org_interval > 0) {
                $sync_interval_days = (int)$org_interval;
                $org_interval_set = true;
            }
            if (!empty($api_url) && !empty($client_assertion)) {
                $matched_prefix = $mg_prefix;
            }
        }

        // Fallback to ClientID prefix if machine group key not found or config vars are empty
        if (empty($api_url) || empty($client_assertion)) {
            $client_id = $this->getClientId($serial_number);
            $client_prefix = $this->envPrefixFromValue($client_id);
            if ($client_prefix !== null) {
                $org_api_url_key = $client_prefix . '_APPLECARE_API_URL';
                $org_assertion_key = $client_prefix . '_APPLECARE_CLIENT_ASSERTION';
                $org_rate_limit_key = $client_prefix . '_APPLECARE_RATE_LIMIT';
                $org_interval_key = $client_prefix . '_APPLECARE_SYNC_INTERVAL_DAYS';

                if (empty($api_url)) {
                    $api_url = getenv($org_api_url_key);
                }
                if (empty($client_assertion)) {
                    $client_assertion = getenv($org_assertion_key);
                }
                $org_rate_limit = getenv($org_rate_limit_key);
                if (!$org_rate_set && $org_rate_limit !== false && $org_rate_limit !== '' && (int)$org_rate_limit > 0) {
                    $rate_limit = (int)$org_rate_limit;
                    $org_rate_set = true;
                }
                $org_interval = getenv($org_interval_key);
                if (!$org_interval_set && $org_interval !== false && $org_interval !== '' && (int)$org_interval > 0) {
                    $sync_interval_days = (int)$org_interval;
                    $org_interval_set = true;
                }
                if (!empty($api_url) && !empty($client_assertion)) {
                    $matched_prefix = $client_prefix;
                }
            }
        }

        // Fallback to default config if org-specific not found
        if (empty($api_url)) {
            $api_url = getenv('APPLECARE_API_URL');
            $matched_prefix = 'default';
        }
        if (empty($client_assertion)) {
            $client_assertion = getenv('APPLECARE_CLIENT_ASSERTION');
            $matched_prefix = 'default';
        }
        if (!$org_rate_set) {
            $default_rate_limit = getenv('APPLECARE_RATE_LIMIT');
            if ($default_rate_limit !== false && $default_rate_limit !== '' && (int)$default_rate_limit > 0) {
                $rate_limit = (int)$default_rate_limit;
            }
        }
        if (!$org_interval_set) {
            $default_interval = getenv('APPLECARE_SYNC_INTERVAL_DAYS');
            if ($default_interval !== false && $default_interval !== '' && (int)$default_interval > 0) {
                $sync_interval_days = (int)$default_interval;
            }
        }

        if (empty($api_url) || empty($client_assertion)) {
            return null;
        }

        return [
            'api_url' => $api_url,
            'client_assertion' => $client_assertion,
            'rate_limit' => self::capRequestsPerMinute($rate_limit),
            'sync_interval_days' => $sync_interval_days,
            'prefix' => $matched_prefix,
        ];
    }

    /**
     * Prefix before the first hyphen, or the whole value when there is no hyphen.
     * Reject characters that cannot be an env-var name segment.
     *
     * @param string|null $value
     * @return string|null
     */
    private function envPrefixFromValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parts = explode('-', (string)$value, 2);
        $prefix = strtoupper($parts[0]);
        if (!preg_match('/^[A-Z0-9]{1,32}$/', $prefix)) {
            return null;
        }
        return $prefix;
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
     * @param string $reseller_id
     * @return string|null
     */
    public function getResellerName($reseller_id)
    {
        if (empty($reseller_id)) {
            return null;
        }

        $normalized_config = $this->loadResellerConfig();
        if (empty($normalized_config)) {
            return $reseller_id;
        }

        if (isset($normalized_config[$reseller_id])) {
            return $normalized_config[$reseller_id];
        }

        $reseller_id_upper = strtoupper($reseller_id);
        foreach ($normalized_config as $key => $value) {
            if (strtoupper($key) === $reseller_id_upper) {
                return $value;
            }
        }

        return $reseller_id;
    }

    /**
     * Flat string map of reseller id => name. Empty when the file is missing or invalid.
     *
     * @return array
     */
    public function getResellerConfigMap()
    {
        return $this->loadResellerConfig();
    }

    /**
     * @return array
     */
    private function loadResellerConfig()
    {
        if ($this->resellerConfigLoaded) {
            return $this->resellerConfig;
        }
        $this->resellerConfigLoaded = true;
        $this->resellerConfig = array();

        $config_path = $this->normalizePath(APP_ROOT . '/local/module_configs/applecare_resellers.yml');
        if (!file_exists($config_path) || !is_readable($config_path)) {
            return $this->resellerConfig;
        }

        try {
            $config = Yaml::parseFile($config_path);
            if (!is_array($config)) {
                return $this->resellerConfig;
            }
            foreach ($config as $key => $value) {
                if (is_scalar($value)) {
                    $this->resellerConfig[(string)$key] = (string)$value;
                }
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Error loading reseller config from ' . $config_path . ': ' . $e->getMessage());
            $this->resellerConfig = array();
        }

        return $this->resellerConfig;
    }

    /**
     * @param string $serial_number
     * @return bool
     */
    public function isValidSerial($serial_number)
    {
        return is_string($serial_number) && preg_match('/^[A-Za-z0-9]{8,32}$/', $serial_number) === 1;
    }

    /**
     * PHP 7 curl handles are resources. PHP 8 uses CurlHandle.
     *
     * @param \CurlHandle|resource $ch
     * @return void
     */
    private function restrictCurlToHttps($ch)
    {
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
    }

    /**
     * @param string $client_assertion
     * @return string
     */
    private function cleanAssertion($client_assertion)
    {
        $client_assertion = trim((string)$client_assertion);
        $client_assertion = preg_replace('/\s+/', '', $client_assertion);
        return trim($client_assertion, '"\'');
    }

    /**
     * @param string $assertion
     * @param string $scope
     * @return string
     */
    private function tokenCacheKey($assertion, $scope)
    {
        return 'access_token_' . hash('sha256', $this->cleanAssertion($assertion) . '|' . $scope);
    }

    /**
     * @param string $api_base_url
     * @return string
     */
    private function apiScope($api_base_url)
    {
        if (strpos($api_base_url, 'api-school') !== false) {
            return 'school.api';
        }
        return 'business.api';
    }

    /**
     * @param string $client_assertion
     * @param string $api_base_url
     * @param bool $forceRefresh
     * @return string
     */
    /**
     * 25 requests per minute is the maximum and the default. That pace stayed under Apple's 429s.
     * A lower APPLECARE_RATE_LIMIT is kept. A higher one is treated as 25.
     *
     * @param int|string|false|null $rate
     * @return int
     */
    public static function capRequestsPerMinute($rate)
    {
        $rate = (int)$rate;
        if ($rate < 1 || $rate > 25) {
            return 25;
        }
        return $rate;
    }

    public function getAccessToken($client_assertion, $api_base_url, $forceRefresh = false, $outputCallback = null, $requestsPerMinute = 25, $onSlice = null)
    {
        $scope = $this->apiScope($api_base_url);
        $key = $this->tokenCacheKey($client_assertion, $scope);
        if (!$forceRefresh) {
            $cached = $this->readCachedToken($key);
            if ($cached !== null) {
                return $cached;
            }
        }
        return $this->generateAccessToken($client_assertion, $api_base_url, $key, $scope, $outputCallback, $requestsPerMinute, $onSlice);
    }

    /**
     * @param string $key
     * @return string|null
     */
    private function readCachedToken($key)
    {
        try {
            $row = \munkireport\models\Cache::where('module', 'applecare')
                ->where('property', $key)
                ->first();
            if ($row && (int)$row->timestamp > time() && $row->value !== null && $row->value !== '') {
                return (string)$row->value;
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to read cached token: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * @param string $status
     * @param string|null $releasedFromOrgDate
     * @return string|null
     */
    private function normalizeAssignmentStatus($status, $releasedFromOrgDate)
    {
        if (!empty($releasedFromOrgDate) && ($status === null || $status === '' || $status === 'DEVICE_ASSIGNMENT_UNKNOWN')) {
            return 'RELEASED';
        }
        return $status;
    }

    /**
     * Backfill RELEASED for rows written before normalization.
     *
     * @return void
     */
    public function normalizeStoredReleasedStatus()
    {
        \Applecare_model::whereNotNull('released_from_org_date')
            ->where(function ($query) {
                $query->whereNull('device_assignment_status')
                    ->orWhere('device_assignment_status', '')
                    ->orWhere('device_assignment_status', 'DEVICE_ASSIGNMENT_UNKNOWN');
            })
            ->update(['device_assignment_status' => 'RELEASED']);
    }

    /**
     * Recompute coverage_status at most once an hour. Dates are Y-m-d strings.
     *
     * @return void
     */
    public function refreshCoverageStatus()
    {
        $now = time();
        try {
            $claimed = \munkireport\models\Cache::where('module', 'applecare')
                ->where('property', 'coverage_status_refresh')
                ->where('timestamp', '<=', $now - 3600)
                ->update(['value' => '1', 'timestamp' => $now]);
            if (!$claimed) {
                $exists = \munkireport\models\Cache::where('module', 'applecare')
                    ->where('property', 'coverage_status_refresh')
                    ->exists();
                if ($exists) {
                    return;
                }
                \munkireport\models\Cache::create([
                    'module' => 'applecare',
                    'property' => 'coverage_status_refresh',
                    'value' => '1',
                    'timestamp' => $now
                ]);
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to claim coverage refresh stamp: ' . $e->getMessage());
            return;
        }

        $today = date('Y-m-d');
        $plus31 = date('Y-m-d', strtotime('+31 days'));

        try {
            \Applecare_model::where('is_primary', 1)->update(['coverage_status' => 'inactive']);

            \Applecare_model::where('is_primary', 1)
                ->whereRaw('UPPER(status) = ?', ['ACTIVE'])
                ->where('isCanceled', 0)
                ->where('endDateTime', '>=', $today)
                ->where('endDateTime', '<', $plus31)
                ->update(['coverage_status' => 'expiring_soon']);

            \Applecare_model::where('is_primary', 1)
                ->whereRaw('UPPER(status) = ?', ['ACTIVE'])
                ->where('isCanceled', 0)
                ->where('endDateTime', '>=', $plus31)
                ->update(['coverage_status' => 'active']);

            $this->normalizeStoredReleasedStatus();
        } catch (\Exception $e) {
            error_log('AppleCare: Coverage status refresh failed: ' . $e->getMessage());
            try {
                \munkireport\models\Cache::where('module', 'applecare')
                    ->where('property', 'coverage_status_refresh')
                    ->delete();
            } catch (\Exception $clearError) {
                error_log('AppleCare: Failed to clear coverage refresh stamp: ' . $clearError->getMessage());
            }
        }
    }

    /**
     * Generate access token from client assertion
     *
     * @param string $client_assertion
     * @param string $api_base_url
     * @return string
     */
    private function generateAccessToken($client_assertion, $api_base_url, $cacheKey, $scope, $outputCallback = null, $requestsPerMinute = 25, $onSlice = null)
    {
        $client_assertion = $this->cleanAssertion($client_assertion);

        $parts = explode('.', $client_assertion);
        if (count($parts) !== 3) {
            throw new \Exception('Invalid client assertion format. Expected JWT token with 3 parts.');
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $client_id = (is_array($payload) && isset($payload['sub'])) ? $payload['sub'] : null;

        if (empty($client_id)) {
            throw new \Exception('Could not extract client ID from assertion.');
        }

        $ch = curl_init('https://account.apple.com/auth/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
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
        $this->restrictCurlToHttps($ch);

        $tokenStartedAt = microtime(true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        // Extract headers before closing handle (same pattern as syncSingleDevice)
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        curl_close($ch);
        $this->logAppleRequest($outputCallback, 'POST oauth/token', $tokenStartedAt, $http_code);
        $this->waitBetweenRequests($requestsPerMinute, $onSlice);

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

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \Exception('No access token in response');
        }

        $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;
        $ttl = $expiresIn - 300;
        if ($ttl < 60) {
            $ttl = 60;
        }
        try {
            \munkireport\models\Cache::updateOrCreate(
                ['module' => 'applecare', 'property' => $cacheKey],
                ['value' => $data['access_token'], 'timestamp' => time() + $ttl]
            );
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to cache access token: ' . $e->getMessage());
        }

        return $data['access_token'];
    }

    /**
     * Clock time a request was sent, with milliseconds.
     *
     * @param float $microtime
     * @return string
     */
    private function formatRequestTimestamp($microtime)
    {
        $seconds = (int)floor($microtime);
        $millis = (int)round(($microtime - $seconds) * 1000);
        if ($millis >= 1000) {
            $seconds++;
            $millis = 0;
        }
        return date('H:i:s', $seconds) . sprintf('.%03d', $millis);
    }

    /**
     * @param callable|null $outputCallback
     * @param string $label
     * @param float $startedAt microtime(true) from just before curl_exec
     * @param int $httpCode
     * @return void
     */
    private function logAppleRequest($outputCallback, $label, $startedAt, $httpCode)
    {
        if (!is_callable($outputCallback)) {
            return;
        }
        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
        if ($elapsedMs < 0) {
            $elapsedMs = 0;
        }
        $outputCallback($this->formatRequestTimestamp($startedAt) . ' ' . $label . ' -> HTTP ' . (int)$httpCode . ' in ' . $elapsedMs . 'ms');
    }

    /**
     * Wait 60/rate seconds after one Apple API call.
     * Uses usleep so a fractional second (1.2s at 50/min) is not truncated by sleep().
     *
     * @param int $requestsPerMinute
     * @param callable|null $onSlice Called between 1-second slices so a long wait can flush SSE
     * @return void
     */
    private function waitBetweenRequests($requestsPerMinute, $onSlice = null)
    {
        $requestsPerMinute = self::capRequestsPerMinute($requestsPerMinute);
        $remainingUs = (int)round((60 / $requestsPerMinute) * 1000000);
        if ($remainingUs < 1) {
            return;
        }
        while ($remainingUs > 0) {
            $slice = $remainingUs > 1000000 ? 1000000 : $remainingUs;
            usleep($slice);
            $remainingUs -= $slice;
            if ($remainingUs > 0 && is_callable($onSlice)) {
                $onSlice();
            }
        }
    }

    /**
     * Sync a single device
     *
     * @param string $serial_number
     * @param string $api_base_url
     * @param string $access_token
     * @param callable|null $outputCallback Optional callback for progress updates (message, isError)
     * @param callable|null $afterRequest Called after each Apple API call
     * @return array ['success' => bool, 'records' => int, 'requests' => int, 'message' => string, 'rate_limit' => int|null, 'rate_limit_remaining' => int|null]
     */
    public function syncSingleDevice($serial_number, $api_base_url, $access_token, $outputCallback = null, $afterRequest = null)
    {
        if ($outputCallback === null) {
            $outputCallback = function($message, $isError = false) {};
        }

        if (!$this->isValidSerial($serial_number)) {
            return $this->syncResult(false, 0, 0, 'Invalid serial number', 400);
        }

        $requests = 0;
        $noteRequest = function () use (&$requests, $afterRequest) {
            $requests++;
            if (is_callable($afterRequest)) {
                $afterRequest();
            }
        };
        $device_info = [];
        $device_attrs = [];
        $detected_rate_limit = null;
        $detected_rate_limit_remaining = null;

        // First, fetch device information
        $device_url = $api_base_url . 'orgDevices/' . rawurlencode($serial_number);
        
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
        $this->restrictCurlToHttps($ch);

        $deviceStartedAt = microtime(true);
        $device_response = curl_exec($ch);
        $device_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $device_curl_error = curl_error($ch);
        
        // Extract body from response (since CURLOPT_HEADER is true)
        $device_header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $device_body = substr($device_response, $device_header_size);
        
        curl_close($ch);
        $this->logAppleRequest($outputCallback, 'GET orgDevices ' . $serial_number, $deviceStartedAt, $device_http_code);
        $noteRequest();

        // Temporary logging for all fetches
        // error_log("AppleCare FETCH: Device lookup for {$serial_number} - URL: {$device_url} - HTTP {$device_http_code}");

        // If device not found in ABM, skip immediately
        if ($device_http_code === 401) {
            return $this->syncResult(false, 0, $requests, 'SKIP (HTTP 401) - Authentication failed on device lookup', 401);
        }

        if ($device_http_code === 404) {
            $this->markApiFetch($serial_number);
            return $this->syncResult(false, 0, $requests, 'SKIP (HTTP 404) - Device not found in Apple Business/School Manager', 404);
        }

        // Handle rate limit (HTTP 429) on device lookup - return immediately with retry_after
        if ($device_http_code === 429) {
            $retry_after = 30; // Default
            $device_headers = substr($device_response, 0, $device_header_size);
            if (preg_match('/Retry-After:\s*(\d+)/i', $device_headers, $matches)) {
                $retry_after = (int)$matches[1];
            }
            if ($retry_after < 1) {
                $retry_after = 30;
            }
            if (preg_match('/X-RateLimit-Limit:\s*(\d+)/i', $device_headers, $limit_matches) && (int)$limit_matches[1] > 0) {
                $detected_rate_limit = (int)$limit_matches[1];
            }
            return $this->syncResult(false, 0, $requests, 'SKIP (HTTP 429 - Rate limit exceeded on device lookup)', 429, $retry_after, $detected_rate_limit);
        }

        // Extract device information if available
        if ($device_http_code === 200) {
            if ($device_curl_error) {
                error_log("AppleCare: Device lookup cURL error for {$serial_number}: {$device_curl_error}");
            } else {
                $device_data = json_decode($device_body, true);
                
                if (isset($device_data['data']['attributes'])) {
                    $device_attrs = $device_data['data']['attributes'];
                    $device_id = $device_data['data']['id'] ?? null;
                    
                    // Fetch MDM server information if device ID is available
                    $mdm_server_name = null;
                    if ($device_id) {
                        $mdm_server_url = $api_base_url . 'orgDevices/' . rawurlencode((string)$device_id) . '/assignedServer';
                        $mdm_ch = curl_init($mdm_server_url);
                        curl_setopt($mdm_ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($mdm_ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $access_token,
                            'Content-Type: application/json',
                        ]);
                        curl_setopt($mdm_ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                        curl_setopt($mdm_ch, CURLOPT_SSL_VERIFYPEER, true);
                        curl_setopt($mdm_ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($mdm_ch, CURLOPT_CONNECTTIMEOUT, 10);
                        $this->restrictCurlToHttps($mdm_ch);
                        
                        $mdmStartedAt = microtime(true);
                        $mdm_response = curl_exec($mdm_ch);
                        $mdm_http_code = curl_getinfo($mdm_ch, CURLINFO_HTTP_CODE);
                        $mdm_curl_error = curl_error($mdm_ch);
                        curl_close($mdm_ch);
                        $this->logAppleRequest($outputCallback, 'GET assignedServer ' . $serial_number, $mdmStartedAt, $mdm_http_code);
                        $noteRequest();
                        
                        // Only process if successful (200) - 404 means no MDM server assigned
                        if ($mdm_http_code === 200) {
                            $mdm_data = json_decode($mdm_response, true);
                            
                            // Check for JSON decode errors
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                error_log("AppleCare: MDM server JSON decode error for {$serial_number}: " . json_last_error_msg() . " - Response: " . substr($mdm_response, 0, 500));
                            } else {
                                // The API returns serverName, not name
                                // Try multiple possible locations for serverName
                                if (isset($mdm_data['data']['attributes']['serverName'])) {
                                    $mdm_server_name = $mdm_data['data']['attributes']['serverName'];
                                } elseif (isset($mdm_data['data']['attributes']['name'])) {
                                    // Fallback to 'name' if serverName not found
                                    $mdm_server_name = $mdm_data['data']['attributes']['name'];
                                } elseif (isset($mdm_data['data']['attributes']['server_name'])) {
                                    // Fallback to snake_case
                                    $mdm_server_name = $mdm_data['data']['attributes']['server_name'];
                                } else {
                                    // Log for debugging - check what the actual response structure is
                                    error_log("AppleCare: MDM server response for {$serial_number} - HTTP 200 but serverName not found. Full response: " . substr($mdm_response, 0, 1000));
                                    error_log("AppleCare: MDM server response structure: " . print_r($mdm_data, true));
                                }
                                
                                // Ensure we preserve the full server name including spaces
                                if ($mdm_server_name !== null) {
                                    $mdm_server_name = trim($mdm_server_name); // Only trim whitespace, don't remove spaces
                                }
                            }
                        } elseif ($mdm_http_code === 404) {
                            // 404 is expected if device has no MDM server assigned - no logging needed
                        } else {
                            // Log other errors for debugging
                            error_log("AppleCare: MDM server lookup failed for {$serial_number} - HTTP {$mdm_http_code}" . ($mdm_curl_error ? " - cURL error: {$mdm_curl_error}" : ""));
                        }
                    }
                    
                    // Map available fields from Apple Business Manager API
                    $device_info = [
                        'serial_number' => $serial_number,
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
                        'order_date' => null,
                        'added_to_org_date' => null,
                        'released_from_org_date' => null,
                        'wifi_mac_address' => null,
                        'ethernet_mac_address' => null,
                        'bluetooth_mac_address' => null,
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
                    
                    // Handle MAC address array fields (API 1.5+ returns arrays for all MAC addresses)
                    if (!empty($device_attrs['wifiMacAddress'])) {
                        $device_info['wifi_mac_address'] = is_array($device_attrs['wifiMacAddress']) 
                            ? implode(', ', array_filter($device_attrs['wifiMacAddress'])) 
                            : $device_attrs['wifiMacAddress'];
                    }
                    if (!empty($device_attrs['bluetoothMacAddress'])) {
                        $device_info['bluetooth_mac_address'] = is_array($device_attrs['bluetoothMacAddress']) 
                            ? implode(', ', array_filter($device_attrs['bluetoothMacAddress'])) 
                            : $device_attrs['bluetoothMacAddress'];
                    }
                    if (!empty($device_attrs['ethernetMacAddress'])) {
                        $device_info['ethernet_mac_address'] = is_array($device_attrs['ethernetMacAddress']) 
                            ? implode(', ', array_filter($device_attrs['ethernetMacAddress'])) 
                            : $device_attrs['ethernetMacAddress'];
                    }

                    $device_info['device_assignment_status'] = $this->normalizeAssignmentStatus(
                        isset($device_info['device_assignment_status']) ? $device_info['device_assignment_status'] : null,
                        isset($device_info['released_from_org_date']) ? $device_info['released_from_org_date'] : null
                    );
                } else {
                    // HTTP 200 but unexpected JSON structure
                    error_log("AppleCare: Device lookup returned 200 for {$serial_number} but JSON structure unexpected. Response: " . substr($device_body, 0, 500));
                }
            }
        } else {
            // Non-200/404/429 response - log warning but continue to fetch coverage
            error_log("AppleCare: Device lookup failed for {$serial_number} with HTTP {$device_http_code}, but continuing to fetch coverage");
        }

        // Call Apple API for AppleCare coverage
        $url = $api_base_url . 'orgDevices/' . rawurlencode($serial_number) . '/appleCareCoverage';

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
        $this->restrictCurlToHttps($ch);

        $coverageStartedAt = microtime(true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        
        // Get response headers for rate limit information
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        curl_close($ch);
        $this->logAppleRequest($outputCallback, 'GET appleCareCoverage ' . $serial_number, $coverageStartedAt, $http_code);
        $noteRequest();

        // Temporary logging for all fetches
        // error_log("AppleCare FETCH: Coverage for {$serial_number} - URL: {$url} - HTTP {$http_code}");

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
                $this->restrictCurlToHttps($ch);
                
                $retryStartedAt = microtime(true);
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers = substr($response, 0, $header_size);
                $body = substr($response, $header_size);
                curl_close($ch);
                $this->logAppleRequest($outputCallback, 'GET appleCareCoverage retry ' . $serial_number, $retryStartedAt, $http_code);
                $noteRequest();

                // Temporary logging for all fetches (retry)
                // error_log("AppleCare FETCH: Coverage for {$serial_number} - URL: {$url} - HTTP {$http_code} (RETRY)");

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
                    $detected_rate_limit = (int)trim(substr($header_line, 18));
                } elseif (stripos($header_line, 'X-Rate-Limit-Limit:') === 0) {
                    $detected_rate_limit = (int)trim(substr($header_line, 20));
                } elseif (stripos($header_line, 'X-RateLimit-Remaining:') === 0) {
                    $detected_rate_limit_remaining = (int)trim(substr($header_line, 22));
                } elseif (stripos($header_line, 'X-Rate-Limit-Remaining:') === 0) {
                    $detected_rate_limit_remaining = (int)trim(substr($header_line, 24));
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
            
            // Use Retry-After if provided, otherwise default to 30 seconds
            $wait_time = $retry_after ?: 30;
            
            $error_msg = "SKIP (HTTP 429 - Rate limit exceeded)";
            if ($retry_after) {
                $error_msg .= " - Retry after {$retry_after}s";
            }
            if ($rate_limit_reset) {
                $reset_time = date('Y-m-d H:i:s', $rate_limit_reset);
                $error_msg .= " - Rate limit resets at {$reset_time}";
            }
            
            return $this->syncResult(false, 0, $requests, $error_msg, 429, $wait_time, ((int)$detected_rate_limit > 0 ? (int)$detected_rate_limit : null), $detected_rate_limit_remaining);
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

            return $this->syncResult(false, 0, $requests, $error_msg, (int)$http_code);
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return $this->syncResult(false, 0, $requests, 'SKIP (invalid JSON)', (int)$http_code);
        }
        if (isset($data['data']) && !is_array($data['data'])) {
            return $this->syncResult(false, 0, $requests, 'SKIP (invalid JSON)', (int)$http_code);
        }

        $coverageList = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : array();
        $hasNextPage = isset($data['links']['next']) && $data['links']['next'];

        if (count($coverageList) === 0) {
            // No coverage, but we still have device info - save it
            // This happens when devices are released from the org but still exist in ABM
            if (!empty($device_info) && isset($device_info['serial_number'])) {
                $fetch_timestamp = time();
                
                // Use API's updatedDateTime if available
                $last_updated = null;
                if (!empty($device_attrs['updatedDateTime'])) {
                    $last_updated = strtotime($device_attrs['updatedDateTime']);
                }
                
                // Prepare device data (without coverage fields)
                $device_data = array_merge($device_info, [
                    'id' => $serial_number . '_NO_COVERAGE', // Placeholder ID for devices with no coverage
                    'serial_number' => $serial_number,
                    'status' => null, // No coverage status
                    'description' => null,
                    'agreementNumber' => null,
                    'paymentType' => null,
                    'isRenewable' => 0,
                    'isCanceled' => 0,
                    'startDateTime' => null,
                    'endDateTime' => null,
                    'contractCancelDateTime' => null,
                    'last_updated' => $last_updated,
                    'last_fetched' => $fetch_timestamp,
                ]);
                
                // Translate reseller ID to name if config exists
                if (!empty($device_data['purchase_source_id'])) {
                    $resellerName = $this->getResellerName($device_data['purchase_source_id']);
                    if ($resellerName && $resellerName !== $device_data['purchase_source_id']) {
                        $device_data['purchase_source_name'] = $resellerName;
                        $device_data['purchase_source_id_display'] = $device_data['purchase_source_id'];
                    }
                }
                
                // Update or create device info record
                // First, try to update any existing records for this serial number (with retry for connection timeouts)
                $existing_records = null;
                $max_retries = 3;
                for ($retry = 0; $retry < $max_retries; $retry++) {
                    try {
                        $existing_records = \Applecare_model::where('serial_number', $serial_number)->get();
                        break; // Success
                    } catch (\Exception $e) {
                        $error_message = $e->getMessage();
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
                        throw $e;
                    }
                }
                if ($existing_records->count() > 0) {
                    // Update all existing records with latest device info
                    foreach ($existing_records as $record) {
                        // Only update device info fields, preserve coverage fields
                        $update_data = [
                            'model' => $device_data['model'],
                            'part_number' => $device_data['part_number'],
                            'product_family' => $device_data['product_family'],
                            'product_type' => $device_data['product_type'],
                            'color' => $device_data['color'],
                            'device_capacity' => $device_data['device_capacity'],
                            'device_assignment_status' => $device_data['device_assignment_status'],
                            'mdm_server' => $device_data['mdm_server'] ?? null,
                            'purchase_source_type' => $device_data['purchase_source_type'],
                            'purchase_source_id' => $device_data['purchase_source_id'],
                            'purchase_source_name' => $device_data['purchase_source_name'] ?? null,
                            'purchase_source_id_display' => $device_data['purchase_source_id_display'] ?? null,
                            'order_number' => $device_data['order_number'],
                            'order_date' => $device_data['order_date'],
                            'added_to_org_date' => $device_data['added_to_org_date'],
                            'released_from_org_date' => $device_data['released_from_org_date'],
                            'wifi_mac_address' => $device_data['wifi_mac_address'],
                            'ethernet_mac_address' => $device_data['ethernet_mac_address'],
                            'bluetooth_mac_address' => $device_data['bluetooth_mac_address'],
                            'last_fetched' => $fetch_timestamp,
                        ];
                        $record->update($update_data);
                    }
                } else {
                    // No existing records, create a placeholder record with device info
                    $max_retries = 3;
                    $retry_count = 0;
                    $saved = false;
                    
                    while ($retry_count < $max_retries && !$saved) {
                        try {
                            \Applecare_model::updateOrCreate(
                                ['id' => $device_data['id']],
                                $device_data
                            );
                            $saved = true;
                        } catch (\Exception $e) {
                            $error_message = $e->getMessage();
                            // Check if it's a connection error
                            if (strpos($error_message, 'server has gone away') !== false || 
                                strpos($error_message, 'Lost connection') !== false ||
                                strpos($error_message, '2006') !== false) {
                                $retry_count++;
                                if ($retry_count < $max_retries) {
                                    // Force reconnect before retry
                                    $this->reconnectDatabase();
                                    usleep(500000); // 0.5 seconds
                                } else {
                                    error_log("AppleCare: Failed to save device info record after {$max_retries} retries: {$error_message}");
                                    throw $e;
                                }
                            } else {
                                throw $e;
                            }
                        }
                    }
                }
                
                // Update which plan is "the one" for this device
                $this->updatePrimaryPlan($serial_number);
                
                // Device info was collected - count as successful API call
                return $this->syncResult(true, 0, $requests, 'No Coverage, getting device Information', 200, 0, $detected_rate_limit, $detected_rate_limit_remaining);
            }
            
            // No coverage and no device info - this is a true skip
            return $this->syncResult(false, 0, $requests, 'SKIP (no coverage)', 200, 0, $detected_rate_limit, $detected_rate_limit_remaining);
        }

        // Save coverage data with device information
        // Only update last_fetched when we actually fetch and save coverage data
        $fetch_timestamp = time();
        $records_saved = 0;
        $savedIds = array();
        $saveFailed = false;
        foreach ($coverageList as $coverage) {
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

            // Insert or update with retry logic for connection timeouts
            $max_retries = 3;
            $retry_count = 0;
            $saved = false;
            
            while ($retry_count < $max_retries && !$saved) {
                try {
                    \Applecare_model::updateOrCreate(
                        ['id' => $coverage['id']],
                        $coverage_data
                    );
                    $saved = true;
                } catch (\Exception $e) {
                    $error_message = $e->getMessage();
                    // Check if it's a connection error
                    if (strpos($error_message, 'server has gone away') !== false || 
                        strpos($error_message, 'Lost connection') !== false ||
                        strpos($error_message, '2006') !== false) {
                        $retry_count++;
                        if ($retry_count < $max_retries) {
                            // Force reconnect before retry
                            $this->reconnectDatabase();
                            usleep(500000); // 0.5 seconds
                        } else {
                            error_log("AppleCare: Failed to save coverage record after {$max_retries} retries: {$error_message}");
                            throw $e;
                        }
                    } else {
                        // Not a connection error, rethrow immediately
                        throw $e;
                    }
                }
            }
            
            if ($saved) {
                $records_saved++;
                if (isset($coverage['id'])) {
                    $savedIds[] = $coverage['id'];
                }
            } else {
                $saveFailed = true;
            }
        }

        if (!$saveFailed && !$hasNextPage && count($savedIds) > 0) {
            \Applecare_model::where('serial_number', $serial_number)
                ->whereNotIn('id', $savedIds)
                ->delete();
        }

        // Update which plan is "the one" for this device
        $this->updatePrimaryPlan($serial_number);

        return $this->syncResult(true, $records_saved, $requests, '', 200, 0, $detected_rate_limit, $detected_rate_limit_remaining);
    }

    /**
     * Update which plan is marked as primary for a device and set coverage_status
     * 
     * Logic (same as tab's get_data):
     * - Pick the plan with the latest end date (treating null as very old date)
     * - This is the "most relevant" plan for display purposes
     * 
     * Coverage status is then determined based on the primary plan:
     * - "active": Plan is active (status=ACTIVE, not canceled, end date > 30 days from now)
     * - "expiring_soon": Plan is active but end date <= 30 days from now
     * - "inactive": Plan is not active (status != ACTIVE, or canceled, or end date in past)
     * 
     * @param string $serial_number
     * @return void
     */
    public function updatePrimaryPlan($serial_number)
    {
        if (empty($serial_number)) {
            return;
        }

        try {
            $now = date('Y-m-d');
            $plus31 = date('Y-m-d', strtotime('+31 days'));
            
            // Get all plans for this device
            $plans = \Applecare_model::where('serial_number', $serial_number)->get();
            
            if ($plans->isEmpty()) {
                return;
            }

            // Reset all plans to non-primary and clear coverage_status
            \Applecare_model::where('serial_number', $serial_number)
                ->update(['is_primary' => 0, 'coverage_status' => null]);

            // Pick the plan with the latest end date (same logic as tab's get_data)
            // Treat null end dates as very old (1970-01-01)
            $primary = $plans->sortByDesc(function($plan) {
                return $this->dateOnly($plan->endDateTime);
            })->first();

            if ($primary) {
                $endDate = $this->dateOnly($primary->endDateTime);
                if ($endDate === '1970-01-01' && empty($primary->endDateTime)) {
                    $endDate = '';
                }
                
                $status = strtoupper($primary->status ?? '');
                $isCanceled = !empty($primary->isCanceled);
                
                // Compare Y-m-d strings only. Do not compare DateTime objects to strings.
                $isActive = $status === 'ACTIVE' 
                    && !$isCanceled 
                    && $endDate !== '' 
                    && $endDate >= $now;
                
                if ($isActive) {
                    $coverageStatus = ($endDate < $plus31) ? 'expiring_soon' : 'active';
                } else {
                    // Inactive (expired, canceled, or status != ACTIVE)
                    $coverageStatus = 'inactive';
                }
                
                \Applecare_model::where('id', $primary->id)
                    ->update(['is_primary' => 1, 'coverage_status' => $coverageStatus]);
            }
        } catch (\Exception $e) {
            error_log("AppleCare: Failed to update primary plan for {$serial_number}: " . $e->getMessage());
        }
    }

    /**
     * True when a client check-in should call Apple.
     * A saved fetch or a 404 starts the interval. A 429 or other failure does not.
     *
     * @param string $serial_number
     * @return bool
     */
    public function clientSyncDue($serial_number)
    {
        if (!$this->isValidSerial($serial_number)) {
            return false;
        }

        $device_config = $this->getAppleCareConfig($serial_number);
        if (!$device_config) {
            return false;
        }

        $days = isset($device_config['sync_interval_days']) ? (int)$device_config['sync_interval_days'] : 7;
        if ($days < 1) {
            $days = 7;
        }

        $last = $this->lastSuccessfulApiFetch($serial_number);
        if ($last === null) {
            return true;
        }

        $waitSeconds = ($days * 86400) + ($this->serialIntervalOffsetHours($serial_number) * 3600);
        return (time() - $last) >= $waitSeconds;
    }

    /**
     * Stable 0–11 hour spread so serials that fetched together do not come due together.
     *
     * @param string $serial_number
     * @return int
     */
    private function serialIntervalOffsetHours($serial_number)
    {
        $piece = hexdec(substr(hash('sha256', $serial_number), 0, 8));
        return (int)($piece % 12);
    }

    /**
     * Newest completed Apple fetch for this serial.
     * Coverage saves store last_fetched. A 404 stores a cache stamp and no listing row.
     *
     * @param string $serial_number
     * @return int|null
     */
    private function lastSuccessfulApiFetch($serial_number)
    {
        $rowTime = null;
        $max_retries = 3;
        for ($retry = 0; $retry < $max_retries; $retry++) {
            try {
                $rowTime = \Applecare_model::where('serial_number', $serial_number)->max('last_fetched');
                break;
            } catch (\Exception $e) {
                $error_message = $e->getMessage();
                if ($retry < $max_retries - 1 && (
                    strpos($error_message, 'server has gone away') !== false ||
                    strpos($error_message, 'Lost connection') !== false ||
                    strpos($error_message, '2006') !== false
                )) {
                    $this->reconnectDatabase();
                    usleep(500000);
                    continue;
                }
                throw $e;
            }
        }

        $cacheTime = null;
        try {
            $cached = \munkireport\models\Cache::where('module', 'applecare')
                ->where('property', 'api_fetch_' . $serial_number)
                ->first();
            if ($cached && $cached->value !== null && $cached->value !== '') {
                $cacheTime = (int)$cached->value;
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to read API fetch time for ' . $serial_number . ': ' . $e->getMessage());
        }

        $rowTime = (int)$rowTime;
        if ($rowTime < 1 && ($cacheTime === null || $cacheTime < 1)) {
            return null;
        }
        if ($rowTime < 1) {
            return $cacheTime;
        }
        if ($cacheTime === null || $cacheTime < 1) {
            return $rowTime;
        }
        return $rowTime > $cacheTime ? $rowTime : $cacheTime;
    }

    /**
     * Remember a completed Apple lookup that did not write an applecare row.
     *
     * @param string $serial_number
     * @return void
     */
    private function markApiFetch($serial_number)
    {
        if (!$this->isValidSerial($serial_number)) {
            return;
        }

        $now = time();
        try {
            \munkireport\models\Cache::updateOrCreate(
                ['module' => 'applecare', 'property' => 'api_fetch_' . $serial_number],
                ['value' => (string)$now, 'timestamp' => $now]
            );
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to record API fetch for ' . $serial_number . ': ' . $e->getMessage());
        }
    }

    /**
     * Sync AppleCare data for a single serial number
     *
     * @param string $serial_number
     * @return array
     */
    public function syncSerial($serial_number)
    {
        if (!$this->isValidSerial($serial_number)) {
            return $this->syncResult(false, 0, 0, 'Invalid serial number', 400);
        }

        try {
            $device_config = $this->getAppleCareConfig($serial_number);
            if (!$device_config) {
                return $this->syncResult(false, 0, 0, 'AppleCare API not configured', 0);
            }

            $api_base_url = $device_config['api_url'];
            if (substr($api_base_url, -1) !== '/') {
                $api_base_url .= '/';
            }

            $serialRate = self::capRequestsPerMinute($device_config['rate_limit']);
            $access_token = $this->getAccessToken($device_config['client_assertion'], $api_base_url, false, null, $serialRate);
            $afterRequest = function () use ($serialRate) {
                $this->waitBetweenRequests($serialRate, null);
            };
            $result = $this->syncSingleDevice($serial_number, $api_base_url, $access_token, null, $afterRequest);
            if (isset($result['http_code']) && (int)$result['http_code'] === 401) {
                $access_token = $this->getAccessToken($device_config['client_assertion'], $api_base_url, true, null, $serialRate);
                $result = $this->syncSingleDevice($serial_number, $api_base_url, $access_token, null, $afterRequest);
            }
            return $result;
        } catch (\Exception $e) {
            return $this->syncResult(false, 0, 0, 'Sync failed: ' . $e->getMessage(), 0);
        }
    }

    /**
     * @param bool $success
     * @param int $records
     * @param int $requests
     * @param string $message
     * @param int $httpCode
     * @param int $retryAfter
     * @param int|null $rateLimit
     * @param int|null $rateLimitRemaining
     * @return array
     */
    private function syncResult($success, $records, $requests, $message, $httpCode = 0, $retryAfter = 0, $rateLimit = null, $rateLimitRemaining = null)
    {
        return array(
            'success' => (bool)$success,
            'records' => (int)$records,
            'requests' => (int)$requests,
            'message' => (string)$message,
            'http_code' => (int)$httpCode,
            'retry_after' => (int)$retryAfter,
            'rate_limit' => $rateLimit,
            'rate_limit_remaining' => $rateLimitRemaining,
        );
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function dateOnly($value)
    {
        if ($value instanceof \DateTime || (is_object($value) && method_exists($value, 'format'))) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
            return $matches[1];
        }
        if (is_numeric($value)) {
            return date('Y-m-d', (int)$value);
        }
        return '1970-01-01';
    }

    /**
     * @param bool $excludeExisting
     * @param bool $applyMachineGroupFilter
     * @return int
     */
    public function countDevices($excludeExisting, $applyMachineGroupFilter)
    {
        $query = $this->deviceQuery($applyMachineGroupFilter, $excludeExisting);
        $row = $query->selectRaw('COUNT(DISTINCT machine.serial_number) AS device_count')->first();
        if (!$row || !isset($row->device_count)) {
            return 0;
        }
        return (int)$row->device_count;
    }

    /**
     * @param bool $applyMachineGroupFilter
     * @param bool $excludeExisting
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function deviceQuery($applyMachineGroupFilter, $excludeExisting)
    {
        $query = \Machine_model::leftJoin('reportdata', 'machine.serial_number', '=', 'reportdata.serial_number')
            ->whereNotNull('machine.serial_number')
            ->where('machine.serial_number', '!=', '');
        if ($applyMachineGroupFilter) {
            $this->applyMachineGroupFilter($query);
        }
        if ($excludeExisting) {
            $query->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('applecare')
                    ->whereColumn('applecare.serial_number', 'machine.serial_number');
            });
        }
        return $query;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    private function applyMachineGroupFilter($query)
    {
        if (!function_exists('get_machine_group_filter')) {
            return;
        }
        $filter = get_machine_group_filter();
        if (!empty($filter)) {
            $filter_condition = preg_replace('/^\s*(WHERE|AND)\s+/i', '', $filter);
            if (!empty($filter_condition)) {
                $query->whereRaw($filter_condition);
            }
        }
    }

    /**
     * @return array|null
     */
    public function loadSyncProgress()
    {
        try {
            $cache_value = \munkireport\models\Cache::select('value')
                ->where('module', 'applecare')
                ->where('property', 'sync_progress')
                ->value('value');
            if ($cache_value) {
                $progress = json_decode($cache_value, true);
                if (is_array($progress)) {
                    return $progress;
                }
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to load progress from cache: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * @return void
     */
    public function clearSyncProgress()
    {
        try {
            \munkireport\models\Cache::where('module', 'applecare')
                ->where('property', 'sync_progress')
                ->delete();
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to clear progress from cache: ' . $e->getMessage());
        }
    }

    /**
     * @return bool
     */
    public function isStopRequested()
    {
        try {
            $stop_flag = \munkireport\models\Cache::select('value')
                ->where('module', 'applecare')
                ->where('property', 'stop_requested')
                ->value('value');
            return $stop_flag === '1';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param bool $value
     * @return void
     */
    public function setStopFlag($value = true)
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
     * @return void
     */
    public function clearStopFlag()
    {
        try {
            \munkireport\models\Cache::where('module', 'applecare')
                ->where('property', 'stop_requested')
                ->delete();
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to clear stop flag: ' . $e->getMessage());
        }
    }

    /**
     * ETA for a fleet that is in Apple Business Manager: 3 calls per device.
     * Each call is the pace gap plus 0.7s for Apple's response.
     *
     * @param int $deviceCount
     * @param int $requestsPerMinute
     * @return int
     */
    private function estimatedSyncSeconds($deviceCount, $requestsPerMinute)
    {
        $deviceCount = (int)$deviceCount;
        $requestsPerMinute = self::capRequestsPerMinute($requestsPerMinute);
        if ($deviceCount < 1) {
            return 0;
        }
        $secondsPerRequest = (60 / $requestsPerMinute) + 0.7;
        return (int)ceil($deviceCount * 3 * $secondsPerRequest);
    }

    /**
     * Fleet sync used by the admin SSE path and the CLI.
     *
     * @param callable|null $outputCallback
     * @param bool $excludeExisting
     * @param bool $applyMachineGroupFilter
     * @param bool $streaming
     * @param array|null $existingProgress
     * @param bool $useWebProgress When false, do not read or write the admin progress and stop flag.
     * @return void
     */
    public function syncAllDevices($outputCallback = null, $excludeExisting = false, $applyMachineGroupFilter = true, $streaming = false, $existingProgress = null, $useWebProgress = true)
    {
        $start_time = time();
        $lastKeepalive = time();
        $keepaliveInterval = 20;
        $finished = false;
        $devices = array();
        $processed_serials = array();
        $processedMap = array();

        if ($outputCallback === null) {
            $outputCallback = function ($message, $isError = false) {
                echo $message . "\n";
            };
        }

        $writeProgress = function ($force) use (&$devices, &$processed_serials, $excludeExisting, $start_time, &$finished, $useWebProgress) {
            if (!$useWebProgress) {
                return;
            }
            if ($finished && !$force) {
                return;
            }
            $this->progressDirtyCount++;
            $now = time();
            if (!$force && $this->progressDirtyCount < 25 && ($now - $this->progressLastSave) < 30) {
                return;
            }
            try {
                $progress = array(
                    'devices' => array_values($devices),
                    'processed' => array_values($processed_serials),
                    'exclude_existing' => (bool)$excludeExisting,
                    'last_updated' => $now,
                    'started_at' => $start_time,
                );
                \munkireport\models\Cache::updateOrCreate(
                    ['module' => 'applecare', 'property' => 'sync_progress'],
                    ['value' => json_encode($progress), 'timestamp' => $now]
                );
                $this->progressDirtyCount = 0;
                $this->progressLastSave = $now;
            } catch (\Exception $e) {
                error_log('AppleCare: Failed to save progress: ' . $e->getMessage());
            }
        };

        register_shutdown_function(function () use (&$finished, $writeProgress) {
            if (!$finished) {
                $writeProgress(true);
            }
        });

        $touchKeepalive = function () use ($streaming, &$lastKeepalive, $keepaliveInterval) {
            if (!$streaming) {
                return;
            }
            $now = time();
            if ($now - $lastKeepalive >= $keepaliveInterval) {
                echo ": keep-alive\n\n";
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
                $lastKeepalive = $now;
            }
        };

        $interruptibleSleep = function ($seconds) use ($touchKeepalive, $streaming) {
            $seconds = (int)$seconds;
            if ($seconds < 1) {
                return;
            }
            if (!$streaming) {
                sleep($seconds);
                return;
            }
            $remaining = $seconds;
            while ($remaining > 0) {
                $chunk = $remaining > 15 ? 15 : $remaining;
                sleep($chunk);
                $remaining -= $chunk;
                $touchKeepalive();
            }
        };

        $outputCallback("================================================");
        $outputCallback("AppleCare Sync Tool");
        $outputCallback("================================================");
        $outputCallback("");

        $default_rate_limit = self::capRequestsPerMinute(getenv('APPLECARE_RATE_LIMIT'));

        $resuming = false;
        if (is_array($existingProgress) && isset($existingProgress['devices']) && isset($existingProgress['processed']) && is_array($existingProgress['devices']) && is_array($existingProgress['processed'])) {
            $devices = array_values($existingProgress['devices']);
            $processed_serials = array_values(array_intersect($existingProgress['processed'], $devices));
            if ((count($devices) - count($processed_serials)) > 0) {
                $resuming = true;
                $outputCallback('Resuming sync: ' . count($processed_serials) . ' devices already processed, ' . (count($devices) - count($processed_serials)) . ' remaining');
                $outputCallback('RESUME_INFO:' . count($devices) . ':' . count($processed_serials) . ':' . (count($devices) - count($processed_serials)));
            } else {
                if ($useWebProgress) {
                    $this->clearSyncProgress();
                }
                $devices = array();
                $processed_serials = array();
            }
        }

        if (!$resuming) {
            $outputCallback('Fetching device list from database...');
            $query = $this->deviceQuery($applyMachineGroupFilter, $excludeExisting);
            $devices = array();
            foreach ($query->select('machine.serial_number')->distinct()->get() as $serialobj) {
                $devices[] = $serialobj->serial_number;
            }
            if ($excludeExisting) {
                $outputCallback('Excluding devices that already have AppleCare records');
            }
            $writeProgress(true);
        }

        foreach ($processed_serials as $doneSerial) {
            $processedMap[$doneSerial] = true;
        }

        $total_devices = count($devices);
        $outputCallback('Found ' . $total_devices . ' devices' . ($resuming ? ' (' . ($total_devices - count($processed_serials)) . ' remaining)' : ''));
        $outputCallback('');

        if ($total_devices === 0) {
            $finished = true;
            if ($useWebProgress) {
                $this->clearSyncProgress();
            }
            throw new \Exception('No devices found in database. Devices must check in to MunkiReport first.');
        }

        $synced = 0;
        $errors = 0;
        $skipped = 0;
        $windows = array();
        $limits = array();

        $estimateRate = $default_rate_limit;
        $remaining_devices = $total_devices - count($processed_serials);
        $outputCallback('ESTIMATED_TIME:' . $this->estimatedSyncSeconds($remaining_devices, $estimateRate) . ':' . $remaining_devices);
        $outputCallback('');
        $outputCallback('Starting sync...');

        $markProcessed = function ($serial) use (&$processed_serials, &$processedMap, $writeProgress, &$devices, $total_devices, &$estimateRate, $outputCallback) {
            if (!isset($processedMap[$serial])) {
                $processed_serials[] = $serial;
                $processedMap[$serial] = true;
            }
            $writeProgress(false);
            $remaining = $total_devices - count($processed_serials);
            if ($remaining > 0) {
                $outputCallback('ESTIMATED_TIME:' . $this->estimatedSyncSeconds($remaining, $estimateRate) . ':' . $remaining);
            } else {
                $outputCallback('ESTIMATED_TIME:0:0');
            }
        };

        foreach ($devices as $serial) {
            $touchKeepalive();
            if ($useWebProgress && $this->isStopRequested()) {
                $writeProgress(true);
                $this->clearStopFlag();
                throw new \Exception('Sync stopped by user. Progress saved. You can resume later.');
            }
            if (isset($processedMap[$serial])) {
                continue;
            }
            if (!$this->isValidSerial($serial)) {
                $skipped++;
                $outputCallback('SKIP (invalid serial)');
                $markProcessed($serial);
                continue;
            }

            $outputCallback('Processing ' . $serial . '... ');
            $device_config = $this->getAppleCareConfig($serial);
            if (!$device_config) {
                $outputCallback('SKIP (no config found)');
                $skipped++;
                $markProcessed($serial);
                continue;
            }

            $api_base_url = $device_config['api_url'];
            if (substr($api_base_url, -1) !== '/') {
                $api_base_url .= '/';
            }
            $credKey = $this->tokenCacheKey($device_config['client_assertion'], $this->apiScope($api_base_url));
            if (!isset($limits[$credKey]) || (int)$limits[$credKey] <= 0) {
                $limits[$credKey] = self::capRequestsPerMinute($device_config['rate_limit'] > 0 ? $device_config['rate_limit'] : $default_rate_limit);
            }
            $baseLimit = (int)$limits[$credKey];
            if ($baseLimit <= 0) {
                $baseLimit = 25;
            }
            $estimateRate = $baseLimit;

            if (!isset($windows[$credKey]) || !is_array($windows[$credKey])) {
                $windows[$credKey] = array();
            }
            $now = time();
            $windows[$credKey] = array_values(array_filter($windows[$credKey], function ($timestamp) use ($now) {
                return ($now - $timestamp) < 60;
            }));
            $inWindow = count($windows[$credKey]);
            if ($inWindow >= $baseLimit && $inWindow > 0) {
                $oldest = min($windows[$credKey]);
                $wait = 60 - ($now - $oldest);
                if ($wait > 0) {
                    $outputCallback('Rate limit reached (' . $inWindow . '/' . $baseLimit . '). Waiting ' . $wait . 's...');
                    $interruptibleSleep($wait);
                    $now = time();
                    $windows[$credKey] = array_values(array_filter($windows[$credKey], function ($timestamp) use ($now) {
                        return ($now - $timestamp) < 60;
                    }));
                }
            }

            $authRetries = 0;
            $rateRetries = 0;
            $result = $this->syncResult(false, 0, 0, 'SKIP (no result)', 0);
            try {
                $access_token = $this->getAccessToken($device_config['client_assertion'], $api_base_url, false, $outputCallback, $baseLimit, $touchKeepalive);
                while (true) {
                    if ($useWebProgress && $this->isStopRequested()) {
                        $writeProgress(true);
                        $this->clearStopFlag();
                        throw new \Exception('Sync stopped by user. Progress saved. You can resume later.');
                    }
                    $pacePerMinute = $baseLimit;
                    $afterRequest = function () use ($pacePerMinute, $touchKeepalive) {
                        $this->waitBetweenRequests($pacePerMinute, $touchKeepalive);
                    };
                    $result = $this->syncSingleDevice($serial, $api_base_url, $access_token, $outputCallback, $afterRequest);
                    $made = isset($result['requests']) ? (int)$result['requests'] : 0;
                    $stamp = time();
                    for ($i = 0; $i < $made; $i++) {
                        $windows[$credKey][] = $stamp;
                    }
                    if (isset($result['rate_limit']) && (int)$result['rate_limit'] > 0) {
                        $limits[$credKey] = self::capRequestsPerMinute($result['rate_limit']);
                    }
                    if (isset($result['http_code']) && (int)$result['http_code'] === 401 && $authRetries === 0) {
                        $access_token = $this->getAccessToken($device_config['client_assertion'], $api_base_url, true, $outputCallback, $baseLimit, $touchKeepalive);
                        $authRetries++;
                        continue;
                    }
                    if ((int)$result['http_code'] === 429 && $rateRetries < 3) {
                        $rateRetries++;
                        $backoff = array(1 => 15, 2 => 30, 3 => 60);
                        $wait = $backoff[$rateRetries];
                        $retryAfter = isset($result['retry_after']) ? (int)$result['retry_after'] : 0;
                        if ($retryAfter > $wait) {
                            $wait = $retryAfter;
                        }
                        if ($wait > 300) {
                            $wait = 300;
                        }
                        $outputCallback('Rate limit hit (attempt ' . $rateRetries . '/3). Waiting ' . $wait . 's before retrying...');
                        $interruptibleSleep($wait);
                        continue;
                    }
                    break;
                }
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'Sync stopped by user') === 0) {
                    throw $e;
                }
                $outputCallback('ERROR (' . $e->getMessage() . ')', true);
                $errors++;
                $markProcessed($serial);
                continue;
            }

            if (!empty($result['success'])) {
                $outputCallback('OK (' . (int)$result['records'] . ' coverage records)');
                $synced++;
            } else {
                $outputCallback(isset($result['message']) ? $result['message'] : 'SKIP');
                $skipped++;
            }
            $markProcessed($serial);
            $touchKeepalive();
        }

        $finished = true;
        if ($useWebProgress) {
            $this->clearSyncProgress();
        }
        $total_time = time() - $start_time;
        $minutes = (int)floor($total_time / 60);
        $seconds = $total_time % 60;
        $time_display = $minutes > 0 ? ($minutes . 'm ' . $seconds . 's') : ($seconds . 's');
        $outputCallback('');
        $outputCallback('================================================');
        $outputCallback('Sync Complete');
        $outputCallback('================================================');
        $outputCallback('Total devices: ' . $total_devices);
        $outputCallback('Synced: ' . $synced);
        $outputCallback('Skipped: ' . $skipped);
        $outputCallback('Errors: ' . $errors);
        $outputCallback('Total time: ' . $time_display);
        $outputCallback('================================================');
    }
}
