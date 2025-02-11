<?php
require_once "../globals.php";
require_once "../../custom/code_types.inc.php";
require_once "$srcdir/patient.inc.php";
require_once "$srcdir/options.inc.php";
require_once "$srcdir/acl.inc";

use OpenEMR\Common\Csrf\CsrfUtils;

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

if (!AclMain::aclCheckCore('acct', 'bill', '', 'write')) {
    echo json_encode(['message' => xlt('Not authorized')]);
    exit;
}

$provider_id = $_POST['provider_id'];
$encounters = $_POST['encounters'];

if (empty($provider_id) || empty($encounters)) {
    echo json_encode(['message' => xlt('Invalid input')]);
    exit;
}

// Update the provider_id for the selected encounters
foreach ($encounters as $encounter) {
    sqlStatement("UPDATE form_encounter SET provider_id = ? WHERE encounter = ?", [$provider_id, $encounter]);
}

echo json_encode(['message' => xlt('Provider updated successfully')]);
exit;
?>