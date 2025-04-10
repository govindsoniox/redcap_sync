<?php
/**
 * REDCap Bidirectional Sync Script with Incremental Sync
 * Version: 2.2
 * Features:
 * - Configurable sync directions
 * - Incremental sync using dateRangeBegin
 * - Optional filter logic for each direction
 * - Form and field-level control
 * - Only syncs changed records since last sync
 */

// ================= USER CONFIGURATION ================= //
// Enable/disable sync directions
$ENABLE_LOCAL_TO_REMOTE = true;
$ENABLE_REMOTE_TO_LOCAL = true;

// Starting record ID
$STARTING_RECORD_ID = 1; 

// Enable/disable filters
$USE_LOCAL_TO_REMOTE_FILTER = false; // Set to false to disable filtering
$USE_REMOTE_TO_LOCAL_FILTER = false; // Set to false to disable filtering

// Filter configurations (ignored if above flags are false)
$LOCAL_TO_REMOTE_FILTER = "[status] = 'complete'"; // Example filter
$REMOTE_TO_LOCAL_FILTER = "[last_modified] > '2023-01-01'"; // Example filter


//Generic setting for guidance
// // Forms and fields configuration
// $LOCAL_TO_REMOTE_CONFIG = [
//     'forms' => [
//         "patient_registration",
//         "screening_data"
//     ],
//     'fields' => [
//         'record_id',
//         'patient_id',
//         'site_id',
//         'enrollment_date'
//     ]
// ];

// $REMOTE_TO_LOCAL_CONFIG = [
//     'forms' => [
//         "clinical_measures",
//         "lab_results"
//     ],
//     'fields' => [
//         'record_id',
//         'patient_id',
//         'blood_pressure',
//         'lab_result_value'
//     ]
// ];


//specific setting for NMO project
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
$config = parse_ini_file('config.ini', true);
if ($config === false) {
    die("Failed to load configuration file");
}
// ====================================================== //

// Constants
define('LOG_FILE', 'sync_log.txt');
define('STATE_FILE', 'sync_state.txt');
define('ID_TRACKER_FILE', 'last_record_id.txt');
define('BATCH_SIZE', 100);

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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 300
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200) {
        throw new Exception("API Error $http_code: " . substr($response, 0, 500));
    }

    return json_decode($response, true);
}

// --- Sync Functions --- //

function sync_local_to_remote() {
    global $config, $LOCAL_TO_REMOTE_CONFIG, $LOCAL_TO_REMOTE_FILTER, $USE_LOCAL_TO_REMOTE_FILTER;
    
    $last_id = get_last_record_id();
    $last_sync = get_last_sync_time();
    log_message("Starting LOCAL→REMOTE sync from record ID: $last_id");
    
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

    $records = redcap_api_call($config['api']['local_url'], $params);
    
    if (empty($records)) {
        log_message("No records found for LOCAL→REMOTE sync");
        return 0;
    }

    $processed_count = 0;
    $max_id = $last_id;

    foreach (array_chunk($records, BATCH_SIZE) as $batch) {
        $import_params = [
            'token' => $config['tokens']['remote_token'],
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'overwriteBehavior' => 'overwrite',
            'data' => json_encode($batch),
            'returnContent' => 'count'
        ];

        $result = redcap_api_call($config['api']['remote_url'], $import_params);
        $processed_count += $result['count'] ?? 0;
        
        foreach ($batch as $record) {
            $current_id = (int)$record['record_id'];
            if ($current_id > $max_id) $max_id = $current_id;
        }
    }
    
    if ($max_id > $last_id) {
        update_last_record_id($max_id);
    }
    
    return $processed_count;
}

function sync_remote_to_local() {
    global $config, $REMOTE_TO_LOCAL_CONFIG, $REMOTE_TO_LOCAL_FILTER, $USE_REMOTE_TO_LOCAL_FILTER;
    
    $last_sync = get_last_sync_time();
    log_message("Starting REMOTE→LOCAL sync");
    
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
        log_message("Only syncing records modified since $last_sync");
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

    $records = redcap_api_call($config['api']['remote_url'], $params);
    
    if (empty($records)) {
        log_message("No records found for REMOTE→LOCAL sync");
        return 0;
    }

    $processed_count = 0;

    foreach (array_chunk($records, BATCH_SIZE) as $batch) {
        $import_params = [
            'token' => $config['tokens']['local_token'],
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'overwriteBehavior' => 'overwrite',
            'data' => json_encode($batch),
            'returnContent' => 'count'
        ];

        $result = redcap_api_call($config['api']['local_url'], $import_params);
        $processed_count += $result['count'] ?? 0;
    }
    
    return $processed_count;
}

// --- Main Sync Process --- //

try {
    $start_time = microtime(true);
    log_message("=== Starting sync process ===");
    
    $results = [
        'local_to_remote' => 0,
        'remote_to_local' => 0
    ];

    if ($ENABLE_LOCAL_TO_REMOTE) {
        $results['local_to_remote'] = sync_local_to_remote();
    }
    
    if ($ENABLE_REMOTE_TO_LOCAL) {
        $results['remote_to_local'] = sync_remote_to_local();
    }

    // Only update sync time if successful
    update_sync_time();
    
    $duration = round(microtime(true) - $start_time, 2);
    log_message(sprintf(
        "Sync completed in {$duration}s. LOCAL→REMOTE: %d records, REMOTE→LOCAL: %d records. Highest ID: %d",
        $results['local_to_remote'],
        $results['remote_to_local'],
        get_last_record_id()
    ));
    log_message("=== Sync finished ===");

} catch (Exception $e) {
    $error_message = "SYNC FAILED: " . $e->getMessage();
    log_message($error_message);
    die($error_message);
}