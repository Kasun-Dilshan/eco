<?php
require_once '../config.php';
require_once '../db.php';

session_start();

// Same auth pattern as admin/applications.php
if (
    !isset($_SESSION['user_id']) &&
    !isset($_SESSION['approver_id']) &&
    !isset($_SESSION['admin_id']) &&
    !(function_exists('isAdminLoggedIn') && isAdminLoggedIn())
) {
    header('Location: index.php');
    exit();
}

$investorId = (int)($_GET['application_id'] ?? $_GET['investor_id'] ?? 0);
if ($investorId <= 0) {
    header('Location: applications.php');
    exit();
}

// If agreements table doesn't exist yet, go to generator.
try {
    $existsStmt = $db->query("SHOW TABLES LIKE 'agreements'");
    $hasAgreementsTable = (bool)$existsStmt->fetchColumn();
} catch (Throwable $e) {
    $hasAgreementsTable = false;
}

if ($hasAgreementsTable) {
    try {
        $stmt = $db->prepare("
            SELECT id
            FROM agreements
            WHERE investor_id = ?
            ORDER BY generated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$investorId]);
        $agreementId = (int)($stmt->fetchColumn() ?: 0);

        if ($agreementId > 0) {
            header('Location: view_agreement.php?id=' . $agreementId);
            exit();
        }
    } catch (Throwable $e) {
        // Fall through to agreement creation flow
    }
}

// No agreements found yet; send user to the current agreement creation flow.
header('Location: find_application.php?auto_generate=1&investor_id=' . $investorId);
exit();

