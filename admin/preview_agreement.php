<?php
require_once '../config.php';
require_once '../db.php';
require_once dirname(__DIR__) . '/includes/agreement_helpers.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Agreement-Engine: hpp-premium-v2');

session_start();

// Same auth style as other admin pages
if (!isset($_SESSION['user_id']) && !isset($_SESSION['approver_id']) && !isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit();
}

$agreementId = (int)($_GET['id'] ?? $_GET['agreement_id'] ?? 0);
if ($agreementId <= 0) {
    header('Location: agreements.php');
    exit();
}

try {
    $row = fetchAgreementWithInvestor($db, $agreementId);
    if (!$row) {
        header('Location: agreements.php');
        exit();
    }

    try {
        $generatedAt = $row['generated_at'] ?? null;
        $row['formatted_generated'] = $generatedAt
            ? (new DateTime($generatedAt))->format('F d, Y H:i')
            : '';
    } catch (Throwable $t) {
        $row['formatted_generated'] = '';
    }

    // Increment view count if column exists (ignore failure)
    try {
        $db->prepare("UPDATE agreements SET view_count = COALESCE(view_count, 0) + 1 WHERE id = ?")->execute([$agreementId]);
    } catch (Throwable $t) {}

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

function getInvestmentTypeNamePdfStyle($type) {
    return getInvestmentTypeDisplayName((string)$type, true);
}

function fmtMoney($amount, $currency = 'LKR') {
    $n = is_numeric($amount) ? (float)$amount : 0.0;
    $cur = strtoupper(trim((string)$currency));
    $prefix = $cur === 'LKR' ? 'LKR ' : ($cur !== '' ? ($cur . ' ') : '');
    return $prefix . number_format($n, 2);
}

function fmtDateDMY($date) {
    if (!$date) return '';
    try {
        return (new DateTime($date))->format('d / m / Y');
    } catch (Throwable $t) {
        return (string)$date;
    }
}

function fmtDateDMYTable(DateTime $dt): string {
    return $dt->format('d/m/Y');
}

function fmtMoneyCell(?float $amount, string $currency): string {
    if ($amount === null) {
        return '';
    }
    return fmtMoney($amount, $currency);
}

function fmtDateLong($date) {
    if (!$date) return '';
    try {
        return (new DateTime($date))->format('jS F Y');
    } catch (Throwable $t) {
        return (string)$date;
    }
}

function numberToWords($number) {
    $number = (int)$number;
    if ($number === 0) return 'Zero';

    $ones = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'
    ];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $words = '';
    $parts = [
        1000000000 => 'Billion',
        1000000 => 'Million',
        1000 => 'Thousand',
        100 => 'Hundred',
    ];

    foreach ($parts as $value => $name) {
        if ($number >= $value) {
            $count = (int)floor($number / $value);
            $number %= $value;
            $words .= numberToWords($count) . ' ' . $name . ' ';
        }
    }

    if ($number >= 20) {
        $words .= $tens[(int)floor($number / 10)] . ' ';
        $number %= 10;
    }

    if ($number > 0) {
        $words .= $ones[$number] . ' ';
    }

    return trim(preg_replace('/\s+/', ' ', $words));
}

function moneyToWordsLKR($amount) {
    $n = is_numeric($amount) ? (float)$amount : 0.0;
    $int = (int)floor($n);
    return numberToWords($int) . ' Only';
}

function fmtMoneyRs($amount) {
    $n = is_numeric($amount) ? (float)$amount : 0.0;
    return 'Rs.' . number_format($n, 2);
}

$ctx = buildAgreementPresentationContext($db, $agreementId, $row);
if (!$ctx) {
    header('Location: agreements.php');
    exit();
}

$agreementNo = (string)$ctx['agreement_number'];
$investmentType = (string)$ctx['investment_type'];
$productName = (string)$ctx['product_name'];
$fullName = (string)$ctx['full_name'];
$nameWithInitials = (string)$ctx['name_with_initials'];
$address = (string)$ctx['address'];
$nic = (string)$ctx['nic_no'];
$commence = $ctx['commence'];
$terminate = $ctx['terminate'];
$currency = (string)$ctx['currency'];
$deposit = (float)$ctx['deposit'];
$isHpp = (bool)$ctx['is_hpp'];
$isHppPremium = (bool)$ctx['is_hpp_premium'];
$monthlyReturn = (float)$ctx['monthly_return'];
$months = (int)$ctx['months'];
$maturityTotal = (float)$ctx['maturity_total'];
$paymentSchedule = $ctx['payment_schedule'];
$monthlyRatePercent = (float)$ctx['monthly_rate_percent'];
$landSizeLabel = (string)$ctx['land_size_label'];
$years = (int)$ctx['years'];
$yearsPadded = (string)$ctx['years_padded'];
$yearsWord = (string)$ctx['years_word'];
$maturityTermLabel = (string)$ctx['maturity_term_label'];
$beneficiaries = is_array($ctx['beneficiaries'] ?? null) ? $ctx['beneficiaries'] : [];
$beneficiaryName = (string)$ctx['beneficiary_name'];
$beneficiaryLabel = count($beneficiaries) > 1 ? 'Names of the beneficiaries' : 'Name of the beneficiary';
$autoPrint = (($_GET['print'] ?? '') === '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agreement Preview | <?php echo htmlspecialchars($agreementNo); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --text: #0b1220;
            --muted: #475569;
            --border: #e2e8f0;
            --paper: #ffffff;
            --bg: #f1f5f9;
            --ink: #111827;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: var(--bg); color: var(--text); padding: 24px; }

        .topbar {
            max-width: 980px;
            margin: 0 auto 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            position: sticky;
            top: 12px;
            z-index: 10;
            background: rgba(241, 245, 249, .85);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(226, 232, 240, .7);
            border-radius: 14px;
            padding: 12px 12px;
        }
        .topbar .left { display: flex; flex-direction: column; gap: 4px; }
        .title { font-size: 18px; font-weight: 900; }
        .subtitle { font-size: 13px; color: var(--muted); }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: white;
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
        }
        .btn.primary { background: #111; border-color: #111; color: white; }

        .paper {
            max-width: 980px;
            margin: 0 auto;
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, .08);
            overflow: hidden;
        }

        .doc { padding: 0 16mm 18mm; font-size: 9pt; }
        .page { page-break-after: always; }
        .page:first-child { padding-top: 3.6cm; }
        .page:not(:first-child) { padding-top: 16mm; }
        .page:last-child { page-break-after: auto; }
        .h1 { font-weight: bold; font-size: 10pt; text-align: right; margin-bottom: 6pt; color: var(--ink); }
        .h2 { font-weight: 900; font-size: 10pt; margin: 8pt 0 6pt; letter-spacing: .6px; color: var(--ink); text-decoration: underline; }
        .muted { margin: 2pt 0 10pt; color: var(--muted); font-size: 10pt; }
        .section { margin-top: 1pt; }
        .sec-title { margin-bottom: 6pt; font-size: 10pt; }

        /* Investor details only — centered on page */
        .investor-section {
            margin-bottom: 3pt;
        }
        .investor-section .sec-title {
            text-align: center;
        }
        .investor-section table.kv {
            margin-left: auto;
            margin-right: auto;
            max-width: 560px;
        }
        table.kv { width: 100%; border-collapse: collapse; font-size: 10pt; }
        table.kv td { padding: 2pt 0; vertical-align: top; }
        table.kv td.k { width: 44%; color: var(--ink); }
        table.kv td.v { width: 56%; }
        table.kv:has(td.sep) td.k {
            width: auto;
            text-align: left;
            padding-right: 6pt;
        }
        table.kv td.sep {
            width: 1%;
            white-space: nowrap;
            text-align: center;
            padding: 2pt 4pt;
            color: var(--ink);
        }
        table.kv:has(td.sep) td.v {
            width: auto;
            text-align: left;
            padding-left: 6pt;
        }

        table.tbl { width: 100%; border-collapse: collapse; margin-top: 12pt; }
        table.tbl th, table.tbl td { border: 1px solid #111827; padding: 3pt 4pt; text-align: center; font-size: 10pt; }
        table.tbl thead th { font-weight: 900; background: #f8fafc; }
        table.tbl tbody tr:nth-child(odd) td { background: #ffffff; }
        table.tbl tbody tr:nth-child(even) td { background: #fbfdff; }
        tr.total td { background: #f1f5f9 !important; }

        .schedule-end { margin-top: 0; }
        .sign-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 24pt;
            margin-top: 26pt;
        }
        .sig {
            width: 45%;
            text-align: center;
            font-weight: 800;
        }
        .sig-space {
            min-height: 58pt;
            border-bottom: 1px solid #333;
        }
        .sig-purchaser .sig-space {
            min-height: 84pt; /* room for purchaser seal/stamp */
        }
        .sig-label { padding-top: 9pt; }
        .center { text-align: center; }
        .doc-title { font-weight: 900; font-size: 10pt; margin-bottom: 2pt; text-transform: uppercase; }
        .para { margin-top: 2pt; line-height: 1.5; text-align: justify; font-size: 10pt; }
        .ol {
            margin: 2pt 0 0 0;
            padding-left: 2.75em;
            list-style-type: decimal;
            list-style-position: outside;
            line-height: 1.5;
            font-size: 10pt;
        }
        .ol li {
            padding-left: 0.4em;
            margin-bottom: 2pt;
            text-align: justify;
        }
        .ol li::marker {

        }
        .sign-block { margin-top: 10pt; }
        .sign-cols { display: flex; justify-content: space-between; gap: 20pt; margin-top: 10pt; }
        .sign-col { width: 50%; }
        .sign-title { font-weight: 900; text-align: center; }
        .line { border-top: 1px solid #333; margin-top: 60pt; }
        .witness { margin-top: 18pt; }

        @media print {
            @page { size: A4; margin: 16mm 14mm; }
            @page :first { margin-top: 3.6cm; }
            body { background: white; padding: 0; color: #000; }
            .topbar { display: none !important; }
            .paper {
                border: none;
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
            }
            .doc {
                padding: 0;
                font-size: 10pt;
                font-family: Georgia, "Times New Roman", Times, serif;
            }
            .page { page-break-inside: auto; }
            .page:first-child { padding-top: 0; }
            .page:not(:first-child) { padding-top: 0; }
            .h1 { margin-bottom: 4pt; }
            .h2 { margin: 4pt 0 4pt; }
            .muted { margin: 1pt 0 6pt; }
            .sec-title { margin-bottom: 4pt; }
            .investor-section { margin-bottom: 2pt; }
            table.kv td { padding: 1.5pt 0; }

            /* Keep payment table + signatures on page 1 (do not start a new page) */
            .schedule-end {
                page-break-before: avoid;
                break-before: avoid-page;
                page-break-inside: avoid;
                break-inside: avoid-page;
                page-break-after: avoid;
                break-after: avoid-page;
            }
            table.tbl {
                margin-top: 8pt;
                page-break-before: avoid;
                break-before: avoid-page;
                page-break-after: avoid;
                break-after: avoid-page;
                page-break-inside: avoid;
                break-inside: avoid-page;
            }
            table.tbl th, table.tbl td { padding: 2.5pt 3pt; }
            table.tbl tr { page-break-inside: avoid; }
            .sign-row {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                margin-top: 18pt;
                page-break-before: avoid;
                break-before: avoid-page;
                page-break-inside: avoid;
                break-inside: avoid-page;
            }
            .sig { width: 45% !important; }
            .sig-space { min-height: 46pt; }
            .sig-purchaser .sig-space { min-height: 64pt; }
            .ol {
                padding-left: 3em;
                margin-left: 0;
                list-style-position: outside;
            }
            .ol li {
                padding-left: 0.5em;
                page-break-inside: avoid;
            }
            table.tbl th, table.tbl td { border-color: #000; }
            table.tbl thead th { background: #fff; }
            table.tbl tbody tr:nth-child(even) td,
            table.tbl tbody tr:nth-child(odd) td { background: #fff; }
        }
        @media screen and (max-width: 720px) {
            .doc { padding: 22px 16px; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .sign-row, .sign-cols { flex-direction: column; }
            .sig, .sign-col { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="left">
            <div class="title">Agreement Preview</div>
            <div class="subtitle">
                <?php echo htmlspecialchars($agreementNo); ?> • <?php echo htmlspecialchars($productName); ?>
                <?php if ($isHppPremium): ?> • Premium HPP (<?php echo htmlspecialchars(rtrim(rtrim(number_format($monthlyRatePercent, 2), '0'), '.')); ?>% / <?php echo htmlspecialchars($yearsPadded); ?> yrs)<?php elseif ($isHpp): ?> • Standard HPP<?php endif; ?>
                • Generated <?php echo htmlspecialchars($row['formatted_generated'] ?? ''); ?>
            </div>
        </div>
        <div class="actions">
            <a class="btn" href="view_agreement.php?id=<?php echo $agreementId; ?>">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a class="btn" href="generate_agreement_pdf.php?id=<?php echo $agreementId; ?>" target="_blank">
                <i class="fas fa-file-pdf"></i> Download PDF (H0345)
            </a>
            <a class="btn primary" href="preview_agreement.php?id=<?php echo $agreementId; ?>&print=1" target="_blank">
                <i class="fas fa-print"></i> Print Agreement
            </a>
            <button class="btn" type="button" onclick="window.print()">
                <i class="fas fa-print"></i> Print This Page
            </button>
        </div>
    </div>

    <div class="paper">
        <div class="doc">
            <!-- Page 1: SCHEDULE -->
            <div class="page">
                <div class="h1">Agreement Number: <?php echo htmlspecialchars($agreementNo); ?></div>
                <div class="h2"><center>SCHEDULE</center></div>
                <div class="muted"><strong><b>PRODUCT :</strong> (<?php echo htmlspecialchars($productName); ?>)</b></div>
                <div class="sec-title">1. Personal Details of the Purchaser :</div>
                <div class="section investor-section">
                  
                    <table class="kv">
                        <tr><td class="k">Full Name</td><td class="sep">:</td><td class="v"><strong><?php echo htmlspecialchars($fullName); ?></strong></td></tr>
                        <tr><td class="k">Name with initials</td><td class="sep">:</td><td class="v"><?php echo htmlspecialchars($nameWithInitials); ?></td></tr>
                        <tr><td class="k">Address</td><td class="sep">:</td><td class="v"><?php echo nl2br(htmlspecialchars($address)); ?></td></tr>
                        <tr><td class="k">NIC No</td><td class="sep">:</td><td class="v"><?php echo htmlspecialchars($nic); ?></td></tr>
                        <tr>
                            <td class="k"><?php echo htmlspecialchars($beneficiaryLabel); ?></td>
                            <td class="sep">:</td>
                            <td class="v">
                                <?php if (count($beneficiaries) > 1): ?>
                                    <?php foreach ($beneficiaries as $beneficiary): ?>
                                        <?php $bName = trim((string)($beneficiary['beneficiary_name'] ?? '')); ?>
                                        <?php if ($bName === '') { continue; } ?>
                                        <div><?php echo htmlspecialchars($bName); ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($beneficiaryName); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="section">
                    <table class="kv">
                        <tr><td class="k">2. Date of.Commencement of this agreement</td><td class="sep">:</td><td class="v"><?php echo htmlspecialchars(fmtDateDMY($commence->format('Y-m-d'))); ?></td></tr>
                        <tr><td class="k">3. Date of Termination of this agreement</td><td class="sep">:</td><td class="v"><?php echo htmlspecialchars(fmtDateDMY($terminate->format('Y-m-d'))); ?></td></tr>
                        <tr><td class="k">4. Total deposit amount</td><td class="sep">:</td><td class="v"><?php echo htmlspecialchars(fmtMoney($deposit, $currency)); ?> (<?php echo htmlspecialchars(moneyToWordsLKR($deposit)); ?>)</td></tr>
                        <tr><td class="k">5. Maturity Term</td><td class="sep">:</td><td class="v"><?php echo htmlspecialchars($maturityTermLabel); ?></td></tr>
                        <tr><td class="k">6. Paying Term</td><td class="sep">:</td><td class="v"><?php echo htmlspecialchars((string)$months); ?> Months</td></tr>
                        <tr><td class="k">7. Monthly return amount</td><td class="sep">:</td><td class="v"><?php echo htmlspecialchars(fmtMoney($monthlyReturn, $currency)); ?></td></tr>
                        <?php if ($isHppPremium): ?>
                        <tr><td class="k">8. Size of the Land</td><td class="sep">:</td><td class="v"><?php echo htmlspecialchars($landSizeLabel); ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="schedule-end">
                    <div class="section">
                        <table class="tbl">
                            <thead>
                            <tr>
                                <th style="width:8%">Year</th>
                                <th style="width:20%">Total Payment</th>
                                <th style="width:20%">Yearly Return</th>
                                <th style="width:20%">Total Return</th>
                                <th style="width:20%">Maturity</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($paymentSchedule['rows'] as $scheduleRow): ?>
                                <?php
                                $periodDate = $scheduleRow['period_date'];
                                $paymentCell = $scheduleRow['total_payment'] !== null
                                    ? htmlspecialchars(fmtMoney($scheduleRow['total_payment'], $currency))
                                    : '';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(fmtDateDMYTable($periodDate)); ?></td>
                                    <td><?php echo $paymentCell; ?></td>
                                    <td><?php echo htmlspecialchars(fmtMoneyCell($scheduleRow['yearly_return'], $currency)); ?></td>
                                    <td><?php echo htmlspecialchars(fmtMoneyCell($scheduleRow['total_return'], $currency)); ?></td>
                                    <td><?php echo htmlspecialchars(fmtMoneyCell($scheduleRow['maturity'], $currency)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total">
                                <td></td>
                                <td></td>
                                <td></td>
                                <td><strong>Total</strong></td>
                                <td><strong><?php echo htmlspecialchars(fmtMoney($maturityTotal, $currency)); ?></strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="sign-row">
                        <div class="sig">
                            <div class="sig-space"></div>
                            <div class="sig-label">Vendor Signature</div>
                        </div>
                        <div class="sig sig-purchaser">
                            <div class="sig-space"></div>
                            <div class="sig-label">Purchaser Signature</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Page 2: Agreement -->
            <div class="page">
                <div class="">
                    <div class="doc-title">AGREEMENT TO INVEST IN SEASONAL CROP CULTIVATION.</div>
                    <div class="muted">Agreement No :<strong><?php echo htmlspecialchars($agreementNo); ?></strong></div>
                </div>

                <?php if ($isHppPremium): ?>
                <div class="para">
                    This agreement was made and entered into on <?php echo htmlspecialchars(fmtDateLong($commence->format('Y-m-d'))); ?> by and between the
                    SERENDIB GREEN PLANTATION (PVT) LIMITED ( Company registration Number PV 00302881 ) Located in No 87, Pannipitiya
                    Road, Battaramulla. Which is a duly incorporated company under the Companies Act No. 07 of 2007 of the Democratic Socialist Republic of Sri Lanka
                    hereinafter called the "Cultivator"(which term or expression as herein used shall where the context so requires or admits mean and include SERENDIB GREEN PLANTATION (PVT) LIMITED. and its successors and permitted assigns) of the ONE PART and
                    <strong><?php echo htmlspecialchars($fullName); ?></strong> Holder of National Identity Card No. <strong><?php echo htmlspecialchars($nic); ?></strong> Of <strong><?php echo htmlspecialchars($address); ?></strong>
                    In the said Republic of Lanka here in after called and referred to as the <strong>"Investor"</strong><?php if ($beneficiaryName !== ''): ?>(which term or expression as herein used shall, where the context so requires or admits mean and include the said <strong><?php echo htmlspecialchars($beneficiaryName); ?></strong> and his/heirs, executors' administrators, and assigns)<?php endif; ?> of the OTHER PART.
                </div>
                <?php else: ?>
                <div class="para">
                This agreement was made and entered into on <?php echo htmlspecialchars(fmtDateLong($commence->format('Y-m-d'))); ?> by and between the
                    SERENDIB GREEN PLANTATION (PVT) LIMITED ( Company registration Number PV 00302881 ) Located in No 87, Pannipitiya
                    Road, Battaramulla. Which is a duly incorporated company under the Companies Act No. 07 of 2007 of the Democratic Socialist Republic of Sri Lanka
                    hereinafter called the "Cultivator"(which term or expression as herein used shall where the context so requires or admits mean and include SERENDIB GREEN PLANTATION (PVT) LIMITED. and its successors and permitted assigns) of the ONE PART and
                    <strong><?php echo htmlspecialchars($fullName); ?></strong> Holder of National Identity Card No. <strong><?php echo htmlspecialchars($nic); ?></strong> Of <strong><?php echo htmlspecialchars($address); ?></strong>
                    In the said Republic of Lanka here in after called and referred to as the <strong>"Investor"</strong><?php if ($beneficiaryName !== ''): ?>(which term or expression as herein used shall, where the context so requires or admits mean and include the said <strong><?php echo htmlspecialchars($beneficiaryName); ?></strong> and his/heirs, executors' administrators, and assigns)<?php endif; ?> of the OTHER PART.
                </div>
                <?php endif; ?>

                <ol class="ol">
                    <li>WHEREAS the Cultivator above name is carrying out a business of cultivating seasonal crops on its owned lands and the lands it has taken on lease.</li>
                    <li>AND WHEREAS the Investor above-named has agreed with the Cultivator to make an investment on the aforesaid business of the Cultivator.</li>
                </ol>

                <div class="para">NOW both parties hereby agree to act subject to the following terms and conditions;</div>
                <?php if ($isHppPremium): ?>
                <ol class="ol" start="3">
                    <li>The Investor hereby agrees to make the investment as set out in the schedule annexed hereto for a period of <strong><?php echo htmlspecialchars($yearsWord); ?>(<?php echo htmlspecialchars($yearsPadded); ?>)</strong> years.</li>
                    <li>The Cultivator agrees to pay a monthly benefit of <strong><?php echo htmlspecialchars(rtrim(rtrim(number_format($monthlyRatePercent, 2), '0'), '.')); ?>%</strong> to the Investor against the total amount made by him within the said period of <?php echo htmlspecialchars($yearsWord); ?>  years.</li>
                    <li>The Cultivator agrees to repay the total sum of the investment to the Investor at the expiry of the said <?php echo htmlspecialchars($yearsWord); ?>-years period.</li>
                    <li>However, if the investor requires to get his investment refunded before the expiration of the agreed period, he may do so after the expiry of one year from the date of the investment and upon giving one month notice to Cultivator.</li>
                    <li>The customer can get the corresponding monthly allowance for the deposit amount for <?php echo htmlspecialchars($yearsWord); ?> Years.</li>
                    <li>The Cultivator hereby acknowledges and agrees with the Investor that, in consideration of the amount of <strong><?php echo htmlspecialchars($landSizeLabel); ?></strong> specified under Item No. 8 of the Payment Schedule, the Cultivator shall provide a land document within a period of not less than six (06) months and not exceeding eight (08) months from the date of such payment. The land so allocated shall be from any location owned by the company, and such allocation shall serve as a guarantee. The applicable terms and conditions relating to the land policy shall be expressly set out in the said land document.</li>
                    <li>In the process of retrieving the customer’s money, he must give a notification by letter or Email that he/she wants to retrieve his money one month in advance.</li>
                    <li>If the customer wishes to cancel the policy, they may do so after one year. Upon cancellation, the policy will be recalculated as a one-year investment plan. The company will process the withdrawal of funds after deducting any additional benefit that has been previously paid.</li>
                    <li>After the expiry of the period of investment, the Investor may re-invest the said money upon the fresh terms and conditions mutually agreed between the parties.</li>
                    <li>The cultivator hereby further agrees and promises to settle the total amount of said investment together with the agreed monthly benefit to the Investor within the agreed period of <?php echo htmlspecialchars($yearsWord); ?> years.</li>
                    
                </ol>
                <?php elseif ($isHpp): ?>
                <ol class="ol" start="3">
                    <li>The Investor hereby agrees to make the investment as set out in the schedule annexed hereto for a period of <strong><?php echo htmlspecialchars($yearsWord); ?>(<?php echo htmlspecialchars($yearsPadded); ?>)</strong> years.</li>
                    <li>The Cultivator agrees to pay a monthly benefit of <strong><?php echo htmlspecialchars(rtrim(rtrim(number_format($monthlyRatePercent, 2), '0'), '.')); ?>%</strong> to the Investor against the total amount made by him within the said period of <?php echo htmlspecialchars($yearsWord); ?> years.</li>
                    <li>The Cultivator agrees to repay the total sum of the investment to the Investor at the expiry of the said <?php echo htmlspecialchars($yearsWord); ?> years period.</li>
                    <li>However, if the investor requires to get his investment refunded before the expiration of the agreed period, he may do so after the expiry of one year from the date of the investment and upon giving one month notice to Cultivator.</li>
                    <li>The customer can get the corresponding monthly allowance for the deposit amount for <?php echo htmlspecialchars($yearsWord); ?> Years.</li>
                    <li>After the commencement of the agreement, If the customer wants to get back his deposit, he can do so after a period of one year from the date of commencement of the agreement.</li>
                    <li>In the process of retrieving the customer’s money, he must give a notification by letter or Email that he/she wants to retrieve his money one month in advance.</li>
                    <li>If the customer wishes to cancel the policy, they may do so after one year. Upon cancellation, the policy will be recalculated as a one-year investment plan. The company will process the withdrawal of funds after deducting any additional benefit that has been previously paid.</li>
                    <li>In this plan, the customer will only receive an agreement and receipt from our company and will not be given a deed.</li>
                    <li>After the expiry of the period of investment, the Investor may re-invest the said money upon the fresh terms and conditions mutually agreed between the parties.</li>
                    <li>The Investor hereby admits and acknowledges that the Cultivator is not bound to provide any deed of transfer against the lands used for cultivation other than this agreement since this investment is made for the short-term seasonal crops and not for any long-term cultivation purposes.</li>
                </ol>
                <?php else: ?>
                <div class="para">This agreement preview is not available for the selected investment plan. Please use the plan-specific agreement template.</div>
                <?php endif; ?>
            </div>

            <?php if ($isHppPremium || $isHpp): ?>
            <!-- Page 3: Agreement clauses 14+ -->
            <div class="page">
                <?php if ($isHppPremium): ?>
                <ol class="ol" start="13">

<li>In the event of arising out of or after the termination of the agreement, the agreement shall be referred to arbitration in Sri Lanka in accordance with the laws of Sri Lanka with respect to this agreement. Its contents or any other aspect thereof. It is further agreed that it should be held in Colombo District.</li>
                    <li>The Investor may nominate a person/s as a beneficiary/rise as referred to in item No. 1(V) of the schedule hereof for the purpose of receiving the benefit of the investment only on the death of the Investor.</li>
                    <li>If the Investor dies during the term of this agreement, the Cultivator pays the stated benefits to the beneficiary nominated by him.</li>
                    <li>The parties hereto further agree that any notice under this agreement is sent under the registered post to the aforementioned address of either party, it is duly served.</li>
                    <li>Further, both parties hereto agree to notify the other party of any change of their addresses mentioned above.</li>
                    <li>The parties agree that this agreement shall be in accordance with the laws of Sri Lanka.</li>
                    <li>It was agreed that both parties had unanimously agreed to all the above conditions and that this agreement was signed at Colombo on the date mentioned above.</li>
                    <li><strong><?php echo htmlspecialchars(fmtMoneyRs($deposit)); ?></strong> invested by the investor for <strong><?php echo htmlspecialchars((string)$years); ?> years</strong> at a <strong><?php echo htmlspecialchars(rtrim(rtrim(number_format($monthlyRatePercent, 2), '0'), '.')); ?>%</strong> benefit. In case of any natural or any kind of damage or accident to the plantation or the company, the investor will get the full sum assured (<strong><?php echo htmlspecialchars(fmtMoneyRs($deposit)); ?></strong>) back.</li>
                    <li>Both parties hereto, along with their respective heirs, executors, and administrators, agree to be bound by the terms and conditions set forth herein. In the event of any dispute arising under this agreement, the parties reserve the right to initiate legal proceedings in a competent court of law, following the conclusion of the arbitration process as prescribed herein.</li>
                </ol>
                <?php else: ?>
                <ol class="ol" start="14">
                
                    <li>The cultivator hereby further agrees and promises to settle the total amount of said investment together with the agreed monthly benefit to the Investor within the agreed period of <strong><?php echo htmlspecialchars($yearsWord); ?>(<?php echo htmlspecialchars($yearsPadded); ?>)</strong> years.</li>
                    <li>In the event of arising out of or after the termination of the agreement, the agreement shall be referred to arbitration in Sri Lanka in accordance with the laws of Sri Lanka with respect to this agreement. Its contents or any other aspect thereof. It is further agreed that it should be held in Colombo District.</li>
                    <li>The Investor may nominate a person/s as a beneficiary/rise as referred to in item No. 1(V) of the schedule hereof for the purpose of receiving the benefit of the investment only on the death of the Investor.</li>
                    <li>If the Investor dies during the term of this agreement, the Cultivator pays the stated benefits to the beneficiary nominated by him.</li>
                    <li>The parties hereto further agree that any notice under this agreement is sent under the registered post to the aforementioned address of either party, it is duly served.</li>
                    <li>Further, both parties hereto agree to notify the other party of any change of their addresses mentioned above.</li>
                    <li>The parties agree that this agreement shall be in accordance with the laws of Sri Lanka.</li>
                    <li>It was agreed that both parties had unanimously agreed to all the above conditions and that this agreement was signed at Colombo on the date mentioned above.</li>
                    <li><strong><?php echo htmlspecialchars(fmtMoneyRs($deposit)); ?></strong> invested by the investor for <strong><?php echo htmlspecialchars((string)$years); ?> year<?php echo $years > 1 ? 's' : ''; ?></strong> at a <strong><?php echo htmlspecialchars(rtrim(rtrim(number_format($monthlyRatePercent, 2), '0'), '.')); ?>%</strong> benefit. In case of any natural or any kind of damage or accident to the plantation or the company, the investor will get the full sum assured (<strong><?php echo htmlspecialchars(fmtMoneyRs($deposit)); ?></strong>) back.</li>
                    <li>Both parties hereto, along with their respective heirs, executors, and administrators, agree to be bound by the terms and conditions set forth herein. In the event of any dispute arising under this agreement, the parties reserve the right to initiate legal proceedings in a competent court of law, following the conclusion of the arbitration process as prescribed herein.</li>
                </ol>
                <?php endif; ?>
                <div class="sign-block">
                    <div class="sign-cols">
                        <div class="sign-col">
                           <br> <div class="sign-title">CULTIVATOR</div><br>
                            <div class="line"></div>
                        </div>
                        <div class="sign-col">
                           <br> <div class="sign-title">INVESTOR</div><br>
                            <div class="line"></div>
                        </div>
                    </div>
                    <br>
                    <div class="witness">
                        <div><strong>WITNESSES:-</strong></div><br><br><br>
                        <div>1.</div>
                        <br><br><div>2.</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
<?php if ($autoPrint): ?>
<script>
    window.addEventListener('load', function () {
        window.print();
    });
</script>
<?php endif; ?>
</body>
</html>

