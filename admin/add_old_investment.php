<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/application_helpers.php';
require_once '../includes/investor_auth.php';
require_once 'includes/SalesPerson.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || (($_SESSION['user_type'] ?? null) !== 'admin')) {
    header('Location: dashboard.php');
    exit();
}

$salesPersonHelper = new SalesPerson();
$allSalesPersons = [];
try {
    // Direct query so the dropdown always gets rows even if helper filters fail
    $spStmt = $db->query("
        SELECT id, name, branch, epf_no, sales_code, status, reported_sales_person_id
        FROM sales_persons
        ORDER BY
            CASE WHEN status = 'active' THEN 0 ELSE 1 END,
            name ASC
    ");
    $allSalesPersons = $spStmt ? ($spStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (PDOException $e) {
    $allSalesPersons = $salesPersonHelper->getAll([]);
}

$error = '';
$success = '';
$form_data = [
    'beneficiary_name' => [''],
    'beneficiary_nic' => [''],
    'beneficiary_percentage' => [''],
];

$validInvestmentTypes = ['HPP', 'GSP', 'GSI', 'GOLD', 'SFPS', 'SFHPS'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_old_investment'])) {
    $form_data = $_POST;

    // Normalize beneficiaries to arrays
    $form_data['beneficiary_name'] = isset($_POST['beneficiary_name']) && is_array($_POST['beneficiary_name'])
        ? $_POST['beneficiary_name'] : [''];
    $form_data['beneficiary_nic'] = isset($_POST['beneficiary_nic']) && is_array($_POST['beneficiary_nic'])
        ? $_POST['beneficiary_nic'] : [''];
    $form_data['beneficiary_percentage'] = isset($_POST['beneficiary_percentage']) && is_array($_POST['beneficiary_percentage'])
        ? $_POST['beneficiary_percentage'] : [''];

    try {
        $uppercaseFields = [
            'full_name', 'name_with_initials', 'nic_no', 'address',
            'occupation', 'employer_name', 'bank_name', 'branch_name',
        ];
        foreach ($uppercaseFields as $field) {
            $form_data[$field] = strtoupper(trim($form_data[$field] ?? ''));
        }

        $form_data['email'] = trim($form_data['email'] ?? '');
        $form_data['tel_no'] = trim($form_data['tel_no'] ?? '');
        $form_data['account_no'] = trim($form_data['account_no'] ?? '');
        $form_data['sales_person_code'] = strtoupper(trim($form_data['sales_person_code'] ?? ''));
        $form_data['reported_sales_person_id'] = trim((string)($form_data['reported_sales_person_id'] ?? ''));
        $form_data['investment_type'] = strtoupper(trim($form_data['investment_type'] ?? ''));
        $form_data['agreement_no'] = trim($form_data['agreement_no'] ?? '');
        $form_data['admin_notes'] = trim($form_data['admin_notes'] ?? '');

        foreach ($form_data['beneficiary_name'] as $i => $name) {
            $form_data['beneficiary_name'][$i] = strtoupper(trim($name));
        }
        foreach ($form_data['beneficiary_nic'] as $i => $nic) {
            $form_data['beneficiary_nic'][$i] = strtoupper(trim($nic));
        }

        $requiredFields = [
            'full_name' => 'Full name',
            'name_with_initials' => 'Name with initials',
            'nic_no' => 'NIC number',
            'tel_no' => 'Telephone number',
            'dob' => 'Date of birth',
            'address' => 'Address',
            'occupation' => 'Occupation',
            'years' => 'Investment years',
            'investment_type' => 'Investment plan',
            'investment_amount' => 'Investment amount',
            'signing_date' => 'Signing / investment date',
            'agreement_no' => 'Agreement number',
            'account_no' => 'Account number',
            'bank_name' => 'Bank name',
            'branch_name' => 'Bank branch',
            'sales_person_code' => 'Sales person code',
        ];

        foreach ($requiredFields as $field => $label) {
            if (!isset($form_data[$field]) || trim((string)$form_data[$field]) === '') {
                throw new Exception("Please fill in all required fields. Missing: {$label}.");
            }
        }

        if ($form_data['email'] !== '' && !filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }

        if (!in_array($form_data['investment_type'], $validInvestmentTypes, true)) {
            throw new Exception('Please select a valid investment plan.');
        }

        $years = (int)$form_data['years'];
        if ($years < 1) {
            throw new Exception('Investment years must be at least 1.');
        }

        $investmentAmount = (float)$form_data['investment_amount'];
        if ($investmentAmount <= 0) {
            throw new Exception('Investment amount must be greater than 0.');
        }

        // Validate sales person (active or inactive — allowed for historical records)
        $salesStmt = $db->prepare("
            SELECT id, name, branch, sales_code, status, reported_sales_person_id
            FROM sales_persons
            WHERE sales_code = ?
            LIMIT 1
        ");
        $salesStmt->execute([$form_data['sales_person_code']]);
        $salesPerson = $salesStmt->fetch(PDO::FETCH_ASSOC);
        if (!$salesPerson) {
            throw new Exception('Invalid sales person code. Please select a valid sales person.');
        }

        $reportedSalesPersonId = null;
        if ($form_data['reported_sales_person_id'] !== '') {
            $reportedSalesPersonId = (int)$form_data['reported_sales_person_id'];
            if ($reportedSalesPersonId <= 0) {
                throw new Exception('Invalid reported sales person.');
            }
            if ($reportedSalesPersonId === (int)$salesPerson['id']) {
                throw new Exception('A sales person cannot report to themselves.');
            }
            $reportedStmt = $db->prepare("SELECT id FROM sales_persons WHERE id = ? LIMIT 1");
            $reportedStmt->execute([$reportedSalesPersonId]);
            if (!$reportedStmt->fetch()) {
                throw new Exception('Invalid reported sales person. Please select a valid sales person.');
            }
        }

        // Beneficiaries
        $beneficiaryNames = [];
        $beneficiaryNics = [];
        $beneficiaryPercentages = [];
        $totalPercentage = 0;

        $count = count($form_data['beneficiary_name']);
        for ($i = 0; $i < $count; $i++) {
            $bName = $form_data['beneficiary_name'][$i] ?? '';
            $bNic = $form_data['beneficiary_nic'][$i] ?? '';
            $bPct = isset($form_data['beneficiary_percentage'][$i]) ? (int)$form_data['beneficiary_percentage'][$i] : 0;

            if ($bName === '' && $bNic === '' && $bPct === 0) {
                continue;
            }
            if ($bName === '' || $bNic === '' || $bPct < 1) {
                throw new Exception('Each beneficiary must have a name, NIC, and percentage.');
            }

            $beneficiaryNames[] = $bName;
            $beneficiaryNics[] = $bNic;
            $beneficiaryPercentages[] = $bPct;
            $totalPercentage += $bPct;
        }

        if (empty($beneficiaryNames)) {
            throw new Exception('At least one beneficiary is required.');
        }
        if ($totalPercentage !== 100) {
            throw new Exception("Total beneficiary percentage must equal 100%. Current total: {$totalPercentage}%");
        }

        $declarationDate = trim($form_data['declaration_date'] ?? '');
        if ($declarationDate === '') {
            $declarationDate = $form_data['signing_date'];
        }

        if ($form_data['agreement_no'] !== '') {
            $agrCheck = $db->prepare('SELECT id FROM investors WHERE agreement_no = ? LIMIT 1');
            $agrCheck->execute([$form_data['agreement_no']]);
            if ($agrCheck->fetch()) {
                throw new Exception('Agreement number already exists on another investment.');
            }
        }

        $adminNotes = $form_data['admin_notes'];
        $legacyNote = 'Legacy investment added by admin (' . ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'admin') . ') on ' . date('Y-m-d H:i');
        if ($adminNotes === '') {
            $adminNotes = $legacyNote;
        } else {
            $adminNotes = $legacyNote . "\n" . $adminNotes;
        }

        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO investors (
                full_name, name_with_initials, nic_no, tel_no, dob, email,
                address, occupation, employer_name, years, investment_type,
                investment_amount, currency, signing_date,
                account_no, bank_name, branch_name, declaration_date,
                sales_person_code, agreement_no,
                status, approver_level, admin_notes,
                reviewed_by, reviewed_at
            ) VALUES (
                :full_name, :name_with_initials, :nic_no, :tel_no, :dob, :email,
                :address, :occupation, :employer_name, :years, :investment_type,
                :investment_amount, :currency, :signing_date,
                :account_no, :bank_name, :branch_name, :declaration_date,
                :sales_person_code, :agreement_no,
                :status, :approver_level, :admin_notes,
                :reviewed_by, NOW()
            )
        ");

        $stmt->execute([
            ':full_name' => $form_data['full_name'],
            ':name_with_initials' => $form_data['name_with_initials'],
            ':nic_no' => $form_data['nic_no'],
            ':tel_no' => $form_data['tel_no'],
            ':dob' => $form_data['dob'],
            ':email' => $form_data['email'],
            ':address' => $form_data['address'],
            ':occupation' => $form_data['occupation'],
            ':employer_name' => $form_data['employer_name'],
            ':years' => $years,
            ':investment_type' => $form_data['investment_type'],
            ':investment_amount' => $investmentAmount,
            ':currency' => 'LKR',
            ':signing_date' => $form_data['signing_date'],
            ':account_no' => $form_data['account_no'],
            ':bank_name' => $form_data['bank_name'],
            ':branch_name' => $form_data['branch_name'],
            ':declaration_date' => $declarationDate,
            ':sales_person_code' => $form_data['sales_person_code'],
            ':agreement_no' => $form_data['agreement_no'] !== '' ? $form_data['agreement_no'] : null,
            ':status' => 'approved',
            ':approver_level' => 3,
            ':admin_notes' => $adminNotes,
            ':reviewed_by' => (int)$_SESSION['user_id'],
        ]);

        $investorId = (int)$db->lastInsertId();

        // Persist reported sales person on the selected sales person master record
        if ($reportedSalesPersonId !== null) {
            $updateReported = $db->prepare("
                UPDATE sales_persons
                SET reported_sales_person_id = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateReported->execute([$reportedSalesPersonId, (int)$salesPerson['id']]);
        }

        syncPortalPasswordToInvestor($db, $investorId, (string)$form_data['nic_no']);

        $beneficiaryStmt = $db->prepare("
            INSERT INTO beneficiaries (investor_id, beneficiary_name, beneficiary_nic, percentage)
            VALUES (:investor_id, :beneficiary_name, :beneficiary_nic, :percentage)
        ");

        for ($i = 0; $i < count($beneficiaryNames); $i++) {
            $beneficiaryStmt->execute([
                ':investor_id' => $investorId,
                ':beneficiary_name' => $beneficiaryNames[$i],
                ':beneficiary_nic' => $beneficiaryNics[$i],
                ':percentage' => $beneficiaryPercentages[$i],
            ]);
        }

        $logStmt = $db->prepare("
            INSERT INTO application_logs (investor_id, action, description, performed_by)
            VALUES (:investor_id, :action, :description, :performed_by)
        ");
        $logStmt->execute([
            ':investor_id' => $investorId,
            ':action' => 'legacy_added',
            ':description' => 'Old investment added by admin (no attachments). Sales person: '
                . $salesPerson['name'] . ' (' . $salesPerson['sales_code'] . ')',
            ':performed_by' => (int)$_SESSION['user_id'],
        ]);

        $db->commit();

        $_SESSION['legacy_investment_success'] = 'Old investment added successfully. Ref: '
            . buildApplicationRef($investorId, $form_data['investment_type']);
        header('Location: view_application.php?id=' . $investorId);
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

$beneficiaryCount = max(1, count($form_data['beneficiary_name'] ?? []));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Old Investment | Serendib Green Plantation Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --accent: #22c55e;
            --neon: #00ff88;
            --error: #ef4444;
            --text: #f0fdf4;
            --text-muted: #a7f3d0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: var(--text);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .form-container { max-width: 960px; margin: 0 auto; }
        .form-card {
            background: rgba(26, 77, 51, 0.6);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 20px;
        }
        h1 { color: var(--neon); margin-bottom: 8px; font-size: 26px; }
        .subtitle { color: var(--text-muted); margin-bottom: 25px; font-size: 14px; }
        .section-title {
            color: var(--neon);
            font-size: 18px;
            margin: 10px 0 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.25);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 14px; }
        .form-group label.required::after { content: ' *'; color: var(--error); }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid rgba(34, 197, 94, 0.3);
            background: rgba(10, 47, 29, 0.8);
            color: var(--text);
            font-size: 15px;
        }
        /* Native dropdown options need solid light colors or Windows hides the list */
        .form-group select {
            color-scheme: dark;
        }
        .form-group select option {
            background-color: #ffffff;
            color: #111111;
        }
        .form-group select option:disabled {
            color: #6b7280;
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
        }
        .empty-sales {
            margin-top: 8px;
            font-size: 13px;
            color: #fca5a5;
        }
        .empty-sales a { color: var(--neon); }
        .sales-picker { position: relative; }
        .sales-picker-toggle {
            width: 100%;
            text-align: left;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid rgba(34, 197, 94, 0.3);
            background: rgba(10, 47, 29, 0.8);
            color: var(--text);
            font-size: 15px;
            cursor: pointer;
        }
        .sales-picker-toggle:focus {
            outline: none;
            border-color: var(--accent);
        }
        .sales-picker-panel {
            display: none;
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            z-index: 40;
            background: #0f3a26;
            border: 1px solid rgba(34, 197, 94, 0.35);
            border-radius: 10px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
            overflow: hidden;
        }
        .sales-picker.open .sales-picker-panel { display: block; }
        .sales-picker-search {
            width: 100%;
            padding: 12px 14px;
            border: none;
            border-bottom: 1px solid rgba(34, 197, 94, 0.25);
            background: rgba(10, 47, 29, 0.95);
            color: var(--text);
            font-size: 14px;
        }
        .sales-picker-search:focus { outline: none; }
        .sales-picker-list {
            max-height: 260px;
            overflow-y: auto;
        }
        .sales-picker-item {
            width: 100%;
            text-align: left;
            padding: 12px 14px;
            border: none;
            border-bottom: 1px solid rgba(34, 197, 94, 0.12);
            background: transparent;
            color: var(--text);
            cursor: pointer;
            font-size: 14px;
        }
        .sales-picker-item:hover,
        .sales-picker-item.active {
            background: rgba(34, 197, 94, 0.18);
        }
        .sales-picker-item small {
            display: block;
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 12px;
        }
        .sales-picker-empty {
            padding: 16px;
            color: var(--text-muted);
            font-size: 13px;
        }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-grid .full { grid-column: 1 / -1; }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary { background: var(--accent); color: white; }
        .btn-secondary {
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        .btn-sm { padding: 8px 14px; font-size: 13px; }
        .btn-danger { background: var(--error); color: white; }
        .form-actions { display: flex; gap: 12px; margin-top: 10px; flex-wrap: wrap; }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid var(--error);
        }
        .info-banner {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 22px;
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: var(--text-muted);
            font-size: 14px;
        }
        .beneficiary-item {
            background: rgba(10, 47, 29, 0.45);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 14px;
            position: relative;
        }
        .beneficiary-item .remove-btn {
            position: absolute;
            top: 12px;
            right: 12px;
        }
        .sales-preview {
            margin-top: 8px;
            padding: 12px 14px;
            border-radius: 8px;
            background: rgba(10, 47, 29, 0.55);
            border: 1px dashed rgba(34, 197, 94, 0.35);
            color: var(--text-muted);
            font-size: 13px;
            display: none;
        }
        .sales-preview.visible { display: block; }
        @media (max-width: 700px) {
            .form-grid { grid-template-columns: 1fr; }
            body { padding: 16px 12px; }
            .form-card { padding: 20px; }
        }
    </style>
</head>
<body>
<div class="form-container">
    <div class="form-card">
        <h1><i class="fas fa-history"></i> Add Old Investment</h1>
        <p class="subtitle">Admin-only entry for historical investments. No attachments required. Saved as approved.</p>

        <div class="info-banner">
            <i class="fas fa-info-circle"></i>
            Use this form to record past investments already completed outside the online application flow.
        </div>

        <?php if ($error): ?>
            <div class="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" id="oldInvestmentForm" novalidate>
            <!-- Investor details -->
            <h2 class="section-title"><i class="fas fa-user"></i> Investor Details</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="full_name" class="required">Full Name</label>
                    <input type="text" id="full_name" name="full_name" required
                           value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>"
                           style="text-transform: uppercase;">
                </div>
                <div class="form-group">
                    <label for="name_with_initials" class="required">Name with Initials</label>
                    <input type="text" id="name_with_initials" name="name_with_initials" required
                           value="<?php echo htmlspecialchars($form_data['name_with_initials'] ?? ''); ?>"
                           style="text-transform: uppercase;">
                </div>
                <div class="form-group">
                    <label for="nic_no" class="required">NIC Number</label>
                    <input type="text" id="nic_no" name="nic_no" required
                           value="<?php echo htmlspecialchars($form_data['nic_no'] ?? ''); ?>"
                           style="text-transform: uppercase;">
                </div>
                <div class="form-group">
                    <label for="tel_no" class="required">Telephone</label>
                    <input type="text" id="tel_no" name="tel_no" required
                           value="<?php echo htmlspecialchars($form_data['tel_no'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="dob" class="required">Date of Birth</label>
                    <input type="date" id="dob" name="dob" required
                           value="<?php echo htmlspecialchars($form_data['dob'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                           value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>">
                </div>
                <div class="form-group full">
                    <label for="address" class="required">Address</label>
                    <textarea id="address" name="address" required
                              style="text-transform: uppercase;"><?php echo htmlspecialchars($form_data['address'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="occupation" class="required">Occupation</label>
                    <input type="text" id="occupation" name="occupation" required
                           value="<?php echo htmlspecialchars($form_data['occupation'] ?? ''); ?>"
                           style="text-transform: uppercase;">
                </div>
                <div class="form-group">
                    <label for="employer_name">Employer Name</label>
                    <input type="text" id="employer_name" name="employer_name"
                           value="<?php echo htmlspecialchars($form_data['employer_name'] ?? ''); ?>"
                           style="text-transform: uppercase;">
                </div>
            </div>

            <!-- Beneficiary details -->
            <h2 class="section-title" style="margin-top: 28px;"><i class="fas fa-users"></i> Beneficiary Details</h2>
            <div id="beneficiaries">
                <?php for ($i = 0; $i < $beneficiaryCount; $i++): ?>
                <div class="beneficiary-item">
                    <?php if ($i > 0): ?>
                        <button type="button" class="btn btn-danger btn-sm remove-btn" onclick="removeBeneficiary(this)">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Beneficiary Name</label>
                            <input type="text" name="beneficiary_name[]" required
                                   value="<?php echo htmlspecialchars($form_data['beneficiary_name'][$i] ?? ''); ?>"
                                   style="text-transform: uppercase;">
                        </div>
                        <div class="form-group">
                            <label class="required">Beneficiary NIC</label>
                            <input type="text" name="beneficiary_nic[]" required
                                   value="<?php echo htmlspecialchars($form_data['beneficiary_nic'][$i] ?? ''); ?>"
                                   style="text-transform: uppercase;">
                        </div>
                        <div class="form-group">
                            <label class="required">Percentage (%)</label>
                            <input type="number" name="beneficiary_percentage[]" min="1" max="100" required
                                   value="<?php echo htmlspecialchars($form_data['beneficiary_percentage'][$i] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addBeneficiary()" style="margin-bottom: 20px;">
                <i class="fas fa-plus"></i> Add Beneficiary
            </button>

            <!-- Investment details -->
            <h2 class="section-title"><i class="fas fa-seedling"></i> Investment Details</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="agreement_no" class="required">Agreement Number</label>
                    <input type="text" id="agreement_no" name="agreement_no" required
                           value="<?php echo htmlspecialchars($form_data['agreement_no'] ?? ''); ?>"
                           placeholder="e.g. 2024/H0123">
                </div>
                <div class="form-group">
                    <label for="investment_type" class="required">Investment Plan</label>
                    <select id="investment_type" name="investment_type" required>
                        <option value="">Select plan</option>
                        <option value="HPP" <?php echo ($form_data['investment_type'] ?? '') === 'HPP' ? 'selected' : ''; ?>>High profit plan (HPP)</option>
                        <option value="GSP" <?php echo ($form_data['investment_type'] ?? '') === 'GSP' ? 'selected' : ''; ?>>Green saving plan (GSP)</option>
                        <option value="GSI" <?php echo ($form_data['investment_type'] ?? '') === 'GSI' ? 'selected' : ''; ?>>Green silver plan (GSI)</option>
                        <option value="GOLD" <?php echo ($form_data['investment_type'] ?? '') === 'GOLD' ? 'selected' : ''; ?>>Gold plan (GOLD)</option>
                        <option value="SFPS" <?php echo ($form_data['investment_type'] ?? '') === 'SFPS' ? 'selected' : ''; ?>>Seraa farm profit share plan (SFPS)</option>
                        <option value="SFHPS" <?php echo ($form_data['investment_type'] ?? '') === 'SFHPS' ? 'selected' : ''; ?>>Seraa farm high profit share plan (SFHPS)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="years" class="required">Years</label>
                    <input type="number" id="years" name="years" min="1" required
                           value="<?php echo htmlspecialchars($form_data['years'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="investment_amount" class="required">Investment Amount (Rs.)</label>
                    <input type="number" id="investment_amount" name="investment_amount" step="0.01" min="0" required
                           value="<?php echo htmlspecialchars($form_data['investment_amount'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="signing_date" class="required">Signing / Investment Date</label>
                    <input type="date" id="signing_date" name="signing_date" required
                           value="<?php echo htmlspecialchars($form_data['signing_date'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="declaration_date">Declaration Date</label>
                    <input type="date" id="declaration_date" name="declaration_date"
                           value="<?php echo htmlspecialchars($form_data['declaration_date'] ?? ''); ?>">
                </div>
                <div class="form-group full">
                    <label for="admin_notes">Admin Notes</label>
                    <textarea id="admin_notes" name="admin_notes"
                              placeholder="Optional notes about this legacy investment"><?php echo htmlspecialchars($form_data['admin_notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- Bank details -->
            <h2 class="section-title" style="margin-top: 28px;"><i class="fas fa-university"></i> Bank Details</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="account_no" class="required">Account Number</label>
                    <input type="text" id="account_no" name="account_no" required
                           value="<?php echo htmlspecialchars($form_data['account_no'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="bank_name" class="required">Bank Name</label>
                    <input type="text" id="bank_name" name="bank_name" required
                           value="<?php echo htmlspecialchars($form_data['bank_name'] ?? ''); ?>"
                           style="text-transform: uppercase;">
                </div>
                <div class="form-group full">
                    <label for="branch_name" class="required">Branch Name</label>
                    <input type="text" id="branch_name" name="branch_name" required
                           value="<?php echo htmlspecialchars($form_data['branch_name'] ?? ''); ?>"
                           style="text-transform: uppercase;">
                </div>
            </div>

            <!-- Sales person details -->
            <h2 class="section-title" style="margin-top: 28px;"><i class="fas fa-user-tie"></i> Sales Person Details</h2>
            <div class="form-grid">
                <div class="form-group full">
                    <label class="required">Sales Person</label>
                    <?php
                    $selectedSalesCode = trim((string)($form_data['sales_person_code'] ?? ''));
                    $selectedReportedId = trim((string)($form_data['reported_sales_person_id'] ?? ''));
                    $selectedSalesLabel = 'Select sales person';
                    $selectedSalesMeta = null;
                    foreach ($allSalesPersons as $sp) {
                        if (trim((string)($sp['sales_code'] ?? '')) === $selectedSalesCode && $selectedSalesCode !== '') {
                            $selectedSalesLabel = trim(($sp['name'] ?? '') . ' - ' . $selectedSalesCode);
                            $selectedSalesMeta = $sp;
                            if ($selectedReportedId === '' && !empty($sp['reported_sales_person_id'])) {
                                $selectedReportedId = (string)$sp['reported_sales_person_id'];
                            }
                            break;
                        }
                    }
                    ?>
                    <input type="hidden" id="sales_person_code" name="sales_person_code"
                           value="<?php echo htmlspecialchars($selectedSalesCode); ?>" required>
                    <div class="sales-picker" id="salesPicker">
                        <button type="button" class="sales-picker-toggle" id="salesPickerToggle">
                            <span id="salesPickerLabel"><?php echo htmlspecialchars($selectedSalesLabel); ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="sales-picker-panel">
                            <input type="text" class="sales-picker-search" id="salesPickerSearch"
                                   placeholder="Search by name, code, or branch..." autocomplete="off">
                            <div class="sales-picker-list" id="salesPickerList">
                                <?php if (empty($allSalesPersons)): ?>
                                    <div class="sales-picker-empty">No sales persons found.</div>
                                <?php else: ?>
                                    <?php foreach ($allSalesPersons as $sp): ?>
                                        <?php
                                        $code = trim((string)($sp['sales_code'] ?? ''));
                                        if ($code === '') {
                                            continue;
                                        }
                                        $name = trim((string)($sp['name'] ?? ''));
                                        $branch = trim((string)($sp['branch'] ?? ''));
                                        $epf = trim((string)($sp['epf_no'] ?? ''));
                                        $status = trim((string)($sp['status'] ?? ''));
                                        $spId = (int)($sp['id'] ?? 0);
                                        $reportedId = (string)($sp['reported_sales_person_id'] ?? '');
                                        $searchText = strtolower($name . ' ' . $code . ' ' . $branch . ' ' . $epf);
                                        ?>
                                        <button type="button"
                                                class="sales-picker-item<?php echo $selectedSalesCode === $code ? ' active' : ''; ?>"
                                                data-id="<?php echo $spId; ?>"
                                                data-code="<?php echo htmlspecialchars($code); ?>"
                                                data-name="<?php echo htmlspecialchars($name); ?>"
                                                data-branch="<?php echo htmlspecialchars($branch); ?>"
                                                data-epf="<?php echo htmlspecialchars($epf); ?>"
                                                data-status="<?php echo htmlspecialchars($status); ?>"
                                                data-reported-id="<?php echo htmlspecialchars($reportedId); ?>"
                                                data-search="<?php echo htmlspecialchars($searchText); ?>">
                                            <strong><?php echo htmlspecialchars($name); ?></strong>
                                            <small>
                                                Code: <?php echo htmlspecialchars($code); ?>
                                                · Branch: <?php echo htmlspecialchars($branch); ?>
                                                · EPF: <?php echo htmlspecialchars($epf); ?>
                                                <?php if ($status === 'inactive'): ?> · Inactive<?php endif; ?>
                                            </small>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if (empty($allSalesPersons)): ?>
                        <p class="empty-sales">
                            No sales persons in the system.
                            <a href="add_sales_person.php">Add a sales person</a> first, then return here.
                        </p>
                    <?php endif; ?>
                    <div id="salesPreview" class="sales-preview<?php echo $selectedSalesMeta ? ' visible' : ''; ?>">
                        <?php if ($selectedSalesMeta): ?>
                            <strong style="color:#f0fdf4;"><?php echo htmlspecialchars($selectedSalesMeta['name'] ?? ''); ?></strong><br>
                            Code: <?php echo htmlspecialchars($selectedSalesCode); ?>
                            · Branch: <?php echo htmlspecialchars($selectedSalesMeta['branch'] ?? ''); ?>
                            · EPF: <?php echo htmlspecialchars($selectedSalesMeta['epf_no'] ?? ''); ?>
                            · Status: <?php echo htmlspecialchars($selectedSalesMeta['status'] ?? ''); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group full">
                    <label for="reported_sales_person_id">Reported Sales Person</label>
                    <select id="reported_sales_person_id" name="reported_sales_person_id">
                        <option value="">— None —</option>
                        <?php foreach ($allSalesPersons as $sp): ?>
                            <?php
                            $optId = (int)($sp['id'] ?? 0);
                            if ($optId <= 0) {
                                continue;
                            }
                            $optCode = trim((string)($sp['sales_code'] ?? ''));
                            $optName = trim((string)($sp['name'] ?? ''));
                            $optStatus = trim((string)($sp['status'] ?? ''));
                            $optLabel = $optName . ' (' . $optCode . ')';
                            if ($optStatus === 'inactive') {
                                $optLabel .= ' — Inactive';
                            }
                            ?>
                            <option value="<?php echo $optId; ?>"
                                <?php echo ($selectedReportedId === (string)$optId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($optLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="empty-sales" style="margin-top:8px;">
                        Optional. If set, this updates who the selected sales person reports to.
                    </p>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="create_old_investment" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Old Investment
                </button>
                <a href="applications.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
function addBeneficiary() {
    const container = document.getElementById('beneficiaries');
    const item = document.createElement('div');
    item.className = 'beneficiary-item';
    item.innerHTML = `
        <button type="button" class="btn btn-danger btn-sm remove-btn" onclick="removeBeneficiary(this)">
            <i class="fas fa-times"></i> Remove
        </button>
        <div class="form-grid">
            <div class="form-group">
                <label class="required">Beneficiary Name</label>
                <input type="text" name="beneficiary_name[]" required style="text-transform: uppercase;">
            </div>
            <div class="form-group">
                <label class="required">Beneficiary NIC</label>
                <input type="text" name="beneficiary_nic[]" required style="text-transform: uppercase;">
            </div>
            <div class="form-group">
                <label class="required">Percentage (%)</label>
                <input type="number" name="beneficiary_percentage[]" min="1" max="100" required>
            </div>
        </div>
    `;
    container.appendChild(item);
}

function removeBeneficiary(btn) {
    const items = document.querySelectorAll('.beneficiary-item');
    if (items.length <= 1) return;
    btn.closest('.beneficiary-item').remove();
}

function setSalesPerson(item) {
    const code = item.dataset.code || '';
    const name = item.dataset.name || '';
    const branch = item.dataset.branch || '';
    const epf = item.dataset.epf || '';
    const status = item.dataset.status || '';
    const salesId = item.dataset.id || '';
    const reportedId = item.dataset.reportedId || '';

    document.getElementById('sales_person_code').value = code;
    document.getElementById('salesPickerLabel').textContent = name + ' - ' + code;

    document.querySelectorAll('.sales-picker-item').forEach(function (el) {
        el.classList.toggle('active', el === item);
    });

    const preview = document.getElementById('salesPreview');
    preview.innerHTML =
        '<strong style="color:#f0fdf4;">' + name + '</strong><br>' +
        'Code: ' + code +
        ' · Branch: ' + branch +
        ' · EPF: ' + epf +
        ' · Status: ' + status;
    preview.classList.add('visible');

    const reportedSelect = document.getElementById('reported_sales_person_id');
    if (reportedSelect) {
        Array.from(reportedSelect.options).forEach(function (opt) {
            if (!opt.value) {
                opt.disabled = false;
                return;
            }
            opt.disabled = opt.value === salesId;
        });
        reportedSelect.value = (reportedId && reportedId !== salesId) ? reportedId : '';
    }

    document.getElementById('salesPicker').classList.remove('open');
}

document.addEventListener('DOMContentLoaded', function () {
    const picker = document.getElementById('salesPicker');
    const toggle = document.getElementById('salesPickerToggle');
    const search = document.getElementById('salesPickerSearch');
    const list = document.getElementById('salesPickerList');

    if (!picker || !toggle) return;

    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        picker.classList.toggle('open');
        if (picker.classList.contains('open') && search) {
            search.focus();
        }
    });

    if (search) {
        search.addEventListener('input', function () {
            const q = search.value.trim().toLowerCase();
            list.querySelectorAll('.sales-picker-item').forEach(function (item) {
                const hay = item.dataset.search || '';
                item.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    list.querySelectorAll('.sales-picker-item').forEach(function (item) {
        item.addEventListener('click', function () {
            setSalesPerson(item);
        });
    });

    // Keep reported dropdown from allowing self-report on reload
    const activeSales = list.querySelector('.sales-picker-item.active');
    const reportedSelect = document.getElementById('reported_sales_person_id');
    if (activeSales && reportedSelect) {
        const salesId = activeSales.dataset.id || '';
        Array.from(reportedSelect.options).forEach(function (opt) {
            if (!opt.value) return;
            opt.disabled = opt.value === salesId;
        });
    }

    document.addEventListener('click', function (e) {
        if (!picker.contains(e.target)) {
            picker.classList.remove('open');
        }
    });

    const form = document.getElementById('oldInvestmentForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const code = document.getElementById('sales_person_code').value.trim();
            if (!code) {
                e.preventDefault();
                alert('Please select a sales person.');
                picker.classList.add('open');
                if (search) search.focus();
            }
        });
    }
});
</script>
</body>
</html>
