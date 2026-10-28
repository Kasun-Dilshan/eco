<?php
require_once '../config.php';

session_start();

if (
    !isset($_SESSION['user_id']) &&
    !isset($_SESSION['approver_id']) &&
    !isset($_SESSION['admin_id']) &&
    !(function_exists('isAdminLoggedIn') && isAdminLoggedIn())
) {
    header('Location: index.php');
    exit();
}

$agreementId = (int)($_GET['id'] ?? $_GET['agreement_id'] ?? 0);
if ($agreementId <= 0) {
    http_response_code(400);
    echo 'Invalid agreement ID';
    exit();
}

header('Location: preview_agreement.php?id=' . $agreementId . '&print=1');
exit();
