-- Sales Persons table for Eco Wealth Portal
-- Run this in phpMyAdmin or MySQL if the table was not auto-created by db.php
-- New columns (phone_number, email, sales_rank, reported_sales_person_id) are also added
-- automatically by db.php on existing databases.

CREATE TABLE IF NOT EXISTS sales_persons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    branch VARCHAR(100) NOT NULL,
    epf_no VARCHAR(20) NOT NULL,
    sales_code VARCHAR(50) NOT NULL,
    phone_number VARCHAR(20) NULL,
    email VARCHAR(255) NULL,
    sales_rank VARCHAR(100) NULL,
    reported_sales_person_id INT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_by INT NULL COMMENT 'User ID of admin who created this record',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_epf_no (epf_no),
    UNIQUE KEY unique_sales_code (sales_code),
    INDEX idx_branch (branch),
    INDEX idx_status (status),
    INDEX idx_reported_sales_person (reported_sales_person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
