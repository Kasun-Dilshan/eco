<?php

function agreementTemplateCodeForInvestmentType(string $investmentType): string
{
    $type = strtoupper(trim($investmentType));
    return $type !== '' ? $type : 'HPP';
}

function isHppInvestmentType(string $investmentType): bool
{
    return agreementTemplateCodeForInvestmentType($investmentType) === 'HPP';
}

function resolveAgreementDisplayNumber(array $row, ?int $agreementId = null): string
{
    $investorAgreementNo = trim((string)($row['agreement_no'] ?? ''));
    if ($investorAgreementNo !== '') {
        return $investorAgreementNo;
    }

    $agreementNo = trim((string)($row['agreement_number'] ?? ''));
    $investmentType = agreementTemplateCodeForInvestmentType((string)($row['investment_type'] ?? 'HPP'));
    $isHpp = isHppInvestmentType($investmentType);
    $resolvedAgreementId = $agreementId ?? (int)($row['agreement_id'] ?? $row['id'] ?? 0);

    if ($isHpp && ($agreementNo === '' || !isHppStyleAgreementNumber($agreementNo))) {
        return formatHppAgreementNumber($resolvedAgreementId);
    }

    return $agreementNo;
}

function formatHppAgreementNumber(int $agreementId, ?int $year = null): string
{
    $year = $year ?? (int)date('Y');
    return $year . '/H' . str_pad((string)$agreementId, 4, '0', STR_PAD_LEFT);
}

function isHppStyleAgreementNumber(string $agreementNumber): bool
{
    return (bool)preg_match('/^\d{4}\/H\d{4}$/i', trim($agreementNumber));
}

function getInvestmentTypeDisplayName(string $investmentType, bool $forAgreement = false): string
{
    $type = agreementTemplateCodeForInvestmentType($investmentType);
    $pdfNames = [
        'HPP' => 'High-Profit Investment Plan',
        'GSP' => 'Green saving plan',
        'GSI' => 'Green silver plan',
        'GOLD' => 'Gold plan',
        'SFPS' => 'Seraa farm profit share plan',
        'SFHPS' => 'Seraa farm high profit share plan',
    ];
    $shortNames = [
        'HPP' => 'High profit plan',
        'GSP' => 'Green saving plan',
        'GSI' => 'Green silver plan',
        'GOLD' => 'Gold plan',
        'SFPS' => 'Seraa farm profit share plan',
        'SFHPS' => 'Seraa farm high profit share plan',
    ];

    if ($forAgreement) {
        return $pdfNames[$type] ?? $pdfNames['HPP'];
    }

    return $shortNames[$type] ?? 'Unknown';
}

/** Minimum deposit (LKR) for HPP long-term / premium agreement. */
function hppPremiumMinimumAmount(): float
{
    return 2500000.0;
}

/** Minimum years for HPP long-term / premium agreement. */
function hppPremiumMinimumYears(): int
{
    return 4;
}

/** HPP premium tier: Rs. 2,500,000+ and 4+ years — premium template + land schedule (rate by term). */
function isHppPremiumAgreement(float $deposit, int $years, string $investmentType = 'HPP'): bool
{
    if (!isHppInvestmentType($investmentType)) {
        return false;
    }

    return $deposit >= hppPremiumMinimumAmount() && $years >= hppPremiumMinimumYears();
}

function parseAgreementAmount(mixed $value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_numeric($value)) {
        return (float)$value;
    }

    $clean = preg_replace('/[^\d.]/', '', (string)$value);

    return ($clean !== '' && is_numeric($clean)) ? (float)$clean : 0.0;
}

function fetchAgreementWithInvestor(PDO $db, int $agreementId): ?array
{
    $stmt = $db->prepare("
        SELECT
            a.id AS agreement_id,
            a.investor_id,
            a.agreement_number,
            a.agreement_type,
            a.status AS agreement_status,
            a.template,
            a.generated_by,
            a.generated_at,
            a.sent_at,
            a.signed_at,
            a.expiration_date,
            a.download_count,
            a.view_count,
            i.full_name,
            i.name_with_initials,
            i.nic_no,
            i.tel_no,
            i.dob,
            i.email,
            i.address,
            i.occupation,
            i.employer_name,
            i.years,
            i.investment_type,
            i.investment_amount,
            i.currency,
            i.signing_date,
            i.declaration_date,
            i.bank_name,
            i.branch_name,
            i.account_no,
            i.agreement_no
        FROM agreements a
        INNER JOIN investors i ON a.investor_id = i.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$agreementId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** Monthly benefit rate by investment plan (2.5% = 0.025). HPP standard uses {@see hppStandardMonthlyRateByYears}. */
function agreementMonthlyRateForInvestmentType(string $investmentType): float
{
    switch (agreementTemplateCodeForInvestmentType($investmentType)) {
        case 'GSP':
        case 'GSI':
        case 'GOLD':
        case 'SFPS':
        case 'SFHPS':
            return 0.025;
        default:
            return 0.025;
    }
}

/** Standard HPP monthly benefit rate by agreement term (years). */
function hppStandardMonthlyRateByYears(int $years): float
{
    $years = max(1, min(8, $years));

    switch ($years) {
        case 1: return 0.025;
        case 2: return 0.03;
        case 3: return 0.0325;
        case 4: return 0.035;
        case 5: return 0.04;
        case 6: return 0.0425;
        case 7: return 0.045;
        case 8: return 0.0475;
        default: return 0.025;
    }
}

/** Monthly benefit rate for a specific investment (HPP standard and premium both use term-based rates). */
function agreementMonthlyRateForInvestment(float $deposit, int $years, string $investmentType = 'HPP'): float
{
    if (isHppInvestmentType($investmentType)) {
        return hppStandardMonthlyRateByYears($years);
    }

    return agreementMonthlyRateForInvestmentType($investmentType);
}

/** Land allocation for HPP premium schedule: (amount / 1,000,000) × 5 perches. */
function agreementLandSizePerches(float $deposit): float
{
    if ($deposit <= 0) {
        return 0.0;
    }

    return ($deposit / 1000000) * 5;
}

function formatLandSizePerches(float $perches): string
{
    if ($perches <= 0) {
        return '';
    }

    $value = abs($perches - round($perches)) < 0.001
        ? (string)(int)round($perches)
        : rtrim(rtrim(number_format($perches, 2, '.', ''), '0'), '.');

    return $value . ' Perch';
}

/**
 * Single source of truth for agreement preview / print (rates, template tier, schedule).
 *
 * @return array<string, mixed>|null
 */
function buildAgreementPresentationContext(PDO $db, int $agreementId, array $row): ?array
{
    if ($agreementId <= 0 || $row === []) {
        return null;
    }

    $investmentType = agreementTemplateCodeForInvestmentType((string)($row['investment_type'] ?? 'HPP'));
    $years = (int)($row['years'] ?? 1);
    if ($years <= 0) {
        $years = 1;
    }

    $deposit = parseAgreementAmount($row['investment_amount'] ?? null);
    $currency = (string)($row['currency'] ?? 'LKR');
    $isHpp = isHppInvestmentType($investmentType);
    $isHppPremium = isHppPremiumAgreement($deposit, $years, $investmentType);
    $monthlyRate = agreementMonthlyRateForInvestment($deposit, $years, $investmentType);

    $signingDate = $row['signing_date'] ?? ($row['generated_at'] ?? date('Y-m-d'));
    $commence = new DateTime($signingDate ?: date('Y-m-d'));
    $terminate = (clone $commence)->modify('+' . $years . ' year');

    $fin = computeAgreementFinancials($deposit, $years, $monthlyRate);
    $paymentSchedule = buildAgreementPaymentSchedule($commence, $deposit, $years, $monthlyRate);

    $agreementNo = resolveAgreementDisplayNumber($row, $agreementId);

    $yearsPadded = str_pad((string)$years, 2, '0', STR_PAD_LEFT);
    $landSizePerches = $isHppPremium ? agreementLandSizePerches($deposit) : 0;
    $beneficiaries = getAgreementBeneficiaries($db, (int)($row['investor_id'] ?? 0));

    return [
        'agreement_id' => $agreementId,
        'investor_id' => (int)($row['investor_id'] ?? 0),
        'investment_type' => $investmentType,
        'is_hpp' => $isHpp,
        'is_hpp_premium' => $isHppPremium,
        'template_key' => $isHppPremium ? 'hpp_premium' : ($isHpp ? 'hpp_standard' : 'other'),
        'agreement_number' => $agreementNo,
        'product_name' => getInvestmentTypeDisplayName($investmentType, true),
        'full_name' => (string)($row['full_name'] ?? ''),
        'name_with_initials' => (string)($row['name_with_initials'] ?? ''),
        'address' => (string)($row['address'] ?? ''),
        'nic_no' => (string)($row['nic_no'] ?? ''),
        'currency' => $currency,
        'deposit' => $deposit,
        'years' => $years,
        'years_padded' => $yearsPadded,
        'years_word' => agreementYearsInWords($years),
        'maturity_term_label' => $yearsPadded . ' Year' . ($years === 1 ? '' : 's'),
        'monthly_rate' => $monthlyRate,
        'monthly_rate_percent' => $monthlyRate * 100,
        'monthly_return' => $fin['monthly_return'],
        'months' => $fin['months'],
        'total_return' => $fin['total_return'],
        'maturity_total' => $fin['maturity_total'],
        'fin' => $fin,
        'payment_schedule' => $paymentSchedule,
        'commence' => $commence,
        'terminate' => $terminate,
        'land_size_label' => $landSizePerches > 0 ? formatLandSizePerches($landSizePerches) : '',
        'beneficiaries' => $beneficiaries,
        'beneficiary_name' => formatBeneficiaryDisplayNames($beneficiaries),
        'signing_date' => $signingDate,
        'generated_at' => $row['generated_at'] ?? null,
    ];
}

function agreementYearsInWords(int $years): string
{
    static $words = [
        1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
        6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
        11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen',
    ];

    return $words[$years] ?? (string)$years;
}

function computeAgreementFinancials(float $deposit, int $years, float $monthlyRate = 0.025): array
{
    if ($years <= 0) {
        $years = 1;
    }
    $months = $years * 12;
    $monthlyReturn = $deposit * $monthlyRate;
    $yearlyReturn = $monthlyReturn * 12;
    $totalReturn = $monthlyReturn * $months;
    $maturityTotal = $deposit + $totalReturn;

    return [
        'months' => $months,
        'monthly_return' => $monthlyReturn,
        'yearly_return' => $yearlyReturn,
        'total_return' => $totalReturn,
        'maturity_total' => $maturityTotal,
    ];
}

/**
 * Year-wise payment schedule for the agreement table (deposit row + return per year + maturity).
 *
 * @return array{
 *   rows: list<array{
 *     period_date: DateTime,
 *     total_payment: ?float,
 *     yearly_return: ?float,
 *     total_return: ?float,
 *     maturity: ?float,
 *     row_type: string
 *   }>,
 *   maturity_total: float,
 *   yearly_return: float,
 *   total_return: float,
 *   monthly_return: float
 * }
 */
function buildAgreementPaymentSchedule(DateTime $commence, float $deposit, int $years, float $monthlyRate = 0.025): array
{
    if ($years <= 0) {
        $years = 1;
    }

    $fin = computeAgreementFinancials($deposit, $years, $monthlyRate);
    $yearlyReturn = $fin['yearly_return'];
    $rows = [];

    // Commencement: investor pays deposit (no returns yet)
    $rows[] = [
        'period_date' => clone $commence,
        'total_payment' => $deposit,
        'yearly_return' => null,
        'total_return' => null,
        'maturity' => null,
        'row_type' => 'deposit',
    ];

    // End of each investment year: monthly benefits paid through the year
    for ($y = 1; $y <= $years; $y++) {
        $periodEnd = (clone $commence)->modify('+' . $y . ' year');
        $totalReturnToDate = $yearlyReturn * $y;
        $isFinalYear = ($y === $years);

        $rows[] = [
            'period_date' => $periodEnd,
            'total_payment' => null,
            'yearly_return' => $yearlyReturn,
            'total_return' => $totalReturnToDate,
            'maturity' => $isFinalYear ? $deposit : null,
            'row_type' => $isFinalYear ? 'maturity' : 'return',
        ];
    }

    return [
        'rows' => $rows,
        'maturity_total' => $fin['maturity_total'],
        'yearly_return' => $yearlyReturn,
        'total_return' => $fin['total_return'],
        'monthly_return' => $fin['monthly_return'],
        'months' => $fin['months'],
    ];
}

/**
 * All nominated beneficiaries for an investor (highest share first).
 *
 * @return list<array{beneficiary_name: string, beneficiary_nic: string, percentage: mixed}>
 */
function getAgreementBeneficiaries(PDO $db, int $investorId): array
{
    if ($investorId <= 0) {
        return [];
    }

    try {
        $stmt = $db->prepare("
            SELECT beneficiary_name, beneficiary_nic, percentage
            FROM beneficiaries
            WHERE investor_id = ?
            ORDER BY percentage DESC, id ASC
        ");
        $stmt->execute([$investorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Join beneficiary names for schedule / legal text: "A", "A and B", "A, B and C". */
function formatBeneficiaryDisplayNames(array $beneficiaries): string
{
    $names = [];
    foreach ($beneficiaries as $beneficiary) {
        $name = trim((string)($beneficiary['beneficiary_name'] ?? $beneficiary));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    $names = array_values(array_unique($names));
    $count = count($names);
    if ($count === 0) {
        return '';
    }
    if ($count === 1) {
        return $names[0];
    }
    if ($count === 2) {
        return $names[0] . ' and ' . $names[1];
    }

    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

function getPrimaryBeneficiaryName(PDO $db, int $investorId): string
{
    return formatBeneficiaryDisplayNames(getAgreementBeneficiaries($db, $investorId));
}

function finalizeHppAgreementRecord(PDO $db, int $agreementId, ?string $preferredNumber = null): string
{
    $number = trim((string)($preferredNumber ?? ''));
    if ($number === '') {
        $number = formatHppAgreementNumber($agreementId);
    }
    $stmt = $db->prepare("
        UPDATE agreements
        SET agreement_number = ?, template = 'HPP', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$number, $agreementId]);
    return $number;
}

function createAgreementForInvestor(PDO $db, array $investor, string $performedBy): array
{
    $investorId = (int)($investor['id'] ?? 0);
    if ($investorId <= 0) {
        return ['success' => false, 'message' => 'Invalid investor'];
    }

    if (($investor['status'] ?? '') !== 'approved') {
        return ['success' => false, 'message' => 'Investor is not approved'];
    }

    $investmentType = agreementTemplateCodeForInvestmentType((string)($investor['investment_type'] ?? 'HPP'));
    $templateCode = $investmentType;

    $preferredAgreementNo = trim((string)($investor['agreement_no'] ?? ''));

    $existing = $db->prepare("SELECT id FROM agreements WHERE investor_id = ? ORDER BY generated_at DESC, id DESC LIMIT 1");
    $existing->execute([$investorId]);
    $existingId = (int)($existing->fetchColumn() ?: 0);
    if ($existingId > 0) {
        if (isHppInvestmentType($investmentType)) {
            $number = finalizeHppAgreementRecord($db, $existingId, $preferredAgreementNo !== '' ? $preferredAgreementNo : null);
            return [
                'success' => true,
                'message' => 'Existing agreement updated for High Profit plan',
                'agreement_id' => $existingId,
                'agreement_number' => $number,
                'created' => false,
            ];
        }

        return [
            'success' => true,
            'message' => 'Agreement already exists',
            'agreement_id' => $existingId,
            'created' => false,
        ];
    }

    $placeholderNumber = date('Y') . '-PENDING-' . $investorId;
    $insert = $db->prepare("
        INSERT INTO agreements (
            investor_id, agreement_number, agreement_type, status, template,
            generated_by, generated_at, expiration_date
        ) VALUES (?, ?, 'investment', 'draft', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
    ");
    $insert->execute([
        $investorId,
        $placeholderNumber,
        $templateCode,
        $performedBy,
    ]);

    $agreementId = (int)$db->lastInsertId();
    if ($preferredAgreementNo !== '') {
        $agreementNumber = finalizeHppAgreementRecord($db, $agreementId, $preferredAgreementNo);
    } elseif (isHppInvestmentType($investmentType)) {
        $agreementNumber = finalizeHppAgreementRecord($db, $agreementId);
    } else {
        $agreementNumber = $placeholderNumber;
    }

    try {
        $logStmt = $db->prepare("
            INSERT INTO agreement_logs (agreement_id, action, description, performed_by)
            VALUES (?, ?, ?, ?)
        ");
        $logStmt->execute([
            $agreementId,
            'agreement_created',
            'Created agreement ' . $agreementNumber . ' for ' . ($investor['full_name'] ?? ('Investor ' . $investorId)),
            $performedBy,
        ]);
    } catch (Throwable $e) {
        // optional logging
    }

    return [
        'success' => true,
        'message' => 'Agreement created',
        'agreement_id' => $agreementId,
        'agreement_number' => $agreementNumber,
        'created' => true,
    ];
}

/**
 * Assign HPP template + 2026/H#### numbers to all approved High Profit investments.
 */
function syncApprovedHppAgreements(PDO $db, string $performedBy): array
{
    $stmt = $db->query("
        SELECT i.*
        FROM investors i
        WHERE i.status = 'approved'
          AND UPPER(TRIM(i.investment_type)) = 'HPP'
        ORDER BY i.id ASC
    ");
    $investors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $created = 0;
    $updated = 0;
    $errors = [];

    foreach ($investors as $investor) {
        try {
            $existing = $db->prepare("SELECT id, agreement_number, template FROM agreements WHERE investor_id = ? ORDER BY generated_at DESC, id DESC LIMIT 1");
            $existing->execute([(int)$investor['id']]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $result = createAgreementForInvestor($db, $investor, $performedBy);
                if (!empty($result['success'])) {
                    $created++;
                } else {
                    $errors[] = 'Investor ' . $investor['id'] . ': ' . ($result['message'] ?? 'Failed');
                }
                continue;
            }

            $agreementId = (int)$row['id'];
            $number = finalizeHppAgreementRecord($db, $agreementId);
            $updated++;

            try {
                $logStmt = $db->prepare("
                    INSERT INTO agreement_logs (agreement_id, action, description, performed_by)
                    VALUES (?, ?, ?, ?)
                ");
                $logStmt->execute([
                    $agreementId,
                    'agreement_synced',
                    'Synced High Profit template (' . $number . ')',
                    $performedBy,
                ]);
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
            $errors[] = 'Investor ' . $investor['id'] . ': ' . $e->getMessage();
        }
    }

    return [
        'total' => count($investors),
        'created' => $created,
        'updated' => $updated,
        'errors' => $errors,
    ];
}
