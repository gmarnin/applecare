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
$rate_limit_window = 60; // seconds

// Sync counters
$synced = 0;
$errors = 0;
$skipped = 0;
$requests_made = 0;
$window_start = time();

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
    
    // Check rate limit
    if ($requests_made >= $rate_limit) {
        $elapsed = time() - $window_start;
        if ($elapsed < $rate_limit_window) {
            $sleep_time = $rate_limit_window - $elapsed;
            echo "Rate limit reached. Sleeping for {$sleep_time}s...\n";
            sleep($sleep_time);
        }
        $requests_made = 0;
        $window_start = time();
    }
    
    echo "Processing $serial... ";
    
    try {
        // Call Apple API
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
        
        $requests_made++;
        
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
        
        // Save coverage data
        foreach ($data['data'] as $coverage) {
            $attrs = $coverage['attributes'] ?? [];
            
            $coverage_data = [
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
            ];
            
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