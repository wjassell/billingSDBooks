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

// Set default dates to the last 2 weeks
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-14 days'));

// Fetch dynamic CPT code options
$cpt_query = "SELECT DISTINCT billing_code_CR FROM SDBooks1.s4me_spot_billingcode ORDER BY billing_code_CR";
$cpt_result = sqlStatement($cpt_query);
while ($row = sqlFetchArray($cpt_result)) {
    $cpt_codes[] = $row['billing_code_CR'];
}

// Build the query
$sql = "
    SELECT eo_form_encounter.id, eo_form_encounter.date, eo_form_encounter.time_in, eo_form_encounter.time_out, 
           s4me_provider.full_name AS Provider, s4me_spot_billingcode.billing_code_CR AS CPT_Code
    FROM SDBooks1.eo_form_encounter
    INNER JOIN SDBooks1.s4me_provider ON eo_form_encounter.provider_id = s4me_provider.id
    INNER JOIN SDBooks1.s4me_spot_billingcode ON eo_form_encounter.pc_catid = s4me_spot_billingcode.spot_id
    WHERE eo_form_encounter.pid = ?
    AND eo_form_encounter.date BETWEEN ? AND ?
    ORDER BY eo_form_encounter.time_in ASC
";

$params = [$patientId, $start_date, $end_date];
$stmt = sqlStatement($sql, $params);

// Fetch results
while ($row = sqlFetchArray($stmt)) {
    // Convert time_in and time_out from UTC to America/Chicago and format as HH:MM AM/PM
    $timezone = new DateTimeZone('America/Chicago');
    
    $start_time = (new DateTime($row['time_in'], new DateTimeZone('UTC')))
        ->setTimezone($timezone)
        ->format('h:i A');

    $end_time = (new DateTime($row['time_out'], new DateTimeZone('UTC')))
        ->setTimezone($timezone)
        ->format('h:i A');

    // Add to results
    $results[] = [
        'id' => $row['id'],
        'date' => $row['date'],
        'start_time' => $start_time,
        'end_time' => $end_time,
        'Provider' => $row['Provider'],
        'CPT_Code' => $row['CPT_Code']
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

    <form method="POST">
        <div class="form-row">
            <label for="start_date">Start Date:</label>
            <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date); ?>">

            <label for="end_date">End Date:</label>
            <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date); ?>">

            <label for="cpt_codes">CPT Codes:</label>
            <select name="cpt_codes[]" id="cpt_codes" multiple>
                <?php foreach ($cpt_codes as $code): ?>
                    <option value="<?= htmlspecialchars($code); ?>" <?= in_array($code, $selected_cpt_codes ?? []) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($code); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" name="search">Search</button>
        </div>
    </form>

    <?php if (!empty($results)): ?>
        <h2>Results</h2>
        <form method="POST" action="generate.php">
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
                    <th>Provider</th>
                    <th>CPT Code</th>
                </tr>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_ids[]" value="<?= $row['id']; ?>"></td>
                        <td><?= htmlspecialchars($row['date']); ?></td>
                        <td><?= htmlspecialchars($row['start_time']); ?></td>
                        <td><?= htmlspecialchars($row['end_time']); ?></td>
                        <td><?= htmlspecialchars($row['Provider']); ?></td>
                        <td><?= htmlspecialchars($row['CPT_Code']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <button type="submit">Generate Documentation</button>
        </form>
    <?php endif; ?>
</body>
</html>
