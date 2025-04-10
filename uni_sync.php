<?php
/**
 * REDCap Asymmetric Sync Script - Optimized
 * Version: 2.0
 * Features:
 * - One-way sync from local to remote (record IDs only)
 * - Incremental sync from remote to local (only changed records)
 * - Uses dateRangeBegin to only fetch modified records
 * - Batch processing for efficiency
 */

// ================= CONFIGURATION ================= //
$STARTING_RECORD_ID = 2362; // Initial record ID for first sync, assuming by this the data exists in both instances for initial load. can be changed to 0 to start form record_id 0, only applicable to very first run.

// Forms to sync
$forms = [
    "personality_iventory_for_dsm5breif_formpid5bf_adul",
    "uk_english_eq5d5l_redcap_selfcomplete",
    "hads_41783b",
    "dsm5b_o_p_i",
    "mfis_v2",
    "brief_pain_inventory_bpi_prom",
    "survey-admin"
];

// Load configuration from file
$config = parse_ini_file('/var/config/config.ini', true); //location of the config file
if ($config === false) {
    die("Failed to load configuration file");
}
// ================================================= //

// Constants
define('LOG_FILE', 'sync_log.txt');
define('STATE_FILE', 'sync_state.txt');
define('ID_TRACKER_FILE', 'last_record_id.txt');
define('BATCH_SIZE', 500);
define('REQUIRED_FIELDS', ['record_id']);

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
}

function redcap_api_call($url, $data) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true, //false for dev, true for prod
        CURLOPT_SSL_VERIFYHOST => 2, // 0 for dev, 2 for prod
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
    curl_close($ch);

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

function sync_local_to_remote() {
    global $config;
    
    $last_id = get_last_record_id();
    $last_sync = get_last_sync_time();
    log_message("Starting local to remote sync from record ID: $last_id");
    
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

    $records = redcap_api_call($config['api']['local_url'], $export_params);
    
    if (empty($records)) {
        log_message("No new records found to create in remote");
        return 0;
    }

    $created_count = 0;
    $max_id = $last_id;

    foreach ($records as $record) {
        if (!isset($record['record_id'])) continue;
        
        $current_id = (int)$record['record_id'];
        if ($current_id > $max_id) {
            $max_id = $current_id;
        }
        
        // Create record in remote
        $import_params = [
            'token' => $config['tokens']['remote_token'],
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'overwriteBehavior' => 'normal',
            'forceAutoNumber' => 'false',
            'data' => json_encode([['record_id' => $current_id]]),
            'returnContent' => 'count',
            'returnFormat' => 'json'
        ];

        $result = redcap_api_call($config['api']['remote_url'], $import_params);
        $created_count += $result['count'] ?? 0;
        log_message("Created remote record: $current_id");
    }
    
    if ($max_id > $last_id) {
        update_last_record_id($max_id);
        log_message("Updated last record ID to: $max_id");
    }
    
    return $created_count;
}

function sync_remote_to_local() {
    global $config, $forms;
    
    $last_sync = get_last_sync_time();
    
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

    $records = redcap_api_call($config['api']['remote_url'], $export_params);
    
    if (empty($records)) {
        log_message("No records modified in remote since last sync");
        return 0;
    }

    $imported_count = 0;

    foreach (array_chunk($records, BATCH_SIZE) as $batch) {
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

        $result = redcap_api_call($config['api']['local_url'], $import_params);
        $imported_count += $result['count'] ?? 0;
    }
    
    log_message("Imported $imported_count records from remote to local");
    return $imported_count;
}

// --- Main Sync Process --- //

try {
    $start_time = microtime(true);
    log_message("Starting sync process");

    // Step 1: Create new records in remote (local → remote)
    $created_remote = sync_local_to_remote();
    
    // Step 2: Sync only changed data from remote to local
    $imported_local = sync_remote_to_local();

    // Update sync time only if successful
    update_sync_time();
    
    $duration = round(microtime(true) - $start_time, 2);
    log_message(sprintf(
        "Sync completed in {$duration}s. Created %d remote records, imported %d local records. Highest ID: %d",
        $created_remote,
        $imported_local,
        get_last_record_id()
    ));

} catch (Exception $e) {
    $error_message = "SYNC FAILED: " . $e->getMessage();
    log_message($error_message);
    die($error_message);
}
