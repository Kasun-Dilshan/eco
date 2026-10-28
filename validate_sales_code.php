<?php
require_once 'config.php';
require_once 'db.php';

header('Content-Type: application/json');

$code = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));

if ($code === '') {
    echo json_encode([
        'valid' => false,
        'message' => 'Sales person code is required.'
    ]);
    exit;
}

try {
    $stmt = $GLOBALS['db']->prepare("
        SELECT id FROM sales_persons
        WHERE sales_code = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$code]);

    if ($stmt->fetch()) {
        echo json_encode(['valid' => true, 'message' => '']);
    } else {
        echo json_encode([
            'valid' => false,
            'message' => 'Invalid sales person code. Please check the code and try again.'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'valid' => false,
        'message' => 'Unable to validate sales code. Please try again.'
    ]);
}
