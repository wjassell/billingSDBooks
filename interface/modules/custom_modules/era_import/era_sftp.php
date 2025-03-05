<?php
// require_once '../../../globals.php';
require_once(dirname(__DIR__, 3) . '/globals.php');


function downloadERAFilesFromSFTP() {

        $host = $GLOBALS['sftp_hostname'];
        $port = $GLOBALS['portno'];
        $username = $GLOBALS['sftp_username'];
        $password = $GLOBALS['sftp_password'];
        $remoteDir = '/inbound/';
        $localDir = __DIR__ . '/downloads/';

      if (!file_exists($localDir)) {
        mkdir($localDir, 0755, true);
    }

    $connection = ssh2_connect($host, $port);
    if (!$connection) {
        error_log("[ERA IMPORT] SFTP connection failed.", 3, __DIR__ . "/logs/era_import.log");
        return false;
    }

    ssh2_auth_password($connection, $username, $password);

    $sftp = ssh2_sftp($connection);

    $dir = "ssh2.sftp://$sftp$remoteDir";

    if (!file_exists($dir)) {
        // Create directory
        if (ssh2_sftp_mkdir($sftp, $remoteDir)) {
            error_log("[CREATE DIR] Directory $remoteDir created successfully.", 3, __DIR__ . "/logs/sftp.log");
            return true;
        } else {
            error_log("[CREATE DIR] Failed to create directory $remoteDir.", 3, __DIR__ . "/logs/sftp.log");
            return false;
        }
    } else {
        error_log("[CREATE DIR] Directory $remoteDir already exists.", 3, __DIR__ . "/logs/sftp.log");
        return true;
    }

    $remoteDir = '/inbound/';
    createSFTPLocalDir($remoteDir);


    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;

        $remoteFile = $dir . $file;
        $localFile = $localDir . $file;


        if (copy($remoteFile, $localFile)) {
            error_log("[ERA IMPORT] Downloaded file: $file\n", 3, __DIR__ . "/logs/era_import.log");
            unlink($remoteFile); // Remove file from SFTP server after download
        } else {
            error_log("[ERA IMPORT] Failed to download file: $file\n", 3, __DIR__ . "/logs/era_import.log");
        }
    }


}
