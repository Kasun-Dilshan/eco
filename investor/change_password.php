<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/investor_auth.php';

$error = '';
$success = '';
$loggedIn = isInvestorLoggedIn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nic = $loggedIn
        ? normalizeInvestorNic((string)($_SESSION['investor_nic'] ?? ''))
        : normalizeInvestorNic($_POST['nic_no'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($nic === '' || $password === '' || $confirm === '' || $currentPassword === '') {
        $error = 'Please fill in all required fields.';
    } elseif (strlen($password) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        try {
            $investor = findInvestorByNic($db, $nic);

            if (!$investor || empty($investor['portal_password_hash'])) {
                $error = 'No portal account found for this NIC. Please create a password first.';
            } elseif (!password_verify($currentPassword, $investor['portal_password_hash'])) {
                $error = 'Current password is incorrect.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                setPortalPasswordForNic($db, $nic, $hash);
                $success = 'Password updated successfully.';
            }
        } catch (PDOException $e) {
            error_log('Investor change password error: ' . $e->getMessage());
            $error = 'Could not update password. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | Investor Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --accent: #22c55e; --neon: #00ff88; --text: #f0fdf4; --text-muted: #a7f3d0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body {
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%); color: var(--text);
        }
        .card {
            width: 100%; max-width: 440px; background: rgba(10, 47, 29, 0.88);
            border: 1px solid rgba(34, 197, 94, 0.25); border-radius: 24px; padding: 42px 36px;
        }
        h2 { text-align: center; margin-bottom: 8px; }
        .subtitle { text-align: center; color: var(--text-muted); margin-bottom: 24px; font-size: 14px; }
        .alert { padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.error { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.4); color: #fecaca; }
        .alert.success { background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.4); color: #a7f3d0; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 8px; font-size: 13px; color: var(--text-muted); font-weight: 600; }
        input {
            width: 100%; padding: 14px; border-radius: 12px;
            border: 1px solid rgba(34, 197, 94, 0.3); background: rgba(26, 77, 51, 0.45);
            color: var(--text); font-size: 15px; outline: none;
        }
        .btn {
            width: 100%; padding: 14px; border: none; border-radius: 12px; margin-top: 8px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: #fff; font-weight: 700; cursor: pointer;
        }
        .links { margin-top: 20px; text-align: center; font-size: 14px; }
        .links a { color: var(--neon); text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Change Password</h2>
        <p class="subtitle">Update your investor portal password</p>

        <?php if ($error): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?php if (!$loggedIn): ?>
                <div class="form-group">
                    <label for="nic_no">NIC Number</label>
                    <input type="text" id="nic_no" name="nic_no" required
                           value="<?php echo htmlspecialchars($_POST['nic_no'] ?? ''); ?>">
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <div class="form-group">
                <label for="password">New password</label>
                <input type="password" id="password" name="password" required minlength="6">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
            </div>
            <button type="submit" class="btn">Update Password</button>
        </form>

        <div class="links">
            <?php if ($loggedIn): ?>
                <a href="dashboard.php">Back to dashboard</a>
            <?php else: ?>
                <a href="login.php">Back to login</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
