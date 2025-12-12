<?php 

/**
 * applecare class
 *
 * @package munkireport
 * @author 
 **/
class Applecare_controller extends Module_controller
{
	    function __construct()
    {
        // Store module path
        $this->module_path = dirname(__FILE__);
    }
	
    /**
     * Admin page entrypoint
     *
     * Renders the AppleCare admin form (sync UI)
     */
    public function applecare_admin()
    {
        // Pass empty result for compatibility with view expectations
        $data = ['result' => null];
        view('admin_form', $data, $this->module_path . '/');
    }
    
    /**
     * Get applecare information for serial_number
     *
     * @param string $serial serial number
     **/
    public function get_data($serial_number = '')
    {
        jsonView(
            Applecare_model::select('applecare.*')
            ->whereSerialNumber($serial_number)
            ->filter()
            ->limit(1)
            ->first()
            ->toArray()
        );
    }

    /**
     * Run the sync script and return stdout/stderr
     */
    public function sync()
    {
        $scriptPath = realpath($this->module_path . '/sync_applecare.php');

        if (! $scriptPath || ! file_exists($scriptPath)) {
            return $this->jsonError('sync_applecare.php not found', 500);
        }

        $phpBin = PHP_BINARY ?: 'php';
        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($scriptPath);

        $descriptorSpec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes, dirname($scriptPath));

        if (! is_resource($process)) {
            return $this->jsonError('Failed to start sync process', 500);
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

    private function jsonError($message, $status = 500)
    {
        http_response_code($status);
        jsonView([
            'success' => false,
            'message' => $message,
        ]);
        exit;
    }

    public function get_list($column = '')
    {
        jsonView(
            Applecare_model::select("applecare.$column AS label")
                ->selectRaw('count(*) AS count')
                ->filter()
                ->groupBy($column)
                ->orderBy('count', 'desc')
                ->get()
                ->toArray()
        );
    }

    /**
     * Sync AppleCare data for a single serial number
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

        try {
            // Get configuration from environment
            $api_base_url = getenv('APPLECARE_API_URL');
            $client_assertion = getenv('APPLECARE_CLIENT_ASSERTION');
            
            if (empty($client_assertion) || empty($api_base_url)) {
                return $this->jsonError('AppleCare API not configured. Please check your .env file.', 500);
            }

            // Clean up the client assertion
            $client_assertion = trim($client_assertion);
            $client_assertion = preg_replace('/\s+/', '', $client_assertion);
            $client_assertion = trim($client_assertion, '"\'');
            
            // Ensure URL ends with /
            if (substr($api_base_url, -1) !== '/') {
                $api_base_url .= '/';
            }

            // Extract client ID from assertion
            $parts = explode('.', $client_assertion);
            if (count($parts) !== 3) {
                return $this->jsonError('Invalid client assertion format', 500);
            }

            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $client_id = $payload['sub'] ?? null;

            if (empty($client_id)) {
                return $this->jsonError('Could not extract client ID from assertion', 500);
            }

            // Determine scope based on API URL
            $scope = 'business.api';
            if (strpos($api_base_url, 'api-school') !== false) {
                $scope = 'school.api';
            }

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
            
            if ($curl_error || $http_code !== 200) {
                return $this->jsonError('Failed to get access token: ' . ($curl_error ?: "HTTP $http_code"), 500);
            }
            
            $data = json_decode($response, true);
            if (!isset($data['access_token'])) {
                return $this->jsonError('No access token in response', 500);
            }
            
            $access_token = $data['access_token'];

            // Call Apple API for this serial number
            $url = $api_base_url . "orgDevices/{$serial_number}/appleCareCoverage";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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
            curl_close($ch);
            
            if ($curl_error) {
                return $this->jsonError('cURL error: ' . $curl_error, 500);
            }

            if ($http_code !== 200) {
                $error_msg = "HTTP $http_code";
                if ($http_code === 404) {
                    $error_msg = "Device not found in Apple School Manager or not enrolled";
                } elseif ($http_code === 401) {
                    $error_msg = "Authentication failed (token may be expired)";
                } elseif ($http_code === 403) {
                    $error_msg = "Access forbidden (check API permissions)";
                }
                
                // Try to parse error response
                if (!empty($response)) {
                    $error_data = json_decode($response, true);
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
                
                return $this->jsonError($error_msg, $http_code);
            }
            
            $data = json_decode($response, true);
            
            if (!isset($data['data']) || empty($data['data'])) {
                // No coverage data - clear existing records for this serial
                Applecare_model::where('serial_number', $serial_number)->delete();
                
                jsonView([
                    'success' => true,
                    'message' => 'No coverage data found for this device',
                    'records_synced' => 0,
                ]);
                return;
            }
            
            // Save coverage data
            $records_synced = 0;
            foreach ($data['data'] as $coverage) {
                $attrs = $coverage['attributes'] ?? [];
                
                $coverage_data = [
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
                ];
                
                // Insert or update - ensure id is included in both search and data
                $coverage_id = $coverage['id'];
                Applecare_model::updateOrCreate(
                    ['id' => $coverage_id],
                    array_merge(['id' => $coverage_id], $coverage_data)
                );
                
                $records_synced++;
            }
            
            jsonView([
                'success' => true,
                'message' => "Synced {$records_synced} coverage record(s) for {$serial_number}",
                'records_synced' => $records_synced,
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonError('Sync failed: ' . $e->getMessage(), 500);
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
} 