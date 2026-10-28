<?php
require_once '../config.php';
require_once '../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || (($_SESSION['user_type'] ?? null) !== 'admin')) {
    header('Location: dashboard.php');
    exit();
}

require_once 'includes/SalesPerson.php';

$salesPerson = new SalesPerson();
$error = '';
$success = '';
$form_data = [];
$salesPersonOptions = $salesPerson->getAll([]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_sales_person'])) {
    $form_data = $_POST;

    $name = trim($form_data['name'] ?? '');
    $branch = trim($form_data['branch'] ?? '');
    $epf_no = trim($form_data['epf_no'] ?? '');
    $sales_code = trim($form_data['sales_code'] ?? '');
    $phone_number = trim($form_data['phone_number'] ?? '');
    $email = trim($form_data['email'] ?? '');
    $sales_rank = trim($form_data['sales_rank'] ?? '');
    $reported_sales_person_id = trim($form_data['reported_sales_person_id'] ?? '');

    if ($name === '' || $branch === '' || $epf_no === '' || $sales_code === '') {
        $error = 'Name, branch, EPF no, and sales code are required.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $result = $salesPerson->create([
            'name' => $name,
            'branch' => $branch,
            'epf_no' => $epf_no,
            'sales_code' => $sales_code,
            'phone_number' => $phone_number,
            'email' => $email,
            'sales_rank' => $sales_rank,
            'reported_sales_person_id' => $reported_sales_person_id,
            'status' => $form_data['status'] ?? 'active',
            'created_by' => $_SESSION['user_id'],
        ]);

        if ($result['success']) {
            header('Location: sales_persons.php');
            exit();
        }
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Sales Person | Serendib Green Plantation Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #0a2f1d; --accent: #22c55e; --neon: #00ff88; --error: #ef4444; --text: #f0fdf4; --text-muted: #a7f3d0; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%); color: var(--text); min-height: 100vh; padding: 30px; }
        .form-container { max-width: 700px; margin: 0 auto; }
        .form-card { background: rgba(26, 77, 51, 0.6); border: 1px solid rgba(34, 197, 94, 0.2); border-radius: 16px; padding: 30px; }
        h1 { color: var(--neon); margin-bottom: 25px; font-size: 26px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 14px; }
        .form-group label.required::after { content: ' *'; color: var(--error); }
        .form-group input, .form-group select { width: 100%; padding: 12px 15px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.3); background: rgba(10, 47, 29, 0.8); color: var(--text); font-size: 15px; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--accent); }
        .btn { padding: 12px 24px; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-size: 14px; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-secondary { background: rgba(26, 77, 51, 0.7); color: var(--text); border: 1px solid rgba(34, 197, 94, 0.2); }
        .form-actions { display: flex; gap: 12px; margin-top: 30px; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; background: rgba(239, 68, 68, 0.2); border: 1px solid var(--error); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="form-container">
    <div class="form-card">
        <h1><i class="fas fa-user-plus"></i> Add Sales Person</h1>

        <?php if ($error): ?>
            <div class="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-grid">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="name" class="required">Sales Person Name</label>
                    <input type="text" id="name" name="name" required
                           value="<?php echo htmlspecialchars($form_data['name'] ?? ''); ?>"
                           placeholder="Full name">
                </div>

                <div class="form-group">
                    <label for="branch" class="required">Branch</label>
                    <input type="text" id="branch" name="branch" required
                           value="<?php echo htmlspecialchars($form_data['branch'] ?? ''); ?>"
                           list="branch-list" placeholder="Select or type branch">
                    <datalist id="branch-list">
                        <option value="Colombo Main Branch">
                        <option value="Kandy Branch">
                        <option value="Galle Branch">
                        <option value="Jaffna Branch">
                        <option value="Kurunegala Branch">
                        <option value="Anuradhapura Branch">
                        <option value="Matara Branch">
                        <option value="Ratnapura Branch">
                    </datalist>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="active" <?php echo ($form_data['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($form_data['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="epf_no" class="required">EPF No</label>
                    <input type="text" id="epf_no" name="epf_no" required
                           value="<?php echo htmlspecialchars($form_data['epf_no'] ?? ''); ?>"
                           placeholder="e.g. 123456789">
                </div>

                <div class="form-group">
                    <label for="sales_code" class="required">Sales Code</label>
                    <input type="text" id="sales_code" name="sales_code" required
                           value="<?php echo htmlspecialchars($form_data['sales_code'] ?? ''); ?>"
                           placeholder="e.g. SP001">
                </div>

                <div class="form-group">
                    <label for="phone_number">Phone Number</label>
                    <input type="tel" id="phone_number" name="phone_number"
                           value="<?php echo htmlspecialchars($form_data['phone_number'] ?? ''); ?>"
                           placeholder="e.g. 0771234567">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                           value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                           placeholder="e.g. name@example.com">
                </div>

                <div class="form-group">
                    <label for="sales_rank">Sales Person Rank</label>
                    <input type="text" id="sales_rank" name="sales_rank"
                           value="<?php echo htmlspecialchars($form_data['sales_rank'] ?? ''); ?>"
                           list="rank-list" placeholder="e.g. Senior Sales Executive">
                    <datalist id="rank-list">
                        <option value="Trainee">
                        <option value="Junior Sales Executive">
                        <option value="Sales Executive">
                        <option value="Senior Sales Executive">
                        <option value="Team Leader">
                        <option value="Sales Manager">
                    </datalist>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="reported_sales_person_id">Reported Sales Person</label>
                    <select id="reported_sales_person_id" name="reported_sales_person_id">
                        <option value="">— None —</option>
                        <?php foreach ($salesPersonOptions as $sp): ?>
                            <option value="<?php echo (int) $sp['id']; ?>"
                                <?php echo ((string)($form_data['reported_sales_person_id'] ?? '') === (string)$sp['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sp['name'] . ' (' . $sp['sales_code'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="create_sales_person" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Sales Person
                </button>
                <a href="sales_persons.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
