-- Create database
CREATE DATABASE IF NOT EXISTS eco_wealth_portal;
USE eco_wealth_portal;

-- Investors table
CREATE TABLE IF NOT EXISTS investors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    name_with_initials VARCHAR(255) NOT NULL,
    nic_no VARCHAR(20) NOT NULL UNIQUE,
    tel_no VARCHAR(20) NOT NULL,
    dob DATE NOT NULL,
    email VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
    occupation VARCHAR(100) NOT NULL,
    employer_name VARCHAR(255) NOT NULL,
    years INT NOT NULL,
    signing_date DATE NOT NULL,
    account_no VARCHAR(50) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    branch_name VARCHAR(100) NOT NULL,
    declaration_date DATE NOT NULL,
    signature_upload VARCHAR(255),
    investor_id_doc VARCHAR(255),
    beneficiary_id_doc VARCHAR(255),
    passbook_doc VARCHAR(255),
    payment_slip_doc VARCHAR(255),
    final_signature VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Beneficiaries table
CREATE TABLE IF NOT EXISTS beneficiaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    investor_id INT NOT NULL,
    beneficiary_name VARCHAR(255) NOT NULL,
    beneficiary_nic VARCHAR(20) NOT NULL,
    percentage INT NOT NULL,
    FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE CASCADE
);

-- Application logs table
CREATE TABLE IF NOT EXISTS application_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    investor_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    description TEXT,
    performed_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE CASCADE
);

-- Create admin user for management (optional)
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    role ENUM('admin', 'staff') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin (password: admin123)
INSERT INTO admin_users (username, password_hash, email, role) 
VALUES ('admin', '$2y$10$YourHashedPasswordHere', 'admin@ecowealth.com', 'admin');