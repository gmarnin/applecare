<?php

namespace munkireport\module\applecare;

use Symfony\Component\Yaml\Yaml;
use Illuminate\Database\Capsule\Manager as Capsule;

class Applecare_helper
{
    /**
     * Force database reconnection
     * 
     * Called when we detect "server has gone away" to ensure
     * the next query uses a fresh connection.
     */
    private function reconnectDatabase()
    {
        try {
            // Get the Capsule connection and force reconnect
            $connection = Capsule::connection();
            $connection->reconnect();
        } catch (\Exception $e) {
            // If reconnect fails, log but don't throw - let the retry logic handle it
            error_log("AppleCare: Database reconnect attempt: " . $e->getMessage());
        }
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
        $rate_limit = 40;

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
     * @param string $reseller_id
     * @return string|null
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
     * Generate access token from client assertion
     *
     * @param string $client_assertion
     * @param string $api_base_url
     * @return string
     */
    private function generateAccessToken($client_assertion, $api_base_url)
    {
        $client_assertion = trim($client_assertion);
        $client_assertion = preg_replace('/\s+/', '', $client_assertion);
        $client_assertion = trim($client_assertion, '"\'');

        $parts = explode('.', $client_assertion);
        if (count($parts) !== 3) {
            throw new \Exception('Invalid client assertion format. Expected JWT token with 3 parts.');
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $client_id = $payload['sub'] ?? null;

        if (empty($client_id)) {
            throw new \Exception('Could not extract client ID from assertion.');
        }

        $scope = 'business.api';
        if (strpos($api_base_url, 'api-school') !== false) {
            $scope = 'school.api';
        }

        $ch = curl_init('https://account.apple.com/auth/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true, // Include headers in response
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
        
        // Extract headers before closing handle (same pattern as syncSingleDevice)
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

        $data = json_decode($body, true);
        if (!isset($data['access_token'])) {
            throw new \Exception("No access token in response: $response");
        }

        return $data['access_token'];
    }

    /**
     * Sync a single device
     *
     * @param string $serial_number
     * @param string $api_base_url
     * @param string $access_token
     * @param callable|null $outputCallback Optional callback for progress updates (message, isError)
     * @return array ['success' => bool, 'records' => int, 'requests' => int, 'message' => string, 'rate_limit' => int|null, 'rate_limit_remaining' => int|null]
     */
    public function syncSingleDevice($serial_number, $api_base_url, $access_token, $outputCallback = null)
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

        // Temporary logging for all fetches
        // error_log("AppleCare FETCH: Device lookup for {$serial_number} - URL: {$device_url} - HTTP {$device_http_code}");

        // If device not found in ABM, skip immediately
        if ($device_http_code === 404) {
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => 'SKIP (HTTP 404) - Device not found in Apple Business/School Manager', 'rate_limit' => null, 'rate_limit_remaining' => null];
        }

        // Handle rate limit (HTTP 429) on device lookup - return immediately with retry_after
        if ($device_http_code === 429) {
            $retry_after = 30; // Default
            $device_headers = substr($device_response, 0, $device_header_size);
            if (preg_match('/Retry-After:\s*(\d+)/i', $device_headers, $matches)) {
                $retry_after = (int)$matches[1];
            }
            return [
                'success' => false, 
                'records' => 0, 
                'requests' => $requests, 
                'message' => 'SKIP (HTTP 429 - Rate limit exceeded on device lookup)',
                'retry_after' => $retry_after,
                'rate_limit' => null,
                'rate_limit_remaining' => null
            ];
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
                        'serial_number' => $serial_number,
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
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers = substr($response, 0, $header_size);
                $body = substr($response, $header_size);
                curl_close($ch);
                $requests++;

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
                return ['success' => true, 'records' => 0, 'requests' => $requests, 'message' => 'No Coverage, getting device Information'];
            }
            
            // No coverage and no device info - this is a true skip
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
            }
        }

        // Update which plan is "the one" for this device
        $this->updatePrimaryPlan($serial_number);

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
            $thirtyDays = date('Y-m-d', strtotime('+30 days'));
            
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
                $endDate = $plan->endDateTime;
                if ($endDate instanceof \DateTime || (is_object($endDate) && method_exists($endDate, 'format'))) {
                    return $endDate->format('Y-m-d');
                }
                return $endDate ?? '1970-01-01';
            })->first();

            if ($primary) {
                // Determine coverage status based on the primary plan
                $endDate = $primary->endDateTime;
                if ($endDate instanceof \DateTime || (is_object($endDate) && method_exists($endDate, 'format'))) {
                    $endDate = $endDate->format('Y-m-d');
                }
                
                $status = strtoupper($primary->status ?? '');
                $isCanceled = !empty($primary->isCanceled);
                
                // Check if plan is active (status=ACTIVE, not canceled, end date in future)
                $isActive = $status === 'ACTIVE' 
                    && !$isCanceled 
                    && !empty($endDate) 
                    && $endDate >= $now;
                
                if ($isActive) {
                    // Active - check if expiring soon
                    $coverageStatus = ($endDate <= $thirtyDays) ? 'expiring_soon' : 'active';
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
     * Sync AppleCare data for a single serial number
     *
     * @param string $serial_number
     * @return array
     */
    public function syncSerial($serial_number)
    {
        if (empty($serial_number) || strlen($serial_number) < 8) {
            return ['success' => false, 'records' => 0, 'message' => 'Invalid serial number'];
        }

        try {
            $device_config = $this->getAppleCareConfig($serial_number);
            if (!$device_config) {
                return ['success' => false, 'records' => 0, 'message' => 'AppleCare API not configured'];
            }

            $api_base_url = $device_config['api_url'];
            $client_assertion = $device_config['client_assertion'];

            if (substr($api_base_url, -1) !== '/') {
                $api_base_url .= '/';
            }

            $access_token = $this->generateAccessToken($client_assertion, $api_base_url);
            return $this->syncSingleDevice($serial_number, $api_base_url, $access_token);
        } catch (\Exception $e) {
            return ['success' => false, 'records' => 0, 'message' => 'Sync failed: ' . $e->getMessage()];
        }
    }
}
