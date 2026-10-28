<?php

/**
 * Investor portal authentication helpers.
 * Username = NIC number; password is set by the investor.
 */

function normalizeInvestorNic(string $nic): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($nic)));
}

function isInvestorLoggedIn(): bool
{
    return isset($_SESSION['investor_id']) && (int)$_SESSION['investor_id'] > 0;
}

function requireInvestorAuth(): void
{
    if (!isInvestorLoggedIn()) {
        $base = defined('BASE_PATH') ? BASE_PATH : '';
        header('Location: ' . $base . '/investor/login.php');
        exit();
    }
}

/**
 * All applications for a NIC (newest first). Same customer may have many proposals.
 *
 * @return array<int, array<string, mixed>>
 */
function findInvestorsByNic(PDO $db, string $nic): array
{
    $nic = normalizeInvestorNic($nic);
    if ($nic === '') {
        return [];
    }

    $stmt = $db->prepare('
        SELECT *
        FROM investors
        WHERE UPPER(REPLACE(nic_no, \' \', \'\')) = ?
        ORDER BY id DESC
    ');
    $stmt->execute([$nic]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * One investor row for a NIC. Prefers a row that already has a portal password.
 */
function findInvestorByNic(PDO $db, string $nic): ?array
{
    $rows = findInvestorsByNic($db, $nic);
    if (!$rows) {
        return null;
    }

    foreach ($rows as $row) {
        if (!empty($row['portal_password_hash'])) {
            return $row;
        }
    }

    return $rows[0];
}

/**
 * Set or clear portal password on every application sharing this NIC.
 */
function setPortalPasswordForNic(PDO $db, string $nic, string $hash): void
{
    $nic = normalizeInvestorNic($nic);
    if ($nic === '') {
        return;
    }

    $stmt = $db->prepare('
        UPDATE investors
        SET portal_password_hash = ?, portal_failed_attempts = 0
        WHERE UPPER(REPLACE(nic_no, \' \', \'\')) = ?
    ');
    $stmt->execute([$hash, $nic]);
}

/**
 * Copy an existing portal password onto a newly inserted application when the NIC already has one.
 */
function syncPortalPasswordToInvestor(PDO $db, int $investorId, string $nic): void
{
    if ($investorId <= 0) {
        return;
    }

    $existing = findInvestorByNic($db, $nic);
    if (!$existing || empty($existing['portal_password_hash'])) {
        return;
    }
    if ((int)($existing['id'] ?? 0) === $investorId) {
        return;
    }

    $stmt = $db->prepare('
        UPDATE investors
        SET portal_password_hash = ?, portal_failed_attempts = 0
        WHERE id = ?
    ');
    $stmt->execute([$existing['portal_password_hash'], $investorId]);
}

function findInvestorById(PDO $db, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM investors WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function setInvestorSession(array $investor): void
{
    $_SESSION['investor_id'] = (int)$investor['id'];
    $_SESSION['investor_nic'] = (string)$investor['nic_no'];
    $_SESSION['investor_name'] = (string)$investor['full_name'];
    $_SESSION['login_type'] = 'investor';
}

function clearInvestorSession(): void
{
    unset(
        $_SESSION['investor_id'],
        $_SESSION['investor_nic'],
        $_SESSION['investor_name']
    );
    if (($_SESSION['login_type'] ?? '') === 'investor') {
        unset($_SESSION['login_type']);
    }
}

function getInvestorBeneficiaries(PDO $db, int $investorId): array
{
    $stmt = $db->prepare('
        SELECT beneficiary_name, beneficiary_nic, percentage
        FROM beneficiaries
        WHERE investor_id = ?
        ORDER BY id ASC
    ');
    $stmt->execute([$investorId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Build financial summary for an investor row (reuses agreement helpers).
 *
 * @return array<string, mixed>
 */
function buildInvestorFinancialSummary(array $investor): array
{
    $deposit = parseAgreementAmount($investor['investment_amount'] ?? null);
    $years = (int)($investor['years'] ?? 1);
    if ($years <= 0) {
        $years = 1;
    }
    $investmentType = (string)($investor['investment_type'] ?? 'HPP');
    $currency = (string)($investor['currency'] ?? 'LKR');
    $monthlyRate = agreementMonthlyRateForInvestment($deposit, $years, $investmentType);
    $fin = computeAgreementFinancials($deposit, $years, $monthlyRate);
    $isPremium = isHppPremiumAgreement($deposit, $years, $investmentType);

    $signingDate = $investor['signing_date'] ?? $investor['created_at'] ?? date('Y-m-d');
    try {
        $commence = new DateTime($signingDate ?: date('Y-m-d'));
    } catch (Exception $e) {
        $commence = new DateTime();
    }
    $maturity = (clone $commence)->modify('+' . $years . ' year');

    return [
        'deposit' => $deposit,
        'years' => $years,
        'currency' => $currency,
        'investment_type' => $investmentType,
        'product_name' => getInvestmentTypeDisplayName($investmentType, true),
        'monthly_rate' => $monthlyRate,
        'monthly_rate_percent' => $monthlyRate * 100,
        'monthly_return' => $fin['monthly_return'],
        'yearly_return' => $fin['yearly_return'],
        'total_return' => $fin['total_return'],
        'maturity_total' => $fin['maturity_total'],
        'months' => $fin['months'],
        'is_hpp_premium' => $isPremium,
        'land_size_label' => $isPremium ? formatLandSizePerches(agreementLandSizePerches($deposit)) : '',
        'commence' => $commence,
        'maturity' => $maturity,
    ];
}

function formatMoneyLkr(float $amount, string $currency = 'LKR'): string
{
    return $currency . ' ' . number_format($amount, 2);
}

/**
 * Resolve a stored profile photo filename to a public URL path.
 */
function investorProfilePhotoUrl(?string $filename): string
{
    $filename = basename(trim((string)$filename));
    if ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
        return '';
    }

    $diskPath = dirname(__DIR__) . '/uploads/profiles/' . $filename;
    if (!is_file($diskPath)) {
        return '';
    }

    $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
    return $base . '/uploads/profiles/' . rawurlencode($filename);
}

/**
 * Store profile photo filename on every application row sharing this NIC.
 */
function setProfilePhotoForNic(PDO $db, string $nic, string $filename): void
{
    $nic = normalizeInvestorNic($nic);
    $filename = basename(trim($filename));
    if ($nic === '' || $filename === '') {
        return;
    }

    $stmt = $db->prepare('
        UPDATE investors
        SET profile_photo = ?, updated_at = NOW()
        WHERE UPPER(REPLACE(nic_no, \' \', \'\')) = ?
    ');
    $stmt->execute([$filename, $nic]);
}

/**
 * Find an existing profile photo for a NIC across any application row.
 */
function findProfilePhotoForNic(PDO $db, string $nic): string
{
    $nic = normalizeInvestorNic($nic);
    if ($nic === '') {
        return '';
    }

    $stmt = $db->prepare('
        SELECT profile_photo
        FROM investors
        WHERE UPPER(REPLACE(nic_no, \' \', \'\')) = ?
          AND profile_photo IS NOT NULL
          AND profile_photo <> \'\'
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ');
    $stmt->execute([$nic]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? basename((string)$row['profile_photo']) : '';
}

/**
 * Validate and save an investor profile photo upload. Returns filename or error message.
 *
 * @return array{ok:bool, filename?:string, error?:string}
 */
function saveInvestorProfilePhotoUpload(array $file, string $nic): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Please choose a photo to upload.'];
    }

    $maxSize = defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : (3 * 1024 * 1024);
    $maxMb = defined('MAX_FILE_SIZE_MB') ? (int)MAX_FILE_SIZE_MB : 3;
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $maxSize) {
        return ['ok' => false, 'error' => "Photo must be under {$maxMb} MB."];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'error' => 'Only JPG or PNG photos are allowed.'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload. Please try again.'];
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['ok' => false, 'error' => 'The selected file is not a valid image.'];
    }

    $mime = strtolower((string)($info['mime'] ?? ''));
    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    if (!isset($mimeMap[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG or PNG photos are allowed.'];
    }
    $ext = $mimeMap[$mime];

    $uploadDir = dirname(__DIR__) . '/uploads/profiles/';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not prepare upload folder.'];
    }

    $nicKey = preg_replace('/[^A-Z0-9]/', '', normalizeInvestorNic($nic)) ?: 'investor';
    $filename = 'profile_' . strtolower($nicKey) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        return ['ok' => false, 'error' => 'Failed to save photo. Please try again.'];
    }

    @chmod($destination, 0644);

    return ['ok' => true, 'filename' => $filename];
}
