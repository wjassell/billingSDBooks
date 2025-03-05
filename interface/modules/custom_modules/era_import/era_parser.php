<?php
require_once(dirname(__DIR__, 3) . '/globals.php');
require_once "$srcdir/sql.inc.php";

function parseAndImportERAFile($filePath) {
    global $conn;

    $data = file_get_contents($filePath);
    $segments = explode('~', $data);

    $payer_id = '';
    $patient_id = '';
    $encounter_id = '';
    $cpt_code = '';
    $code_type = '';
    $fee = '';
    $billing_date = date('Y-m-d H:i:s');
    $description = 'ERA Payment Import';
    $modified_time = date('Y-m-d H:i:s');
    $check_date = '';
    $post_to_date = '';  // Ensure default value is set
    $pay_total = '';
    $payment_method = '';
    $deposit_date = '';
    $reference = '';
    $adjustment_code = ''; // Ensure default value is set
    $reason_code = '';
    $provider_id = 1; // Ensure provider_id is assigned correctly
    $created_time = date('Y-m-d H:i:s');




    foreach ($segments as $segment) {
        $elements = explode('|', $segment);

        switch ($elements[0]) {
            case 'N1':
                if ($elements[1] == 'PR') {
                    $payer_name = $elements[2];
                }

                break;

            case 'REF':
                if ($elements[1] == '2U') {
                    $payer_id = $elements[2];
                }
                break;

            case 'NM1':

                if ($elements[1] == 'IL') {
                    $patient_id = $elements[9];
                }
                if ($elements[1] == '82') {

                    $provider_id = $elements[9];
                }
                break;

            case 'CLP':
                $encounter_id = $elements[1];
                break;

                case 'DTM':
                    if ($elements[1] == '405') {
                        $check_date = $elements[2] ?? '';
                    } elseif ($elements[1] == '472') {
                        $post_to_date = $elements[2] ?? date('Y-m-d H:i:s');
                    }
                    break;



            case 'BPR':
                    $pay_total = $elements[2];          // Total Payment Amount
                    $payment_method = $elements[4];     // Payment Method
                    $deposit_date = $elements[16];      // Check Issue/Deposit Date
                    break;
            case 'TRN':
                    $reference = $elements[2];          // Check/EFT Trace Number
                    break;

            case 'CAS':
                        $adjustment_code = isset($elements[2]) ? $elements[2] : '';   // Ensure it's not NULL
                        $reason_code = isset($elements[3]) ? $elements[3] : '';       // Ensure it's not NULL
                        break;
            case 'PER': // Contact Details

                    $fax = isset($elements[6]) ? $elements[6] : '';
                    $email = isset($elements[4]) ? $elements[4] : '';
                    break;

            case 'SVC':
                $cpt_code_parts = explode('^', $elements[1]);
                $code_type = $cpt_code_parts[0];
                $cpt_code = $cpt_code_parts[1];
                $fee = $elements[2];


                if ($cpt_code && $encounter_id) {
                    sqlInsert("INSERT INTO billing (date, code_type, code, pid, provider_id, encounter, payer_id, fee)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE code = VALUES(code), fee = VALUES(fee)",
                            [$billing_date, $code_type, $cpt_code, $patient_id, $provider_id, $encounter_id, $payer_id, $fee]);
                }

                if ($provider_id && $patient_id) {
                    sqlInsert("INSERT INTO ar_session (payer_id, user_id, closed, reference, check_date, deposit_date, pay_total, created_time, modified_time, global_amount, payment_type, description, adjustment_code, post_to_date, patient_id, payment_method)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE payer_id = VALUES(payer_id),user_id = VALUES(user_id),check_date = VALUES(check_date),deposit_date = VALUES(deposit_date),pay_total = VALUES(pay_total),modified_time = VALUES(modified_time),payment_type = VALUES(payment_type),description = VALUES(description),adjustment_code = VALUES(adjustment_code),post_to_date = VALUES(post_to_date), patient_id = VALUES(patient_id),payment_method = VALUES(payment_method)",
                        [$payer_id, $provider_id, 0, $reference, $check_date, $deposit_date, $pay_total,
                        $created_time, $modified_time, $pay_total, $payment_method, $description, $adjustment_code ?: 'NULL',
                        $post_to_date ?:'NULL', $patient_id, $payment_method]);
                }

                break;
        }
    }

}
?>
