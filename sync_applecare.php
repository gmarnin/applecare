#!/usr/bin/env php
<?php
/**
 * AppleCare Sync Command Line Tool
 * 
 * Run this script to sync AppleCare data from Apple School Manager API
 * 
 * Usage:
 *   php sync_applecare.php
 *   OR
 *   ./sync_applecare.php
 * 
 * This script can be run:
 * - Manually from command line
 * - Via cron job
 * - From web server
 */

// Find MunkiReport root
// Allow passing MunkiReport path as a CLI argument
$default_root = '/usr/local/munkireport';
$munkireport_root = $argv[2] ?? null;

// If no CLI argument is given, try to locate vendor/autoload.php
if (empty($munkireport_root)) {
    $search_paths = [
        '/usr/local/munkireport',                 // Standard install location
        dirname(__DIR__, 4),                      // If module is inside munkireport
        '/var/www/munkireport',                   // Common web install location
    ];

    foreach ($search_paths as $path) {
        if (file_exists($path . '/vendor/autoload.php')) {
            $munkireport_root = $path;
            break;
        }
    }
}

// If still not found, fall back to default
if (empty($munkireport_root) || !file_exists($munkireport_root . '/vendor/autoload.php')) {
    die(
        "ERROR: Could not find MunkiReport installation.\n" .
        "Please provide the path as an argument.\n"
    );
}

echo "MunkiReport root: $munkireport_root\n";

// Bootstrap MunkiReport
require $munkireport_root . '/vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable($munkireport_root);
$dotenv->load();

echo "================================================\n";
echo "AppleCare Sync Tool\n";
echo "================================================\n\n";

// Check configuration
$api_base_url = getenv('APPLECARE_API_URL');
$client_assertion = getenv('APPLECARE_CLIENT_ASSERTION');
$rate_limit = (int)getenv('APPLECARE_RATE_LIMIT') ?: 20;

if (empty($client_assertion)) {
    die("ERROR: APPLECARE_CLIENT_ASSERTION not set in .env file\n");
}

if (empty($api_base_url)) {
    die("ERROR: APPLECARE_API_URL not set in .env file\n");
}

// Clean up the client assertion (remove whitespace, newlines, quotes)
$client_assertion = trim($client_assertion);
$client_assertion = preg_replace('/\s+/', '', $client_assertion); // Remove all whitespace
$client_assertion = trim($client_assertion, '"\''); // Remove surrounding quotes

// Ensure URL ends with /
if (substr($api_base_url, -1) !== '/') {
    $api_base_url .= '/';
}

echo "✓ Configuration OK\n";
echo "✓ API URL: $api_base_url\n";
echo "✓ Rate Limit: $rate_limit calls per minute\n";

// Validate and extract client ID from assertion
// JWT format: header.payload.signature (3 parts separated by dots)
$parts = explode('.', $client_assertion);
if (count($parts) !== 3) {
    echo "\nERROR: Invalid client assertion format\n";
    echo "Expected format: JWT token with 3 parts separated by dots\n";
    echo "Format: header.payload.signature\n";
    echo "Example: eyJhbGciOiJFUzI1NiIsImtpZCI6IkFCQ0RFRiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJjbGllbnRfaWQiLCJhdWQiOiJodHRwczovL2FjY291bnQuYXBwbGUuY29tL2F1dGgvb2F1dGgyL3YyL3Rva2VuIiwiaWF0IjoxNjAwMDAwMDAwLCJleHAiOjE2MDAwMDAwMDAsImp0aSI6IkFCQ0RFRiIsImlzcyI6ImNsaWVudF9pZCJ9.signature\n\n";
    echo "Your assertion length: " . strlen($client_assertion) . " characters\n";
    echo "Number of parts: " . count($parts) . "\n";
    echo "First 50 chars: " . substr($client_assertion, 0, 50) . "...\n\n";
    echo "To generate a client assertion, use an external script:\n";
    echo "  https://github.com/bartreardon/macscripts/blob/master/AxM/create_client_assertion.sh\n";
    echo "  Or follow: https://developer.apple.com/documentation/apple-school-and-business-manager-api/implementing-oauth-for-the-apple-school-and-business-manager-api\n\n";
    die();
}

$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
$client_id = $payload['sub'] ?? null;

if (empty($client_id)) {
    die("ERROR: Could not extract client ID from assertion\n");
}

echo "✓ Client ID: $client_id\n";

// Determine scope based on API URL
$scope = 'business.api'; 
if (strpos($api_base_url, 'api-school') !== false) {
    $scope = 'school.api'; 
}

echo "✓ Using scope: $scope\n";

// Generate access token from client assertion
echo "✓ Generating access token from client assertion...\n";

try {
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
        throw new Exception("cURL error: {$curl_error}");
    }

    if ($http_code !== 200) {
        throw new Exception("Failed to get access token: HTTP $http_code - $response");
    }

    $data = json_decode($response, true);

    if (!isset($data['access_token'])) {
        throw new Exception("No access token in response: $response");
    }

    $access_token = $data['access_token'];
    echo "✓ Access token generated successfully\n";

} catch (Exception $e) {
    die("ERROR: " . $e->getMessage() . "\n");
}

echo "\n";

// Initialize database connection
try {
    $db_config = [
        'driver' => getenv('CONNECTION_DRIVER') ?: 'sqlite',
        'database' => getenv('CONNECTION_DATABASE') ?: $munkireport_root . '/app/db/db.sqlite',
        'host' => getenv('CONNECTION_HOST') ?: 'localhost',
        'username' => getenv('CONNECTION_USERNAME') ?: '',
        'password' => getenv('CONNECTION_PASSWORD') ?: '',
        'charset' => 'utf8',
        'collation' => 'utf8_unicode_ci',
        'prefix' => '',
    ];

    $capsule = new \Illuminate\Database\Capsule\Manager;
    $capsule->addConnection($db_config);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    echo "✓ Database connected\n\n";
} catch (Exception $e) {
    die("ERROR: Could not connect to database: " . $e->getMessage() . "\n");
}

// Get all devices
echo "Fetching device list from database...\n";
$devices = $capsule::table('machine')
    ->select('serial_number')
    ->whereNotNull('serial_number')
    ->where('serial_number', '!=', '')
    ->distinct()
    ->get();

$total_devices = count($devices);
echo "✓ Found $total_devices devices\n\n";

if ($total_devices == 0) {
    die("No devices found in database. Devices must check in to MunkiReport first.\n");
}

// API configuration (already loaded from env above)
$rate_limit_window = 60; // seconds - moving window size

// Sync counters
$synced = 0;
$errors = 0;
$skipped = 0;

// Moving window: track timestamps of successful API requests
// This provides smoother rate limiting than fixed windows
$request_timestamps = [];

echo "\nStarting sync...\n";
echo "Rate limit: $rate_limit calls per minute\n";
echo "Estimated time: " . ceil($total_devices / $rate_limit) . " minutes\n\n";

foreach ($devices as $device) {
    $serial = $device->serial_number;
    
    // Skip invalid serials
    if (empty($serial) || strlen($serial) < 8) {
        $skipped++;
        continue;
    }

    // Moving window rate limiting: clean up timestamps older than window
    $now = time();
    $request_timestamps = array_filter($request_timestamps, function($timestamp) use ($now, $rate_limit_window) {
        return ($now - $timestamp) < $rate_limit_window;
    });
    
    // Use 80% of configured rate limit to allow room for background updates
    $effective_rate_limit = (int)($rate_limit * 0.8);
    
    // Check if we need to throttle before making requests
    $requests_in_window = count($request_timestamps);
    $requests_per_device = 2; // Each device makes 2 API calls
    $projected_requests = $requests_in_window + $requests_per_device;
    
    if ($projected_requests > $effective_rate_limit) {
        // Find the oldest request timestamp to determine when we can make another request
        $oldest_timestamp = min($request_timestamps);
        $time_until_oldest_expires = $rate_limit_window - ($now - $oldest_timestamp);
        
        if ($time_until_oldest_expires > 0) {
            echo "Rate limit reached ({$requests_in_window}/{$effective_rate_limit}, would be {$projected_requests} with this device). Waiting {$time_until_oldest_expires}s for oldest request to expire...\n";
            sleep($time_until_oldest_expires);
            
            // Clean up expired timestamps after waiting
            $now = time();
            $request_timestamps = array_filter($request_timestamps, function($timestamp) use ($now, $rate_limit_window) {
                return ($now - $timestamp) < $rate_limit_window;
            });
        }
    }

    echo "Processing $serial... ";

    // Start timing for this device (includes API calls + DB save)
    $device_start_time = microtime(true);

    try {
        // First, fetch device information
        $device_info = [];
        $device_url = $api_base_url . "orgDevices/{$serial}";
        
        $ch = curl_init($device_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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

        // Track request timestamp (count all HTTP responses, they consume rate limit quota)
        // Only skip if there was a curl error (no HTTP response received)
        if (!$device_curl_error && $device_http_code > 0) {
            $request_timestamps[] = time();
        }

        // Extract device information if available
        $device_attrs = [];
        if (!$device_curl_error && $device_http_code === 200) {
            $device_data = json_decode($device_response, true);
            
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
                    'device_assignment_status' => $device_attrs['status'] ?? null, // ASSIGNED, UNASSIGNED, etc.
                    'purchase_source_type' => $device_attrs['purchaseSourceType'] ?? null, // RESELLER, DIRECT, etc.
                    'purchase_source_id' => $device_attrs['purchaseSourceId'] ?? null,
                    'order_number' => $device_attrs['orderNumber'] ?? null,
                    'order_date' => null,
                    'added_to_org_date' => null,
                    'released_from_org_date' => null,
                    'wifi_mac_address' => $device_attrs['wifiMacAddress'] ?? null,
                    'ethernet_mac_address' => null,
                    'bluetooth_mac_address' => $device_attrs['bluetoothMacAddress'] ?? null,
                ];
                
                // Handle order date
                if (!empty($device_attrs['orderDateTime'])) {
                    $device_info['order_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['orderDateTime']));
                }
                
                // Handle added to org date
                if (!empty($device_attrs['addedToOrgDateTime'])) {
                    $device_info['added_to_org_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['addedToOrgDateTime']));
                }
                
                // Handle released from org date
                if (!empty($device_attrs['releasedFromOrgDateTime'])) {
                    $device_info['released_from_org_date'] = date('Y-m-d H:i:s', strtotime($device_attrs['releasedFromOrgDateTime']));
                }
                
                // Handle array fields (ethernetMacAddress)
                if (!empty($device_attrs['ethernetMacAddress']) && is_array($device_attrs['ethernetMacAddress'])) {
                    $device_info['ethernet_mac_address'] = implode(', ', array_filter($device_attrs['ethernetMacAddress']));
                }
                
                // Note: activation_lock_status and mdm_enrollment_status are not available
                // in the orgDevices endpoint - they may require different API endpoints
            }
        }

        // If device not found in ABM, skip immediately
        if ($device_http_code === 404) {
            echo "SKIP (HTTP 404 - Device not found in Apple Business/School Manager)\n";
            $skipped++;
            continue;
        }

        // Call Apple API for AppleCare coverage
        $url = $api_base_url . "orgDevices/{$serial}/appleCareCoverage";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ]);

        // Force HTTP/1.1 to avoid HTTP/2 protocol issues
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        // Enable verbose output for debugging (comment out in production)
        // curl_setopt($ch, CURLOPT_VERBOSE, true);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        curl_close($ch);

        // Track request timestamp (count all HTTP responses, they consume rate limit quota)
        // Only skip if there was a curl error (no HTTP response received)
        if (!$curl_error && $http_code > 0) {
            $request_timestamps[] = time();
        }

        if ($curl_error) {
            // HTTP/2 errors - retry with exponential backoff
            if ($curl_errno == 92 || $curl_errno == 16) { // HTTP/2 stream errors
                echo "RETRY (HTTP/2 error)... ";
                sleep(2);

                // Retry once
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
                curl_close($ch);

                if ($curl_error) {
                    throw new Exception("cURL error after retry: {$curl_error}");
                }
            } else {
                throw new Exception("cURL error: {$curl_error} (errno: {$curl_errno})");
            }
        }

        if ($http_code !== 200) {
            $error_msg = "SKIP (HTTP $http_code)";
            if ($http_code === 404) {
                $error_msg .= " - Device not found in Apple School Manager or not enrolled";
            } elseif ($http_code === 401) {
                $error_msg .= " - Authentication failed (token may be expired)";
            } elseif ($http_code === 403) {
                $error_msg .= " - Access forbidden (check API permissions)";
            }

            // Try to parse error response for more details
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

            echo "$error_msg\n";
            $skipped++;
            continue;
        }

        $data = json_decode($response, true);

        if (!isset($data['data']) || empty($data['data'])) {
            echo "SKIP (no coverage)\n";
            $skipped++;
            continue;
        }

        // Save coverage data with device information
        // Only update last_fetched when we actually fetch and save coverage data
        $fetch_timestamp = time();
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
                'last_fetched' => $fetch_timestamp, // Use the timestamp we set earlier
            ]);

            // Insert or update
            $existing = $capsule::table('applecare')
                ->where('id', $coverage['id'])
                ->first();

            if ($existing) {
                $capsule::table('applecare')
                    ->where('id', $coverage['id'])
                    ->update($coverage_data);
            } else {
                $capsule::table('applecare')->insert($coverage_data);
            }
        }

        echo "OK (" . count($data['data']) . " coverage records)\n";
        $synced++;

    } catch (Exception $e) {
        echo "ERROR (" . $e->getMessage() . ")\n";
        $errors++;
    }
    
    // Calculate time taken for this device (API calls + DB save + processing)
    $device_end_time = microtime(true);
    $time_took = $device_end_time - $device_start_time;
    
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

// Summary
echo "\n================================================\n";
echo "Sync Complete\n";
echo "================================================\n";
echo "Total devices: $total_devices\n";
echo "Synced: $synced\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n";
echo "================================================\n";

exit(0);