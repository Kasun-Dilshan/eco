-- Investor feedback (submit from portal, approve in admin)
-- Also auto-created by db.php::ensureInvestorFeedbackTable()

CREATE TABLE IF NOT EXISTS investor_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    investor_id INT NOT NULL,
    investor_name VARCHAR(255) NOT NULL,
    nic_no VARCHAR(20) NULL,
    feedback_text TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_by_name VARCHAR(255) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_feedback_status (status),
    INDEX idx_feedback_investor (investor_id),
    INDEX idx_feedback_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
