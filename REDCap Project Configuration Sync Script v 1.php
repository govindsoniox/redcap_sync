<?php
/**
 * REDCap Project Configuration Sync Script
 * Version: 1.0
 * 
 * Syncs project configuration from remote to local including:
 * 1. Metadata (Data Dictionary)
 * 2. Project Information
 * 3. Repeating Instruments/Events
 */

// ================= CONFIGURATION ================= //
// Load configuration from file
$config = parse_ini_file('/var/config/config_project_sync.ini', true);
if ($config === false) {
    die("Failed to load configuration file");
}

// Set runtime limits
ini_set('max_execution_time', 300);    // 5 minutes
ini_set('memory_limit', '1024M');      // 1GB memory

// Constants
define('LOG_FILE', 'config_sync_log.txt');
define('STATE_FILE', 'config_sync_state.txt');
// ================================================= //

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

function get_user_options() {
    echo "\nREDCap Configuration Sync Options:\n";
    echo "1. Sync Metadata (Data Dictionary)\n";
    echo "2. Sync Project Information\n";
    echo "3. Sync Repeating Instruments/Events\n";
    echo "4. Sync All (1+2+3)\n";
    echo "Enter your choice (1-4): ";
    
    $choice = trim(fgets(STDIN));
    while (!in_array($choice, ['1', '2', '3', '4'])) {
        echo "Invalid choice. Please enter 1-4: ";
        $choice = trim(fgets(STDIN));
    }
    
    $options = [
        'metadata' => in_array($choice, ['1', '4']),
        'project_info' => in_array($choice, ['2', '4']),
        'repeating' => in_array($choice, ['3', '4'])
    ];
    
    return $options;
}

// --- Sync Functions --- //

function sync_metadata($config) {
    log_message("Starting Metadata (Data Dictionary) sync");
    
    // Export metadata from remote
    $export_params = [
        'token' => $config['tokens']['remote_token'],
        'content' => 'metadata',
        'format' => 'json',
        'returnFormat' => 'json'
    ];
    
    $metadata = redcap_api_call($config['api']['remote_url'], $export_params);
    log_message("Exported " . count($metadata) . " fields from remote");
    
    // Import metadata to local
    $import_params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'metadata',
        'format' => 'json',
        'data' => json_encode($metadata),
        'returnFormat' => 'json'
    ];
    
    $result = redcap_api_call($config['api']['local_url'], $import_params);
    log_message("Imported " . $result . " fields to local");
    
    return $result;
}

function sync_project_info($config) {
    log_message("Starting Project Information sync");
    
    // Export project info from remote
    $export_params = [
        'token' => $config['tokens']['remote_token'],
        'content' => 'project',
        'format' => 'json',
        'returnFormat' => 'json'
    ];
    
    $project_info = redcap_api_call($config['api']['remote_url'], $export_params);
    log_message("Exported project info from remote");
    
    // Prepare data for import (only updatable fields)
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
        'bypass_branching_erase_field_prompt' => $project_info['bypass_branching_erase_field_prompt'] ?? 0
    ];
    
    // Import project info to local
    $import_params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'project_settings',
        'format' => 'json',
        'data' => json_encode($import_data),
        'returnFormat' => 'json'
    ];
    
    $result = redcap_api_call($config['api']['local_url'], $import_params);
    log_message("Updated " . $result . " project settings in local");
    
    return $result;
}

function sync_repeating_instruments($config) {
    log_message("Starting Repeating Instruments/Events sync");
    
    // Export repeating instruments from remote
    $export_params = [
        'token' => $config['tokens']['remote_token'],
        'content' => 'repeatingFormsEvents',
        'format' => 'json',
        'returnFormat' => 'json'
    ];
    
    $repeating = redcap_api_call($config['api']['remote_url'], $export_params);
    log_message("Exported " . count($repeating) . " repeating instruments/events from remote");
    
    if (empty($repeating)) {
        log_message("No repeating instruments/events to sync");
        return 0;
    }
    
    // Import repeating instruments to local
    $import_params = [
        'token' => $config['tokens']['local_token'],
        'content' => 'repeatingFormsEvents',
        'format' => 'json',
        'data' => json_encode($repeating),
        'returnFormat' => 'json'
    ];
    
    $result = redcap_api_call($config['api']['local_url'], $import_params);
    log_message("Imported " . $result . " repeating instruments/events to local");
    
    return $result;
}

// --- Main Sync Process --- //

try {
    log_message("=== CONFIGURATION SYNC STARTED ===");
    
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