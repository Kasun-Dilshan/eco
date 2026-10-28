<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/investor_auth.php';

if (isInvestorLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nic = normalizeInvestorNic($_POST['nic_no'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($nic === '' || $password === '' || $confirm === '') {
        $error = 'Please fill in all fields.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $investor = findInvestorByNic($db, $nic);

            if (!$investor) {
                $error = 'No investment record found for this NIC number. Please apply first or check your NIC.';
            } elseif (!empty($investor['portal_password_hash'])) {
                $error = 'A portal password is already set for this NIC. Please sign in, or use Change Password.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                setPortalPasswordForNic($db, $nic, $hash);

                header('Location: login.php?registered=1');
                exit();
            }
        } catch (PDOException $e) {
            error_log('Investor register error: ' . $e->getMessage());
            $error = 'Could not create password. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Password | Investor Portal</title>
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: var(--text);
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at 20% 80%, rgba(34, 197, 94, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(0, 255, 136, 0.12) 0%, transparent 50%);
            z-index: -1;
        }
        .card {
            width: 100%;
            max-width: 440px;
            background: rgba(10, 47, 29, 0.88);
            border: 1px solid rgba(34, 197, 94, 0.25);
            border-radius: 24px;
            padding: 42px 36px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
        }
        .logo { text-align: center; margin-bottom: 24px; }
        .logo-icon {
            width: 64px; height: 64px; margin: 0 auto 14px; border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--accent), var(--neon));
        }
        .logo-icon i { font-size: 28px; color: #fff; }
        .logo h1 { font-size: 22px; font-weight: 800; }
        h2 { text-align: center; font-size: 22px; margin-bottom: 6px; }
        .subtitle { text-align: center; color: var(--text-muted); margin-bottom: 24px; font-size: 14px; }
        .alert {
            padding: 12px 14px; border-radius: 10px; margin-bottom: 18px; font-size: 14px;
            background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fecaca;
        }
        .hint {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.25);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 8px; font-size: 13px; color: var(--text-muted); font-weight: 600; }
        .input-wrap { position: relative; }
        .input-wrap i {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted);
        }
        input {
            width: 100%;
            padding: 14px 14px 14px 42px;
            border-radius: 12px;
            border: 1px solid rgba(34, 197, 94, 0.3);
            background: rgba(26, 77, 51, 0.45);
            color: var(--text);
            font-size: 15px;
            outline: none;
        }
        input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2); }
        .btn {
            width: 100%; padding: 14px; border: none; border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: #fff; font-weight: 700; font-size: 15px; cursor: pointer; margin-top: 8px;
        }
        .links { margin-top: 22px; text-align: center; font-size: 14px; color: var(--text-muted); }
        .links a { color: var(--neon); text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-user-plus"></i></div>
            <h1>Create Portal Password</h1>
        </div>
        <h2>Set up access</h2>
        <p class="subtitle">Use the NIC number from your investment application</p>

        <div class="hint">
            <i class="fas fa-info-circle"></i>
            Your NIC must already exist in our system. Use that NIC as your username and create a password.
        </div>

        <?php if ($error): ?>
            <div class="alert"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label for="nic_no">NIC Number</label>
                <div class="input-wrap">
                    <i class="fas fa-id-card"></i>
                    <input type="text" id="nic_no" name="nic_no" required
                           value="<?php echo htmlspecialchars($_POST['nic_no'] ?? ''); ?>"
                           placeholder="Your NIC number">
                </div>
            </div>
            <div class="form-group">
                <label for="password">Create password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" required minlength="6"
                           placeholder="At least 6 characters">
                </div>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6"
                           placeholder="Re-enter password">
                </div>
            </div>
            <button type="submit" class="btn"><i class="fas fa-key"></i> Create Password</button>
        </form>

        <div class="links">
            Already have a password? <a href="login.php">Sign in</a>
        </div>
    </div>
</body>
</html>
