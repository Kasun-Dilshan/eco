<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config.php';
require_once '../db.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

function spbEnsureSalesPersonsTable(PDO $db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS sales_persons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                branch VARCHAR(100) NOT NULL,
                epf_no VARCHAR(20) NOT NULL,
                sales_code VARCHAR(50) NOT NULL,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_epf_no (epf_no),
                UNIQUE KEY unique_sales_code (sales_code),
                INDEX idx_branch (branch),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('sales_person_business ensure table: ' . $e->getMessage());
    }
}

function spbFetchActiveSalesPersons(PDO $db) {
    try {
        $stmt = $db->prepare("
            SELECT id, name, branch, epf_no, sales_code, status
            FROM sales_persons
            WHERE status = 'active'
            ORDER BY name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('sales_person_business fetch sales persons: ' . $e->getMessage());
        return [];
    }
}

function spbNormalizeBranchKey($branch) {
    $b = strtolower(trim((string)$branch));
    if ($b === '') {
        return '';
    }

    $b = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u', ' ', $b);
    $b = preg_replace('/\s+/u', ' ', $b);
    $b = preg_replace('/\s+branch$/u', '', $b);

    return trim((string)$b);
}

function spbUtf8($value) {
    $value = (string)$value;
    if ($value === '' || !function_exists('mb_check_encoding')) {
        return $value;
    }
    if (mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }
    $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
    return $converted !== false ? $converted : $value;
}

function spbJsonFlags() {
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return $flags;
}

function spbJsonEncode($data, $empty = '[]') {
    $json = json_encode($data, spbJsonFlags());
    return $json !== false ? $json : $empty;
}

function spbParseTarget($raw) {
    $value = str_replace([',', ' '], '', trim((string)$raw));
    if ($value === '' || !is_numeric($value)) {
        return 0.0;
    }
    $amount = (float)$value;
    return $amount > 0 ? $amount : 0.0;
}

function spbParseMonth($raw) {
    $value = trim((string)$raw);
    if ($value === '' || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
        return '';
    }
    return $value;
}

function spbMonthLabel($month) {
    if ($month === '') {
        return 'All months';
    }
    $ts = strtotime($month . '-01');
    return $ts ? date('F Y', $ts) : $month;
}

function spbMonthOptions($selected = '') {
    $options = [];
    $cursor = new DateTime('first day of this month');
    for ($i = 0; $i < 36; $i++) {
        $value = $cursor->format('Y-m');
        $options[$value] = $cursor->format('F Y');
        $cursor->modify('-1 month');
    }
    if ($selected !== '' && !isset($options[$selected])) {
        $ts = strtotime($selected . '-01');
        $options[$selected] = $ts ? date('F Y', $ts) : $selected;
    }
    return $options;
}

function spbFetchStatsByCodes(PDO $db, array $salesCodes, $month = '') {
    $stats = [];
    if (empty($salesCodes)) {
        return $stats;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($salesCodes), '?'));
        $sql = "
            SELECT
                sales_person_code,
                COUNT(*) AS investment_count,
                COALESCE(SUM(investment_amount), 0) AS total_amount
            FROM investors
            WHERE sales_person_code IN ($placeholders)
        ";
        $params = array_values($salesCodes);
        if ($month !== '') {
            $sql .= " AND DATE_FORMAT(created_at, '%Y-%m') = ?";
            $params[] = $month;
        }
        $sql .= " GROUP BY sales_person_code";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = (string)($row['sales_person_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $stats[$code] = [
                'investment_count' => (int)($row['investment_count'] ?? 0),
                'total_amount' => (float)($row['total_amount'] ?? 0),
            ];
        }
    } catch (PDOException $e) {
        error_log('sales_person_business fetch stats: ' . $e->getMessage());
    }

    return $stats;
}

function spbBuildReportRows(array $salesPersons, array $targets, array $stats) {
    $rows = [];
    $totals = [
        'investment_count' => 0,
        'total_amount' => 0.0,
        'target' => 0.0,
        'achieved' => 0,
        'not_achieved' => 0,
        'no_target' => 0,
    ];

    foreach ($salesPersons as $sp) {
        $code = (string)($sp['sales_code'] ?? '');
        $target = isset($targets[$code]) ? spbParseTarget($targets[$code]) : 0.0;
        $count = (int)($stats[$code]['investment_count'] ?? 0);
        $amount = (float)($stats[$code]['total_amount'] ?? 0);
        $hasTarget = $target > 0;
        $percent = $hasTarget ? ($amount / $target) * 100 : 0.0;
        $met = $hasTarget && $amount >= $target;

        $rows[] = [
            'name' => $sp['name'] ?? '',
            'sales_code' => $code,
            'epf_no' => $sp['epf_no'] ?? '',
            'investment_count' => $count,
            'total_amount' => $amount,
            'target' => $target,
            'has_target' => $hasTarget,
            'percent' => $percent,
            'met' => $met,
        ];

        $totals['investment_count'] += $count;
        $totals['total_amount'] += $amount;
        $totals['target'] += $target;
        if (!$hasTarget) {
            $totals['no_target']++;
        } elseif ($met) {
            $totals['achieved']++;
        } else {
            $totals['not_achieved']++;
        }
    }

    usort($rows, static function ($a, $b) {
        if ($a['total_amount'] != $b['total_amount']) {
            return $b['total_amount'] <=> $a['total_amount'];
        }
        if ($a['percent'] != $b['percent']) {
            return $b['percent'] <=> $a['percent'];
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    $rank = 0;
    $position = 0;
    $lastAmount = null;
    foreach ($rows as &$row) {
        $position++;
        if ($lastAmount === null || (float)$row['total_amount'] !== (float)$lastAmount) {
            $rank = $position;
            $lastAmount = $row['total_amount'];
        }
        $row['rank'] = $rank;
    }
    unset($row);

    return [$rows, $totals];
}

function spbBuildExportQuery($branch, $month, array $targets) {
    $params = [
        'branch' => $branch,
        'action' => 'report',
    ];
    if ($month !== '') {
        $params['month'] = $month;
    }
    foreach ($targets as $code => $value) {
        if (spbParseTarget($value) > 0) {
            $params['targets'][$code] = $value;
        }
    }
    return http_build_query($params);
}

function spbExportExcel(array $rows, array $totals, $branch, $month) {
    $filename = 'SalesPerson_Branch_Report_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $branch) . '_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<h2>ECO WEALTH FINANCE - Sales Person Business Report</h2>';
    echo '<p>Generated: ' . date('F d, Y H:i:s') . '</p>';
    echo '<table border="1" cellpadding="6" cellspacing="0">';
    echo '<tr><th>Branch</th><td>' . htmlspecialchars($branch) . '</td></tr>';
    echo '<tr><th>Month</th><td>' . htmlspecialchars(spbMonthLabel($month)) . '</td></tr>';
    echo '<tr><th>Sales Persons</th><td>' . count($rows) . '</td></tr>';
    echo '<tr><th>Total Investments</th><td>' . (int)$totals['investment_count'] . '</td></tr>';
    echo '<tr><th>Total Investment Price</th><td>Rs. ' . number_format($totals['total_amount'], 2) . '</td></tr>';
    echo '</table><br>';

    echo '<table border="1" cellpadding="6" cellspacing="0">';
    echo '<tr style="background:#0a2f1d;color:#fff;">';
    echo '<th>Rank</th><th>Sales Person</th><th>Code</th><th>Investment Amounts</th><th>Investment Price Amounts (Rs.)</th>';
    echo '<th>Target (Rs.)</th><th>Target Status</th><th>Percentage</th>';
    echo '</tr>';

    foreach ($rows as $row) {
        $status = !$row['has_target'] ? 'No target' : ($row['met'] ? 'Achieved' : 'Not Achieved');
        echo '<tr>';
        echo '<td>' . (int)($row['rank'] ?? 0) . '</td>';
        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['sales_code']) . '</td>';
        echo '<td>' . (int)$row['investment_count'] . '</td>';
        echo '<td>' . number_format($row['total_amount'], 2) . '</td>';
        echo '<td>' . ($row['has_target'] ? number_format($row['target'], 2) : '-') . '</td>';
        echo '<td>' . htmlspecialchars($status) . '</td>';
        echo '<td>' . ($row['has_target'] ? number_format($row['percent'], 2) . '%' : '-') . '</td>';
        echo '</tr>';
    }

    echo '<tr style="font-weight:bold;">';
    echo '<td></td>';
    echo '<td colspan="2">Total</td>';
    echo '<td>' . (int)$totals['investment_count'] . '</td>';
    echo '<td>' . number_format($totals['total_amount'], 2) . '</td>';
    echo '<td>' . number_format($totals['target'], 2) . '</td>';
    echo '<td></td><td></td>';
    echo '</tr>';
    echo '</table></body></html>';
    exit;
}

function spbExportPdf(array $rows, array $totals, $branch, $month) {
    $tcpdfPath = __DIR__ . '/../libs/tcpdf/tcpdf.php';
    if (!file_exists($tcpdfPath)) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<p>PDF library not available. Please use Excel export or contact administrator.</p>';
        exit;
    }

    require_once $tcpdfPath;

    $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('SERENDIB GREEN PLANTATION PVT LTD');
    $pdf->SetTitle('Sales Person Business Report');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(10, 47, 29);
    $pdf->Cell(0, 8, 'ECO WEALTH FINANCE', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 8, 'Sales Person Business Report', 0, 1, 'C');
    $pdf->Ln(4);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell(0, 5,
        'Branch: ' . $branch . "\n" .
        'Month: ' . spbMonthLabel($month) . "\n" .
        'Sales Persons: ' . count($rows) . ' | Investments: ' . (int)$totals['investment_count'] .
        ' | Total Price: Rs. ' . number_format($totals['total_amount'], 2) . "\n" .
        'Generated: ' . date('F d, Y H:i:s'), 0, 'L');
    $pdf->Ln(6);

    $html = '<table border="1" cellpadding="4" cellspacing="0">
        <thead>
            <tr style="background-color:#0a2f1d;color:#ffffff;">
                <th><b>Rank</b></th>
                <th><b>Sales Person</b></th>
                <th><b>Code</b></th>
                <th><b>Investments</b></th>
                <th><b>Investment Price</b></th>
                <th><b>Target</b></th>
                <th><b>Status</b></th>
                <th><b>%</b></th>
            </tr>
        </thead>
        <tbody>';

    foreach ($rows as $row) {
        $status = !$row['has_target'] ? 'No target' : ($row['met'] ? 'Achieved' : 'Not Achieved');
        $html .= '<tr>';
        $html .= '<td>' . (int)($row['rank'] ?? 0) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['sales_code']) . '</td>';
        $html .= '<td>' . (int)$row['investment_count'] . '</td>';
        $html .= '<td>Rs. ' . number_format($row['total_amount'], 2) . '</td>';
        $html .= '<td>' . ($row['has_target'] ? 'Rs. ' . number_format($row['target'], 2) : '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($status) . '</td>';
        $html .= '<td>' . ($row['has_target'] ? number_format($row['percent'], 1) . '%' : '-') . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    $pdf->writeHTML($html, true, false, true, false, '');

    $filename = 'SalesPerson_Branch_Report_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $branch) . '_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

spbEnsureSalesPersonsTable($db);

$branch = isset($_GET['branch']) ? trim((string)$_GET['branch']) : '';
$month = spbParseMonth($_GET['month'] ?? '');
$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
$rawTargets = isset($_GET['targets']) && is_array($_GET['targets']) ? $_GET['targets'] : [];

$allActiveSalesPersons = spbFetchActiveSalesPersons($db);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'sales_persons') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $ajaxBranch = isset($_GET['branch']) ? trim((string)$_GET['branch']) : '';
    $want = spbNormalizeBranchKey($ajaxBranch);
    $payload = [];

    if ($want !== '') {
        foreach ($allActiveSalesPersons as $sp) {
            if (spbNormalizeBranchKey($sp['branch'] ?? '') !== $want) {
                continue;
            }
            $payload[] = [
                'code' => spbUtf8($sp['sales_code'] ?? ''),
                'name' => spbUtf8($sp['name'] ?? ''),
            ];
        }
    }

    echo spbJsonEncode($payload, '[]');
    exit;
}

$branches = array_values(array_unique(array_filter(array_map(static function ($sp) {
    return isset($sp['branch']) ? trim((string)$sp['branch']) : '';
}, $allActiveSalesPersons))));
sort($branches);

$salesPersonOptions = [];
$targets = [];
$reportRows = [];
$reportTotals = [
    'investment_count' => 0,
    'total_amount' => 0.0,
    'target' => 0.0,
    'achieved' => 0,
    'not_achieved' => 0,
    'no_target' => 0,
];
$showTargetList = false;
$showReport = false;

if ($branch !== '') {
    $want = spbNormalizeBranchKey($branch);
    foreach ($allActiveSalesPersons as $sp) {
        if (spbNormalizeBranchKey($sp['branch'] ?? '') === $want) {
            $salesPersonOptions[] = $sp;
            $code = (string)($sp['sales_code'] ?? '');
            if ($code !== '' && isset($rawTargets[$code])) {
                $targets[$code] = $rawTargets[$code];
            }
        }
    }
}

if ($branch !== '' && in_array($action, ['list', 'report'], true)) {
    $showTargetList = true;
}

if ($action === 'report' && $branch !== '' && !empty($salesPersonOptions)) {
    $codes = array_values(array_filter(array_map(static function ($sp) {
        return (string)($sp['sales_code'] ?? '');
    }, $salesPersonOptions)));
    $stats = spbFetchStatsByCodes($db, $codes, $month);
    list($reportRows, $reportTotals) = spbBuildReportRows($salesPersonOptions, $targets, $stats);
    $showReport = true;
}

if (isset($_GET['export']) && $showReport) {
    $exportType = $_GET['export'];
    if ($exportType === 'excel') {
        spbExportExcel($reportRows, $reportTotals, $branch, $month);
    }
    if ($exportType === 'pdf') {
        spbExportPdf($reportRows, $reportTotals, $branch, $month);
    }
}

$monthOptions = spbMonthOptions($month);
$adminName = $_SESSION['full_name'] ?? $_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Person Business | SERENDIB GREEN PLANTATION PVT LTD Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --neon: #00ff88;
            --success: #10b981;
            --error: #ef4444;
            --text: #f0fdf4;
            --text-muted: #a7f3d0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%); color: var(--text); min-height: 100vh; }
        .admin-container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background: rgba(10, 47, 29, 0.9); border-right: 1px solid rgba(34, 197, 94, 0.2); padding: 20px 0; }
        .sidebar-header { padding: 0 20px 20px; border-bottom: 1px solid rgba(34, 197, 94, 0.2); margin-bottom: 20px; }
        .sidebar-header h2 { color: var(--neon); font-size: 20px; margin-bottom: 5px; display: flex; align-items: center; gap: 10px; }
        .sidebar-nav ul { list-style: none; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 15px 20px; color: var(--text-muted); text-decoration: none; border-left: 3px solid transparent; transition: all 0.3s ease; }
        .sidebar-nav a:hover, .sidebar-nav .active a { background: rgba(34, 197, 94, 0.15); color: var(--text); border-left-color: var(--accent); }
        .main-content { flex: 1; padding: 20px; overflow-y: auto; }
        .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 1px solid rgba(34, 197, 94, 0.2); flex-wrap: wrap; gap: 15px; }
        .content-header h1 { font-size: 28px; background: linear-gradient(90deg, var(--text), var(--neon)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-size: 14px; transition: all 0.3s ease; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-primary:hover { background: #16a34a; transform: translateY(-2px); }
        .btn-secondary { background: rgba(26, 77, 51, 0.7); color: var(--text); border: 1px solid rgba(34, 197, 94, 0.2); }
        .btn-secondary:hover { background: rgba(34, 197, 94, 0.15); border-color: var(--accent); }
        .btn-warning { background: #f59e0b; color: white; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .filter-section { background: rgba(10, 47, 29, 0.7); border: 1px solid rgba(34, 197, 94, 0.2); border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .filter-group label { display: block; margin-bottom: 8px; font-weight: 600; }
        .filter-group select, .filter-group input { width: 100%; padding: 10px; background: rgba(26, 77, 51, 0.7); border: 1px solid rgba(34, 197, 94, 0.2); border-radius: 8px; color: var(--text); font-size: 14px; }
        .filter-group select:disabled, .filter-group input:disabled { opacity: 0.5; cursor: not-allowed; }
        .filter-actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
        .name-banner { background: rgba(10, 47, 29, 0.7); border: 1px solid rgba(34, 197, 94, 0.2); border-radius: 12px; padding: 20px 24px; margin-bottom: 20px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .name-banner i { font-size: 28px; color: var(--neon); }
        .name-banner small { display: block; color: var(--text-muted); font-size: 12px; margin-bottom: 4px; }
        .name-banner strong { display: block; font-size: 22px; color: var(--text); }
        .name-banner span { color: var(--text-muted); font-size: 13px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: rgba(10, 47, 29, 0.7); border: 1px solid rgba(34, 197, 94, 0.2); border-radius: 12px; padding: 22px; text-align: center; }
        .stat-value { font-size: 24px; font-weight: 800; color: var(--neon); margin-bottom: 8px; }
        .stat-label { font-size: 14px; color: var(--text-muted); }
        .results-section { background: rgba(10, 47, 29, 0.7); border: 1px solid rgba(34, 197, 94, 0.2); border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        .results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .results-header h2 { color: var(--neon); font-size: 20px; display: flex; align-items: center; gap: 10px; }
        .data-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .data-table th { background: rgba(34, 197, 94, 0.2); color: var(--text); padding: 12px; text-align: left; font-weight: 600; border-bottom: 1px solid rgba(34, 197, 94, 0.3); white-space: nowrap; }
        .data-table td { padding: 12px; border-bottom: 1px solid rgba(34, 197, 94, 0.1); color: var(--text-muted); }
        .data-table tr:hover { background: rgba(34, 197, 94, 0.05); }
        .data-table tfoot td { color: var(--text); font-weight: 700; border-top: 1px solid rgba(34, 197, 94, 0.3); }
        .table-wrap { overflow-x: auto; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .status-achieved { background: #10b981; color: white; }
        .status-not-achieved { background: #ef4444; color: white; }
        .status-no-target { background: #64748b; color: white; }
        .empty-state { text-align: center; padding: 50px 20px; color: var(--text-muted); }
        .step-hint { color: var(--text-muted); font-size: 14px; margin-bottom: 15px; }
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; background: rgba(239, 68, 68, 0.15); border: 1px solid var(--error); color: #fecaca; }
        .target-input { width: 160px; padding: 8px 10px; background: rgba(26, 77, 51, 0.7); border: 1px solid rgba(34, 197, 94, 0.2); border-radius: 8px; color: var(--text); font-size: 14px; }
        .person-name { color: var(--text); font-weight: 700; }
        .person-code { display: block; font-size: 12px; color: var(--text-muted); }
        .mini-progress { height: 8px; background: rgba(26, 77, 51, 0.8); border-radius: 999px; overflow: hidden; margin-top: 6px; min-width: 90px; }
        .mini-progress span { display: block; height: 100%; background: linear-gradient(90deg, #22c55e, #00ff88); }
        .mini-progress span.below { background: linear-gradient(90deg, #f59e0b, #ef4444); }
        .percent-cell { white-space: nowrap; }
        .rank-cell { font-weight: 800; color: var(--neon); text-align: center; white-space: nowrap; }
        .rank-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; border-radius: 50%; background: rgba(34, 197, 94, 0.2); border: 1px solid rgba(34, 197, 94, 0.35); }
        .rank-badge.rank-1 { background: rgba(234, 179, 8, 0.25); border-color: #eab308; color: #facc15; }
        .rank-badge.rank-2 { background: rgba(148, 163, 184, 0.25); border-color: #94a3b8; color: #e2e8f0; }
        .rank-badge.rank-3 { background: rgba(180, 83, 9, 0.25); border-color: #d97706; color: #fdba74; }
        @media (max-width: 768px) {
            .admin-container { flex-direction: column; }
            .sidebar { width: 100%; border-right: none; border-bottom: 1px solid rgba(34, 197, 94, 0.2); }
        }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-chart-line"></i> EcoWealth Analytics</h2>
            <p>Welcome, <?php echo htmlspecialchars($adminName); ?></p>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="applications.php"><i class="fas fa-file-alt"></i> Applications</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports & Analytics</a></li>
                <li class="active"><a href="sales_person_business.php"><i class="fas fa-user-tie"></i> Sales Person Business</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </div>

    <div class="main-content">
        <div class="content-header">
            <h1>Sales Person Business</h1>
            <div class="header-actions">
                <a href="reports.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Reports</a>
            </div>
        </div>

        <div class="filter-section">
            <p class="step-hint"><i class="fas fa-info-circle"></i> Step 1: Select branch (and optionally a month). Step 2: Click Get Performance. Step 3: Enter a target for each sales person. Step 4: Click Get Report.</p>
            <form method="GET" action="" id="filterForm">
                <input type="hidden" name="action" value="list">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="branch"><i class="fas fa-building"></i> Branch</label>
                        <select name="branch" id="branch" required>
                            <option value="">-- Select Branch --</option>
                            <?php if (empty($branches)): ?>
                                <option value="" disabled>No branches with sales persons</option>
                            <?php endif; ?>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $branch === $b ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="month"><i class="fas fa-calendar-alt"></i> Month</label>
                        <select name="month" id="month">
                            <option value="">All months</option>
                            <?php foreach ($monthOptions as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $month === $value ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary" id="viewBtn" <?php echo $branch === '' ? 'disabled' : ''; ?>>
                        <i class="fas fa-chart-line"></i> Get Performance
                    </button>
                    <a href="sales_person_business.php" class="btn btn-secondary"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </form>
        </div>

        <?php if ($showTargetList && empty($salesPersonOptions)): ?>
            <div class="alert">No active sales persons found for this branch. Add sales persons under <strong>Sales Persons</strong> first.</div>
        <?php endif; ?>

        <?php if ($showTargetList && !empty($salesPersonOptions)): ?>
            <div id="targetWrap" class="results-section">
                <div class="results-header">
                    <h2><i class="fas fa-bullseye"></i> Set Targets for <?php echo htmlspecialchars($branch); ?></h2>
                </div>
                <p class="step-hint">Enter a target amount (Rs.) for each sales person, then click <strong>Get Report</strong>.<?php echo $month !== '' ? ' Report will use <strong>' . htmlspecialchars(spbMonthLabel($month)) . '</strong> only.' : ' Select a month above to limit the report to that month.'; ?></p>
                <form method="GET" action="" id="reportForm">
                    <input type="hidden" name="action" value="report">
                    <input type="hidden" name="branch" id="reportBranch" value="<?php echo htmlspecialchars($branch); ?>">
                    <input type="hidden" name="month" id="reportMonth" value="<?php echo htmlspecialchars($month); ?>">
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Sales Person</th>
                                    <th>EPF No</th>
                                    <th>Target (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($salesPersonOptions as $index => $sp): ?>
                                    <?php $code = (string)($sp['sales_code'] ?? ''); ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <span class="person-name"><?php echo htmlspecialchars($sp['name']); ?></span>
                                            <span class="person-code"><?php echo htmlspecialchars($code); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($sp['epf_no'] ?? ''); ?></td>
                                        <td>
                                            <input class="target-input" type="number" min="0.01" step="0.01"
                                                   name="targets[<?php echo htmlspecialchars($code); ?>]"
                                                   value="<?php echo isset($targets[$code]) && spbParseTarget($targets[$code]) > 0 ? htmlspecialchars((string)$targets[$code]) : ''; ?>"
                                                   placeholder="Enter target">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="filter-actions" style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" id="reportBtn">
                            <i class="fas fa-table"></i> Get Report
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($showReport): ?>
            <div id="resultsWrap">
            <div class="name-banner">
                <i class="fas fa-building"></i>
                <div>
                    <small>Branch Report</small>
                    <strong><?php echo htmlspecialchars($branch); ?></strong>
                    <span><?php echo htmlspecialchars(spbMonthLabel($month)); ?> · <?php echo count($reportRows); ?> sales persons</span>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo (int)$reportTotals['investment_count']; ?></div>
                    <div class="stat-label">Investment Amounts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">Rs. <?php echo number_format($reportTotals['total_amount'], 2); ?></div>
                    <div class="stat-label">Investment Price Amounts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo (int)$reportTotals['achieved']; ?></div>
                    <div class="stat-label">Target Achieved</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo (int)$reportTotals['not_achieved']; ?></div>
                    <div class="stat-label">Not Achieved</div>
                </div>
            </div>

            <div class="results-section">
                <div class="results-header">
                    <h2><i class="fas fa-list"></i> Performance Report</h2>
                    <?php $exportQuery = spbBuildExportQuery($branch, $month, $targets); ?>
                    <div class="header-actions">
                        <a href="?<?php echo $exportQuery; ?>&export=excel" class="btn btn-primary">
                            <i class="fas fa-file-excel"></i> Download Excel
                        </a>
                        <a href="?<?php echo $exportQuery; ?>&export=pdf" class="btn btn-warning">
                            <i class="fas fa-file-pdf"></i> Download PDF
                        </a>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Sales Person</th>
                                <th>Investment Amounts</th>
                                <th>Investment Price Amounts</th>
                                <th>Target</th>
                                <th>Target Status</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportRows as $row): ?>
                                <?php $rank = (int)($row['rank'] ?? 0); ?>
                                <tr>
                                    <td class="rank-cell">
                                        <span class="rank-badge<?php echo $rank >= 1 && $rank <= 3 ? ' rank-' . $rank : ''; ?>">
                                            <?php echo $rank; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="person-name"><?php echo htmlspecialchars($row['name']); ?></span>
                                        <span class="person-code"><?php echo htmlspecialchars($row['sales_code']); ?></span>
                                    </td>
                                    <td><?php echo (int)$row['investment_count']; ?></td>
                                    <td>Rs. <?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td><?php echo $row['has_target'] ? 'Rs. ' . number_format($row['target'], 2) : '—'; ?></td>
                                    <td>
                                        <?php if (!$row['has_target']): ?>
                                            <span class="status-badge status-no-target">No target</span>
                                        <?php elseif ($row['met']): ?>
                                            <span class="status-badge status-achieved">Achieved</span>
                                        <?php else: ?>
                                            <span class="status-badge status-not-achieved">Not Achieved</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="percent-cell">
                                        <?php if ($row['has_target']): ?>
                                            <?php echo number_format($row['percent'], 1); ?>%
                                            <div class="mini-progress">
                                                <span class="<?php echo $row['met'] ? '' : 'below'; ?>"
                                                      style="width: <?php echo min(100, $row['percent']); ?>%;"></span>
                                            </div>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td></td>
                                <td>Total</td>
                                <td><?php echo (int)$reportTotals['investment_count']; ?></td>
                                <td>Rs. <?php echo number_format($reportTotals['total_amount'], 2); ?></td>
                                <td>Rs. <?php echo number_format($reportTotals['target'], 2); ?></td>
                                <td><?php echo (int)$reportTotals['achieved']; ?> achieved / <?php echo (int)$reportTotals['not_achieved']; ?> not</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            </div>
        <?php elseif (!$showTargetList): ?>
            <div class="empty-state" id="emptyHint">
                <p>Select a branch to begin. Optionally choose a month for month-wise details.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('branch').addEventListener('change', function () {
    var viewBtn = document.getElementById('viewBtn');
    if (viewBtn) {
        viewBtn.disabled = !this.value;
    }
    var targetWrap = document.getElementById('targetWrap');
    var resultsWrap = document.getElementById('resultsWrap');
    if (targetWrap) targetWrap.style.display = 'none';
    if (resultsWrap) resultsWrap.style.display = 'none';
});

document.getElementById('month').addEventListener('change', function () {
    var reportMonth = document.getElementById('reportMonth');
    if (reportMonth) {
        reportMonth.value = this.value;
    }

    var reportForm = document.getElementById('reportForm');
    if (!reportForm) {
        return;
    }

    var filled = 0;
    reportForm.querySelectorAll('.target-input').forEach(function (input) {
        if (Number(input.value) > 0) {
            filled++;
        }
    });
    if (filled > 0) {
        reportForm.submit();
    }
});

document.getElementById('filterForm').addEventListener('submit', function (e) {
    var branch = document.getElementById('branch').value;
    if (!branch) {
        e.preventDefault();
        document.getElementById('branch').focus();
    }
});

var reportForm = document.getElementById('reportForm');
if (reportForm) {
    reportForm.addEventListener('submit', function (e) {
        var monthField = document.getElementById('reportMonth');
        var monthSelect = document.getElementById('month');
        if (monthField && monthSelect) {
            monthField.value = monthSelect.value;
        }

        var inputs = reportForm.querySelectorAll('.target-input');
        var filled = 0;
        inputs.forEach(function (input) {
            if (Number(input.value) > 0) {
                filled++;
            }
        });
        if (filled === 0) {
            e.preventDefault();
            alert('Enter at least one sales person target before getting the report.');
            if (inputs[0]) {
                inputs[0].focus();
            }
        }
    });
}
</script>
</body>
</html>
