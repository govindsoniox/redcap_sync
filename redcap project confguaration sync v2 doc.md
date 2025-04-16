# REDCap Configuration Sync Tool

## Overview

This tool synchronizes project configuration between REDCap instances, allowing you to export configuration from a remote REDCap instance and import it into a local instance. The tool supports both interactive and non-interactive modes of operation.

## Features

- **Metadata (Data Dictionary) Sync**: Full export/import of all field definitions
- **Project Information Sync**: Project settings, titles, and configurations
- **Repeating Instruments/Events Sync**: Maintains repeating structure
- **Data Access Groups (DAGs) Sync**: Group names and unique identifiers
- **Events Sync**: For longitudinal studies (with override option)
- **Flexible Operation**: Both interactive and non-interactive modes
- **Comprehensive Logging**: Detailed operation logs with timestamps

## Requirements

- PHP 7.0 or higher
- REDCap API access with appropriate permissions
- Configuration file (`config_project_sync.ini`) with API tokens and URLs

## Installation

1. Place the script (`config_sync.php`) on your server
2. Ensure the `config_project_sync.ini` file exists in `/var/config/` with:
   - Remote and local API URLs
   - Remote and local API tokens
3. Set appropriate permissions for the script and log files

## Configuration

### config_project_sync.ini Structure

```ini
[api]
remote_url = "https://remote.redcap.instance/api/"
local_url = "https://local.redcap.instance/api/"

[tokens]
remote_token = "REMOTE_API_TOKEN"
local_token = "LOCAL_API_TOKEN"
```

### Script Configuration Options

At the top of `config_sync.php`, you can set these flags for non-interactive operation:

```php
$SYNC_METADATA = false;       // Sync Data Dictionary
$SYNC_PROJECT_INFO = false;   // Sync Project Information
$SYNC_REPEATING = false;      // Sync Repeating Instruments/Events
$SYNC_DAGS = false;           // Sync Data Access Groups
$SYNC_EVENTS = false;         // Sync Events
$OVERRIDE_EVENTS = false;     // Override existing events when syncing
```

## Usage

### Interactive Mode

Run the script without any pre-configured options:

```bash
php config_sync.php
```

You will be presented with a menu to select which components to sync.

### Non-Interactive Mode

1. Edit the configuration flags at the top of `config_sync.php`
2. Run the script:

```bash
php config_sync.php
```

The script will execute based on your pre-configured options.

### Command Line Options

For advanced usage, you can pass parameters directly:

```bash
php config_sync.php --metadata --project-info --dags
```

Available flags:
- `--metadata`: Sync Data Dictionary
- `--project-info`: Sync Project Information
- `--repeating`: Sync Repeating Instruments/Events
- `--dags`: Sync Data Access Groups
- `--events`: Sync Events
- `--override-events`: Override existing events (must be used with `--events`)

## Permissions Required

| Feature                   | Export Permissions Required              | Import Permissions Required               |
|---------------------------|------------------------------------------|-------------------------------------------|
| Metadata                  | API Export                               | API Import/Update + Project Design/Setup  |
| Project Information       | API Export                               | API Import/Update + Project Setup/Design  |
| Repeating Instruments     | API Export + Project Setup/Design        | API Import/Update + Project Design/Setup  |
| Data Access Groups (DAGs) | API Export + Data Access Groups          | API Import/Update + Data Access Groups    |
| Events                    | API Export                               | API Import/Update + Project Design/Setup  |

## Logging

The script logs all operations to `config_sync_log.txt` with timestamps. Each run includes:

- Start and completion times
- Number of items processed for each component
- Any errors encountered

## Error Handling

The script includes comprehensive error handling for:

- API connection issues
- Permission problems
- JSON parsing errors
- Invalid configurations

## Best Practices

1. **Test First**: Always test with a small project first
2. **Backup**: Create backups before syncing critical projects
3. **Development Mode**: Perform imports in Development status projects when possible
4. **Monitor Logs**: Regularly check the log file for any issues
5. **Rate Limiting**: Be mindful of API rate limits when syncing large projects

## Example Output

```
[2023-06-15 14:30:45] === CONFIGURATION SYNC STARTED ===
[2023-06-15 14:30:46] Starting Metadata (Data Dictionary) sync
[2023-06-15 14:30:48] Exported 142 fields from remote
[2023-06-15 14:30:50] Imported 142 fields to local
[2023-06-15 14:30:51] Starting Project Information sync
[2023-06-15 14:30:52] Exported project info from remote
[2023-06-15 14:30:53] Updated 12 project settings in local
[2023-06-15 14:30:54] === FINAL STATISTICS ===
[2023-06-15 14:30:54] Sync completed in 9s
[2023-06-15 14:30:54] Metadata: 142 items
[2023-06-15 14:30:54] Project info: 12 items
[2023-06-15 14:30:54] === SYNC COMPLETED ===
```

## Troubleshooting

**Problem**: API connection errors  
**Solution**: Verify API URLs and tokens in config_project_sync.ini

**Problem**: Permission denied errors  
**Solution**: Check user has required permissions for both export and import

**Problem**: Memory exhaustion  
**Solution**: Increase memory_limit in script configuration

**Problem**: Events not syncing  
**Solution**: Ensure project is longitudinal and in Development status for override

## License

This tool is provided as-is without warranty. Use at your own risk.
