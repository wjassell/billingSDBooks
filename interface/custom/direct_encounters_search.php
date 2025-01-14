<?php
// Initialize variables
$results = [];
$patient = $start_date = $end_date = '';
$cpt_codes = [];

// Database connection
$servername = "localhost";
$username = "root";
$password = "your_password";
$dbname = "openemr_db";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch dynamic CPT code options
    $cpt_query = "SELECT DISTINCT billing_code_CR FROM SDBooks1.s4me_spot_billingcode ORDER BY billing_code_CR";
    $cpt_stmt = $conn->query($cpt_query);
    $cpt_options = $cpt_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if the form was submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Retrieve form inputs
        $patient = $_POST['patient'] ?? '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $cpt_codes = $_POST['cpt_codes'] ?? [];

        // Build the query
        $sql = "SELECT eo_form_encounter.id, eo_form_encounter.date, s4me_provider.full_name AS Provider, 
                       s4me_patient.full_name AS Patient, s4me_spot_billingcode.billing_code_CR AS CPT_Code
                FROM SDBooks1.eo_form_encounter
                INNER JOIN SDBooks1.s4me_provider ON eo_form_encounter.provider_id = s4me_provider.id
                INNER JOIN SDBooks1.s4me_patient ON eo_form_encounter.pid = s4me_patient.id
                INNER JOIN SDBooks1.s4me_spot_billingcode ON eo_form_encounter.pc_catid = s4me_spot_billingcode.spot_id
                WHERE eo_form_encounter.date BETWEEN :start_date AND :end_date";

        if (!empty($cpt_codes)) {
            $sql .= " AND s4me_spot_billingcode.billing_code_CR IN ('" . implode("','", $cpt_codes) . "')";
        }
        if (!empty($patient)) {
            $sql .= " AND (s4me_patient.full_name LIKE :patient OR eo_form_encounter.pid = :patient)";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);
        if (!empty($patient)) {
            $stmt->bindValue(':patient', "%$patient%");
        }
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Direct Encounters Search</title>
</head>
<body>
    <h1>Direct Encounters Search</h1>
    <form method="POST">
        <label for="patient">Patient:</label>
        <input type="text" name="patient" id="patient" value="<?= htmlspecialchars($patient); ?>" placeholder="Enter patient name or ID"><br><br>
        
        <label for="start_date">Start Date:</label>
        <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date); ?>"><br><br>
        
        <label for="end_date">End Date:</label>
        <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date); ?>"><br><br>
        
        <label for="cpt_codes">CPT Codes:</label>
        <select name="cpt_codes[]" id="cpt_codes" multiple>
            <?php foreach ($cpt_options as $option): ?>
                <option value="<?= htmlspecialchars($option['billing_code_CR']); ?>" 
                    <?= in_array($option['billing_code_CR'], $cpt_codes) ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($option['billing_code_CR']); ?>
                </option>
            <?php endforeach; ?>
        </select><br><br>
        
        <button type="submit">Search</button>
    </form>

    <h2>Results</h2>
    <form action="generate.php" method="POST">
        <table border="1">
            <tr>
                <th>Select</th>
                <th>Date</th>
                <th>Provider</th>
                <th>Patient</th>
                <th>CPT Code</th>
            </tr>
            <?php if (!empty($results)): ?>
                <?php foreach ($results as $row): ?>
                <tr>
                    <td><input type="checkbox" name="selected_ids[]" value="<?= $row['id']; ?>"></td>
                    <td><?= htmlspecialchars($row['date']); ?></td>
                    <td><?= htmlspecialchars($row['Provider']); ?></td>
                    <td><?= htmlspecialchars($row['Patient']); ?></td>
                    <td><?= htmlspecialchars($row['CPT_Code']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No results found</td>
                </tr>
            <?php endif; ?>
        </table>
        <?php if (!empty($results)): ?>
        <br>
        <button type="submit">Generate Documentation</button>
        <?php endif; ?>
    </form>
</body>
</html>
