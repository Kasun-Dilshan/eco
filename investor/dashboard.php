<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/agreement_helpers.php';
require_once __DIR__ . '/../includes/application_helpers.php';
require_once __DIR__ . '/../includes/investor_auth.php';

requireInvestorAuth();

$sessionInvestorId = (int)$_SESSION['investor_id'];
$sessionInvestor = findInvestorById($db, $sessionInvestorId);

if (!$sessionInvestor) {
    clearInvestorSession();
    header('Location: login.php');
    exit();
}

$nic = normalizeInvestorNic((string)($sessionInvestor['nic_no'] ?? $_SESSION['investor_nic'] ?? ''));
$photoFlash = '';
$photoError = '';
$feedbackFlash = '';
$feedbackError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_profile_photo') {
    $result = saveInvestorProfilePhotoUpload($_FILES['profile_photo'] ?? [], $nic);
    if (!$result['ok']) {
        $photoError = (string)($result['error'] ?? 'Could not upload photo.');
    } else {
        $oldPhoto = findProfilePhotoForNic($db, $nic);
        setProfilePhotoForNic($db, $nic, (string)$result['filename']);
        if ($oldPhoto !== '' && $oldPhoto !== $result['filename']) {
            $oldPath = dirname(__DIR__) . '/uploads/profiles/' . $oldPhoto;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
        $redirectApp = isset($_POST['app']) ? (int)$_POST['app'] : $sessionInvestorId;
        $qs = $redirectApp > 0 ? ('?app=' . $redirectApp . '&photo=1') : '?photo=1';
        header('Location: dashboard.php' . $qs);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_feedback') {
    $feedbackText = trim((string)($_POST['feedback_text'] ?? ''));
    $redirectApp = isset($_POST['app']) ? (int)$_POST['app'] : $sessionInvestorId;

    if ($feedbackText === '') {
        $feedbackError = 'Please enter your feedback before sending.';
    } elseif (mb_strlen($feedbackText) > 2000) {
        $feedbackError = 'Feedback must be 2000 characters or less.';
    } else {
        try {
            $fbStmt = $db->prepare('
                INSERT INTO investor_feedback (investor_id, investor_name, nic_no, feedback_text, status)
                VALUES (?, ?, ?, ?, ?)
            ');
            $fbStmt->execute([
                $sessionInvestorId,
                (string)($sessionInvestor['full_name'] ?? $_SESSION['investor_name'] ?? 'Investor'),
                $nic,
                $feedbackText,
                'pending',
            ]);
            $qs = $redirectApp > 0 ? ('?app=' . $redirectApp . '&feedback=1') : '?feedback=1';
            header('Location: dashboard.php' . $qs);
            exit();
        } catch (PDOException $e) {
            $feedbackError = 'Could not send feedback. Please try again.';
        }
    }
}

if (isset($_GET['photo']) && (string)$_GET['photo'] === '1') {
    $photoFlash = 'Profile photo updated.';
}
if (isset($_GET['feedback']) && (string)$_GET['feedback'] === '1') {
    $feedbackFlash = 'Thank you! Your feedback was sent and is waiting for admin approval.';
}

$proposals = findInvestorsByNic($db, $nic);
if (!$proposals) {
    $proposals = [$sessionInvestor];
}

$selectedId = isset($_GET['app']) ? (int)$_GET['app'] : $sessionInvestorId;
$investor = null;
foreach ($proposals as $p) {
    if ((int)$p['id'] === $selectedId) {
        $investor = $p;
        break;
    }
}
if (!$investor) {
    $investor = $proposals[0];
    $selectedId = (int)$investor['id'];
}

if ((int)$_SESSION['investor_id'] !== $selectedId) {
    setInvestorSession($investor);
}

$investorId = (int)$investor['id'];
$beneficiaries = getInvestorBeneficiaries($db, $investorId);
$finance = buildInvestorFinancialSummary($investor);

$salesCode = trim((string)($investor['sales_person_code'] ?? ''));
$salesPerson = null;
if ($salesCode !== '') {
    try {
        $spStmt = $db->prepare('
            SELECT sp.name, sp.email, sp.phone_number, sp.sales_code,
                   rsp.name AS reported_sales_person_name,
                   rsp.email AS reported_sales_person_email
            FROM sales_persons sp
            LEFT JOIN sales_persons rsp ON sp.reported_sales_person_id = rsp.id
            WHERE sp.sales_code = ?
            LIMIT 1
        ');
        $spStmt->execute([$salesCode]);
        $salesPerson = $spStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $salesPerson = null;
    }
}

$statusLabels = [
    'pending' => 'Pending review',
    'reviewed' => 'Under review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];
$status = strtolower((string)($investor['status'] ?? 'pending'));
$statusLabel = $statusLabels[$status] ?? ucfirst($status);

$accentPalette = [
    ['accent' => '#059669', 'soft' => '#ecfdf5', 'bar' => 'linear-gradient(90deg, #10b981, #34d399)'],
    ['accent' => '#2563eb', 'soft' => '#eff6ff', 'bar' => 'linear-gradient(90deg, #3b82f6, #60a5fa)'],
    ['accent' => '#7c3aed', 'soft' => '#f5f3ff', 'bar' => 'linear-gradient(90deg, #8b5cf6, #a78bfa)'],
    ['accent' => '#ea580c', 'soft' => '#fff7ed', 'bar' => 'linear-gradient(90deg, #f97316, #fb923c)'],
    ['accent' => '#db2777', 'soft' => '#fdf2f8', 'bar' => 'linear-gradient(90deg, #ec4899, #f472b6)'],
    ['accent' => '#0891b2', 'soft' => '#ecfeff', 'bar' => 'linear-gradient(90deg, #06b6d4, #22d3ee)'],
];

$investments = [];
$portfolioDeposit = 0.0;
$portfolioMonthly = 0.0;
$portfolioMaturity = 0.0;
$approvedCount = 0;

foreach ($proposals as $index => $p) {
    $pid = (int)$p['id'];
    $pFinance = buildInvestorFinancialSummary($p);
    $pStatus = strtolower((string)($p['status'] ?? 'pending'));
    $pAgreementNo = trim((string)($p['agreement_no'] ?? ''));

    try {
        $agrStmt = $db->prepare('
            SELECT agreement_number, status, generated_at
            FROM agreements
            WHERE investor_id = ?
            ORDER BY id DESC
            LIMIT 1
        ');
        $agrStmt->execute([$pid]);
        $pAgreement = $agrStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($pAgreement && $pAgreementNo === '') {
            $pAgreementNo = resolveAgreementDisplayNumber(
                array_merge($p, $pAgreement),
                null
            );
        }
    } catch (PDOException $e) {
        $pAgreement = null;
    }

    $palette = $accentPalette[$index % count($accentPalette)];
    $investments[] = [
        'row' => $p,
        'id' => $pid,
        'finance' => $pFinance,
        'status' => $pStatus,
        'status_label' => $statusLabels[$pStatus] ?? ucfirst($pStatus),
        'ref' => buildApplicationRef($pid, $p['investment_type'] ?? ''),
        'agreement_no' => $pAgreementNo,
        'palette' => $palette,
        'selected' => $pid === $selectedId,
    ];

    $portfolioDeposit += (float)$pFinance['deposit'];
    $portfolioMonthly += (float)$pFinance['monthly_return'];
    $portfolioMaturity += (float)$pFinance['maturity_total'];
    if ($pStatus === 'approved') {
        $approvedCount++;
    }
}

$agreementNo = '';
foreach ($investments as $inv) {
    if ($inv['id'] === $selectedId) {
        $agreementNo = $inv['agreement_no'];
        break;
    }
}

function fmtDate(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d M Y', $ts) : '—';
}

$displayCurrency = (string)($finance['currency'] ?? 'LKR');
$investmentCount = count($investments);
$profilePhotoFile = trim((string)($investor['profile_photo'] ?? ''));
if ($profilePhotoFile === '') {
    $profilePhotoFile = findProfilePhotoForNic($db, $nic);
}
$profilePhotoUrl = investorProfilePhotoUrl($profilePhotoFile);
$nameInitials = '';
foreach (preg_split('/\s+/', trim((string)($investor['full_name'] ?? ''))) as $part) {
    if ($part !== '') {
        $nameInitials .= strtoupper(substr($part, 0, 1));
    }
    if (strlen($nameInitials) >= 2) {
        break;
    }
}
if ($nameInitials === '') {
    $nameInitials = 'IN';
}

$approvedFeedbacks = [];
try {
    $approvedFbStmt = $db->query("
        SELECT investor_name, feedback_text, created_at, reviewed_at
        FROM investor_feedback
        WHERE status = 'approved'
        ORDER BY COALESCE(reviewed_at, created_at) DESC, id DESC
        LIMIT 50
    ");
    $approvedFeedbacks = $approvedFbStmt ? $approvedFbStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $approvedFeedbacks = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Investments | Investor Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #ffffff;
            --page: #f4f7fb;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --card: #ffffff;
            --shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            --green: #059669;
            --blue: #2563eb;
            --violet: #7c3aed;
            --orange: #ea580c;
            --pink: #db2777;
            --cyan: #0891b2;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            background: var(--page);
            color: var(--text);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .wrap {
            max-width: 1120px;
            margin: 0 auto;
            padding: 24px 18px 56px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
            padding: 16px 18px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-icon {
            width: 48px; height: 48px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #10b981, #06b6d4, #3b82f6);
            color: #fff;
            font-size: 18px;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.28);
        }
        .brand h1 { font-size: 20px; color: var(--text); }
        .brand p { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .top-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 14px; border-radius: 10px; text-decoration: none;
            color: var(--blue); border: 1px solid #bfdbfe;
            background: #eff6ff; font-size: 13px; font-weight: 600;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(37, 99, 235, 0.15); }
        .btn.primary {
            background: linear-gradient(135deg, #10b981, #059669);
            border: none; color: #fff;
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.28);
        }

        .welcome {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 22px;
            margin-bottom: 18px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        .welcome::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, #10b981, #06b6d4, #3b82f6, #8b5cf6, #f97316);
        }
        .welcome-head {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .profile-photo-form { margin: 0; }
        .profile-avatar {
            position: relative;
            width: 84px;
            height: 84px;
            border-radius: 50%;
            flex-shrink: 0;
            background: linear-gradient(145deg, #059669, #0ea5e9);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(5, 150, 105, 0.25);
            border: 3px solid #fff;
        }
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            border-radius: 50%;
        }
        .profile-avatar > span {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .profile-upload-btn {
            position: absolute;
            right: -2px;
            bottom: -2px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 2px solid #fff;
            background: #0f172a;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);
            z-index: 2;
        }
        .profile-upload-btn:hover { background: #059669; }
        .profile-upload-btn input { display: none; }
        .welcome-copy { min-width: 0; flex: 1; }
        .welcome h2 {
            font-size: 26px;
            margin-bottom: 6px;
            letter-spacing: 0.02em;
        }
        .meta { color: var(--muted); font-size: 14px; }
        .meta strong { color: var(--text); }
        .flash {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
        }
        .flash.ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .flash.err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .portfolio {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 18px;
        }
        .portfolio-tile {
            border-radius: 14px;
            padding: 14px 16px;
            color: #fff;
            min-height: 92px;
        }
        .portfolio-tile .label { font-size: 12px; opacity: 0.92; margin-bottom: 6px; font-weight: 600; }
        .portfolio-tile .value { font-size: 20px; font-weight: 800; line-height: 1.2; }
        .portfolio-tile .hint { font-size: 12px; opacity: 0.9; margin-top: 6px; }
        .pt-count { background: linear-gradient(145deg, #2563eb, #60a5fa); }
        .pt-deposit { background: linear-gradient(145deg, #059669, #34d399); }
        .pt-monthly { background: linear-gradient(145deg, #7c3aed, #a78bfa); }
        .pt-maturity { background: linear-gradient(145deg, #ea580c, #fb923c); }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            flex-wrap: wrap;
            margin: 8px 0 14px;
        }
        .section-head h3 {
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-head h3 i { color: var(--green); }
        .section-head p { color: var(--muted); font-size: 13px; }

        .investments { display: grid; gap: 16px; margin-bottom: 18px; }
        .inv-card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 20px;
            box-shadow: var(--shadow);
            text-decoration: none;
            color: inherit;
            display: block;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
            position: relative;
            overflow: hidden;
        }
        .inv-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.1);
        }
        .inv-card.selected {
            border-color: transparent;
            box-shadow: 0 0 0 2px var(--card-accent, var(--green)), 0 14px 34px rgba(15, 23, 42, 0.08);
        }
        .inv-card::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--card-bar);
        }
        .inv-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .inv-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--card-accent, var(--green));
        }
        .inv-sub { color: var(--muted); font-size: 13px; margin-top: 4px; }
        .badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 7px 12px; border-radius: 999px; font-size: 12px; font-weight: 700;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .badge.pending { color: #92400e; background: #fef3c7; border-color: #fcd34d; }
        .badge.reviewed { color: #1e40af; background: #dbeafe; border-color: #93c5fd; }
        .badge.approved { color: #166534; background: #dcfce7; border-color: #86efac; }
        .badge.rejected { color: #991b1b; background: #fee2e2; border-color: #fca5a5; }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }
        .stat {
            border-radius: 12px;
            padding: 14px;
            background: #f8fafc;
            border: 1px solid var(--line);
        }
        .stat .label { font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600; }
        .stat .value { font-size: 17px; font-weight: 800; color: var(--text); line-height: 1.25; }
        .stat .hint { font-size: 12px; color: var(--muted); margin-top: 6px; }
        .stat-invested { background: #ecfdf5; border-color: #a7f3d0; }
        .stat-invested .value { color: #047857; }
        .stat-period { background: #eff6ff; border-color: #bfdbfe; }
        .stat-period .value { color: #1d4ed8; }
        .stat-monthly { background: #f5f3ff; border-color: #ddd6fe; }
        .stat-monthly .value { color: #6d28d9; }
        .stat-maturity { background: #fff7ed; border-color: #fed7aa; }
        .stat-maturity .value { color: #c2410c; }
        .inv-note {
            margin-top: 12px;
            font-size: 12px;
            color: var(--muted);
            line-height: 1.5;
            padding: 10px 12px;
            border-radius: 10px;
            background: var(--card-soft, #f8fafc);
        }
        .selected-hint {
            margin-top: 10px;
            font-size: 12px;
            font-weight: 700;
            color: var(--card-accent, var(--green));
        }

        .grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
        }
        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 18px;
            box-shadow: var(--shadow);
        }
        .panel h3 {
            font-size: 16px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--line);
        }
        .panel-returns { border-top: 4px solid var(--green); }
        .panel-returns h3 { color: var(--green); }
        .panel-investment { border-top: 4px solid var(--blue); }
        .panel-investment h3 { color: var(--blue); }
        .panel-personal { border-top: 4px solid var(--violet); }
        .panel-personal h3 { color: var(--violet); }
        .panel-bank { border-top: 4px solid var(--cyan); }
        .panel-bank h3 { color: var(--cyan); }
        .panel-beneficiaries { border-top: 4px solid var(--pink); }
        .panel-beneficiaries h3 { color: var(--pink); }
        .panel-sales { border-top: 4px solid var(--orange); }
        .panel-sales h3 { color: var(--orange); }
        .panel-subhead {
            margin: 14px 0 10px;
            padding-top: 12px;
            border-top: 1px dashed var(--line);
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 0.02em;
        }

        .rows { display: flex; flex-direction: column; gap: 10px; }
        .row {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--line);
        }
        .row:last-child { border-bottom: none; padding-bottom: 0; }
        .k { color: var(--muted); font-size: 13px; }
        .v { font-size: 14px; font-weight: 600; word-break: break-word; color: var(--text); text-align: right; }

        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #fce7f3; font-size: 13px; }
        th { color: var(--pink); font-weight: 700; }
        .empty { color: var(--muted); font-size: 14px; }

        .detail-banner {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            font-size: 13px;
            font-weight: 600;
        }

        .feedback-section {
            margin-top: 28px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 22px;
            box-shadow: var(--shadow);
        }
        .feedback-section .section-head { margin-top: 0; }
        .feedback-list { display: flex; flex-direction: column; gap: 12px; margin-top: 14px; }
        .feedback-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px 16px;
            background: #f8fafc;
        }
        .feedback-card .fb-text {
            font-size: 14px;
            line-height: 1.55;
            color: var(--text);
            white-space: pre-wrap;
            word-break: break-word;
        }
        .feedback-card .fb-meta {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }
        .feedback-footer {
            margin-top: 28px;
            display: flex;
            justify-content: center;
            padding-bottom: 8px;
        }
        .btn.feedback-btn {
            background: linear-gradient(135deg, #0ea5e9, #2563eb);
            border: none;
            color: #fff;
            padding: 12px 22px;
            font-size: 14px;
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);
            cursor: pointer;
        }
        .btn.feedback-btn:hover { box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35); }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 18px;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--line);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
            padding: 22px;
        }
        .modal h3 {
            font-size: 18px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
        }
        .modal p.hint {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 14px;
        }
        .modal label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .modal textarea {
            width: 100%;
            min-height: 140px;
            resize: vertical;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            font-size: 14px;
            font-family: inherit;
            color: var(--text);
            outline: none;
        }
        .modal textarea:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .modal-actions {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn.ghost {
            background: #f8fafc;
            border: 1px solid var(--line);
            color: var(--text);
            cursor: pointer;
        }

        @media (max-width: 900px) {
            .portfolio, .stats { grid-template-columns: repeat(2, 1fr); }
            .grid { grid-template-columns: 1fr; }
            .row { grid-template-columns: 1fr; gap: 4px; }
            .v { text-align: left; }
            .portfolio-tile .value, .stat .value { font-size: 16px; }
        }
        @media (max-width: 520px) {
            .portfolio, .stats { grid-template-columns: 1fr; }
            .welcome h2 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div class="brand">
                <div class="brand-icon"><i class="fas fa-leaf"></i></div>
                <div>
                    <h1>Investor Portal</h1>
                    <p>Serendib Green Plantation</p>
                </div>
            </div>
            <div class="top-actions">
                <a class="btn" href="change_password.php"><i class="fas fa-key"></i> Change password</a>
                <a class="btn primary" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <section class="welcome">
            <div class="welcome-head">
                <form class="profile-photo-form" method="post" enctype="multipart/form-data" action="dashboard.php?app=<?php echo (int)$investorId; ?>">
                    <input type="hidden" name="action" value="upload_profile_photo">
                    <input type="hidden" name="app" value="<?php echo (int)$investorId; ?>">
                    <div class="profile-avatar" title="Profile photo">
                        <?php if ($profilePhotoUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile photo">
                        <?php else: ?>
                            <span><?php echo htmlspecialchars($nameInitials); ?></span>
                        <?php endif; ?>
                        <label class="profile-upload-btn" title="<?php echo $profilePhotoUrl !== '' ? 'Change photo' : 'Upload photo'; ?>">
                            <i class="fas fa-camera"></i>
                            <input type="file" name="profile_photo" accept="image/jpeg,image/png,.jpg,.jpeg,.png" onchange="this.form.submit()">
                        </label>
                    </div>
                </form>
                <div class="welcome-copy">
                    <h2>Welcome, <?php echo htmlspecialchars(strtoupper((string)$investor['full_name'])); ?></h2>
                    <p class="meta">
                        NIC: <strong><?php echo htmlspecialchars((string)$investor['nic_no']); ?></strong>
                        · Showing <strong><?php echo $investmentCount; ?></strong> investment<?php echo $investmentCount === 1 ? '' : 's'; ?>
                    </p>
                </div>
            </div>

            <?php if ($photoFlash !== ''): ?>
                <div class="flash ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($photoFlash); ?></div>
            <?php endif; ?>
            <?php if ($photoError !== ''): ?>
                <div class="flash err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($photoError); ?></div>
            <?php endif; ?>
            <?php if ($feedbackFlash !== ''): ?>
                <div class="flash ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($feedbackFlash); ?></div>
            <?php endif; ?>
            <?php if ($feedbackError !== ''): ?>
                <div class="flash err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($feedbackError); ?></div>
            <?php endif; ?>

            <div class="portfolio">
                <div class="portfolio-tile pt-count">
                    <div class="label">Investments</div>
                    <div class="value"><?php echo $investmentCount; ?></div>
                    <div class="hint"><?php echo $approvedCount; ?> approved</div>
                </div>
                <div class="portfolio-tile pt-deposit">
                    <div class="label">Total invested</div>
                    <div class="value"><?php echo htmlspecialchars(formatMoneyLkr($portfolioDeposit, $displayCurrency)); ?></div>
                    <div class="hint">Across all plans</div>
                </div>
                <div class="portfolio-tile pt-monthly">
                    <div class="label">Total monthly return</div>
                    <div class="value"><?php echo htmlspecialchars(formatMoneyLkr($portfolioMonthly, $displayCurrency)); ?></div>
                    <div class="hint">Combined payout</div>
                </div>
                <div class="portfolio-tile pt-maturity">
                    <div class="label">Total at maturity</div>
                    <div class="value"><?php echo htmlspecialchars(formatMoneyLkr($portfolioMaturity, $displayCurrency)); ?></div>
                    <div class="hint">All investments</div>
                </div>
            </div>
        </section>

        <div class="section-head">
            <h3><i class="fas fa-layer-group"></i> My investments</h3>
            <p>Tap an investment to view full details below</p>
        </div>

        <div class="investments">
            <?php foreach ($investments as $inv): ?>
                <?php
                    $f = $inv['finance'];
                    $p = $inv['row'];
                ?>
                <a class="inv-card<?php echo $inv['selected'] ? ' selected' : ''; ?>"
                   href="dashboard.php?app=<?php echo (int)$inv['id']; ?>"
                   style="--card-accent: <?php echo htmlspecialchars($inv['palette']['accent']); ?>; --card-soft: <?php echo htmlspecialchars($inv['palette']['soft']); ?>; --card-bar: <?php echo htmlspecialchars($inv['palette']['bar']); ?>;">
                    <div class="inv-top">
                        <div>
                            <div class="inv-title"><?php echo htmlspecialchars($inv['agreement_no'] !== '' ? $inv['agreement_no'] : $inv['ref']); ?></div>
                            <div class="inv-sub">
                                <?php echo htmlspecialchars($f['product_name']); ?>
                                · <?php echo (int)$f['years']; ?> year<?php echo $f['years'] === 1 ? '' : 's'; ?>
                            </div>
                        </div>
                        <span class="badge <?php echo htmlspecialchars($inv['status']); ?>">
                            <i class="fas fa-circle" style="font-size:8px;"></i>
                            <?php echo htmlspecialchars($inv['status_label']); ?>
                        </span>
                    </div>

                    <div class="stats">
                        <div class="stat stat-invested">
                            <div class="label">You invested</div>
                            <div class="value"><?php echo htmlspecialchars(formatMoneyLkr($f['deposit'], $f['currency'])); ?></div>
                            <div class="hint"><?php echo htmlspecialchars($f['product_name']); ?></div>
                        </div>
                        <div class="stat stat-period">
                            <div class="label">Investment period</div>
                            <div class="value"><?php echo (int)$f['years']; ?> year<?php echo $f['years'] === 1 ? '' : 's'; ?></div>
                            <div class="hint">
                                <?php echo htmlspecialchars($f['commence']->format('d M Y')); ?>
                                →
                                <?php echo htmlspecialchars($f['maturity']->format('d M Y')); ?>
                            </div>
                        </div>
                        <div class="stat stat-monthly">
                            <div class="label">Monthly return</div>
                            <div class="value"><?php echo htmlspecialchars(formatMoneyLkr($f['monthly_return'], $f['currency'])); ?></div>
                            <div class="hint"><?php echo rtrim(rtrim(number_format($f['monthly_rate_percent'], 2), '0'), '.'); ?>% per month</div>
                        </div>
                        <div class="stat stat-maturity">
                            <div class="label">You get at maturity</div>
                            <div class="value"><?php echo htmlspecialchars(formatMoneyLkr($f['maturity_total'], $f['currency'])); ?></div>
                            <div class="hint">
                                Returns:
                                <?php echo htmlspecialchars(formatMoneyLkr($f['total_return'], $f['currency'])); ?>
                            </div>
                        </div>
                    </div>

                    <p class="inv-note">
                        Maturity total = investment + total returns over <?php echo (int)$f['years']; ?> year<?php echo $f['years'] === 1 ? '' : 's'; ?>.
                        <?php if (!empty($f['is_hpp_premium']) && $f['land_size_label'] !== ''): ?>
                            Premium HPP land allocation: <?php echo htmlspecialchars($f['land_size_label']); ?>.
                        <?php endif; ?>
                    </p>
                    <?php if ($inv['selected']): ?>
                        <div class="selected-hint"><i class="fas fa-check-circle"></i> Selected — details shown below</div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="detail-banner">
            Details for <?php echo htmlspecialchars($agreementNo !== '' ? $agreementNo : buildApplicationRef($investorId, $investor['investment_type'] ?? '')); ?>
            · <?php echo htmlspecialchars($finance['product_name']); ?>
            · <?php echo htmlspecialchars($statusLabel); ?>
        </div>

        <div class="grid">
            <div>
                <div class="panel panel-returns">
                    <h3><i class="fas fa-chart-line"></i> Return summary</h3>
                    <div class="rows">
                        <div class="row"><div class="k">Investment</div><div class="v"><?php echo htmlspecialchars(formatMoneyLkr($finance['deposit'], $finance['currency'])); ?></div></div>
                        <div class="row"><div class="k">Monthly benefit</div><div class="v"><?php echo htmlspecialchars(formatMoneyLkr($finance['monthly_return'], $finance['currency'])); ?></div></div>
                        <div class="row"><div class="k">Yearly return</div><div class="v"><?php echo htmlspecialchars(formatMoneyLkr($finance['yearly_return'], $finance['currency'])); ?></div></div>
                        <div class="row"><div class="k">Total returns</div><div class="v"><?php echo htmlspecialchars(formatMoneyLkr($finance['total_return'], $finance['currency'])); ?></div></div>
                        <div class="row"><div class="k">At maturity</div><div class="v"><?php echo htmlspecialchars(formatMoneyLkr($finance['maturity_total'], $finance['currency'])); ?></div></div>
                        <div class="row"><div class="k">Term</div><div class="v"><?php echo (int)$finance['months']; ?> months (<?php echo (int)$finance['years']; ?> years)</div></div>
                    </div>
                </div>

                <div class="panel panel-investment">
                    <h3><i class="fas fa-briefcase"></i> Investment details</h3>
                    <div class="rows">
                        <div class="row"><div class="k">Plan</div><div class="v"><?php echo htmlspecialchars($finance['product_name']); ?> (<?php echo htmlspecialchars($finance['investment_type']); ?>)</div></div>
                        <div class="row"><div class="k">Amount</div><div class="v"><?php echo htmlspecialchars(formatMoneyLkr($finance['deposit'], $finance['currency'])); ?></div></div>
                        <div class="row"><div class="k">Years</div><div class="v"><?php echo (int)$finance['years']; ?></div></div>
                        <div class="row"><div class="k">Signing date</div><div class="v"><?php echo htmlspecialchars(fmtDate($investor['signing_date'] ?? null)); ?></div></div>
                        <div class="row"><div class="k">Agreement no.</div><div class="v"><?php echo htmlspecialchars($agreementNo !== '' ? $agreementNo : '—'); ?></div></div>
                        <div class="row"><div class="k">Application status</div><div class="v"><?php echo htmlspecialchars($statusLabel); ?></div></div>
                        <div class="row"><div class="k">Applied on</div><div class="v"><?php echo htmlspecialchars(fmtDate($investor['created_at'] ?? null)); ?></div></div>
                        <?php if ($salesCode !== ''): ?>
                            <div class="row"><div class="k">Sales code</div><div class="v"><?php echo htmlspecialchars($salesCode); ?></div></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($salesCode !== ''): ?>
                <div class="panel panel-sales">
                    <h3><i class="fas fa-user-tie"></i> Sales person details</h3>
                    <div class="rows">
                        <div class="row">
                            <div class="k">Sales person name</div>
                            <div class="v"><?php echo htmlspecialchars($salesPerson['name'] ?? '—'); ?></div>
                        </div>
                        <div class="row">
                            <div class="k">Email</div>
                            <div class="v"><?php echo htmlspecialchars(($salesPerson['email'] ?? '') !== '' ? $salesPerson['email'] : '—'); ?></div>
                        </div>
                        <div class="row">
                            <div class="k">Phone number</div>
                            <div class="v"><?php echo htmlspecialchars(($salesPerson['phone_number'] ?? '') !== '' ? $salesPerson['phone_number'] : '—'); ?></div>
                        </div>
                    </div>
                    <div class="panel-subhead">Reported sales person</div>
                    <div class="rows">
                        <div class="row">
                            <div class="k">Name</div>
                            <div class="v"><?php echo htmlspecialchars(($salesPerson['reported_sales_person_name'] ?? '') !== '' ? $salesPerson['reported_sales_person_name'] : '—'); ?></div>
                        </div>
                        <div class="row">
                            <div class="k">Email</div>
                            <div class="v"><?php echo htmlspecialchars(($salesPerson['reported_sales_person_email'] ?? '') !== '' ? $salesPerson['reported_sales_person_email'] : '—'); ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div>
                <div class="panel panel-personal">
                    <h3><i class="fas fa-user"></i> Personal details</h3>
                    <div class="rows">
                        <div class="row"><div class="k">Full name</div><div class="v"><?php echo htmlspecialchars($investor['full_name']); ?></div></div>
                        <div class="row"><div class="k">Name with initials</div><div class="v"><?php echo htmlspecialchars($investor['name_with_initials']); ?></div></div>
                        <div class="row"><div class="k">NIC</div><div class="v"><?php echo htmlspecialchars($investor['nic_no']); ?></div></div>
                        <div class="row"><div class="k">Date of birth</div><div class="v"><?php echo htmlspecialchars(fmtDate($investor['dob'] ?? null)); ?></div></div>
                        <div class="row"><div class="k">Phone</div><div class="v"><?php echo htmlspecialchars($investor['tel_no']); ?></div></div>
                        <div class="row"><div class="k">Email</div><div class="v"><?php echo htmlspecialchars($investor['email']); ?></div></div>
                        <div class="row"><div class="k">Address</div><div class="v"><?php echo htmlspecialchars($investor['address']); ?></div></div>
                        <div class="row"><div class="k">Occupation</div><div class="v"><?php echo htmlspecialchars($investor['occupation']); ?></div></div>
                        <div class="row"><div class="k">Employer</div><div class="v"><?php echo htmlspecialchars($investor['employer_name']); ?></div></div>
                    </div>
                </div>

                <div class="panel panel-bank">
                    <h3><i class="fas fa-university"></i> Bank details</h3>
                    <div class="rows">
                        <div class="row"><div class="k">Account no.</div><div class="v"><?php echo htmlspecialchars($investor['account_no']); ?></div></div>
                        <div class="row"><div class="k">Bank</div><div class="v"><?php echo htmlspecialchars($investor['bank_name']); ?></div></div>
                        <div class="row"><div class="k">Branch</div><div class="v"><?php echo htmlspecialchars($investor['branch_name']); ?></div></div>
                    </div>
                </div>

                <div class="panel panel-beneficiaries">
                    <h3><i class="fas fa-users"></i> Beneficiaries</h3>
                    <?php if ($beneficiaries): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>NIC</th>
                                    <th>%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($beneficiaries as $b): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($b['beneficiary_name']); ?></td>
                                        <td><?php echo htmlspecialchars($b['beneficiary_nic']); ?></td>
                                        <td><?php echo (int)$b['percentage']; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="empty">No beneficiaries on record.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <section class="feedback-section" id="community-feedback">
            <div class="section-head">
                <h3><i class="fas fa-comments"></i> Investor feedback</h3>
                <span class="meta">Comments from fellow investors</span>
            </div>
            <?php if ($approvedFeedbacks): ?>
                <div class="feedback-list">
                    <?php foreach ($approvedFeedbacks as $fb): ?>
                        <div class="feedback-card">
                            <div class="fb-text"><?php echo htmlspecialchars($fb['feedback_text']); ?></div>
                            <div class="fb-meta">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($fb['investor_name'] ?: 'Investor'); ?></span>
                                <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars(fmtDate($fb['reviewed_at'] ?? $fb['created_at'] ?? null)); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty" style="margin-top: 10px;">No feedback yet. Be the first to share your experience.</p>
            <?php endif; ?>
        </section>

        <div class="feedback-footer">
            <button type="button" class="btn feedback-btn" id="openFeedbackModal">
                <i class="fas fa-comment-dots"></i> Feedback
            </button>
        </div>
    </div>

    <div class="modal-overlay" id="feedbackModal" aria-hidden="true">
        <div class="modal" role="dialog" aria-labelledby="feedbackModalTitle">
            <h3 id="feedbackModalTitle"><i class="fas fa-comment-dots"></i> Add your feedback</h3>
            <p class="hint">Share your experience. Your feedback will appear on the dashboard after admin approval.</p>
            <form method="post" action="dashboard.php?app=<?php echo (int)$investorId; ?>">
                <input type="hidden" name="action" value="submit_feedback">
                <input type="hidden" name="app" value="<?php echo (int)$investorId; ?>">
                <label for="feedback_text">Your feedback</label>
                <textarea id="feedback_text" name="feedback_text" maxlength="2000" required placeholder="Write your feedback here..."><?php echo htmlspecialchars((string)($_POST['feedback_text'] ?? '')); ?></textarea>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" id="closeFeedbackModal">Cancel</button>
                    <button type="submit" class="btn primary"><i class="fas fa-paper-plane"></i> Send feedback</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            var overlay = document.getElementById('feedbackModal');
            var openBtn = document.getElementById('openFeedbackModal');
            var closeBtn = document.getElementById('closeFeedbackModal');
            if (!overlay || !openBtn) return;

            function openModal() {
                overlay.classList.add('active');
                overlay.setAttribute('aria-hidden', 'false');
                var ta = document.getElementById('feedback_text');
                if (ta) ta.focus();
            }
            function closeModal() {
                overlay.classList.remove('active');
                overlay.setAttribute('aria-hidden', 'true');
            }

            openBtn.addEventListener('click', openModal);
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeModal();
            });

            <?php if ($feedbackError !== ''): ?>
            openModal();
            <?php endif; ?>
        })();
    </script>
</body>
</html>
