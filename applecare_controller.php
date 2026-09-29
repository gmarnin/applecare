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

    private $applecareHelper = null;

    public function __construct()
    {
        $this->module_path = dirname(__FILE__);
    }

    /**
     * @return \munkireport\module\applecare\Applecare_helper
     */
    private function helper()
    {
        if ($this->applecareHelper === null) {
            require_once __DIR__ . '/lib/applecare_helper.php';
            $this->applecareHelper = new \munkireport\module\applecare\Applecare_helper();
        }
        return $this->applecareHelper;
    }

    /**
     * @param string $path
     * @return string
     */
    private function normalizePath($path)
    {
        $path = str_replace('\\', '/', $path);
        return preg_replace('#/+#', '/', $path);
    }

    public function get_reseller_config()
    {
        jsonView((object) $this->helper()->getResellerConfigMap());
    }

    /**
     * Admin page entrypoint
     */
    public function applecare_admin()
    {
        if (! $this->authorized('global')) {
            http_response_code(403);
            die('<html><head><title>403 Forbidden</title></head><body><h1>Forbidden</h1><p>Admin access required.</p></body></html>');
        }
        $obj = new View();
        $obj->view('applecare_admin', [], $this->module_path.'/views/');
    }

    /**
     * One-time nonce for the fleet sync GET. EventSource cannot send the CSRF header.
     */
    public function sync_nonce()
    {
        if (! $this->authorized('global')) {
            return $this->jsonError('Not authorized - admin access required', 403);
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['applecare_sync_nonce'] = $nonce;
        $_SESSION['applecare_sync_nonce_at'] = time();
        jsonView(array('nonce' => $nonce));
    }

    /**
     * @return bool
     */
    private function consumeSyncNonce()
    {
        $nonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';
        $stored = isset($_SESSION['applecare_sync_nonce']) ? (string) $_SESSION['applecare_sync_nonce'] : '';
        $at = isset($_SESSION['applecare_sync_nonce_at']) ? (int) $_SESSION['applecare_sync_nonce_at'] : 0;
        unset($_SESSION['applecare_sync_nonce'], $_SESSION['applecare_sync_nonce_at']);

        if ($nonce === '' || $stored === '' || ! hash_equals($stored, $nonce)) {
            return false;
        }
        if ($at < 1 || (time() - $at) > 60) {
            return false;
        }
        return true;
    }

    /**
     * Run the sync and return stdout/stderr, or stream it.
     */
    public function sync()
    {
        if (! $this->authorized('global')) {
            return $this->jsonError('Not authorized - admin access required', 403);
        }
        if (! $this->consumeSyncNonce()) {
            return $this->jsonError('Invalid or expired sync token', 403);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $stream = isset($_GET['stream']) && $_GET['stream'] === '1';
        if ($stream) {
            return $this->syncStream();
        }

        $scriptPath = realpath($this->module_path . '/sync_applecare.php');
        if (! $scriptPath || ! file_exists($scriptPath)) {
            return $this->jsonError('sync_applecare.php not found', 500);
        }

        $mrRoot = defined('APP_ROOT') ? APP_ROOT : dirname(dirname(dirname(dirname(__FILE__))));
        if (! is_dir($mrRoot) || ! file_exists($mrRoot . '/vendor/autoload.php')) {
            return $this->jsonError('MunkiReport root not found: ' . $mrRoot, 500);
        }

        $phpBin = PHP_BINARY ? PHP_BINARY : 'php';
        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($scriptPath) . ' sync ' . escapeshellarg($mrRoot);

        $descriptorSpec = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = @proc_open($cmd, $descriptorSpec, $pipes, $mrRoot);
        if (! is_resource($process)) {
            return $this->jsonError('Failed to start sync process', 500);
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        jsonView(array(
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ));
    }

    public function stop_sync()
    {
        if (! $this->authorized('global')) {
            $this->jsonError('Not authorized - admin access required', 403);
            return;
        }

        try {
            $this->helper()->setStopFlag(true);
            jsonView(array(
                'success' => true,
                'message' => 'Stop signal sent. The sync will stop after processing the current device.',
            ));
        } catch (\Exception $e) {
            error_log('AppleCare: Failed to stop sync: ' . $e->getMessage());
            $this->jsonError('Failed to stop sync', 500);
        }
    }

    public function get_progress()
    {
        if (! $this->authorized('global')) {
            $this->jsonError('Not authorized - admin access required', 403);
            return;
        }

        $progress = $this->helper()->loadSyncProgress();
        if ($progress && isset($progress['devices']) && isset($progress['processed']) && is_array($progress['devices']) && is_array($progress['processed'])) {
            $total = count($progress['devices']);
            $processed = count($progress['processed']);
            jsonView(array(
                'success' => true,
                'has_progress' => true,
                'total' => $total,
                'processed' => $processed,
                'remaining' => $total - $processed,
            ));
            return;
        }

        jsonView(array(
            'success' => true,
            'has_progress' => false,
            'remaining' => 0,
        ));
    }

    public function reset_progress()
    {
        if (! $this->authorized('global')) {
            $this->jsonError('Not authorized - admin access required', 403);
            return;
        }

        $this->helper()->clearSyncProgress();
        jsonView(array(
            'success' => true,
            'message' => 'Sync progress has been reset. The next sync will start from the beginning.',
        ));
    }

    private function syncStream()
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        ob_implicit_flush(true);
        echo ':' . str_repeat(' ', 4096) . "\n\n";
        flush();

        $helper = $this->helper();
        try {
            $helper->clearStopFlag();
            $excludeExisting = isset($_GET['exclude_existing']) && $_GET['exclude_existing'] === '1';
            $existingProgress = null;
            $loaded = $helper->loadSyncProgress();
            if (is_array($loaded) && isset($loaded['last_updated'])) {
                $age = time() - (int) $loaded['last_updated'];
                $sameMode = isset($loaded['exclude_existing']) && (bool) $loaded['exclude_existing'] === (bool) $excludeExisting;
                $processedCount = (isset($loaded['processed']) && is_array($loaded['processed'])) ? count($loaded['processed']) : 0;
                $totalCount = (isset($loaded['devices']) && is_array($loaded['devices'])) ? count($loaded['devices']) : 0;
                if ($age < 7200 && $sameMode && ($totalCount - $processedCount) > 0) {
                    $existingProgress = $loaded;
                    $this->sendEvent('output', 'Resuming previous sync (interrupted ' . (int) round($age / 60) . ' minutes ago, ' . ($totalCount - $processedCount) . ' devices remaining)...');
                    $this->sendEvent('resume', array(
                        'total' => $totalCount,
                        'processed' => $processedCount,
                        'remaining' => $totalCount - $processedCount,
                    ));
                } else {
                    $helper->clearSyncProgress();
                }
            }

            $helper->syncAllDevices(function ($message, $isError = false) {
                if ($isError) {
                    $this->sendEvent('error', $message);
                } else {
                    $this->sendEvent('output', $message);
                }
            }, $excludeExisting, true, true, $existingProgress);

            $this->sendEvent('complete', array(
                'exit_code' => 0,
                'success' => true,
            ));
        } catch (\Exception $e) {
            $this->sendEvent('error', 'Sync failed: ' . $e->getMessage());
            $this->sendEvent('complete', array(
                'exit_code' => 1,
                'success' => false,
            ));
        }
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
        $this->flushStream();
    }

    private function flushStream()
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    private function jsonError($message, $status = 500)
    {
        jsonView([
            'success' => false,
            'message' => $message,
        ], $status);
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
        $column = preg_replace('/[^A-Za-z0-9_]/', '', $column);
        try {
            $this->helper()->refreshCoverageStatus();
        } catch (\Throwable $e) {
            error_log('AppleCare get_binary_widget coverage refresh: ' . $e->getMessage());
        }

        // Handle purchase_source_name specially - need to translate purchase_source_id to names
        if ($column === 'purchase_source_name') {
            try {
                try {
                    $results = Applecare_model::selectRaw('applecare.purchase_source_id AS purchase_source_id')
                        ->selectRaw('COUNT(DISTINCT applecare.serial_number) AS count')
                        ->where('applecare.is_primary', 1)
                        ->whereNotNull('applecare.purchase_source_id')
                        ->where('applecare.purchase_source_id', '!=', '')
                        ->filter()
                        ->groupBy('applecare.purchase_source_id')
                        ->get();
                } catch (\Exception $e) {
                    error_log('AppleCare get_binary_widget error for purchase_source_name (query failed): ' . $e->getMessage());
                    error_log('AppleCare get_binary_widget error trace: ' . $e->getTraceAsString());
                    jsonView([]);
                    return;
                }

                $out = [];
                foreach ($results as $obj) {
                    if (empty($obj->purchase_source_id)) {
                        continue;
                    }
                    $resellerId = (string) $obj->purchase_source_id;
                    $resellerName = $this->helper()->getResellerName($resellerId);
                    $displayName = ($resellerName && $resellerName !== $resellerId)
                        ? $resellerName
                        : $resellerId;
                    $out[] = [
                        'label' => $displayName,
                        'count' => (int) $obj->count
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
                jsonView(['error' => 'Failed to retrieve reseller data']);
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
                    // Keep widget counts aligned with click-through filters and tab data,
                    // both of which use the primary AppleCare row per device.
                    ->where('applecare.is_primary', 1)
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
                'DEVICE_ASSIGNMENT_UNKNOWN' => 'Unknown'
            ];

            $out = [];
            foreach ($temp_results as $status => $count) {
                if ($status === 'UNKNOWN') {
                    continue;
                }
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
                    ->where('applecare.is_primary', 1)
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
                jsonView(['error' => 'Failed to retrieve enrolled_in_dep data']);
                return;
            }
        }

        $allowed_simple_columns = [
            'description',
            'paymentType',
            'isCanceled',
            'isRenewable',
            'status',
        ];
        if (! in_array($column, $allowed_simple_columns, true)) {
            jsonView([]);
            return;
        }

        $qc = 'applecare.' . $column;
        jsonView(
            Applecare_model::selectRaw($qc . ' AS label')
                ->selectRaw('COUNT(DISTINCT applecare.serial_number) AS count')
                ->where('applecare.is_primary', 1)
                ->whereNotNull($qc)
                ->filter()
                ->groupBy($qc)
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
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonError('POST required', 405);
        }

        if (empty($serial_number) || !$this->helper()->isValidSerial($serial_number)) {
            return $this->jsonError('Invalid serial number', 400);
        }

        if (!authorized_for_serial($serial_number)) {
            return $this->jsonError('Not authorized for this device', 403);
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
            $this->helper()->refreshCoverageStatus();
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
                $resellerName = $this->helper()->getResellerName($data['purchase_source_id']);
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
        if (! $this->authorized('global')) {
            return $this->jsonError('Not authorized - admin access required', 403);
        }

        try {
            $helper = $this->helper();
            $helper->normalizeStoredReleasedStatus();

            $serials = Applecare_model::distinct()
                ->whereNotNull('serial_number')
                ->pluck('serial_number');

            $updated = 0;
            foreach ($serials as $serial) {
                if (empty($serial)) {
                    continue;
                }
                $helper->updatePrimaryPlan($serial);
                $updated++;
            }
            
            jsonView([
                'success' => true,
                'message' => "Recalculated is_primary and coverage_status for {$updated} devices"
            ]);
        } catch (\Exception $e) {
            error_log('AppleCare recalculate_primary failed: ' . $e->getMessage());
            jsonView([
                'success' => false,
                'error' => 'Recalculation failed',
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
        if (! $this->authorized('global')) {
            return $this->jsonError('Not authorized - admin access required', 403);
        }

        // Get actual PHP max_execution_time (0 means unlimited)
        $max_execution_time = (int)ini_get('max_execution_time');
        
        $data = [
            'api_url_configured' => false,
            'client_assertion_configured' => false,
            'rate_limit' => 25,
            'default_api_url' => getenv('APPLECARE_API_URL') ?: '',
            'default_client_assertion' => getenv('APPLECARE_CLIENT_ASSERTION') ? 'Yes' : 'No',
            'default_rate_limit' => '25',
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
        
        // Get rate limit (check default first, then look for any org-specific).
        // 25 per minute is the maximum and the default. A lower setting is kept.
        $this->helper();
        $rate_limit = getenv('APPLECARE_RATE_LIMIT');
        if (!empty($rate_limit)) {
            $data['rate_limit'] = \munkireport\module\applecare\Applecare_helper::capRequestsPerMinute($rate_limit);
        } else {
            // Check for org-specific rate limits
            foreach ($all_env as $key => $value) {
                if (is_string($key) && preg_match('/^[A-Z0-9]+_APPLECARE_RATE_LIMIT$/', $key) && !empty($value)) {
                    $data['rate_limit'] = \munkireport\module\applecare\Applecare_helper::capRequestsPerMinute($value);
                    break; // Use first found
                }
            }
        }
        $data['default_rate_limit'] = (string)$data['rate_limit'];
        
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
        if (! $this->authorized('global')) {
            return $this->jsonError('Not authorized - admin access required', 403);
        }

        $excludeExisting = isset($_GET['exclude_existing']) && $_GET['exclude_existing'] === '1';

        try {
            jsonView([
                'count' => $this->helper()->countDevices($excludeExisting, true),
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
