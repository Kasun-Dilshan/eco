<?php

require_once __DIR__ . '/application_helpers.php';

/**
 * Configured email for an approval level (from APPROVER_LEVEL_EMAILS / APPROVER_LEVEL_N_EMAIL).
 */
function getConfiguredApproverEmailForLevel($level)
{
    $level = (int) $level;
    if (defined('APPROVER_LEVEL_EMAILS') && is_array(APPROVER_LEVEL_EMAILS) && !empty(APPROVER_LEVEL_EMAILS[$level])) {
        $email = trim((string) APPROVER_LEVEL_EMAILS[$level]);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    $constants = array(
        1 => 'APPROVER_LEVEL_1_EMAIL',
        2 => 'APPROVER_LEVEL_2_EMAIL',
        3 => 'APPROVER_LEVEL_3_EMAIL',
    );
    if (isset($constants[$level]) && defined($constants[$level])) {
        $email = trim((string) constant($constants[$level]));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    return '';
}

/**
 * Find approver email recipients for a given approval level (1, 2, or 3).
 * Level 3 uses APPROVER_LEVEL_EMAILS[3] when set (authoritative final approver).
 */
function findApproverRecipientsForLevel($db, $level)
{
    $recipients = array();
    $level = (int) $level;

    if ($level < 1 || $level > 3) {
        return $recipients;
    }

    // Final approver email from config takes precedence when configured
    $configEmail = getConfiguredApproverEmailForLevel($level);
    if ($level === 3 && $configEmail !== '') {
        return array(array(
            'name' => 'Final Approver',
            'email' => $configEmail,
            'role' => 'final_approver',
            'level' => 3,
        ));
    }

    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'approvers'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $stmt = $db->query("
                SELECT name, email, level, role
                FROM approvers
                WHERE email IS NOT NULL AND TRIM(email) != ''
                ORDER BY level ASC, name ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                if ((int) (isset($row['level']) ? $row['level'] : 0) === $level) {
                    $recipients[] = $row;
                }
            }

            if (empty($recipients)) {
                $roleMap = array(
                    1 => array('first_approver', 'approver_1', 'approver1'),
                    2 => array('second_approver', 'approver_2', 'approver2'),
                    3 => array('final_approver', 'approver_3', 'approver3', 'third_approver'),
                );

                if (isset($roleMap[$level])) {
                    foreach ($rows as $row) {
                        $role = strtolower(trim(isset($row['role']) ? $row['role'] : ''));
                        if (in_array($role, $roleMap[$level], true)) {
                            $recipients[] = $row;
                        }
                    }
                }
            }

            if (empty($recipients) && $level === 1 && !empty($rows)) {
                $recipients[] = $rows[0];
            }
        }
    } catch (Exception $e) {
        if (function_exists('debug_log')) {
            debug_log('findApproverRecipientsForLevel approvers failed', $e->getMessage());
        }
    }

    if (empty($recipients) && $configEmail !== '') {
        $names = array(1 => 'First Approver', 2 => 'Second Approver', 3 => 'Final Approver');
        $roles = array(1 => 'first_approver', 2 => 'second_approver', 3 => 'final_approver');
        return array(array(
            'name' => isset($names[$level]) ? $names[$level] : 'Approver',
            'email' => $configEmail,
            'role' => isset($roles[$level]) ? $roles[$level] : 'approver',
            'level' => $level,
        ));
    }

    if (empty($recipients) && $level === 1) {
        try {
            $tableCheck = $db->query("SHOW TABLES LIKE 'users'");
            if ($tableCheck && $tableCheck->rowCount() > 0) {
                $stmt = $db->query("
                    SELECT full_name AS name, email, 'admin' AS role, 1 AS level
                    FROM users
                    WHERE user_type IN ('admin', 'branch_manager')
                      AND email IS NOT NULL
                      AND TRIM(email) != ''
                    ORDER BY id ASC
                    LIMIT 5
                ");
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            if (function_exists('debug_log')) {
                debug_log('findApproverRecipientsForLevel users failed', $e->getMessage());
            }
        }
    }

    return $recipients;
}

/**
 * Send approval notification email to approver(s) at the given level.
 *
 * @return int Number of emails sent
 */
function sendApproverLevelEmail($db, $applicationId, $level, $trigger = 'new_application')
{
    $applicationId = (int) $applicationId;
    $level = (int) $level;

    if ($applicationId <= 0 || $level < 1 || $level > 3) {
        return 0;
    }

    $recipients = findApproverRecipientsForLevel($db, $level);
    if (empty($recipients)) {
        if (function_exists('debug_log')) {
            debug_log('sendApproverLevelEmail: no recipients', array(
                'application_id' => $applicationId,
                'level' => $level,
            ));
        }
        return 0;
    }

    try {
        $stmt = $db->prepare('
            SELECT full_name, nic_no, investment_type, sales_person_code
            FROM investors
            WHERE id = ?
        ');
        $stmt->execute(array($applicationId));
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        try {
            $stmt = $db->prepare('SELECT full_name, nic_no, investment_type FROM investors WHERE id = ?');
            $stmt->execute(array($applicationId));
            $app = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($app) {
                $app['sales_person_code'] = '';
            }
        } catch (Exception $inner) {
            if (function_exists('debug_log')) {
                debug_log('sendApproverLevelEmail: application load failed', $inner->getMessage());
            }
            return 0;
        }
    }

    if (!$app) {
        return 0;
    }

    $levelNames = array(
        1 => 'First Approver',
        2 => 'Second Approver',
        3 => 'Final Approver',
    );
    $levelLabel = isset($levelNames[$level]) ? $levelNames[$level] : 'Approver';
    $applicationRef = buildApplicationRef($applicationId, isset($app['investment_type']) ? $app['investment_type'] : '');
    $reviewUrl = rtrim(SITE_URL, '/') . '/admin/view_application.php?id=' . $applicationId;
    $subject = 'SERENDIB GREEN PLANTATION PVT LTD - Application ' . $applicationRef . ' Requires Your Approval';

    if ($trigger === 'new_application') {
        $intro = 'A new investment application has been submitted and requires your review.';
    } else {
        $intro = 'An application has been approved at the previous level and now requires your review.';
    }

    $sentCount = 0;
    $sentEmails = array();

    foreach ($recipients as $recipient) {
        $toEmail = trim(isset($recipient['email']) ? $recipient['email'] : '');
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (in_array(strtolower($toEmail), $sentEmails, true)) {
            continue;
        }

        $approverName = htmlspecialchars(isset($recipient['name']) ? $recipient['name'] : 'Approver', ENT_QUOTES, 'UTF-8');
        $investorName = htmlspecialchars(isset($app['full_name']) ? $app['full_name'] : '', ENT_QUOTES, 'UTF-8');
        $nicNo = htmlspecialchars(isset($app['nic_no']) ? $app['nic_no'] : '', ENT_QUOTES, 'UTF-8');
        $salesCode = htmlspecialchars(isset($app['sales_person_code']) ? $app['sales_person_code'] : '', ENT_QUOTES, 'UTF-8');

        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .header { background: #0a2f1d; color: white; padding: 20px; }
                .content { padding: 20px; }
                .footer { background: #f4f4f4; padding: 10px; text-align: center; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>SERENDIB GREEN PLANTATION PVT LTD - Approval Required</h2>
            </div>
            <div class='content'>
                <h3>Application Approval Request</h3>
                <p>Dear {$approverName},</p>
                <p>{$intro}</p>
                <p><strong>Application Reference:</strong> {$applicationRef}</p>
                <p><strong>Investor:</strong> {$investorName}</p>
                <p><strong>NIC:</strong> {$nicNo}</p>";

        if ($salesCode !== '') {
            $message .= "<p><strong>Sales Person Code:</strong> {$salesCode}</p>";
        }

        $message .= "
                <p><strong>Your Role:</strong> {$levelLabel}</p>
                <p>Please log in to the admin portal to review and approve this application.</p>
                <p style='margin-top: 24px;'>
                    <a href='{$reviewUrl}' style='display:inline-block;padding:12px 24px;background:#22c55e;color:#fff;text-decoration:none;border-radius:5px;'>
                        Review Application
                    </a>
                </p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " SERENDIB GREEN PLANTATION PVT LTD. All rights reserved.</p>
            </div>
        </body>
        </html>
        ";

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= 'From: ' . ADMIN_EMAIL . "\r\n";
        $headers .= 'Reply-To: ' . ADMIN_EMAIL . "\r\n";
        $headers .= 'X-Mailer: PHP/' . phpversion();

        $emailSent = mail($toEmail, $subject, $message, $headers);

        if (function_exists('debug_log')) {
            debug_log('sendApproverLevelEmail attempt', array(
                'to' => $toEmail,
                'level' => $level,
                'sent' => $emailSent ? 'yes' : 'no',
                'application_ref' => $applicationRef,
            ));
        }

        if ($emailSent) {
            $sentCount++;
            $sentEmails[] = strtolower($toEmail);
        }
    }

    if ($sentCount > 0) {
        try {
            $logStmt = $db->prepare("
                INSERT INTO application_logs (investor_id, action, description)
                VALUES (?, 'approver_notified', ?)
            ");
            $logStmt->execute(array(
                $applicationId,
                'Notification sent to ' . $levelLabel . ' (level ' . $level . ') for application ' . $applicationRef,
            ));
        } catch (Exception $e) {
            // Logging must not block approval
        }
    }

    return $sentCount;
}
