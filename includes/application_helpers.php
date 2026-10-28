<?php

function getApplicationDisplayNumber($id, $applicationNumber = null) {
    if ($applicationNumber !== null && $applicationNumber !== '' && (int)$applicationNumber > 0) {
        return (int)$applicationNumber;
    }

    return (int)$id;
}

function formatApplicationNumber($id, $applicationNumber = null) {
    return str_pad(getApplicationDisplayNumber($id, $applicationNumber), 6, '0', STR_PAD_LEFT);
}

function buildApplicationRef($id, $investmentType, $applicationNumber = null) {
    $planCode = strtoupper(trim((string)($investmentType ?? '')));
    $planCodeSegment = $planCode !== '' ? ($planCode . '-') : '';
    $displayNumber = getApplicationDisplayNumber($id, $applicationNumber);

    return $planCodeSegment . str_pad($displayNumber, 6, '0', STR_PAD_LEFT);
}

/**
 * True until the first approver has approved/forwarded (level still 1, pending).
 */
function isBeforeFirstApproval($status, $approverLevel) {
    return (int)$approverLevel === 1
        && ($status ?? '') === 'pending';
}

/**
 * Ensure user_permissions supports string permission keys used by staff grants.
 */
function ensureStaffPermissionStorage($db) {
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'user_permissions'");
        if (!$stmt->fetch()) {
            $db->exec("
                CREATE TABLE user_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    permission VARCHAR(50) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_permission (user_id, permission)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $colStmt = $db->query("SHOW COLUMNS FROM user_permissions LIKE 'permission'");
        if (!$colStmt->fetch()) {
            $db->exec("ALTER TABLE user_permissions ADD COLUMN permission VARCHAR(50) NULL");
        }

        // Allow string-key staff grants alongside legacy permission_id rows.
        $idColStmt = $db->query("SHOW COLUMNS FROM user_permissions LIKE 'permission_id'");
        $idCol = $idColStmt->fetch(PDO::FETCH_ASSOC);
        if ($idCol && strtoupper((string)($idCol['Null'] ?? '')) === 'NO') {
            $db->exec("ALTER TABLE user_permissions MODIFY permission_id INT NULL");
        }
    } catch (PDOException $e) {
        error_log('ensureStaffPermissionStorage: ' . $e->getMessage());
    }
}

function getUserPermissionKeys($db, $userId) {
    try {
        ensureStaffPermissionStorage($db);
        $stmt = $db->prepare("
            SELECT permission
            FROM user_permissions
            WHERE user_id = ?
              AND permission IS NOT NULL
              AND permission != ''
        ");
        $stmt->execute([(int)$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (PDOException $e) {
        error_log('getUserPermissionKeys: ' . $e->getMessage());
        return [];
    }
}

function userHasPermissionKey($db, $userId, $permission, $userType = null) {
    if ($userType === 'admin') {
        return true;
    }

    $keys = getUserPermissionKeys($db, $userId);
    return in_array($permission, $keys, true);
}

/**
 * Staff-specific permissions managed on Edit User.
 */
function getStaffManagedPermissions() {
    return [
        'replace_uploads' => [
            'name' => 'Replace Uploaded Files',
            'description' => 'Allow this staff user to upload or replace application files before the first approver approves.',
        ],
    ];
}

function syncStaffManagedPermissions($db, $userId, $selectedPermissions, $userType) {
    ensureStaffPermissionStorage($db);
    $managedKeys = array_keys(getStaffManagedPermissions());

    $deleteStmt = $db->prepare("
        DELETE FROM user_permissions
        WHERE user_id = ? AND permission = ?
    ");
    foreach ($managedKeys as $key) {
        $deleteStmt->execute([(int)$userId, $key]);
    }

    if ($userType !== 'staff') {
        return;
    }

    $selected = is_array($selectedPermissions) ? $selectedPermissions : [];
    $hasPermissionId = false;
    try {
        $colStmt = $db->query("SHOW COLUMNS FROM user_permissions LIKE 'permission_id'");
        $hasPermissionId = (bool)$colStmt->fetch();
    } catch (PDOException $e) {
        $hasPermissionId = false;
    }

    if ($hasPermissionId) {
        $insertStmt = $db->prepare("
            INSERT INTO user_permissions (user_id, permission_id, permission)
            VALUES (?, NULL, ?)
        ");
    } else {
        $insertStmt = $db->prepare("
            INSERT INTO user_permissions (user_id, permission)
            VALUES (?, ?)
        ");
    }

    foreach ($managedKeys as $key) {
        if (in_array($key, $selected, true)) {
            $insertStmt->execute([(int)$userId, $key]);
        }
    }
}
