<?php
session_start();
require_once '../config.php';
require_once '../db.php';
require_once '../includes/application_helpers.php';
require_once '../includes/approval_email.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['approver_id'])) {
    header('Location: index.php');
    exit();
}

$result = '';
$error = '';
$recipients = array();
$debug = array();

try {
    $recipients = findApproverRecipientsForLevel($db, 1);
} catch (Exception $e) {
    $error = 'Could not load approver recipients: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    try {
        $latest = $db->query('SELECT id, full_name, nic_no, investment_type FROM investors ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        if (!$latest) {
            $result = 'No applications found to use for test notification.';
        } elseif (empty($recipients)) {
            $result = 'No recipients found. Add approver emails first.';
        } else {
            $sent = sendApproverLevelEmail($db, (int) $latest['id'], 1, 'new_application');
            $result = $sent > 0
                ? 'Test notification sent to ' . $sent . ' recipient(s) for application #' . (int) $latest['id'] . '.'
                : 'No email was sent. Check server mail settings and recipient addresses.';
        }
    } catch (Exception $e) {
        $error = 'Test send failed: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approver Email Check</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #0a2f1d; color: #fff; }
        .warn { color: #ef4444; }
        .info { color: #2563eb; }
        button { background: #22c55e; color: #fff; border: 0; padding: 12px 18px; border-radius: 6px; cursor: pointer; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Approver Email Check</h1>
    <p>Level 1 recipients that will receive new application notifications:</p>

    <?php if ($error !== ''): ?>
        <p class="warn"><strong><?php echo htmlspecialchars($error); ?></strong></p>
    <?php endif; ?>

    <?php if ($result !== ''): ?>
        <p class="info"><strong><?php echo htmlspecialchars($result); ?></strong></p>
    <?php endif; ?>

    <?php if (!empty($recipients)): ?>
        <table>
            <tr><th>Name</th><th>Email</th><th>Level</th><th>Role</th></tr>
            <?php foreach ($recipients as $recipient): ?>
                <tr>
                    <td><?php echo htmlspecialchars(isset($recipient['name']) ? $recipient['name'] : ''); ?></td>
                    <td><?php echo htmlspecialchars(isset($recipient['email']) ? $recipient['email'] : ''); ?></td>
                    <td><?php echo htmlspecialchars((string) (isset($recipient['level']) ? $recipient['level'] : '')); ?></td>
                    <td><?php echo htmlspecialchars(isset($recipient['role']) ? $recipient['role'] : ''); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <form method="POST">
            <button type="submit" name="send_test" value="1">Send Test Notification</button>
        </form>
    <?php else: ?>
        <p class="warn">No level 1 recipients found.</p>
        <p>Add approvers with valid emails in <strong>Users &amp; Approvers</strong>, or ensure admin users have email addresses in the <code>users</code> table.</p>
    <?php endif; ?>

    <?php if (!empty($debug)): ?>
        <h3>Debug</h3>
        <ul>
            <?php foreach ($debug as $line): ?>
                <li><?php echo htmlspecialchars($line); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p><a href="dashboard.php">Back to dashboard</a></p>
</body>
</html>
