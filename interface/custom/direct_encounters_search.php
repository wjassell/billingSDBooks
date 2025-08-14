<?php
// Include OpenEMR globals
require_once("../globals.php");

// Check if a patient is selected
if (!isset($_SESSION['pid']) || empty($_SESSION['pid'])) {
    // Redirect to the Patient Finder if no patient is selected
    header("Location: /interface/main/finder/patient_finder.php");
    exit;
}

// Get the selected patient ID
$patientId = $_SESSION['pid'];

// Initialize variables
$results = [];
$cpt_codes = [];
$selected_cpt_codes = [];
$claim_number_search = '';

// Set default dates to the last 2 weeks
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-14 days'));

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    if (isset($_POST['start_date']) && !empty($_POST['start_date'])) {
        $start_date = $_POST['start_date'];
    }
    if (isset($_POST['end_date']) && !empty($_POST['end_date'])) {
        $end_date = $_POST['end_date'];
    }
    if (isset($_POST['cpt_codes']) && is_array($_POST['cpt_codes'])) {
        $selected_cpt_codes = $_POST['cpt_codes'];
    }
    if (isset($_POST['claim_number']) && !empty($_POST['claim_number'])) {
        $claim_number_search = trim($_POST['claim_number']);
    }
}

// Fetch dynamic CPT code options
$cpt_query = "SELECT DISTINCT billing_code_CR FROM SDBooks1.s4me_spot_billingcode ORDER BY billing_code_CR";
$cpt_result = sqlStatement($cpt_query);
while ($row = sqlFetchArray($cpt_result)) {
    $cpt_codes[] = $row['billing_code_CR'];
}

// Build the query
$sql = "
    SELECT eo_form_encounter.id, eo_form_encounter.date, eo_form_encounter.time_in, eo_form_encounter.time_out, 
           s4me_provider.full_name AS Provider, s4me_spot_billingcode.billing_code_CR AS CPT_Code, 
           eo_form_encounter.consolidated_enc_id, eo_form_encounter.pid
    FROM SDBooks1.eo_form_encounter
    INNER JOIN SDBooks1.s4me_provider ON eo_form_encounter.provider_id = s4me_provider.id
    INNER JOIN SDBooks1.s4me_spot_billingcode ON eo_form_encounter.pc_catid = s4me_spot_billingcode.spot_id
    WHERE eo_form_encounter.pid = ?
    AND eo_form_encounter.date BETWEEN ? AND ?
";

$params = [$patientId, $start_date, $end_date];

// Add CPT code filter if specific codes are selected
if (!empty($selected_cpt_codes)) {
    $cpt_placeholders = implode(',', array_fill(0, count($selected_cpt_codes), '?'));
    $sql .= " AND s4me_spot_billingcode.billing_code_CR IN ($cpt_placeholders)";
    $params = array_merge($params, $selected_cpt_codes);
}

// Add claim number filter if provided
if (!empty($claim_number_search)) {
    // Check if the search contains a hyphen (full claim number format)
    if (strpos($claim_number_search, '-') !== false) {
        // Split the claim number into patient_id and consolidated_enc_id
        $claim_parts = explode('-', $claim_number_search, 2);
        if (count($claim_parts) == 2) {
            $search_pid = trim($claim_parts[0]);
            $search_enc_id = trim($claim_parts[1]);
            $sql .= " AND eo_form_encounter.pid = ? AND eo_form_encounter.consolidated_enc_id = ?";
            $params[] = $search_pid;
            $params[] = $search_enc_id;
        }
    } else {
        // Search for partial match in either pid or consolidated_enc_id
        $sql .= " AND (eo_form_encounter.pid LIKE ? OR eo_form_encounter.consolidated_enc_id LIKE ?)";
        $params[] = '%' . $claim_number_search . '%';
        $params[] = '%' . $claim_number_search . '%';
    }
}

$sql .= " ORDER BY eo_form_encounter.time_in ASC";

$stmt = sqlStatement($sql, $params);

// Fetch results
while ($row = sqlFetchArray($stmt)) {
    // Convert time_in and time_out from UTC to America/Chicago and format as HH:MM AM/PM
    $timezone = new DateTimeZone('America/Chicago');
    
    $start_time_obj = (new DateTime($row['time_in'], new DateTimeZone('UTC')))
        ->setTimezone($timezone);
    $end_time_obj = (new DateTime($row['time_out'], new DateTimeZone('UTC')))
        ->setTimezone($timezone);
    
    $start_time = $start_time_obj->format('h:i A');
    $end_time = $end_time_obj->format('h:i A');
    
    // Calculate duration in minutes
    $duration = $end_time_obj->diff($start_time_obj);
    $total_minutes = ($duration->h * 60) + $duration->i;
    
    // Format claim number as patient_id-consolidated_enc_id
    $claim_number = $row['pid'] . '-' . $row['consolidated_enc_id'];

    // Add to results
    $results[] = [
        'id' => $row['id'],
        'date' => $row['date'],
        'start_time' => $start_time,
        'end_time' => $end_time,
        'minutes' => $total_minutes,
        'Provider' => $row['Provider'],
        'CPT_Code' => $row['CPT_Code'],
        'claim_number' => $claim_number
    ];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Direct Encounters Search</title>
    <style>
        .form-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .form-row label {
            font-weight: bold;
        }
        .form-row select {
            min-width: 200px;
            max-width: 300px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table, th, td {
            border: 1px solid black;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        .checkbox-actions {
            margin: 10px 0;
        }
        .search-info {
            background-color: #f0f0f0;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
    </style>
    <script>
        // Select all checkboxes
        function selectAllCheckboxes() {
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => checkbox.checked = true);
        }

        // Deselect all checkboxes
        function deselectAllCheckboxes() {
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => checkbox.checked = false);
        }
    </script>
</head>
<body>
    <h1>Direct Encounters Search</h1>
    <p><strong>Patient ID:</strong> <?= htmlspecialchars($patientId); ?></p>
    
    <div class="search-info">
        <strong>Current Search Parameters:</strong><br>
        Start Date: <?= htmlspecialchars($start_date); ?><br>
        End Date: <?= htmlspecialchars($end_date); ?><br>
        <?php if (!empty($selected_cpt_codes)): ?>
            CPT Codes: <?= htmlspecialchars(implode(', ', $selected_cpt_codes)); ?><br>
        <?php else: ?>
            CPT Codes: All codes<br>
        <?php endif; ?>
        <?php if (!empty($claim_number_search)): ?>
            Claim Number: <?= htmlspecialchars($claim_number_search); ?><br>
        <?php else: ?>
            Claim Number: All claims<br>
        <?php endif; ?>
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])): ?>
            <em>Search was performed using the form above.</em>
        <?php else: ?>
            <em>Showing default results for the last 2 weeks.</em>
        <?php endif; ?>
    </div>

    <form method="POST">
        <div class="form-row">
            <label for="start_date">Start Date:</label>
            <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date); ?>">

            <label for="end_date">End Date:</label>
            <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date); ?>">

            <label for="cpt_codes">CPT Codes:</label>
            <select name="cpt_codes[]" id="cpt_codes" multiple>
                <option value="">-- Select CPT Codes (Leave empty for all) --</option>
                <?php foreach ($cpt_codes as $code): ?>
                    <option value="<?= htmlspecialchars($code); ?>" <?= in_array($code, $selected_cpt_codes) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($code); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="claim_number">Claim Number:</label>
            <input type="text" name="claim_number" id="claim_number" placeholder="e.g., 1011-130461 or 1011" value="<?= htmlspecialchars($claim_number_search); ?>">

            <button type="submit" name="search">Search</button>
        </div>
    </form>

    <?php if (!empty($results)): ?>
        <h2>Results</h2>
        <form method="POST" action="generate.php" target="_blank">
            <div class="checkbox-actions">
                <button type="button" onclick="selectAllCheckboxes()">Select All</button>
                <button type="button" onclick="deselectAllCheckboxes()">Deselect All</button>
            </div>

            <table>
                <tr>
                    <th>Select</th>
                    <th>Date</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Minutes</th>
                    <th>Provider</th>
                    <th>CPT Code</th>
                    <th>Claim Number</th>
                </tr>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_ids[]" value="<?= $row['id']; ?>"></td>
                        <td><?= htmlspecialchars($row['date']); ?></td>
                        <td><?= htmlspecialchars($row['start_time']); ?></td>
                        <td><?= htmlspecialchars($row['end_time']); ?></td>
                        <td><?= htmlspecialchars($row['minutes']); ?></td>
                        <td><?= htmlspecialchars($row['Provider']); ?></td>
                        <td><?= htmlspecialchars($row['CPT_Code']); ?></td>
                        <td><?= htmlspecialchars($row['claim_number']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <button type="submit">Generate Documentation</button>
        </form>
    <?php endif; ?>
</body>
</html>
