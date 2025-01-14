<?php
require_once("../globals.php"); // Include OpenEMR's global variables
require_once("../../vendor/tecnickcom/tcpdf/tcpdf.php"); // Include TCPDF library

// Check if any encounters were selected
if (!isset($_POST['selected_ids'])) {
    die("No encounters selected.");
}

$selected_ids = $_POST['selected_ids'];
$placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

// Fetch encounter details for the selected IDs
$sql = "
    SELECT eo_session_notes.note_content, eo_form_encounter.date, 
           s4me_provider.full_name AS Provider, s4me_patient.full_name AS Patient, 
           s4me_spot_billingcode.billing_code_CR AS CPT_Code
    FROM SDBooks1.eo_form_encounter
    INNER JOIN SDBooks1.eo_session_notes ON eo_form_encounter.id = eo_session_notes.eo_form_encounter
    INNER JOIN SDBooks1.s4me_provider ON eo_form_encounter.provider_id = s4me_provider.id
    INNER JOIN SDBooks1.s4me_patient ON eo_form_encounter.pid = s4me_patient.id
    INNER JOIN SDBooks1.s4me_spot_billingcode ON eo_form_encounter.pc_catid = s4me_spot_billingcode.spot_id
    WHERE eo_form_encounter.id IN ($placeholders)
";

$params = $selected_ids;
$stmt = sqlQ($sql, $params);

// Initialize PDF
$pdf = new TCPDF();
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('OpenEMR');
$pdf->SetTitle('Encounter Documentation');
$pdf->SetHeaderData('', '', 'Encounter Documentation', '');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 12);

// Loop through the results and add them to the PDF
while ($row = $stmt->FetchRow()) {
    $pdf->Write(0, "Date: " . $row['date']);
    $pdf->Ln();
    $pdf->Write(0, "Provider: " . $row['Provider']);
    $pdf->Ln();
    $pdf->Write(0, "Patient: " . $row['Patient']);
    $pdf->Ln();
    $pdf->Write(0, "CPT Code: " . $row['CPT_Code']);
    $pdf->Ln();
    $pdf->Write(0, "Session Notes: ");
    $pdf->Ln();
    $pdf->Write(0, $row['note_content']);
    $pdf->Ln();
    $pdf->Ln();
}

// Output the PDF to the browser
$pdf->Output('encounter_documentation.pdf', 'I');
?>
