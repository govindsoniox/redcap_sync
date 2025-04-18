<?php
/**
 * REDCap Project Configuration Sync Script
 * Version: 2.1
 * 
 * Syncs project configuration from local to remote including:
 * 1. Metadata (Data Dictionary)
 * 2. Project Information
 * 3. Repeating Instruments/Events
 * 4. Data Access Groups (DAGs)
 * 5. Events (for longitudinal studies only)
 */

// ================= CONFIGURATION ================= //
// Load configuration from file
$config = parse_ini_file('/var/config/config.ini', true);
if ($config === false) {
    die("Failed to load configuration file");
}

// Set runtime limits
ini_set('max_execution_time', 300);    // 5 minutes
ini_set('memory_limit', '1024M');      // 1GB memory

// Constants
define('LOG_FILE', 'config_sync_log.txt');
define('STATE_FILE', 'config_sync_state.txt');

// --- Helper Functions --- //

function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);
    echo $log_entry;
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
        CURLOPT_FAILONERROR => false,
        CURLOPT_TIMEOUT => 120
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

function is_project_longitudinal($config) {
    $params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'project',
        'format' => 'json',
        'returnFormat' => 'json'
    ];
    
    $project_info = redcap_api_call($config['api']['local_url'], $params);
    return (bool)$project_info['is_longitudinal'];
}

function get_user_options() {
    global $SYNC_METADATA, $SYNC_PROJECT_INFO, $SYNC_REPEATING, $SYNC_DAGS, $SYNC_EVENTS, $OVERRIDE_EVENTS;
    
    // Check if project is longitudinal
    $is_longitudinal = is_project_longitudinal($GLOBALS['config']);
    
    // Check if any sync option is already set (non-interactive mode)
    if ($SYNC_METADATA || $SYNC_PROJECT_INFO || $SYNC_REPEATING || $SYNC_DAGS || $SYNC_EVENTS) {
        return [
            'metadata' => $SYNC_METADATA,
            'project_info' => $SYNC_PROJECT_INFO,
            'repeating' => $SYNC_REPEATING,
            'dags' => $SYNC_DAGS,
            'events' => $is_longitudinal && $SYNC_EVENTS, // Only sync events if project is longitudinal
            'override_events' => $OVERRIDE_EVENTS
        ];
    }
    
    // Interactive mode
    echo "\nREDCap Configuration Sync Options:\n";
    echo "1. Sync Metadata (Data Dictionary)\n";
    echo "2. Sync Project Information\n";
    echo "3. Sync Repeating Instruments/Events\n";
    echo "4. Sync Data Access Groups (DAGs)\n";
    
    $max_choice = 6;
    if ($is_longitudinal) {
        echo "5. Sync Events (for longitudinal studies)\n";
        echo "6. Sync All (1+2+3+4+5)\n";
    } else {
        echo "6. Sync All (1+2+3+4)\n";
    }
    echo "Enter your choice (1-6): ";
    
    $choice = trim(fgets(STDIN));
    $valid_choices = $is_longitudinal ? ['1','2','3','4','5','6'] : ['1','2','3','4','6'];
    while (!in_array($choice, $valid_choices)) {
        echo "Invalid choice. Please enter 1-" . ($is_longitudinal ? '6' : '4 or 6') . ": ";
        $choice = trim(fgets(STDIN));
    }
    
    $options = [
        'metadata' => in_array($choice, ['1', '6']),
        'project_info' => in_array($choice, ['2', '6']),
        'repeating' => in_array($choice, ['3', '6']),
        'dags' => in_array($choice, ['4', '6']),
        'events' => $is_longitudinal && in_array($choice, ['5', '6']),
        'override_events' => false
    ];
    
    if ($options['events']) {
        echo "Override existing events? (y/n) [n]: ";
        $override = strtolower(trim(fgets(STDIN)));
        $options['override_events'] = ($override === 'y');
    }
    
    return $options;
}

// --- Sync Functions --- //

function sync_metadata($config) {
    log_message("Starting Metadata (Data Dictionary) sync");
    
    // Export metadata from local
    $export_params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'metadata',
        'format' => 'json',
        'returnFormat' => 'json'
    ];
    
    $metadata = redcap_api_call($config['api']['local_url'], $export_params);
    log_message("Exported " . count($metadata) . " fields from local");
    
    // Import metadata to remote
    $import_params = [
        'token' => $config['tokens']['remote_token'],
        'content' => 'metadata',
        'format' => 'json',
        'data' => json_encode($metadata),
        'returnFormat' => 'json'
    ];
    
    $result = redcap_api_call($config['api']['remote_url'], $import_params);
    log_message("Imported " . $result . " fields to remote");
    
    return $result;
}




function sync_project_info($config) {
    log_message("Starting Project Information sync");
    
    // Export project info from local
    $export_params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'project',
        'format' => 'json',
        'returnFormat' => 'json'
    ];
    
    $project_info = redcap_api_call($config['api']['local_url'], $export_params);
    log_message("Exported project info from local");
    
    // Prepare complete data for import with all available fields
    $import_data = [
        'project_title' => $project_info['project_title'],
        'project_language' => $project_info['project_language'],
        'purpose' => $project_info['purpose'],
        'purpose_other' => $project_info['purpose_other'] ?? '',
        'project_notes' => $project_info['project_notes'] ?? '',
        'custom_record_label' => $project_info['custom_record_label'] ?? '',
        'secondary_unique_field' => $project_info['secondary_unique_field'] ?? '',
        'is_longitudinal' => $project_info['is_longitudinal'],
        'surveys_enabled' => $project_info['surveys_enabled'],
        'scheduling_enabled' => $project_info['scheduling_enabled'],
        'record_autonumbering_enabled' => $project_info['record_autonumbering_enabled'],
        'randomization_enabled' => $project_info['randomization_enabled'] ?? 0,
        'project_irb_number' => $project_info['project_irb_number'] ?? '',
        'project_grant_number' => $project_info['project_grant_number'] ?? '',
        'project_pi_firstname' => $project_info['project_pi_firstname'] ?? '',
        'project_pi_lastname' => $project_info['project_pi_lastname'] ?? '',
        'display_today_now_button' => $project_info['display_today_now_button'],
        'bypass_branching_erase_field_prompt' => $project_info['bypass_branching_erase_field_prompt'] ?? 0,
        'missing_data_codes' => $project_info['missing_data_codes'] ?? ''
    ];
    
    // Handle external modules if they exist
    if (isset($project_info['external_modules'])) {
        $import_data['external_modules'] = $project_info['external_modules'];
    }
    
    // Import project info to remote using project_settings API
    $import_params = [
        'token' => $config['tokens']['remote_token'],
        'content' => 'project_settings',
        'format' => 'json',
        'data' => json_encode($import_data),
        'returnFormat' => 'json'
    ];
    
    $result = redcap_api_call($config['api']['remote_url'], $import_params);
    log_message("Updated " . $result . " project settings in remote");
    
    return $result;
}











function sync_repeating_instruments($config) {
    log_message("Starting Repeating Instruments/Events sync");
    
    // Export repeating instruments from local
    $export_params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'repeatingFormsEvents',
        'format' => 'json',
        'returnFormat' => 'json'
    ];
    
    $repeating = redcap_api_call($config['api']['local_url'], $export_params);
    log_message("Exported " . count($repeating) . " repeating instruments/events from local");
    
    if (empty($repeating)) {
        log_message("No repeating instruments/events to sync");
        return 0;
    }
    
    // Import repeating instruments to remote
    $import_params = [
        'token' => $config['tokens']['remote_token'],
        'content' => 'repeatingFormsEvents',
        'format' => 'json',
        'data' => json_encode($repeating),
        'returnFormat' => 'json'
    ];
    
    $result = redcap_api_call($config['api']['remote_url'], $import_params);
    log_message("Imported " . $result . " repeating instruments/events to remote");
    
    return $result;
}

function sync_dags($config) {
    log_message("Starting Data Access Groups (DAGs) sync");
    
    // Export DAGs from local
    $export_params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'dag',
        'format' => 'json',
        'returnFormat' => 'json'
    ];
    
    $dags = redcap_api_call($config['api']['local_url'], $export_params);
    log_message("Exported " . count($dags) . " DAGs from local");
    
    if (empty($dags)) {
        log_message("No DAGs to sync");
        return 0;
    }
    
    // Blank out the unique_group_name and data_access_group_id fields
    $modified_dags = array_map(function($dag) {
        $dag['unique_group_name'] = '';
        $dag['data_access_group_id'] = '';
        return $dag;
    }, $dags);
    
    // Import DAGs to remote
    $import_params = [
        'token' => $config['tokens']['remote_token'],
        'content' => 'dag',
        'action' => 'import',
        'format' => 'json',
        'data' => json_encode($modified_dags),
        'returnFormat' => 'json'
    ];
    
    $result = redcap_api_call($config['api']['remote_url'], $import_params);
    log_message("Imported " . $result . " DAGs to remote");
    
    return $result;
}

function sync_events($config, $override = false) {
    log_message("Starting Events sync");
    
    // Export events from local
    $export_params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'event',
        'format' => 'json',
        'returnFormat' => 'json'
    ];
    
    $events = redcap_api_call($config['api']['local_url'], $export_params);
    log_message("Exported " . count($events) . " events from local");
    
    if (empty($events)) {
        log_message("No events to sync");
        return 0;
    }
    
    // Import events to remote
    $import_params = [
        'token' => $config['tokens']['remote_token'],
        'content' => 'event',
        'action' => 'import',
        'override' => $override ? 1 : 0,
        'format' => 'json',
        'data' => json_encode($events),
        'returnFormat' => 'json'
    ];
    
    $result = redcap_api_call($config['api']['remote_url'], $import_params);
    log_message("Imported " . $result . " events to remote");
    
    return $result;
}

// --- Main Sync Process --- //

try {
    log_message("=== CONFIGURATION SYNC STARTED ===");
    
    // Set default sync flags
    $SYNC_METADATA = true;
    $SYNC_PROJECT_INFO = true;
    $SYNC_REPEATING = true;
    $SYNC_DAGS = true;
    $SYNC_EVENTS = true;
    $OVERRIDE_EVENTS = false;
    
    // Get user options
    $options = get_user_options();
    
    $start_time = microtime(true);
    $results = [];
    
    if ($options['metadata']) {
        $results['metadata'] = sync_metadata($config);
    }
    
    if ($options['project_info']) {
        $results['project_info'] = sync_project_info($config);
    }
    
    if ($options['repeating']) {
        $results['repeating'] = sync_repeating_instruments($config);
    }
    
    if ($options['dags']) {
        $results['dags'] = sync_dags($config);
    }
    
    if ($options['events']) {
        $results['events'] = sync_events($config, $options['override_events']);
    }
    
    // Update sync time
    file_put_contents(STATE_FILE, date('Y-m-d H:i:s'));
    
    $duration = round(microtime(true) - $start_time, 2);
    
    log_message("=== FINAL STATISTICS ===");
    log_message("Sync completed in {$duration}s");
    foreach ($results as $type => $count) {
        log_message(ucfirst(str_replace('_', ' ', $type)) . ": $count items");
    }
    log_message("=== SYNC COMPLETED ===\n");

} catch (Exception $e) {
    $error_message = "=== SYNC FAILED ===\n" . $e->getMessage();
    log_message($error_message);
    die($error_message);
}
