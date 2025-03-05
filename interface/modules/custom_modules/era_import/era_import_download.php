<?php
// require_once "../../../globals.php";
require_once(dirname(__DIR__, 3) . '/globals.php');



if (isset($_POST['fn']) && $_POST['fn'] === 'getLogs') {
    getLogs();
}

function getLogs() {
    $logFilePath = __DIR__ . '/logs/era_import.log'; // Path to the log file
    $data = array(); // Array to hold log entries
    $sno = 1; // Serial number for table rows

    if (file_exists($logFilePath)) {
        // Read the log file line by line
        $logEntries = file($logFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);


        foreach ($logEntries as $entry) {
            $data[] = array(
                "sno" => $sno,
                "log_entry" => htmlspecialchars($entry) // Ensure safe output
            );
            $sno++;
        }
    }

    // DataTables expects this response structure
    $response = array(
        "data" => $data
    );

    // Return the response as JSON
    header('Content-Type: application/json');
    echo json_encode($response);
}
