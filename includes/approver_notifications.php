<?php

require_once __DIR__ . '/application_helpers.php';

function getApproverRoleNamesForLevel(int $level): array
{
    $map = [
        1 => ['first_approver', 'approver_1', 'approver1', 'level_1', 'first approver'],
        2 => ['second_approver', 'approver_2', 'approver2', 'level_2', 'second approver'],
        3 => ['final_approver', 'approver_3', 'approver3', 'third_approver', 'level_3', 'final approver'],
    ];

    return $map[$level] ?? [];
}

function approversTableExists(PDO $db): bool
{
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'approvers'");
        return $tableCheck && $tableCheck->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function fetchAllApproversWithEmail(PDO $db): array
{
    if (!approversTableExists($db)) {
        return [];
    }

    try {
        $stmt = $db->query("
            SELECT id, name, email, role, level
            FROM approvers
            WHERE email IS NOT NULL
              AND TRIM(email) != ''
            ORDER BY CAST(level AS UNSIGNED) ASC, name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $stmt = $db->query("
                SELECT id, name, email, role, level
                FROM approvers
                WHERE email IS NOT NULL
                  AND TRIM(email) != ''
                ORDER BY name ASC
            ");

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $inner) {
            error_log('fetchAllApproversWithEmail failed: ' . $inner->getMessage());
            return [];
        }
    }
}

function getAdminUsersAsApprovers(PDO $db, int $level): array
{
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'users'");
        if (!$tableCheck || $tableCheck->rowCount() === 0) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT id, full_name AS name, email, 'admin' AS role, ? AS level
            FROM users
            WHERE user_type IN ('admin', 'branch_manager')
              AND email IS NOT NULL
              AND TRIM(email) != ''
              AND (status = 'active' OR status IS NULL OR status = '')
            ORDER BY id ASC
        ");
        $stmt->execute([$level]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('getAdminUsersAsApprovers failed: ' . $e->getMessage());
        return [];
    }
}

function filterApproversByLevel(array $approvers, int $level): array
{
    $matched = [];
    foreach ($approvers as $approver) {
        if ((int) ($approver['level'] ?? 0) === $level) {
            $matched[] = $approver;
        }
    }

    return $matched;
}

function getLowestLevelApprovers(array $approvers): array
{
    if (empty($approvers)) {
        return [];
    }

    $levels = array_map(static function ($approver) {
        return (int) ($approver['level'] ?? 0);
    }, $approvers);

    $levels = array_filter($levels, static function ($level) {
        return $level > 0;
    });

    if (empty($levels)) {
        return $approvers;
    }

    $minLevel = min($levels);

    return array_values(array_filter($approvers, static function ($approver) use ($minLevel) {
        return (int) ($approver['level'] ?? 0) === $minLevel;
    }));
}

/**
 * Fetch approvers for a given approval level (1 = first, 2 = second, 3 = final).
 */
function getApproversByLevel(PDO $db, int $level): array
{
    if ($level < 1 || $level > 3) {
        return [];
    }

    // Final approver email from config takes precedence when configured
    if ($level === 3) {
        $configApprovers = getConfigApproverFallback(3);
        if (!empty($configApprovers)) {
            return $configApprovers;
        }
    }

    try {
        $allApprovers = fetchAllApproversWithEmail($db);

        if (!empty($allApprovers)) {
            $byLevel = filterApproversByLevel($allApprovers, $level);
            if (!empty($byLevel)) {
                return $byLevel;
            }

            $roles = getApproverRoleNamesForLevel($level);
            $byRole = [];
            foreach ($allApprovers as $approver) {
                $role = strtolower(trim((string) ($approver['role'] ?? '')));
                if (in_array($role, $roles, true)) {
                    $byRole[] = $approver;
                }
            }
            if (!empty($byRole)) {
                return $byRole;
            }

            if ($level === 1) {
                $lowest = getLowestLevelApprovers($allApprovers);
                if (!empty($lowest)) {
                    return $lowest;
                }

                return $allApprovers;
            }
        }

        $configApprovers = getConfigApproverFallback($level);
        if (!empty($configApprovers)) {
            return $configApprovers;
        }

        if ($level === 1) {
            return getAdminUsersAsApprovers($db, $level);
        }

        return [];
    } catch (Throwable $e) {
        error_log('getApproversByLevel failed: ' . $e->getMessage());
        if (function_exists('debug_log')) {
            debug_log('getApproversByLevel failed', $e->getMessage());
        }

        $configApprovers = getConfigApproverFallback($level);
        if (!empty($configApprovers)) {
            return $configApprovers;
        }

        return $level === 1 ? getAdminUsersAsApprovers($db, $level) : [];
    }
}

function getApproverLevelEmailsFromConfig()
{
    if (defined('APPROVER_LEVEL_EMAILS') && is_array(APPROVER_LEVEL_EMAILS)) {
        return APPROVER_LEVEL_EMAILS;
    }

    return array(
        1 => defined('APPROVER_LEVEL_1_EMAIL') ? APPROVER_LEVEL_1_EMAIL : '',
        2 => defined('APPROVER_LEVEL_2_EMAIL') ? APPROVER_LEVEL_2_EMAIL : '',
        3 => defined('APPROVER_LEVEL_3_EMAIL') ? APPROVER_LEVEL_3_EMAIL : '',
    );
}

function getConfigApproverFallback($level)
{
    $emails = getApproverLevelEmailsFromConfig();
    $email = trim((string) (isset($emails[$level]) ? $emails[$level] : ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [];
    }

    $names = [1 => 'First Approver', 2 => 'Second Approver', 3 => 'Final Approver'];

    return [[
        'id' => 0,
        'name' => $names[$level] ?? 'Approver',
        'email' => $email,
        'role' => getApproverRoleNamesForLevel($level)[0] ?? 'approver',
        'level' => $level,
    ]];
}

function getApplicationNotificationDetails(PDO $db, int $applicationId): ?array
{
    $stmt = $db->prepare('
        SELECT id, full_name, email, nic_no, investment_type
        FROM investors
        WHERE id = ?
    ');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app) {
        return null;
    }

    $app['sales_person_code'] = '';
    try {
        $columnCheck = $db->query("SHOW COLUMNS FROM investors LIKE 'sales_person_code'");
        if ($columnCheck && $columnCheck->rowCount() > 0) {
            $salesStmt = $db->prepare('SELECT sales_person_code FROM investors WHERE id = ?');
            $salesStmt->execute([$applicationId]);
            $app['sales_person_code'] = (string) ($salesStmt->fetchColumn() ?: '');
        }
    } catch (Throwable $e) {
        // Optional column only
    }

    return $app;
}

function sendPortalHtmlEmail(string $toEmail, string $subject, string $htmlBody): bool
{
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= 'From: ' . ADMIN_EMAIL . "\r\n";
    $headers .= 'Reply-To: ' . ADMIN_EMAIL . "\r\n";
    $headers .= 'X-Mailer: PHP/' . phpversion();

    $result = @mail($toEmail, $subject, $htmlBody, $headers);

    if (function_exists('debug_log')) {
        debug_log('sendPortalHtmlEmail', [
            'to' => $toEmail,
            'subject' => $subject,
            'sent' => $result ? 'yes' : 'no',
        ]);
    }

    return $result;
}

function sendApproverNotification(PDO $db, int $applicationId, int $approverLevel, array $options = []): int
{
    try {
        return sendApproverNotificationInternal($db, $applicationId, $approverLevel, $options);
    } catch (Throwable $e) {
        error_log('sendApproverNotification failed: ' . $e->getMessage());
        if (function_exists('debug_log')) {
            debug_log('sendApproverNotification failed', $e->getMessage());
        }
        return 0;
    }
}

function sendApproverNotificationInternal(PDO $db, int $applicationId, int $approverLevel, array $options = []): int
{
    if ($approverLevel < 1 || $approverLevel > 3) {
        return 0;
    }

    $app = getApplicationNotificationDetails($db, $applicationId);
    if (!$app) {
        error_log("sendApproverNotification: application {$applicationId} not found");
        return 0;
    }

    $approvers = getApproversByLevel($db, $approverLevel);

    if (function_exists('debug_log')) {
        debug_log('Approver lookup result', [
            'level' => $approverLevel,
            'count' => count($approvers),
            'emails' => array_column($approvers, 'email'),
            'names' => array_column($approvers, 'name'),
        ]);
    }

    if (empty($approvers)) {
        $message = "sendApproverNotification: no recipients found for level {$approverLevel}";
        error_log($message);
        if (function_exists('debug_log')) {
            debug_log('Approver notification skipped', $message);
        }
        return 0;
    }

    $applicationRef = buildApplicationRef($applicationId, $app['investment_type'] ?? '');
    $levelNames = [
        1 => 'First Approver',
        2 => 'Second Approver',
        3 => 'Final Approver',
    ];
    $levelLabel = $levelNames[$approverLevel] ?? "Level {$approverLevel} Approver";
    $trigger = $options['trigger'] ?? 'new_application';
    $reviewUrl = rtrim(SITE_URL, '/') . '/admin/view_application.php?id=' . $applicationId;

    $intro = ($trigger === 'new_application')
        ? 'A new investment application has been submitted and requires your review.'
        : 'An application has been approved at the previous level and now requires your review.';

    $subject = "SERENDIB GREEN PLANTATION PVT LTD - Application {$applicationRef} Requires Your Approval";
    $sentCount = 0;
    $sentEmails = [];

    foreach ($approvers as $approver) {
        $toEmail = strtolower(trim($approver['email'] ?? ''));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        if (in_array($toEmail, $sentEmails, true)) {
            continue;
        }

        $approverName = htmlspecialchars($approver['name'] ?? 'Approver', ENT_QUOTES, 'UTF-8');
        $investorName = htmlspecialchars($app['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $nicNo = htmlspecialchars($app['nic_no'] ?? '', ENT_QUOTES, 'UTF-8');
        $salesCode = htmlspecialchars($app['sales_person_code'] ?? '', ENT_QUOTES, 'UTF-8');

        $body = "
            <h3>Application Approval Request</h3>
            <p>Dear {$approverName},</p>
            <p>{$intro}</p>
            <p><strong>Application Reference:</strong> {$applicationRef}</p>
            <p><strong>Investor:</strong> {$investorName}</p>
            <p><strong>NIC:</strong> {$nicNo}</p>";

        if ($salesCode !== '') {
            $body .= "<p><strong>Sales Person Code:</strong> {$salesCode}</p>";
        }

        $body .= "
            <p><strong>Your Role:</strong> {$levelLabel}</p>
            <p>Please log in to the admin portal to review and approve this application.</p>
            <p style='margin-top: 24px;'>
                <a href='{$reviewUrl}' style='display:inline-block;padding:12px 24px;background:#22c55e;color:#fff;text-decoration:none;border-radius:5px;'>
                    Review Application
                </a>
            </p>
        ";

        $emailSent = sendPortalHtmlEmail($toEmail, $subject, wrapApproverEmailHtml($body));

        if (function_exists('debug_log')) {
            debug_log('Approver notification email attempt', [
                'to' => $toEmail,
                'level' => $approverLevel,
                'sent' => $emailSent ? 'yes' : 'no',
                'application_ref' => $applicationRef,
            ]);
        }

        if ($emailSent) {
            $sentCount++;
            $sentEmails[] = $toEmail;
        }
    }

    if ($sentCount > 0) {
        try {
            $logStmt = $db->prepare("
                INSERT INTO application_logs (investor_id, action, description)
                VALUES (?, 'approver_notified', ?)
            ");
            $logStmt->execute([
                $applicationId,
                "Notification sent to {$levelLabel} (level {$approverLevel}) for application {$applicationRef}",
            ]);
        } catch (PDOException $e) {
            error_log('sendApproverNotification log failed: ' . $e->getMessage());
        }
    } else {
        error_log("sendApproverNotification: mail() failed for all recipients at level {$approverLevel}");
        if (function_exists('debug_log')) {
            debug_log('Approver notification mail failed for all recipients', [
                'level' => $approverLevel,
                'attempted' => array_column($approvers, 'email'),
            ]);
        }
    }

    return $sentCount;
}

function wrapApproverEmailHtml(string $body): string
{
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background: #0a2f1d; color: white; padding: 20px; }
            .content { padding: 20px; }
            .footer { background: #f4f4f4; padding: 10px; text-align: center; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h2>SERENDIB GREEN PLANTATION PVT LTD - Approval Required</h2>
        </div>
        <div class='content'>{$body}</div>
        <div class='footer'>
            <p>&copy; " . date('Y') . " SERENDIB GREEN PLANTATION PVT LTD. All rights reserved.</p>
        </div>
    </body>
    </html>";
}
