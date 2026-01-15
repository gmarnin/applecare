<?php

namespace munkireport\module\applecare;

use Symfony\Component\Yaml\Yaml;

class Applecare_helper
{
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
     * @param string $serial_number
     * @return array|null
     */
    private function getAppleCareConfig($serial_number)
    {
        $client_id = $this->getClientId($serial_number);

        $api_url = null;
        $client_assertion = null;
        $rate_limit = 20;

        if (!empty($client_id)) {
            $parts = explode('-', $client_id, 2);
            $prefix = strtoupper($parts[0]);

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
     * @param string $reseller_id
     * @return string|null
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
            if (isset($config['resellers'][$reseller_id])) {
                return $config['resellers'][$reseller_id];
            }
        } catch (\Exception $e) {
            error_log('AppleCare: Error loading reseller config: ' . $e->getMessage());
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

        return $data['access_token'];
    }

    /**
     * Sync a single device
     *
     * @param string $serial_number
     * @param string $api_base_url
     * @param string $access_token
     * @return array
     */
    private function syncSingleDevice($serial_number, $api_base_url, $access_token)
    {
        $requests = 0;
        $device_info = [];
        $device_attrs = [];

        $device_url = $api_base_url . "orgDevices/{$serial_number}";

        $ch = curl_init($device_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // No header parsing needed for device lookup
        curl_setopt($ch, CURLOPT_HEADER, false);
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
        curl_close($ch);
        $requests++;

        if ($device_http_code === 404) {
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => 'SKIP (HTTP 404) - Device not found in Apple Business/School Manager'];
        }

        if ($device_curl_error) {
            throw new \Exception("Device lookup cURL error: {$device_curl_error}");
        }

        $device_data = null;
        if (!empty($device_response)) {
            $device_data = json_decode($device_response, true);
        }

        // Always try to populate device_info if we got a successful response
        if ($device_http_code === 200) {
            if (isset($device_data['data']['attributes'])) {
                $device_attrs = $device_data['data']['attributes'];
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
                    'wifi_mac_address' => $device_attrs['wifiMacAddress'] ?? null,
                    'ethernet_mac_address' => null,
                    'bluetooth_mac_address' => $device_attrs['bluetoothMacAddress'] ?? null,
                ];

                if (!empty($device_attrs['orderDateTime'])) {
                    $device_info['order_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['orderDateTime']));
                }
                if (!empty($device_attrs['addedToOrgDateTime'])) {
                    $device_info['added_to_org_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['addedToOrgDateTime']));
                }
                if (!empty($device_attrs['releasedFromOrgDateTime'])) {
                    $device_info['released_from_org_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['releasedFromOrgDateTime']));
                }
                if (!empty($device_attrs['ethernetMacAddress']) && is_array($device_attrs['ethernetMacAddress'])) {
                    $device_info['ethernet_mac_address'] = implode(', ', array_filter($device_attrs['ethernetMacAddress']));
                }
            } else {
                // HTTP 200 but unexpected JSON structure - log for debugging
                error_log("AppleCare: Device lookup returned 200 for {$serial_number} but JSON structure unexpected. Response: " . substr(json_encode($device_data), 0, 500));
            }
        } else {
            // Non-200 response - log warning but continue to fetch coverage
            error_log("AppleCare: Device lookup failed for {$serial_number} with HTTP {$device_http_code}, but continuing to fetch coverage");
        }

        $url = $api_base_url . "orgDevices/{$serial_number}/appleCareCoverage";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
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
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        curl_close($ch);
        $requests++;

        if ($curl_error) {
            throw new \Exception("cURL error: {$curl_error}");
        }

        if ($http_code !== 200) {
            $error_msg = "SKIP (HTTP $http_code)";
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => $error_msg];
        }

        $data = json_decode($body, true);
        if (!isset($data['data']) || empty($data['data'])) {
            return ['success' => false, 'records' => 0, 'requests' => $requests, 'message' => 'SKIP (no coverage)'];
        }

        $fetch_timestamp = time();
        $records_saved = 0;
        foreach ($data['data'] as $coverage) {
            $attrs = $coverage['attributes'] ?? [];
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
                'last_fetched' => $fetch_timestamp,
            ]);

            foreach (['isRenewable', 'isCanceled'] as $field) {
                if (isset($coverage_data[$field])) {
                    $coverage_data[$field] = ($coverage_data[$field] === true ||
                         $coverage_data[$field] === 1 ||
                         $coverage_data[$field] === '1' ||
                         strtolower($coverage_data[$field]) === 'true') ? 1 : 0;
                }
            }

            if (!empty($coverage_data['purchase_source_id'])) {
                $resellerName = $this->getResellerName($coverage_data['purchase_source_id']);
                if ($resellerName && $resellerName !== $coverage_data['purchase_source_id']) {
                    $coverage_data['purchase_source_name'] = $resellerName;
                    $coverage_data['purchase_source_id_display'] = $coverage_data['purchase_source_id'];
                }
            }

            \Applecare_model::updateOrCreate(
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
        ];
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
