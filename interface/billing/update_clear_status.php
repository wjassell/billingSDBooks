<?php
require_once("../globals.php");

$sessionId = $_POST['session_id'];
$clearStatus = $_POST['clear'];

if (!empty($sessionId)) {
    $sql = "UPDATE ar_session SET cleared = ? WHERE session_id = ?";
    $result = sqlStatement($sql, array($clearStatus, $sessionId));

    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
} else {
    echo json_encode(['success' => false]);
}
?>