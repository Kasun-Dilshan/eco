<?php
require_once '../config.php';
require_once '../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || (($_SESSION['user_type'] ?? null) !== 'admin')) {
    header('Location: dashboard.php');
    exit();
}

$message = '';
$type = '';
$adminId = (int)$_SESSION['user_id'];
$adminName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);
    $action = (string)($_POST['feedback_action'] ?? '');

    if ($feedbackId > 0 && in_array($action, ['approve', 'reject', 'delete'], true)) {
        try {
            if ($action === 'delete') {
                $stmt = $db->prepare('DELETE FROM investor_feedback WHERE id = ?');
                $stmt->execute([$feedbackId]);
                $message = 'Feedback deleted.';
                $type = 'success';
            } else {
                $newStatus = $action === 'approve' ? 'approved' : 'rejected';
                $stmt = $db->prepare('
                    UPDATE investor_feedback
                    SET status = ?, reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW()
                    WHERE id = ? AND status = ?
                ');
                $stmt->execute([$newStatus, $adminId, $adminName, $feedbackId, 'pending']);
                if ($stmt->rowCount() > 0) {
                    $message = $action === 'approve'
                        ? 'Feedback approved. It is now visible on the investor dashboard.'
                        : 'Feedback rejected.';
                    $type = 'success';
                } else {
                    $message = 'Feedback could not be updated (it may already have been reviewed).';
                    $type = 'error';
                }
            }
        } catch (PDOException $e) {
            $message = 'Could not update feedback. Please try again.';
            $type = 'error';
        }
    }
}

$filterStatus = strtolower(trim((string)($_GET['status'] ?? '')));
if (!in_array($filterStatus, ['pending', 'approved', 'rejected'], true)) {
    $filterStatus = '';
}
$search = trim((string)($_GET['search'] ?? ''));

$feedbacks = [];
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];

try {
    $countStmt = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
        FROM investor_feedback
    ");
    $countRow = $countStmt ? $countStmt->fetch(PDO::FETCH_ASSOC) : null;
    if ($countRow) {
        $stats = [
            'total' => (int)($countRow['total'] ?? 0),
            'pending' => (int)($countRow['pending'] ?? 0),
            'approved' => (int)($countRow['approved'] ?? 0),
            'rejected' => (int)($countRow['rejected'] ?? 0),
        ];
    }

    $sql = '
        SELECT id, investor_id, investor_name, nic_no, feedback_text, status,
               reviewed_by_name, reviewed_at, created_at
        FROM investor_feedback
        WHERE 1=1
    ';
    $params = [];

    if ($filterStatus !== '') {
        $sql .= ' AND status = ?';
        $params[] = $filterStatus;
    }
    if ($search !== '') {
        $sql .= ' AND (investor_name LIKE ? OR nic_no LIKE ? OR feedback_text LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY
        CASE status WHEN \'pending\' THEN 0 WHEN \'approved\' THEN 1 ELSE 2 END,
        created_at DESC,
        id DESC
        LIMIT 200';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $message = $message ?: 'Feedback table is not ready yet. Refresh the page once.';
    $type = $type ?: 'error';
}

function feedbackStatusClass(string $status): string
{
    return match ($status) {
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        default => 'status-pending',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investor Feedback | Serendib Green Plantation Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #0a2f1d; --secondary: #1a4d33; --accent: #22c55e; --neon: #00ff88; --error: #ef4444; --text: #f0fdf4; --text-muted: #a7f3d0; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%); color: var(--text); min-height: 100vh; }
        .admin-container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background: rgba(10, 47, 29, 0.9); border-right: 1px solid rgba(34, 197, 94, 0.2); padding: 20px 0; }
        .sidebar-header { padding: 0 20px 20px; border-bottom: 1px solid rgba(34, 197, 94, 0.2); margin-bottom: 20px; }
        .sidebar-header h2 { color: var(--neon); font-size: 20px; }
        .sidebar-nav ul { list-style: none; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 15px 20px; color: var(--text-muted); text-decoration: none; border-left: 3px solid transparent; }
        .sidebar-nav a:hover, .sidebar-nav .active a { background: rgba(34, 197, 94, 0.15); color: var(--text); border-left-color: var(--accent); }
        .main-content { flex: 1; padding: 20px; overflow-y: auto; }
        .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 1px solid rgba(34, 197, 94, 0.2); gap: 12px; flex-wrap: wrap; }
        .content-header h1 { font-size: 28px; color: var(--neon); }
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-size: 14px; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-secondary { background: rgba(26, 77, 51, 0.7); color: var(--text); border: 1px solid rgba(34, 197, 94, 0.2); }
        .btn-danger { background: var(--error); color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert.success { background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; }
        .alert.error { background: rgba(239, 68, 68, 0.2); border: 1px solid var(--error); }
        .filters { background: rgba(26, 77, 51, 0.5); padding: 20px; border-radius: 12px; margin-bottom: 20px; border: 1px solid rgba(34, 197, 94, 0.2); }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .filter-group label { display: block; margin-bottom: 5px; color: var(--text-muted); font-size: 13px; }
        .filter-group input, .filter-group select { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.3); background: rgba(10, 47, 29, 0.8); color: var(--text); }
        .data-table { width: 100%; border-collapse: collapse; background: rgba(26, 77, 51, 0.5); border-radius: 12px; overflow: hidden; }
        .data-table th, .data-table td { padding: 14px; text-align: left; border-bottom: 1px solid rgba(34, 197, 94, 0.15); vertical-align: top; }
        .data-table th { background: rgba(10, 47, 29, 0.8); color: var(--neon); font-size: 13px; text-transform: uppercase; }
        .data-table tr:hover { background: rgba(34, 197, 94, 0.08); }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .status-pending { background: rgba(251, 191, 36, 0.2); color: #fbbf24; }
        .status-approved { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .status-rejected { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .action-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
        .no-data { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: rgba(26, 77, 51, 0.5); padding: 20px; border-radius: 12px; border: 1px solid rgba(34, 197, 94, 0.2); text-align: center; }
        .stat-number { font-size: 28px; font-weight: bold; color: var(--neon); }
        .stat-label { font-size: 13px; color: var(--text-muted); margin-top: 5px; }
        .fb-text { white-space: pre-wrap; word-break: break-word; max-width: 420px; line-height: 1.45; }
        .muted { color: var(--text-muted); font-size: 12px; }
        @media (max-width: 900px) {
            .admin-container { flex-direction: column; }
            .sidebar { width: 100%; }
        }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="sidebar">
        <div class="sidebar-header"><h2><i class="fas fa-leaf"></i> Admin Portal</h2></div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="active"><a href="feedback.php"><i class="fas fa-comments"></i> Feedback</a></li>
                <li><a href="sales_persons.php"><i class="fas fa-user-tie"></i> Sales Persons</a></li>
                <li><a href="manage_users.php"><i class="fas fa-users"></i> Users</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </div>

    <div class="main-content">
        <div class="content-header">
            <h1><i class="fas fa-comments"></i> Investor Feedback</h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert <?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo (int)$stats['total']; ?></div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo (int)$stats['pending']; ?></div>
                <div class="stat-label">Pending approval</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo (int)$stats['approved']; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo (int)$stats['rejected']; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>

        <div class="filters">
            <form method="GET">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All</option>
                            <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Name, NIC, or feedback text" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="feedback.php" class="btn btn-secondary">Clear</a>
            </form>
        </div>

        <?php if (empty($feedbacks)): ?>
            <div class="no-data">
                <i class="fas fa-comment-slash" style="font-size: 48px; margin-bottom: 15px;"></i>
                <h3>No feedback found</h3>
                <p style="margin-top: 10px;">Investor submissions will appear here for admin approval.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Investor</th>
                            <th>Feedback</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Reviewed</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feedbacks as $fb): ?>
                            <?php $status = strtolower((string)($fb['status'] ?? 'pending')); ?>
                            <tr>
                                <td>#<?php echo (int)$fb['id']; ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars($fb['investor_name'] ?? 'Investor'); ?></div>
                                    <div class="muted">
                                        NIC: <?php echo htmlspecialchars(($fb['nic_no'] ?? '') !== '' ? $fb['nic_no'] : '—'); ?>
                                        · App #<?php echo (int)($fb['investor_id'] ?? 0); ?>
                                    </div>
                                </td>
                                <td><div class="fb-text"><?php echo htmlspecialchars($fb['feedback_text'] ?? ''); ?></div></td>
                                <td><?php echo htmlspecialchars($fb['created_at'] ? date('d M Y H:i', strtotime($fb['created_at'])) : '—'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo feedbackStatusClass($status); ?>">
                                        <?php echo htmlspecialchars(ucfirst($status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($fb['reviewed_at'])): ?>
                                        <div><?php echo htmlspecialchars(date('d M Y H:i', strtotime($fb['reviewed_at']))); ?></div>
                                        <div class="muted"><?php echo htmlspecialchars($fb['reviewed_by_name'] ?? ''); ?></div>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($status === 'pending'): ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="feedback_id" value="<?php echo (int)$fb['id']; ?>">
                                                <input type="hidden" name="feedback_action" value="approve">
                                                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Approve this feedback and show it to all investors?');">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            </form>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="feedback_id" value="<?php echo (int)$fb['id']; ?>">
                                                <input type="hidden" name="feedback_action" value="reject">
                                                <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Reject this feedback?');">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="feedback_id" value="<?php echo (int)$fb['id']; ?>">
                                            <input type="hidden" name="feedback_action" value="delete">
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this feedback permanently?');">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
