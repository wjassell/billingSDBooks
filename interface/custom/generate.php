<?php
// Include OpenEMR globals and TCPDF library
require_once("../globals.php");
require_once("../../vendor/tecnickcom/tcpdf/tcpdf.php");

// Include data queries
require_once("data_queries/prog_data_yn.php");
require_once("data_queries/prog_data_duration.php");
require_once("data_queries/prog_data_frequency.php");
require_once("data_queries/prog_data_interval.php");
require_once("data_queries/prog_data_multistep.php");

// Check if any encounters were selected
if (!isset($_POST['selected_ids']) || empty($_POST['selected_ids'])) {
    die("No encounters selected.");
}

// Get selected encounter IDs
$selected_ids = $_POST['selected_ids'];
$placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

// Fetch encounter details for the selected IDs
$sql = "
    SELECT eo_session_notes.note_content, eo_form_encounter.date, eo_form_encounter.time_in, eo_form_encounter.time_out,
           s4me_provider.full_name AS Provider, s4me_patient.full_name AS Patient, 
           s4me_spot_billingcode.billing_code_CR AS CPT_Code, eo_signatures.signature, eo_form_encounter.id AS encounter_id
    FROM SDBooks1.eo_form_encounter
    INNER JOIN SDBooks1.eo_session_notes ON eo_form_encounter.id = eo_session_notes.eo_form_encounter
    INNER JOIN SDBooks1.s4me_provider ON eo_form_encounter.provider_id = s4me_provider.id
    INNER JOIN SDBooks1.s4me_patient ON eo_form_encounter.pid = s4me_patient.id
    INNER JOIN SDBooks1.s4me_spot_billingcode ON eo_form_encounter.pc_catid = s4me_spot_billingcode.spot_id
    INNER JOIN SDBooks1.eo_signatures ON eo_signatures.eo_enc_ID = eo_form_encounter.id
    WHERE eo_form_encounter.id IN ($placeholders)
";

$params = $selected_ids;
$stmt = sqlStatement($sql, $params);

// Initialize TCPDF
$pdf = new TCPDF();
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('OpenEMR');
$pdf->SetTitle('Encounter Documentation');
$pdf->SetHeaderData('', 0, 'Encounter Documentation', '');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);
$pdf->AddPage();

// Function to parse JSON note content and add to the PDF
function parseNoteContent($jsonContent, $pdf) {
    $data = json_decode($jsonContent, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (!empty($data['FormName']) && $data['FormName'] !== 'N/A') {
            $pdf->Write(0, "Form Name: " . $data['FormName'], '', 0, 'L', true);
        }
        if (!empty($data['Notes'])) {
            $pdf->MultiCell(0, 10, "Notes: " . $data['Notes'], 0, 'L', 0, 1);
        }
        if (!empty($data['Questions'])) {
            foreach ($data['Questions'] as $question) {
                $questionText = $question['Question'] ?? '';
                $answers = isset($question['Answers']) ? implode(', ', $question['Answers']) : 'N/A';
                if (!empty($questionText) && $answers !== 'N/A') {
                    $pdf->Write(0, "$questionText: $answers", '', 0, 'L', true);
                }
            }
        }
        if (isset($data['CaregiverPresent'])) {
            if (!empty($data['CaregiverPresent']) && $data['CaregiverPresent'] !== 'N/A') {
                $pdf->Write(0, "Caregiver Present: " . $data['CaregiverPresent'], '', 0, 'L', true);
            }
        }
        $pdf->Ln(10);
    } else {
        $pdf->Write(0, "Invalid JSON format in note content.", '', 0, 'L', true);
    }
}

// Track total encounters
$totalEncounters = 0;
while ($row = sqlFetchArray($stmt)) {
    $totalEncounters++;
}

// Reset statement to loop through again
$stmt = sqlStatement($sql, $params);
$currentEncounter = 1;

// Loop through the results and add them to the PDF
while ($row = sqlFetchArray($stmt)) {
    $pdf->SetFont('helvetica', '', 12);

    // Header Information
    if (!empty($row['date']) && $row['date'] !== 'N/A') {
        $pdf->Write(0, "Date: " . $row['date'], '', 0, 'L', true);
    }
    $timezone = new DateTimeZone('America/Chicago');

    // Convert and display start time
    if (!empty($row['time_in'])) {
        $start_time = (new DateTime($row['time_in'], new DateTimeZone('UTC')))->setTimezone($timezone)->format('h:i A');
        $pdf->Write(0, "Start Time: $start_time", '', 0, 'L', true);
    }

    // Convert and display end time
    if (!empty($row['time_out'])) {
        $end_time = (new DateTime($row['time_out'], new DateTimeZone('UTC')))->setTimezone($timezone)->format('h:i A');
        $pdf->Write(0, "End Time: $end_time", '', 0, 'L', true);
    }

    if (!empty($row['Provider']) && $row['Provider'] !== 'N/A') {
        $pdf->Write(0, "Provider: " . $row['Provider'], '', 0, 'L', true);
    }
    if (!empty($row['Patient']) && $row['Patient'] !== 'N/A') {
        $pdf->Write(0, "Patient: " . $row['Patient'], '', 0, 'L', true);
    }
    if (!empty($row['CPT_Code']) && $row['CPT_Code'] !== 'N/A') {
        $pdf->Write(0, "CPT Code: " . $row['CPT_Code'], '', 0, 'L', true);
    }
    $pdf->Ln(5);

    // Parse and display note content
    parseNoteContent($row['note_content'], $pdf);

    // Fetch data from the external files and sort by Class
    $dataCollectionFunctions = [
        'fetchProgDataYN',
        'fetchProgDataDuration',
        'fetchProgDataFrequency',
        'fetchProgDataInterval',
        'fetchProgDataMultistep'
    ];

    $dataCollection = [];

    foreach ($dataCollectionFunctions as $function) {
        ob_start();
        $function($row['encounter_id'], $pdf);
        $data = ob_get_clean();
        $dataCollection[] = $data;
    }

    // Sort and display data collection
    sort($dataCollection);
    foreach ($dataCollection as $data) {
        $pdf->Write(0, $data, '', 0, 'L', true);
    }

    // Decode base64 signature and save as a PNG file
    if (!empty($row['signature'])) {
        $decoded_signature = base64_decode($row['signature']);
        if ($decoded_signature !== false) {
            $signature_file = tempnam(sys_get_temp_dir(), 'sig') . '.png';
            file_put_contents($signature_file, $decoded_signature);

            // Embed the image in the PDF
            $pdf->Image($signature_file, '', '', 50, 20, 'PNG');
            unlink($signature_file); // Delete the temporary file
            $pdf->Ln(10);
        } else {
            $pdf->Write(0, "Signature could not be processed.", '', 0, 'L', true);
        }
    }

    // Add a page break if it's not the last encounter
    if ($currentEncounter < $totalEncounters) {
        $pdf->AddPage();
    }
    $currentEncounter++;
}

// Output the PDF to the browser
$pdf->Output('encounter_documentation.pdf', 'I');
?>
