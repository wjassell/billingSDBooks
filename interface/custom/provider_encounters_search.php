<?php
// Include OpenEMR globals
require_once("../globals.php");

// Initialize variables
$results = [];
$cpt_codes = [];
$selected_cpt_codes = [];
$providers = [];
$selected_provider_id = '';
$claim_number_search = '';
$insurance_companies = [];
$selected_insurance_ids = [];

// Sorting variables
$sort_primary = 'date';
$sort_secondary = 'cpt_code';
$sort_tertiary = 'claim_number';
$sort_direction = 'ASC';

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
    if (isset($_POST['provider_id']) && !empty($_POST['provider_id'])) {
        $selected_provider_id = $_POST['provider_id'];
    }
    if (isset($_POST['cpt_codes']) && is_array($_POST['cpt_codes'])) {
        $selected_cpt_codes = $_POST['cpt_codes'];
    }
    if (isset($_POST['claim_number']) && !empty($_POST['claim_number'])) {
        $claim_number_search = trim($_POST['claim_number']);
    }
    if (isset($_POST['insurance_ids']) && is_array($_POST['insurance_ids'])) {
        $selected_insurance_ids = $_POST['insurance_ids'];
    }
    // Handle sorting parameters
    if (isset($_POST['sort_primary']) && !empty($_POST['sort_primary'])) {
        $sort_primary = $_POST['sort_primary'];
    }
    if (isset($_POST['sort_secondary']) && !empty($_POST['sort_secondary'])) {
        $sort_secondary = $_POST['sort_secondary'];
    }
    if (isset($_POST['sort_tertiary']) && !empty($_POST['sort_tertiary'])) {
        $sort_tertiary = $_POST['sort_tertiary'];
    }
    if (isset($_POST['sort_direction']) && !empty($_POST['sort_direction'])) {
        $sort_direction = $_POST['sort_direction'];
    }
}

// Fetch dynamic CPT code options
$cpt_query = "SELECT DISTINCT billing_code_CR FROM SDBooks1.s4me_spot_billingcode ORDER BY billing_code_CR";
$cpt_result = sqlStatement($cpt_query);
while ($row = sqlFetchArray($cpt_result)) {
    $cpt_codes[] = $row['billing_code_CR'];
}

// Fetch provider options
$provider_query = "SELECT id, full_name FROM SDBooks1.s4me_provider ORDER BY full_name";
$provider_result = sqlStatement($provider_query);
while ($row = sqlFetchArray($provider_result)) {
    $providers[] = [
        'id' => $row['id'],
        'name' => $row['full_name']
    ];
}

// Fetch insurance company options
$insurance_query = "SELECT id, name FROM SDBooks1.insurance_companies ORDER BY name";
$insurance_result = sqlStatement($insurance_query);
while ($row = sqlFetchArray($insurance_result)) {
    $insurance_companies[] = [
        'id' => $row['id'],
        'name' => $row['name']
    ];
}

// Build the query - only search if a provider is selected
if (!empty($selected_provider_id) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search']))) {
    $sql = "
        SELECT eo_form_encounter.id, eo_form_encounter.date, eo_form_encounter.time_in, eo_form_encounter.time_out, 
               s4me_provider.full_name AS Provider, s4me_spot_billingcode.billing_code_CR AS CPT_Code, 
               eo_form_encounter.consolidated_enc_id, eo_form_encounter.pid, s4me_patient.full_name AS Patient
        FROM SDBooks1.eo_form_encounter
        INNER JOIN SDBooks1.s4me_provider ON eo_form_encounter.provider_id = s4me_provider.id
        INNER JOIN SDBooks1.s4me_spot_billingcode ON eo_form_encounter.pc_catid = s4me_spot_billingcode.spot_id
        INNER JOIN SDBooks1.s4me_patient ON eo_form_encounter.pid = s4me_patient.id
        LEFT JOIN SDBooks1.eo_billing_info ON eo_billing_info.id = eo_form_encounter.id
        WHERE eo_form_encounter.date BETWEEN ? AND ?
    ";

    $params = [$start_date, $end_date];

    // Add provider filter if selected
    if (!empty($selected_provider_id)) {
        $sql .= " AND eo_form_encounter.provider_id = ?";
        $params[] = $selected_provider_id;
    }

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

    // Add insurance filter if selected
    if (!empty($selected_insurance_ids)) {
        $insurance_placeholders = implode(',', array_fill(0, count($selected_insurance_ids), '?'));
        $sql .= " AND eo_billing_info.ins_id IN ($insurance_placeholders)";
        $params = array_merge($params, $selected_insurance_ids);
    }

    $sql .= " ORDER BY eo_form_encounter.date DESC, eo_form_encounter.time_in ASC";

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
            'Patient' => $row['Patient'],
            'CPT_Code' => $row['CPT_Code'],
            'claim_number' => $claim_number
        ];
    }

    // Apply custom sorting based on hierarchy
    if (!empty($results)) {
        usort($results, function($a, $b) use ($sort_primary, $sort_secondary, $sort_tertiary, $sort_direction) {
            $fields = [$sort_primary, $sort_secondary, $sort_tertiary];
            
            foreach ($fields as $field) {
                $valueA = '';
                $valueB = '';
                
                switch ($field) {
                    case 'date':
                        $valueA = $a['date'];
                        $valueB = $b['date'];
                        break;
                    case 'start_time':
                        $valueA = $a['start_time'];
                        $valueB = $b['start_time'];
                        break;
                    case 'end_time':
                        $valueA = $a['end_time'];
                        $valueB = $b['end_time'];
                        break;
                    case 'minutes':
                        $valueA = (int)$a['minutes'];
                        $valueB = (int)$b['minutes'];
                        break;
                    case 'provider':
                        $valueA = $a['Provider'];
                        $valueB = $b['Provider'];
                        break;
                    case 'patient':
                        $valueA = $a['Patient'];
                        $valueB = $b['Patient'];
                        break;
                    case 'cpt_code':
                        $valueA = $a['CPT_Code'];
                        $valueB = $b['CPT_Code'];
                        break;
                    case 'claim_number':
                        $valueA = $a['claim_number'];
                        $valueB = $b['claim_number'];
                        break;
                }
                
                if ($field === 'minutes') {
                    $comparison = $valueA <=> $valueB;
                } else {
                    $comparison = strcasecmp($valueA, $valueB);
                }
                
                if ($comparison !== 0) {
                    return ($sort_direction === 'DESC') ? -$comparison : $comparison;
                }
            }
            
            return 0;
        });
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Provider Encounters Search</title>
    <style>
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            align-items: start;
            margin-bottom: 20px;
            padding: 20px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 5px;
        }
        .form-row label {
            font-weight: bold;
            min-width: 100px;
        }
        .form-row input, .form-row select {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            min-width: 150px;
            width: 100%;
        }
        .form-row select[multiple] {
            min-width: 200px;
            max-width: 100%;
            height: 100px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .checkbox-actions {
            margin: 10px 0;
        }
        .checkbox-actions button {
            margin-right: 10px;
            padding: 5px 15px;
            background-color: #007cba;
            color: white;
            border: none;
            cursor: pointer;
            border-radius: 3px;
        }
        .checkbox-actions button:hover {
            background-color: #005a87;
        }
        .search-info {
            background-color: #e8f4f8;
            padding: 10px;
            margin: 15px 0;
            border-left: 4px solid #007cba;
            border-radius: 5px;
        }
        button[type="submit"] {
            background-color: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            border-radius: 5px;
        }
        button[type="submit"]:hover {
            background-color: #218838;
        }
        button[name="search"] {
            background-color: #007cba;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            border-radius: 5px;
        }
        button[name="search"]:hover {
            background-color: #005a87;
        }
        .provider-required {
            background-color: #fff3cd;
            padding: 10px;
            margin: 15px 0;
            border-left: 4px solid #ffc107;
            border-radius: 5px;
        }
        .sort-section {
            background-color: #f8f9fa;
            padding: 15px;
            margin: 15px 0;
            border-left: 4px solid #6c757d;
            border-radius: 5px;
        }
        .sort-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            margin-bottom: 10px;
        }
        .sort-row label {
            font-weight: bold;
            min-width: 120px;
        }
        .sort-row select {
            padding: 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
            min-width: 140px;
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
    <h1>Provider Encounters Search</h1>
    
    <div class="search-info">
        <strong>Current Search Parameters:</strong><br>
        Start Date: <?= htmlspecialchars($start_date); ?><br>
        End Date: <?= htmlspecialchars($end_date); ?><br>
        <?php if (!empty($selected_provider_id)): ?>
            <?php 
            $selected_provider_name = '';
            foreach ($providers as $provider) {
                if ($provider['id'] == $selected_provider_id) {
                    $selected_provider_name = $provider['name'];
                    break;
                }
            }
            ?>
            Provider: <?= htmlspecialchars($selected_provider_name); ?><br>
        <?php else: ?>
            Provider: Not selected<br>
        <?php endif; ?>
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
        <?php if (!empty($selected_insurance_ids)): ?>
            <?php 
            $selected_insurance_names = [];
            foreach ($insurance_companies as $insurance) {
                if (in_array($insurance['id'], $selected_insurance_ids)) {
                    $selected_insurance_names[] = $insurance['name'];
                }
            }
            ?>
            Insurance: <?= htmlspecialchars(implode(', ', $selected_insurance_names)); ?><br>
        <?php else: ?>
            Insurance: All companies<br>
        <?php endif; ?>
        <strong>Sort Order:</strong> 
        <?= ucfirst(str_replace('_', ' ', $sort_primary)); ?> → 
        <?= ucfirst(str_replace('_', ' ', $sort_secondary)); ?> → 
        <?= ucfirst(str_replace('_', ' ', $sort_tertiary)); ?> 
        (<?= $sort_direction; ?>)<br>
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])): ?>
            <em>Search was performed using the form below.</em>
        <?php else: ?>
            <em>Use the form below to search for encounters by provider.</em>
        <?php endif; ?>
    </div>

    <form method="POST">
        <div class="form-row">
            <div class="form-group">
                <label for="start_date">Start Date:</label>
                <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date); ?>">
            </div>

            <div class="form-group">
                <label for="end_date">End Date:</label>
                <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date); ?>">
            </div>

            <div class="form-group">
                <label for="provider_id">Provider:</label>
                <select name="provider_id" id="provider_id" required>
                    <option value="">-- Select Provider --</option>
                    <?php foreach ($providers as $provider): ?>
                        <option value="<?= htmlspecialchars($provider['id']); ?>" <?= ($provider['id'] == $selected_provider_id) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($provider['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="cpt_codes">CPT Codes:</label>
                <select name="cpt_codes[]" id="cpt_codes" multiple>
                    <option value="">-- Select CPT Codes (Leave empty for all) --</option>
                    <?php foreach ($cpt_codes as $code): ?>
                        <option value="<?= htmlspecialchars($code); ?>" <?= in_array($code, $selected_cpt_codes) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($code); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="claim_number">Claim Number:</label>
                <input type="text" name="claim_number" id="claim_number" placeholder="e.g., 1011-130461 or 1011" value="<?= htmlspecialchars($claim_number_search); ?>">
            </div>

            <div class="form-group">
                <label for="insurance_ids">Insurance:</label>
                <select name="insurance_ids[]" id="insurance_ids" multiple>
                    <option value="">-- Select Insurance Companies (Leave empty for all) --</option>
                    <?php foreach ($insurance_companies as $insurance): ?>
                        <option value="<?= htmlspecialchars($insurance['id']); ?>" <?= in_array($insurance['id'], $selected_insurance_ids) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($insurance['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <button type="submit" name="search">Search</button>
            </div>
        </div>
        
        <div class="sort-section">
            <h3>Sort Order (Primary → Secondary → Tertiary)</h3>
            <div class="sort-row">
                <label for="sort_primary">1st Sort By:</label>
                <select name="sort_primary" id="sort_primary">
                    <option value="date" <?= ($sort_primary === 'date') ? 'selected' : ''; ?>>Date</option>
                    <option value="start_time" <?= ($sort_primary === 'start_time') ? 'selected' : ''; ?>>Start Time</option>
                    <option value="end_time" <?= ($sort_primary === 'end_time') ? 'selected' : ''; ?>>End Time</option>
                    <option value="minutes" <?= ($sort_primary === 'minutes') ? 'selected' : ''; ?>>Minutes</option>
                    <option value="provider" <?= ($sort_primary === 'provider') ? 'selected' : ''; ?>>Provider</option>
                    <option value="patient" <?= ($sort_primary === 'patient') ? 'selected' : ''; ?>>Patient</option>
                    <option value="cpt_code" <?= ($sort_primary === 'cpt_code') ? 'selected' : ''; ?>>CPT Code</option>
                    <option value="claim_number" <?= ($sort_primary === 'claim_number') ? 'selected' : ''; ?>>Claim Number</option>
                </select>

                <label for="sort_secondary">2nd Sort By:</label>
                <select name="sort_secondary" id="sort_secondary">
                    <option value="date" <?= ($sort_secondary === 'date') ? 'selected' : ''; ?>>Date</option>
                    <option value="start_time" <?= ($sort_secondary === 'start_time') ? 'selected' : ''; ?>>Start Time</option>
                    <option value="end_time" <?= ($sort_secondary === 'end_time') ? 'selected' : ''; ?>>End Time</option>
                    <option value="minutes" <?= ($sort_secondary === 'minutes') ? 'selected' : ''; ?>>Minutes</option>
                    <option value="provider" <?= ($sort_secondary === 'provider') ? 'selected' : ''; ?>>Provider</option>
                    <option value="patient" <?= ($sort_secondary === 'patient') ? 'selected' : ''; ?>>Patient</option>
                    <option value="cpt_code" <?= ($sort_secondary === 'cpt_code') ? 'selected' : ''; ?>>CPT Code</option>
                    <option value="claim_number" <?= ($sort_secondary === 'claim_number') ? 'selected' : ''; ?>>Claim Number</option>
                </select>

                <label for="sort_tertiary">3rd Sort By:</label>
                <select name="sort_tertiary" id="sort_tertiary">
                    <option value="date" <?= ($sort_tertiary === 'date') ? 'selected' : ''; ?>>Date</option>
                    <option value="start_time" <?= ($sort_tertiary === 'start_time') ? 'selected' : ''; ?>>Start Time</option>
                    <option value="end_time" <?= ($sort_tertiary === 'end_time') ? 'selected' : ''; ?>>End Time</option>
                    <option value="minutes" <?= ($sort_tertiary === 'minutes') ? 'selected' : ''; ?>>Minutes</option>
                    <option value="provider" <?= ($sort_tertiary === 'provider') ? 'selected' : ''; ?>>Provider</option>
                    <option value="patient" <?= ($sort_tertiary === 'patient') ? 'selected' : ''; ?>>Patient</option>
                    <option value="cpt_code" <?= ($sort_tertiary === 'cpt_code') ? 'selected' : ''; ?>>CPT Code</option>
                    <option value="claim_number" <?= ($sort_tertiary === 'claim_number') ? 'selected' : ''; ?>>Claim Number</option>
                </select>

                <label for="sort_direction">Direction:</label>
                <select name="sort_direction" id="sort_direction">
                    <option value="ASC" <?= ($sort_direction === 'ASC') ? 'selected' : ''; ?>>Ascending (A-Z, 1-9, Old-New)</option>
                    <option value="DESC" <?= ($sort_direction === 'DESC') ? 'selected' : ''; ?>>Descending (Z-A, 9-1, New-Old)</option>
                </select>
            </div>
        </div>
    </form>

    <?php if (!empty($results)): ?>
        <h2>Results (<?= count($results); ?> encounters found)</h2>
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
                    <th>Patient</th>
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
                        <td><?= htmlspecialchars($row['Patient']); ?></td>
                        <td><?= htmlspecialchars($row['CPT_Code']); ?></td>
                        <td><?= htmlspecialchars($row['claim_number']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <button type="submit">Generate Documentation</button>
        </form>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])): ?>
        <div class="provider-required">
            <strong>No results found.</strong> Try adjusting your search criteria.
        </div>
    <?php else: ?>
        <div class="provider-required">
            <strong>Please select a provider</strong> and click Search to view encounters.
        </div>
    <?php endif; ?>
</body>
</html>
