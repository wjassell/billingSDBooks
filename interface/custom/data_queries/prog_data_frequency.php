<?php
// Include OpenEMR globals
require_once($GLOBALS['fileroot'] . "/interface/globals.php");

// Function to fetch prog_data_frequency and related tables
function fetchProgDataFrequency($encounter_id, $pdf) {
    $sql = "
        SELECT prog_class.Class, prog_book_specific_targets.Insurance_Target, prog_data_frequency.frequency
        FROM SDBooks1.prog_data_frequency
        INNER JOIN SDBooks1.prog_book_specific_targets ON prog_data_frequency.specific_target_ID = prog_book_specific_targets.ID
        INNER JOIN SDBooks1.prog_book_main_targets ON prog_book_specific_targets.MainTargetID = prog_book_main_targets.ID
        INNER JOIN SDBooks1.prog_guide ON prog_book_main_targets.QuestionID = prog_guide.QuestionID
        INNER JOIN SDBooks1.prog_class ON prog_class.ClassID = prog_guide.ClassID
        WHERE prog_data_frequency.eo_form_encounter = ?
    ";

    $stmt = sqlStatement($sql, [$encounter_id]);

    while ($row = sqlFetchArray($stmt)) {
        $pdf->Write(0, "Class: " . $row['Class'], '', 0, 'L', true);
        $pdf->Write(0, "Insurance Target: " . $row['Insurance_Target'], '', 0, 'L', true);
        $pdf->Write(0, "Frequency: " . $row['frequency'], '', 0, 'L', true);
        $pdf->Ln(5);
    }
    $pdf->Ln(10);
}
