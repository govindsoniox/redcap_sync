<?php
/**
 * for some reason does not work from local to remote yet other than record_id, same as the previous version
 * REDCap Bidirectional Sync Script with Incremental Sync - Enhanced
 * Version: 2.3
 * Features:
 * - Configurable sync directions with batch processing
 * - Rate limiting for API calls
 * - Execution time and memory limits
 * - Enhanced error handling and logging
 * - Incremental sync using dateRangeBegin
 * - Form and field-level control
 * - Only syncs changed records since last sync
 */

// ================= USER CONFIGURATION ================= //
// Operational Parameters
define('BATCH_SIZE', 800);            // Records per batch
define('API_RATE_LIMIT', 600);        // Requests per minute
define('SSL_VERIFY', false);          // SSL verification (false for dev, true for prod)
define('MAX_EXECUTION_TIME', 120);    // Max runtime in seconds (2 minutes)
define('MEMORY_LIMIT', '2048M');      // Memory limit for large datasets

// Sync Direction Control
$ENABLE_LOCAL_TO_REMOTE = true;
$ENABLE_REMOTE_TO_LOCAL = true;

// Starting record ID
$STARTING_RECORD_ID = 1; 

// Filter Control
$USE_LOCAL_TO_REMOTE_FILTER = false; // Set to false to disable filtering
$USE_REMOTE_TO_LOCAL_FILTER = false; // Set to false to disable filtering

// Filter configurations (ignored if above flags are false)
$LOCAL_TO_REMOTE_FILTER = "[status] = 'complete'"; // Example filter
$REMOTE_TO_LOCAL_FILTER = "[last_modified] > '2023-01-01'"; // Example filter

// Forms and fields configuration
$LOCAL_TO_REMOTE_CONFIG = [
    'forms' => [
        "admin"
    ],
    'fields' => [
        'record_id',
        'email'
    ]
];

$REMOTE_TO_LOCAL_CONFIG = [
    'forms' => [
        "personality_iventory_for_dsm5breif_formpid5bf_adul",
        "dsm5b_o_p_i",
        "uk_english_eq5d5l_redcap_selfcomplete",
        "hads_41783b",
        "mfis_v2",
        "brief_pain_inventory_bpi_prom",
        "survey_admin"
    ],
    'fields' => [
        'record_id',
        'email'
    ]
];

// Load API configuration
$config = parse_ini_file('/var/config/config.ini', true);
if ($config === false) {
    die("Failed to load configuration file");
}

// Set runtime limits
ini_set('max_execution_time', MAX_EXECUTION_TIME);
ini_set('memory_limit', MEMORY_LIMIT);
// ====================================================== //

// Constants
define('LOG_FILE', 'sync_log.txt');
define('STATE_FILE', 'sync_state.txt');
define('ID_TRACKER_FILE', 'last_record_id.txt');

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
    global $config, $LOCAL_TO_REMOTE_CONFIG, $LOCAL_TO_REMOTE_FILTER, $USE_LOCAL_TO_REMOTE_FILTER;
    
    $last_id = get_last_record_id();
    $last_sync = get_last_sync_time();
    log_message("=== LOCAL → REMOTE SYNC ===");
    log_message("Starting from record ID: $last_id");
    
    // Build base parameters
    $params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'record',
        'format' => 'json',
        'type' => 'flat',
        'returnFormat' => 'json',
        'filterLogic' => "[record_id] > $last_id"
    ];

    // Add date range for incremental sync if not first run
    if ($last_sync !== null) {
        $params['dateRangeBegin'] = $last_sync;
        log_message("Only syncing records modified since $last_sync");
    }

    // Add optional filter
    if ($USE_LOCAL_TO_REMOTE_FILTER && !empty($LOCAL_TO_REMOTE_FILTER)) {
        $params['filterLogic'] .= " AND ($LOCAL_TO_REMOTE_FILTER)";
        log_message("Applying LOCAL→REMOTE filter: $LOCAL_TO_REMOTE_FILTER");
    }

    // Add fields and forms
    foreach ($LOCAL_TO_REMOTE_CONFIG['fields'] as $index => $field) {
        $params["fields[$index]"] = $field;
    }
    foreach ($LOCAL_TO_REMOTE_CONFIG['forms'] as $index => $form) {
        $params["forms[$index]"] = $form;
    }

    $records = redcap_api_call($config['api']['local_url'], $params, $rateLimiter);
    
    if (empty($records)) {
        log_message("No records found for LOCAL→REMOTE sync");
        return 0;
    }

    $processed_count = 0;
    $max_id = $last_id;
    $batch_count = 0;

    foreach (array_chunk($records, BATCH_SIZE) as $batch) {
        $batch_count++;
        $batch_stats = $rateLimiter->getStats();
        log_message("Processing batch $batch_count - API calls this minute: {$batch_stats['current_minute_calls']}");
        
        $import_params = [
            'token' => $config['tokens']['remote_token'],
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'overwriteBehavior' => 'overwrite',
            'data' => json_encode($batch),
            'returnContent' => 'count'
        ];

        $result = redcap_api_call($config['api']['remote_url'], $import_params, $rateLimiter);
        $processed_count += $result['count'] ?? 0;
        
        // Track highest ID
        $current_max = max(array_column($batch, 'record_id'));
        if ($current_max > $max_id) $max_id = $current_max;
    }
    
    if ($max_id > $last_id) {
        update_last_record_id($max_id);
        log_message("Updated last record ID to: $max_id");
    }
    
    log_message("Records processed in LOCAL→REMOTE: $processed_count");
    log_message("=== LOCAL → REMOTE COMPLETE ===");
    return $processed_count;
}

function sync_remote_to_local($rateLimiter) {
    global $config, $REMOTE_TO_LOCAL_CONFIG, $REMOTE_TO_LOCAL_FILTER, $USE_REMOTE_TO_LOCAL_FILTER;
    
    $last_sync = get_last_sync_time();
    log_message("=== REMOTE → LOCAL SYNC ===");
    log_message($last_sync ? "Syncing changes since $last_sync" : "Performing initial full sync");
    
    $params = [
        'token' => $config['tokens']['remote_token'],
        'content' => 'record',
        'format' => 'json',
        'type' => 'flat',
        'returnFormat' => 'json'
    ];

    // Add date range for incremental sync if not first run
    if ($last_sync !== null) {
        $params['dateRangeBegin'] = $last_sync;
    }

    // Add optional filter
    if ($USE_REMOTE_TO_LOCAL_FILTER && !empty($REMOTE_TO_LOCAL_FILTER)) {
        $params['filterLogic'] = $REMOTE_TO_LOCAL_FILTER;
        log_message("Applying REMOTE→LOCAL filter: $REMOTE_TO_LOCAL_FILTER");
    }

    // Add fields and forms
    foreach ($REMOTE_TO_LOCAL_CONFIG['fields'] as $index => $field) {
        $params["fields[$index]"] = $field;
    }
    foreach ($REMOTE_TO_LOCAL_CONFIG['forms'] as $index => $form) {
        $params["forms[$index]"] = $form;
    }

    $records = redcap_api_call($config['api']['remote_url'], $params, $rateLimiter);
    
    if (empty($records)) {
        log_message("No records found for REMOTE→LOCAL sync");
        return 0;
    }

    $processed_count = 0;
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
            'data' => json_encode($batch),
            'returnContent' => 'count'
        ];

        $result = redcap_api_call($config['api']['local_url'], $import_params, $rateLimiter);
        $processed_count += $result['count'] ?? 0;
    }
    
    log_message("Records processed in REMOTE→LOCAL: $processed_count");
    log_message("=== REMOTE → LOCAL COMPLETE ===");
    return $processed_count;
}

// --- Main Sync Process --- //

try {
    $start_time = microtime(true);
    $rateLimiter = new RateLimiter(API_RATE_LIMIT, 60);
    log_message("=== SYNC STARTED ===");
    
    $results = [
        'local_to_remote' => 0,
        'remote_to_local' => 0,
        'api_calls' => 0
    ];

    if ($ENABLE_LOCAL_TO_REMOTE) {
        $results['local_to_remote'] = sync_local_to_remote($rateLimiter);
    }
    
    if ($ENABLE_REMOTE_TO_LOCAL) {
        $results['remote_to_local'] = sync_remote_to_local($rateLimiter);
    }

    // Update sync time if successful
    update_sync_time();
    
    $duration = round(microtime(true) - $start_time, 2);
    $stats = $rateLimiter->getStats();
    $results['api_calls'] = $stats['total_calls'];
    
    log_message("=== FINAL STATISTICS ===");
    log_message("Sync completed in {$duration}s");
    log_message("API calls made: {$results['api_calls']}");
    log_message("Records processed LOCAL→REMOTE: {$results['local_to_remote']}");
    log_message("Records processed REMOTE→LOCAL: {$results['remote_to_local']}");
    log_message("Highest ID processed: " . get_last_record_id());
    log_message("=== SYNC COMPLETED ===\n");

} catch (Exception $e) {
    $error_message = "=== SYNC FAILED ===\n" . $e->getMessage();
    log_message($error_message);
    die($error_message);
}
