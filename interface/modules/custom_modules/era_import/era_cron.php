<?php

// Simulate required $_SERVER variables for CLI and non-CLI execution
$_SERVER['REQUEST_URI'] = $_SERVER['PHP_SELF'] ?? '';
$_SERVER['SERVER_NAME']  = 'localhost';

// If running via CLI and a site parameter is provided (or missing), set it in $_GET
if (php_sapi_name() === 'cli') {
    // For CLI, ensure we have a $_GET['site'] value. If $argc is greater than 1, check the first argument.
    if (($argc ?? 0) > 1 && empty($_GET['site'])) {
        // Expecting a parameter in the form "site=..."
        if (stripos($argv[1], 'site=') === false) {
            echo xlt("Missing Site Id using default") . "\n";
            $argv[1] = "site=default";
        }
        $args = explode('=', $argv[1]);
        $_GET['site'] = $args[1] ?? 'default';
    } else {
        // No CLI parameter provided; default to 'default'
        $_GET['site'] = 'default';
    }
    // Set additional server variables for CLI environment
    $_SERVER["HTTP_HOST"] = "localhost";
    $ignoreAuth = true;
} else {

    // // If no site id in session, you may choose to set a default (if desired)
    if (empty($_SESSION['site_id'])) {
        $_SESSION['site_id'] = $_GET['site'] ?? 'default';
    }
}

// Include OpenEMR globals (adjust the path if necessary)
require_once(dirname(__DIR__, 3) . '/globals.php');

// Log the start of the script execution
error_log("[ERA IMPORT] Script started at: " . date("Y-m-d H:i:s") . "\n", 3, __DIR__ . "/logs/era_import.log");

// Load required files for SFTP download and ERA parsing
require_once(__DIR__ . "/era_sftp.php");
require_once(__DIR__ . "/era_parser.php");

// Define the local downloads directory (where ERA files will be stored)
$localDir = __DIR__ . '/downloads/';

// Ensure the download directory exists (create it if it doesn't)
if (!is_dir($localDir)) {
    mkdir($localDir, 0777, true);
    error_log("[ERA IMPORT] Created downloads directory.\n", 3, __DIR__ . "/logs/era_import.log");
}

// Run the SFTP download and process files
error_log("[ERA IMPORT] Downloading files from SFTP...\n", 3, __DIR__ . "/logs/era_import.log");
if (downloadERAFilesFromSFTP()) {
    error_log("[ERA IMPORT] SFTP Download Successful.\n", 3, __DIR__ . "/logs/era_import.log");

    // Scan for downloaded files (ignoring '.' and '..')
    $files = array_diff(scandir($localDir), ['.', '..']);

    if (empty($files)) {
        error_log("[ERA IMPORT] No new files found.\n", 3, __DIR__ . "/logs/era_import.log");
    }

    // Process each file found in the downloads directory
    foreach ($files as $file) {
        $filePath = $localDir . DIRECTORY_SEPARATOR . $file;

        if (!file_exists($filePath)) {
            error_log("[ERA IMPORT] File not found: $filePath\n", 3, __DIR__ . "/logs/era_import.log");
            continue;
        }

        $fileSize = filesize($filePath);
        $formattedFileSize = formatFileSize($fileSize);

        error_log("[ERA IMPORT] Processing file: $file, Size: $formattedFileSize.\n", 3, __DIR__ . "/logs/era_import.log");

        // Process and import the ERA file
        parseAndImportERAFile($filePath);

        unlink($filePath); // Remove processed file

    }
    error_log("[ERA IMPORT] Completed processing all files.\n", 3, __DIR__ . "/logs/era_import.log");
} else {
    error_log("[ERA IMPORT] SFTP download failed.\n", 3, __DIR__ . "/logs/era_import.log");
}

/**
 * Helper function to format file sizes into human-readable form.
 *
 * @param int $size The file size in bytes.
 * @return string The formatted file size.
 */
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unitIndex = 0;
    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }
    return round($size, 2) . ' ' . $units[$unitIndex];
}

// Log the completion of the script execution
error_log("[ERA IMPORT] Script execution completed at: " . date("Y-m-d H:i:s") . "\n", 3, __DIR__ . "/logs/era_import.log");


use OpenEMR\Core\Header;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo xlt('ERA Import'); ?></title>
    <?php Header::setupHeader(['opener','common', 'bootstrap','jquery', 'jquery-ui-base','jquery-ui','datatables-jqui', 'datatables-jqui-theme','moment','dialog', 'datetime-picker','fontawesome','datatables','select2']); ?>

</head>
<body class="body_top" style="padding:5px;">
<div class="container-fluid">
         <div class="card card-primary">
        <div class="card-header">
                <h5>ERA Import</h5>
            </div>
            <div class="card-body">
            <!-- <strong>Downloaded File Name:</strong><br>  -->
            <div class="download-header">
            <div class="row tab-pane" role="tabpanel" id="downloaded-div">
            <div class="col-sm-12">
                 <div class="table-responsive">
                 <table class="table table-bordered table-striped table-hover" id="eraImportDownload" width="100%">
                        <thead>
                            <tr>
                                <th>Sno</th>
                                <th>Log Entry</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
</div>
</body>

<script>

$(document).ready(function() {
    $('#eraImportDownload').DataTable({
        "processing": true,
        "serverSide": false,
        "ajax": {
            "url": "era_import_download.php",
            "type": "POST",
            "data": function(d) {
                d.fn = 'getLogs';
            },
            "dataSrc": "data"
        },
        "columns": [
            { "data": "sno" },
            { "data": "log_entry" }
        ],
        "order": [[0, 'asc']],
        "responsive": true,
        "language": {
            "emptyTable": "No logs available to display."
        }
    });
});

</script>
