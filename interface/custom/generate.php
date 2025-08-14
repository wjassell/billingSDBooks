<?php
/**
 * @file generate.php
 * @requires TCPDF
 * @phpcs:disable - TCPDF methods not recognized by linter
 * 
 * OVERVIEW: This file generates a PDF document containing encounter documentation
 * for selected encounters. It includes encounter details, session notes, progress data,
 * and digital signatures.
 */

// Include required files for OpenEMR database functions and PDF generation
require_once("../globals.php");           // OpenEMR global functions and database connection
require_once("../../vendor/tecnickcom/tcpdf/tcpdf.php"); // TCPDF library for PDF generation

// Include custom data query modules that fetch progress tracking data
require_once("data_queries/prog_data_yn.php");        // Yes/No progress data
require_once("data_queries/prog_data_duration.php");  // Duration-based progress data
require_once("data_queries/prog_data_frequency.php"); // Frequency-based progress data
require_once("data_queries/prog_data_interval.php");  // Interval-based progress data
require_once("data_queries/prog_data_multistep.php"); // Multi-step progress data

/**
 * SECURITY CHECK: Verify that encounters were selected via POST
 * This prevents the script from running without proper form submission
 */
// Check if any encounters were selected
if (!isset($_POST['selected_ids']) || empty($_POST['selected_ids'])) {
    die("No encounters selected.");
}

/**
 * DATA PREPARATION: Get the list of encounter IDs to process
 * These IDs come from the search form where users checked specific encounters
 */
// Get selected encounter IDs from the form submission
$selected_ids = $_POST['selected_ids'];

// Create SQL placeholders (?, ?, ?) for the IN clause - one for each selected ID
$placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

/**
 * DATABASE QUERY: Fetch all encounter data needed for the PDF
 * This joins multiple tables to get complete encounter information:
 * - eo_form_encounter: Main encounter data (dates, times, IDs)
 * - eo_session_notes: JSON-formatted session notes
 * - s4me_provider: Provider full name
 * - users: Provider NPI number
 * - s4me_patient: Patient full name
 * - s4me_spot_billingcode: CPT billing codes
 * - eo_signatures: Digital signature images (base64 encoded)
 */
$sql = "
    SELECT eo_session_notes.note_content, eo_form_encounter.date, eo_form_encounter.time_in, eo_form_encounter.time_out,
           s4me_provider.full_name AS Provider, s4me_patient.full_name AS Patient, 
           s4me_spot_billingcode.billing_code_CR AS CPT_Code, eo_signatures.signature, eo_form_encounter.id AS encounter_id, eo_form_encounter.consolidated_enc_id, eo_form_encounter.pid, users.npi
    FROM SDBooks1.eo_form_encounter
    INNER JOIN SDBooks1.eo_session_notes ON eo_form_encounter.id = eo_session_notes.eo_form_encounter
    INNER JOIN SDBooks1.s4me_provider ON eo_form_encounter.provider_id = s4me_provider.id
    INNER JOIN SDBooks1.users ON eo_form_encounter.provider_id = users.id
    INNER JOIN SDBooks1.s4me_patient ON eo_form_encounter.pid = s4me_patient.id
    INNER JOIN SDBooks1.s4me_spot_billingcode ON eo_form_encounter.pc_catid = s4me_spot_billingcode.spot_id
    INNER JOIN SDBooks1.eo_signatures ON eo_signatures.eo_enc_ID = eo_form_encounter.id
    WHERE eo_form_encounter.id IN ($placeholders)
    ORDER BY eo_form_encounter.consolidated_enc_id ASC
";

// Prepare SQL parameters - the selected encounter IDs will replace the placeholders
$params = $selected_ids;

// Execute the query to get encounter data
$stmt = sqlStatement($sql, $params);

// Get the first encounter to set initial claim number for header
/**
 * RESET DATABASE CURSOR: Start the query over for main processing
 * Execute the query to process all encounters for PDF generation
 */
$stmt = sqlStatement($sql, $params);

/**
 * CUSTOM PDF CLASS: Extends TCPDF to add a header with logo
 * This class creates a professional header with logo and title
 */
class PDF_WITH_LOGO extends TCPDF {
    /**
     * CUSTOM HEADER METHOD: Generates the header for each PDF page
     * This method is automatically called by TCPDF when adding new pages
     * It displays a logo on the left and the title better aligned
     * Optimized for 566 x 340 logo dimensions with improved spacing
     */
    public function Header() {
        // Logo path - points to the interface/pic directory
        // Place your 566 x 340 logo as TBlogo.png in the interface/pic directory
        $logo_path = dirname(dirname(__FILE__)) . '/pic/TBlogo.png';
        
        // Alternative: you can use an existing image like druglogo.png for testing
        // $logo_path = dirname(dirname(__FILE__)) . '/pic/druglogo.png';
        
        // Check if logo file exists
        if (file_exists($logo_path)) {
            // Add logo on the left side - sized appropriately for 566x340 ratio
            // Width: 40mm, Height: auto (will maintain aspect ratio)
            $this->Image($logo_path, 10, 8, 40, 0, 'PNG');
            
            // Position title better aligned and vertically centered with logo
            $this->SetFont('helvetica', 'B', 18);
            $this->SetXY(58, 16); // Better positioning for alignment
            $this->Cell(0, 10, 'Encounter Documentation', 0, false, 'L', 0, '', 0, false, 'M', 'M');
        } else {
            // If no logo, center the title
            $this->SetFont('helvetica', 'B', 18);
            $this->SetXY(10, 16);
            $this->Cell(0, 10, 'Encounter Documentation', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        }
        
        // Add a line below the header for professional appearance
        $this->SetLineWidth(0.5);
        $this->Line(10, 36, 200, 36); // Professional separator line
        
        $this->Ln(35); // Increased space after header for better content separation
    }
}

/**
 * PDF INITIALIZATION: Create new PDF document and set basic properties
 * This configures the PDF with proper margins, auto page breaks, and metadata
 */
// Create PDF instance using our custom class with logo
$pdf = new PDF_WITH_LOGO(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('OpenEMR');
$pdf->SetTitle('Encounter Documentation');
$pdf->SetMargins(10, 40, 10); // Left, top, right margins - increased top margin for better header spacing
$pdf->SetAutoPageBreak(TRUE, 10); // Auto page break with 10mm bottom margin
$pdf->AddPage(); // Add the first page

/**
 * JSON PARSER FUNCTION: Extract readable data from JSON-formatted session notes
 * The session notes are stored as JSON in the database and need to be converted
 * to human-readable text for the PDF output. This function handles the complex
 * structure of form data including questions, answers, and metadata.
 * 
 * @param string $jsonContent - The JSON string from the database
 * @param PDF $pdf - The PDF object to write content to
 */
function parseNoteContent($jsonContent, $pdf) {
    /**
     * DECODE JSON: Convert JSON string to PHP array
     * If decoding fails, display an error message instead of crashing
     */
    $data = json_decode($jsonContent, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        /**
         * FORM NAME DISPLAY: Show the form name if it exists and isn't placeholder
         */
        if (!empty($data['FormName']) && $data['FormName'] !== 'N/A') {
            $pdf->Write(0, "Form Name: " . $data['FormName'], '', 0, 'L', true);
        }
        
        /**
         * NOTES SECTION: Display general notes using MultiCell for text wrapping
         * MultiCell handles long text better than Write for paragraph content
         */
        if (!empty($data['Notes'])) {
            $pdf->MultiCell(0, 10, "Notes: " . $data['Notes'], 0, 'L', 0, 1);
        }
        
        /**
         * QUESTIONS AND ANSWERS: Process the questions array from the form
         * Each question can have multiple answers that need to be formatted
         */
        if (!empty($data['Questions'])) {
            foreach ($data['Questions'] as $question) {
                $questionText = $question['Question'] ?? '';
                $answers = isset($question['Answers']) ? $question['Answers'] : [];
                
                // Only display questions that have non-empty answers
                if (!empty($questionText) && !empty($answers) && is_array($answers)) {
                    // Filter out empty answers to avoid displaying blank responses
                    $filteredAnswers = array_filter($answers, function($answer) {
                        return !empty(trim($answer));
                    });
                    
                    // Only show questions with actual content
                    if (!empty($filteredAnswers)) {
                        $answersText = implode(', ', $filteredAnswers);
                        $pdf->Write(0, "$questionText: $answersText", '', 0, 'L', true);
                    }
                }
            }
        }
        
        /**
         * CAREGIVER INFORMATION: Display caregiver presence if recorded
         */
        if (isset($data['CaregiverPresent'])) {
            if (!empty($data['CaregiverPresent']) && $data['CaregiverPresent'] !== 'N/A') {
                $pdf->Write(0, "Caregiver Present: " . $data['CaregiverPresent'], '', 0, 'L', true);
            }
        }
        
        $pdf->Ln(5); // Add spacing after note content
    } else {
        /**
         * ERROR HANDLING: If JSON parsing fails, show a helpful error message
         */
        $pdf->Write(0, "Invalid JSON format in note content.", '', 0, 'L', true);
    }
}

/**
 * ENCOUNTER COUNTING: Count total encounters for processing progress
 * We need to know how many encounters we're processing to handle pagination properly
 */
$totalEncounters = 0;
while ($row = sqlFetchArray($stmt)) {
    $totalEncounters++;
}

/**
 * RESET FOR MAIN PROCESSING: Start the query over for the actual PDF generation
 * Since we counted encounters above, we need to re-execute the query
 */
$stmt = sqlStatement($sql, $params);
$currentEncounter = 1;

/**
 * MAIN ENCOUNTER PROCESSING LOOP: Generate PDF content for each encounter
 * This is the core loop that processes each encounter and adds it to the PDF
 */
while ($row = sqlFetchArray($stmt)) {
    // Set standard font for encounter content
    $pdf->SetFont('helvetica', '', 12);

    /**
     * ENCOUNTER HEADER INFORMATION: Display basic encounter details
     * This section shows claim number, date, times, provider, patient, and CPT code
     */
    
    // Display claim number at the top of each encounter
    if (isset($row['consolidated_enc_id']) && isset($row['pid'])) {
        $claim_number = $row['pid'] . '-' . $row['consolidated_enc_id'];
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Write(0, "Claim Number: " . $claim_number, '', 0, 'L', true);
        $pdf->SetFont('helvetica', '', 12);
    }
    
    // Display encounter date
    if (!empty($row['date']) && $row['date'] !== 'N/A') {
        $pdf->Write(0, "Date: " . $row['date'], '', 0, 'L', true);
    }
    
    // Set timezone for time conversion (UTC to Central Time)
    $timezone = new DateTimeZone('America/Chicago');

    /**
     * TIME CONVERSION AND DISPLAY: Convert UTC times to local display format
     * Database stores times in UTC, but users want to see local times
     * Also calculate and display the duration in minutes
     */
    $start_time_obj = null;
    $end_time_obj = null;
    
    // Convert and display start time in 12-hour format
    if (!empty($row['time_in'])) {
        $start_time_obj = (new DateTime($row['time_in'], new DateTimeZone('UTC')))->setTimezone($timezone);
        $start_time = $start_time_obj->format('h:i A');
        $pdf->Write(0, "Start Time: $start_time", '', 0, 'L', true);
    }

    // Convert and display end time in 12-hour format
    if (!empty($row['time_out'])) {
        $end_time_obj = (new DateTime($row['time_out'], new DateTimeZone('UTC')))->setTimezone($timezone);
        $end_time = $end_time_obj->format('h:i A');
        $pdf->Write(0, "End Time: $end_time", '', 0, 'L', true);
    }
    
    // Calculate and display duration in minutes
    if ($start_time_obj && $end_time_obj) {
        $duration = $end_time_obj->diff($start_time_obj);
        $total_minutes = ($duration->h * 60) + $duration->i;
        $pdf->Write(0, "Duration: $total_minutes minutes", '', 0, 'L', true);
    }

    /**
     * PROVIDER INFORMATION: Display provider name and NPI if available
     */
    if (!empty($row['Provider']) && $row['Provider'] !== 'N/A') {
        $provider_text = "Provider: " . $row['Provider'];
        if (!empty($row['npi'])) {
            $provider_text .= " (" . $row['npi'] . ")";
        }
        $pdf->Write(0, $provider_text, '', 0, 'L', true);
    }
    
    /**
     * PATIENT AND CPT CODE: Display patient name and billing code
     */
    if (!empty($row['Patient']) && $row['Patient'] !== 'N/A') {
        $pdf->Write(0, "Patient: " . $row['Patient'], '', 0, 'L', true);
    }
    if (!empty($row['CPT_Code']) && $row['CPT_Code'] !== 'N/A') {
        $pdf->Write(0, "CPT Code: " . $row['CPT_Code'], '', 0, 'L', true);
    }
    
    $pdf->Ln(5); // Add space before note content

    /**
     * SESSION NOTES: Parse and display the JSON-formatted session notes
     */
    parseNoteContent($row['note_content'], $pdf);

    /**
     * EXTERNAL DATA COLLECTION: Fetch additional data from separate modules
     * These functions collect supplementary information related to the encounter
     * Each function queries different data sources and returns formatted results
     */
    $dataCollectionFunctions = [
        'fetchProgDataYN',        // Yes/No progress data
        'fetchProgDataDuration',  // Duration-based progress data  
        'fetchProgDataFrequency', // Frequency-based progress data
        'fetchProgDataInterval',  // Interval-based progress data
        'fetchProgDataMultistep'  // Multi-step progress data
    ];

    $dataCollection = [];

    /**
     * CALL EXTERNAL FUNCTIONS: Execute each data collection function
     * Use output buffering to capture the results from each function
     */
    foreach ($dataCollectionFunctions as $function) {
        ob_start(); // Start capturing output
        $function($row['encounter_id'], $pdf); // Call the function
        $data = ob_get_clean(); // Get the captured output
        $dataCollection[] = $data;
    }

    /**
     * SORT AND DISPLAY: Organize the collected data and add to PDF
     * Sort alphabetically and only display non-empty content
     */
    sort($dataCollection);
    $hasDataContent = false;
    foreach ($dataCollection as $data) {
        if (!empty(trim($data))) {
            $pdf->Write(0, $data, '', 0, 'L', true);
            $hasDataContent = true;
        }
    }

    // Add minimal spacing only if we had actual data content
    if ($hasDataContent) {
        $pdf->Ln(3);
    }

    /**
     * SIGNATURE PROCESSING: Handle digital signature display
     * Signatures are stored as base64-encoded PNG images in the database
     */
    if (!empty($row['signature'])) {
        /**
         * DECODE SIGNATURE: Convert base64 to binary image data
         */
        $decoded_signature = base64_decode($row['signature']);
        if ($decoded_signature !== false) {
            // Create temporary file for the signature image
            $signature_file = tempnam(sys_get_temp_dir(), 'sig') . '.png';
            file_put_contents($signature_file, $decoded_signature);

            /**
             * PAGE BREAK CHECK: Ensure signature fits on current page
             * If less than 30mm space remaining, start a new page
             */
            $remaining_space = $pdf->getPageHeight() - $pdf->GetY() - $pdf->getBreakMargin();
            if ($remaining_space < 30) {
                $pdf->AddPage();
            }

            /**
             * SIGNATURE POSITIONING: Get current position and draw border
             */
            $current_x = $pdf->GetX();
            $current_y = $pdf->GetY();
            
            // Draw border around signature area (52mm width, 22mm height)
            $pdf->Rect($current_x, $current_y, 52, 22);
            
            /**
             * IMAGE EMBEDDING: Place the signature image within the border
             * Position with 1mm margin inside the border for clean appearance
             */
            $pdf->Image($signature_file, $current_x + 1, $current_y + 1, 50, 20, 'PNG');
            
            /**
             * POSITIONING: Move cursor below the signature for additional text
             */
            $pdf->SetXY($current_x, $current_y + 23);
            
            /**
             * PROVIDER NAME: Display provider information below signature
             * Include NPI number if available for verification purposes
             */
            if (!empty($row['Provider']) && $row['Provider'] !== 'N/A') {
                $provider_signature_text = $row['Provider'];
                if (!empty($row['npi'])) {
                    $provider_signature_text .= " (" . $row['npi'] . ")";
                }
                $pdf->SetFont('helvetica', '', 10); // Smaller font for signature line
                $pdf->Write(0, $provider_signature_text, '', 0, 'L', true);
                $pdf->SetFont('helvetica', '', 12); // Reset to normal font size
            }
            
            /**
             * CLEANUP: Remove temporary signature file and add spacing
             */
            unlink($signature_file); // Delete the temporary file from system
            $pdf->Ln(5); // Add space after signature block
        } else {
            /**
             * ERROR HANDLING: Display message if signature decoding fails
             */
            $pdf->Write(0, "Signature could not be processed.", '', 0, 'L', true);
        }
    }

    /**
     * PAGE BREAK MANAGEMENT: Add new page between encounters (except for last)
     * This ensures each encounter starts on a fresh page for better organization
     */
    if ($currentEncounter < $totalEncounters) {
        $pdf->AddPage();
    }
    $currentEncounter++; // Increment counter for next encounter
}

/**
 * PDF OUTPUT: Send the completed PDF to the browser
 * 'I' parameter means display inline in browser (not download)
 * This allows users to view the PDF directly without saving to disk
 */
$pdf->Output('encounter_documentation.pdf', 'I');
?>
