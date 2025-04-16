<?php
/**
 * REDCap Asymmetric Sync Script - Optimized
 * Version: 2.1 (Enhanced)
 * Features:
 * - One-way sync from local to remote (record IDs only)
 * - Incremental sync from remote to local (only changed records)
 * - Uses dateRangeBegin to only fetch modified records
 * - Batch processing for both directions
 * - Rate limiting for API calls
 * - Configurable execution limits
 */

// ================= CONFIGURATION ================= //
// Operational Parameters
define('BATCH_SIZE', 800);            // Records per batch
define('API_RATE_LIMIT', 600);        // Requests per minute
define('SSL_VERIFY', false);          // SSL verification (false for dev, true for prod)
define('MAX_EXECUTION_TIME', 120);    // Max runtime in seconds (2 minutes)
define('MEMORY_LIMIT', '2048M');      // Memory limit for large datasets

// Initial record ID for first sync
$STARTING_RECORD_ID = 0; // Can be changed to start from a specific record_id

// Forms to sync
$forms = [
    "personality_iventory_for_dsm5breif_formpid5bf_adul",
    "uk_english_eq5d5l_redcap_selfcomplete",
    "hads_41783b",
    "dsm5b_o_p_i",
    "mfis_v2",
    "brief_pain_inventory_bpi_prom",
    "survey_admin"
];

// Load configuration from file
$config = parse_ini_file('/var/config/config.ini', true);
if ($config === false) {
    die("Failed to load configuration file");
}

// Set runtime limits
ini_set('max_execution_time', MAX_EXECUTION_TIME);
ini_set('memory_limit', MEMORY_LIMIT);
// ================================================= //

// Constants
define('LOG_FILE', 'sync_log.txt');
define('STATE_FILE', 'sync_state.txt');
define('ID_TRACKER_FILE', 'last_record_id.txt');
define('REQUIRED_FIELDS', ['record_id']);

// Rate Limiter Class
class RateLimiter {
    private $rate;
    private $per;
    private $last_check;
    private $allowance;
    private $api_call_count = 0;
    private $minute_start;
    private $calls_this_minute = 0;
    
    public function __construct($rate, $per) {
        $this->rate = $rate;
        $this->per = $per;
        $this->last_check = microtime(true);
        $this->allowance = $rate;
        $this->minute_start = time();
    }
    
    public function check() {
        $this->api_call_count++;
        $this->calls_this_minute++;
        
        // Reset minute counter if new minute
        if (time() - $this->minute_start >= 60) {
            $this->minute_start = time();
            $this->calls_this_minute = 1;
        }
        
        $current = microtime(true);
        $time_passed = $current - $this->last_check;
        $this->last_check = $current;
        
        $this->allowance += $time_passed * ($this->rate / $this->per);
        if ($this->allowance > $this->rate) $this->allowance = $this->rate;
        
        if ($this->allowance < 1.0) {
            usleep((1.0 - $this->allowance) * ($this->per / $this->rate) * 1000000);
            $this->allowance = 1.0;
        }
        
        $this->allowance -= 1.0;
    }
    
    public function getStats() {
        return [
            'total_calls' => $this->api_call_count,
            'current_minute_calls' => $this->calls_this_minute,
            'current_minute_start' => date('Y-m-d H:i:s', $this->minute_start)
        ];
    }
}

// --- Helper Functions --- //

function get_last_record_id() {
    global $STARTING_RECORD_ID;
    if (!file_exists(ID_TRACKER_FILE)) {
        return (int)$STARTING_RECORD_ID;
    }
    return (int)trim(file_get_contents(ID_TRACKER_FILE));
}

function update_last_record_id($id) {
    file_put_contents(ID_TRACKER_FILE, (int)$id);
}

function get_last_sync_time() {
    return file_exists(STATE_FILE) ? trim(file_get_contents(STATE_FILE)) : null;
}

function update_sync_time() {
    file_put_contents(STATE_FILE, date('Y-m-d H:i:s'));
}

function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);
    echo $log_entry;
}

function redcap_api_call($url, $data, $rateLimiter = null) {
    if ($rateLimiter) $rateLimiter->check();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => SSL_VERIFY,
        CURLOPT_SSL_VERIFYHOST => SSL_VERIFY ? 2 : 0,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ],
        CURLOPT_FAILONERROR => false,
        CURLOPT_TIMEOUT => 300
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("CURL Error: $error");
    }

    if ($http_code != 200) {
        throw new Exception("API Error $http_code: " . substr($response, 0, 500));
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON Error: " . json_last_error_msg());
    }

    return $decoded;
}

// --- Sync Functions --- //

function sync_local_to_remote($rateLimiter) {
    global $config;
    
    $last_id = get_last_record_id();
    $last_sync = get_last_sync_time();
    log_message("=== LOCAL → REMOTE SYNC ===");
    log_message("Starting from record ID: $last_id");
    
    // Export new record IDs from local
    $export_params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'record',
        'format' => 'json',
        'type' => 'flat',
        'fields[0]' => 'record_id',
        'returnFormat' => 'json',
        'filterLogic' => "[record_id] > $last_id"
    ];

    // Only use date range if this isn't the first sync
    if ($last_sync !== null) {
        $export_params['dateRangeBegin'] = $last_sync;
    }

    $records = redcap_api_call($config['api']['local_url'], $export_params, $rateLimiter);
    
    if (empty($records)) {
        log_message("No new records found to create in remote");
        return 0;
    }

    $created_count = 0;
    $max_id = $last_id;
    $batch_count = 0;

    foreach (array_chunk($records, BATCH_SIZE) as $batch) {
        $batch_count++;
        $batch_stats = $rateLimiter->getStats();
        log_message("Processing batch $batch_count - API calls this minute: {$batch_stats['current_minute_calls']}");
        
        // Prepare batch data
        $import_data = array_map(function($r) { return ['record_id' => $r['record_id']]; }, $batch);
        
        // Create records in remote
        $import_params = [
            'token' => $config['tokens']['remote_token'],
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'overwriteBehavior' => 'normal',
            'forceAutoNumber' => 'false',
            'data' => json_encode($import_data),
            'returnContent' => 'count',
            'returnFormat' => 'json'
        ];

        $result = redcap_api_call($config['api']['remote_url'], $import_params, $rateLimiter);
        $created_count += $result['count'] ?? 0;
        
        // Track highest ID
        $current_max = max(array_column($batch, 'record_id'));
        if ($current_max > $max_id) {
            $max_id = $current_max;
        }
    }
    
    if ($max_id > $last_id) {
        update_last_record_id($max_id);
        log_message("Updated last record ID to: $max_id");
    }
    
    log_message("Records created in remote: $created_count");
    log_message("=== LOCAL → REMOTE COMPLETE ===");
    return $created_count;
}

function sync_remote_to_local($rateLimiter) {
    global $config, $forms;
    
    $last_sync = get_last_sync_time();
    log_message("=== REMOTE → LOCAL SYNC ===");
    
    if ($last_sync === null) {
        log_message("Performing initial full sync from remote to local");
    } else {
        log_message("Syncing only records modified since $last_sync from remote to local");
    }
    
    // Build forms array parameter
    $forms_params = [];
    foreach ($forms as $index => $form) {
        $forms_params["forms[$index]"] = $form;
    }
    
    // Build fields array parameter
    $fields_params = [];
    foreach (REQUIRED_FIELDS as $index => $field) {
        $fields_params["fields[$index]"] = $field;
    }
    
    // Export parameters - only get records modified since last sync
    $export_params = array_merge([
        'token' => $config['tokens']['remote_token'],
        'content' => 'record',
        'format' => 'json',
        'type' => 'flat',
        'exportDataAccessGroups' => 'true',
        'returnFormat' => 'json'
    ], $forms_params, $fields_params);

    // Only use date range if this isn't the first sync
    if ($last_sync !== null) {
        $export_params['dateRangeBegin'] = $last_sync;
    }

    $records = redcap_api_call($config['api']['remote_url'], $export_params, $rateLimiter);
    
    if (empty($records)) {
        log_message("No records modified in remote since last sync");
        return 0;
    }

    $imported_count = 0;
    $batch_count = 0;

    foreach (array_chunk($records, BATCH_SIZE) as $batch) {
        $batch_count++;
        $batch_stats = $rateLimiter->getStats();
        log_message("Processing batch $batch_count - API calls this minute: {$batch_stats['current_minute_calls']}");
        
        $import_params = [
            'token' => $config['tokens']['local_token'],
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'overwriteBehavior' => 'overwrite',
            'forceAutoNumber' => 'false',
            'data' => json_encode($batch),
            'returnContent' => 'count',
            'returnFormat' => 'json'
        ];

        $result = redcap_api_call($config['api']['local_url'], $import_params, $rateLimiter);
        $imported_count += $result['count'] ?? 0;
    }
    
    log_message("Records imported to local: $imported_count");
    log_message("=== REMOTE → LOCAL COMPLETE ===");
    return $imported_count;
}

// --- Main Sync Process --- //

try {
    $start_time = microtime(true);
    $rateLimiter = new RateLimiter(API_RATE_LIMIT, 60);
    log_message("=== SYNC STARTED ===");

    // Step 1: Create new records in remote (local → remote)
    $created_remote = sync_local_to_remote($rateLimiter);
    
    // Step 2: Sync only changed data from remote to local
    $imported_local = sync_remote_to_local($rateLimiter);

    // Update sync time only if successful
    update_sync_time();
    
    $duration = round(microtime(true) - $start_time, 2);
    $stats = $rateLimiter->getStats();
    
    log_message("=== FINAL STATISTICS ===");
    log_message("Sync completed in {$duration}s");
    log_message("API calls made: {$stats['total_calls']}");
    log_message("Records created in remote: $created_remote");
    log_message("Records imported to local: $imported_local");
    log_message("Highest ID processed: " . get_last_record_id());
    log_message("=== SYNC COMPLETED ===\n");

} catch (Exception $e) {
    $error_message = "=== SYNC FAILED ===\n" . $e->getMessage();
    log_message($error_message);
    die($error_message);
}
