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

if (isset($_GET['registered'])) {
    $success = 'Portal password created successfully. You can now sign in with your NIC number.';
}
if (isset($_GET['logout'])) {
    $success = 'You have been signed out.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nic = normalizeInvestorNic($_POST['nic_no'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($nic === '' || $password === '') {
        $error = 'Please enter your NIC number and password.';
    } else {
        try {
            $investor = findInvestorByNic($db, $nic);

            if (!$investor) {
                $error = 'No investment record found for this NIC number.';
            } elseif (empty($investor['portal_password_hash'])) {
                $error = 'You have not set a portal password yet. Please create one first.';
            } elseif ((int)($investor['portal_failed_attempts'] ?? 0) >= 5) {
                $error = 'Account locked due to too many failed attempts. Please contact support.';
            } elseif (!password_verify($password, $investor['portal_password_hash'])) {
                $attempts = (int)($investor['portal_failed_attempts'] ?? 0) + 1;
                $upd = $db->prepare('
                    UPDATE investors
                    SET portal_failed_attempts = ?
                    WHERE UPPER(REPLACE(nic_no, \' \', \'\')) = ?
                ');
                $upd->execute([$attempts, $nic]);
                $remaining = max(0, 5 - $attempts);
                $error = $remaining > 0
                    ? "Invalid password. {$remaining} attempt(s) remaining."
                    : 'Account locked due to too many failed attempts.';
            } else {
                $upd = $db->prepare('
                    UPDATE investors
                    SET portal_last_login = NOW(), portal_failed_attempts = 0
                    WHERE UPPER(REPLACE(nic_no, \' \', \'\')) = ?
                ');
                $upd->execute([$nic]);

                setInvestorSession($investor);
                header('Location: dashboard.php');
                exit();
            }
        } catch (PDOException $e) {
            error_log('Investor login error: ' . $e->getMessage());
            $error = 'Login failed. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investor Login | Serendib Green</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ink: #2a1f14;
            --muted: #6b5344;
            --cream: #fff8f0;
            --gold: #e8a838;
            --amber: #f59e0b;
            --coral: #e85d4c;
            --rose: #f472b6;
            --sky: #38bdf8;
            --orchid: #fbbf24;
            --bean: #3d2314;
            --error: #dc2626;
            --ok: #059669;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            font-family: 'Outfit', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(ellipse at 10% 20%, rgba(248, 113, 113, 0.35) 0%, transparent 45%),
                radial-gradient(ellipse at 90% 10%, rgba(56, 189, 248, 0.3) 0%, transparent 40%),
                radial-gradient(ellipse at 80% 90%, rgba(244, 114, 182, 0.28) 0%, transparent 45%),
                radial-gradient(ellipse at 15% 85%, rgba(251, 191, 36, 0.4) 0%, transparent 40%),
                linear-gradient(145deg, #fff1e0 0%, #ffe4ec 40%, #e0f2fe 100%);
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(circle at 25% 35%, rgba(232, 168, 56, 0.12) 0 2px, transparent 3px),
                radial-gradient(circle at 70% 60%, rgba(232, 93, 76, 0.1) 0 1.5px, transparent 2.5px);
            background-size: 48px 48px, 36px 36px;
            pointer-events: none;
            z-index: 0;
        }

        /* Page preloader */
        #preloader {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 18px;
            background:
                radial-gradient(circle at 50% 40%, rgba(255, 248, 240, 0.95) 0%, transparent 55%),
                linear-gradient(160deg, #fff7ed 0%, #fce7f3 50%, #e0f2fe 100%);
            transition: opacity 0.55s ease, visibility 0.55s ease;
        }
        #preloader.hide {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .preloader-flower {
            width: min(160px, 42vw);
            height: auto;
            filter: drop-shadow(0 18px 28px rgba(61, 35, 20, 0.28));
            animation: flowerBounce 1.1s ease-in-out infinite, flowerSpin 2.8s linear infinite;
            transform-origin: center center;
            will-change: transform;
        }
        .preloader-text {
            font-family: 'Fraunces', serif;
            font-size: 15px;
            font-weight: 600;
            color: var(--bean);
            letter-spacing: 0.04em;
        }
        .preloader-dots span {
            display: inline-block;
            width: 7px;
            height: 7px;
            margin: 0 3px;
            border-radius: 50%;
            background: var(--coral);
            animation: dotPulse 1.2s ease-in-out infinite;
        }
        .preloader-dots span:nth-child(2) { background: var(--gold); animation-delay: 0.15s; }
        .preloader-dots span:nth-child(3) { background: var(--sky); animation-delay: 0.3s; }
        @keyframes flowerBounce {
            0%, 100% { translate: 0 0; }
            50% { translate: 0 -22px; }
        }
        @keyframes flowerSpin {
            from { rotate: 0deg; }
            to { rotate: 360deg; }
        }
        @keyframes dotPulse {
            0%, 100% { transform: scale(0.75); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 1; }
        }

        .page {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            animation: pageIn 0.7s ease both;
            animation-delay: 0.15s;
        }
        @keyframes pageIn {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand {
            text-align: center;
            margin-bottom: 22px;
        }
        .brand-mark {
            width: 72px;
            height: 72px;
            margin: 0 auto 12px;
            display: grid;
            place-items: center;
        }
        .brand-mark img {
            width: 100%;
            height: auto;
            filter: drop-shadow(0 10px 18px rgba(61, 35, 20, 0.22));
            animation: softFloat 3.2s ease-in-out infinite;
        }
        @keyframes softFloat {
            0%, 100% { transform: translateY(0) rotate(-2deg); }
            50% { transform: translateY(-8px) rotate(2deg); }
        }
        .brand h1 {
            font-family: 'Fraunces', serif;
            font-size: clamp(1.55rem, 4vw, 1.9rem);
            font-weight: 700;
            background: linear-gradient(120deg, var(--coral), var(--gold), #0ea5e9);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            line-height: 1.15;
        }
        .brand p {
            margin-top: 6px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .card {
            background: rgba(255, 255, 255, 0.78);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.85);
            border-radius: 28px;
            padding: 36px 32px;
            box-shadow:
                0 24px 50px rgba(61, 35, 20, 0.1),
                0 2px 0 rgba(255, 255, 255, 0.9) inset;
        }
        h2 {
            text-align: center;
            font-family: 'Fraunces', serif;
            font-size: 1.55rem;
            margin-bottom: 6px;
            color: var(--bean);
        }
        .subtitle {
            text-align: center;
            color: var(--muted);
            margin-bottom: 26px;
            font-size: 14px;
            line-height: 1.45;
        }
        .alert {
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 14px;
        }
        .alert.error {
            background: rgba(220, 38, 38, 0.08);
            border: 1px solid rgba(220, 38, 38, 0.28);
            color: #b91c1c;
        }
        .alert.success {
            background: rgba(5, 150, 105, 0.08);
            border: 1px solid rgba(5, 150, 105, 0.28);
            color: #047857;
        }
        .form-group { margin-bottom: 16px; }
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            color: var(--muted);
            font-weight: 600;
        }
        .input-wrap { position: relative; }
        .input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gold);
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 14px 14px 14px 42px;
            border-radius: 14px;
            border: 1.5px solid rgba(61, 35, 20, 0.12);
            background: rgba(255, 248, 240, 0.9);
            color: var(--ink);
            font-family: inherit;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input::placeholder { color: #b8a396; }
        input:focus {
            border-color: var(--coral);
            box-shadow: 0 0 0 4px rgba(232, 93, 76, 0.15);
        }
        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(120deg, var(--coral) 0%, var(--gold) 55%, #0ea5e9 100%);
            background-size: 160% 100%;
            color: #fff;
            font-family: inherit;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            margin-top: 8px;
            box-shadow: 0 12px 28px rgba(232, 93, 76, 0.28);
            transition: background-position 0.35s ease, transform 0.2s ease, filter 0.2s;
        }
        .btn:hover {
            background-position: 100% 0;
            filter: brightness(1.03);
            transform: translateY(-1px);
        }
        .btn:active { transform: translateY(0); }
        .links {
            margin-top: 22px;
            text-align: center;
            font-size: 14px;
            color: var(--muted);
        }
        .links a {
            color: var(--coral);
            text-decoration: none;
            font-weight: 600;
        }
        .links a:hover { text-decoration: underline; color: #0ea5e9; }
        .divider { margin: 10px 0; }

        @media (max-width: 480px) {
            .card { padding: 28px 20px; border-radius: 22px; }
            .preloader-flower { width: 120px; }
        }
    </style>
</head>
<body>
    <div id="preloader" aria-live="polite" aria-busy="true">
        <img class="preloader-flower" src="../assets/images/vanilla-flower.png" alt="Loading">
        <p class="preloader-text">Loading portal</p>
        <div class="preloader-dots" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
    </div>

    <div class="page">
        <div class="brand">
            <div class="brand-mark">
                <img src="../assets/images/vanilla-flower.png" alt="Serendib Green">
            </div>
            <h1>Serendib Green</h1>
            <p>Investor Portal</p>
        </div>

        <div class="card">
            <h2>Investor Login</h2>
            <p class="subtitle">Sign in with your NIC number to view your investment</p>

            <?php if ($error): ?>
                <div class="alert error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label for="nic_no">NIC Number (Username)</label>
                    <div class="input-wrap">
                        <i class="fas fa-id-card"></i>
                        <input type="text" id="nic_no" name="nic_no" placeholder="e.g. 123456789V"
                               value="<?php echo htmlspecialchars($_POST['nic_no'] ?? ''); ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder="Your portal password" required>
                    </div>
                </div>
                <button type="submit" class="btn"><i class="fas fa-sign-in-alt"></i> Sign In</button>
            </form>

            <div class="links">
                <div>First time? <a href="register.php">Create your password</a></div>
                <div class="divider"></div>
                <div><a href="../index.php">New application</a> · <a href="change_password.php">Change password</a></div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var preloader = document.getElementById('preloader');
            var minMs = 900;
            var start = Date.now();

            function hide() {
                var wait = Math.max(0, minMs - (Date.now() - start));
                setTimeout(function () {
                    preloader.classList.add('hide');
                    preloader.setAttribute('aria-busy', 'false');
                    setTimeout(function () {
                        if (preloader.parentNode) preloader.parentNode.removeChild(preloader);
                    }, 600);
                }, wait);
            }

            if (document.readyState === 'complete') {
                hide();
            } else {
                window.addEventListener('load', hide);
            }
        })();
    </script>
</body>
</html>
