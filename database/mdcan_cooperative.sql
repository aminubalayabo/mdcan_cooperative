-- ============================================================
-- MDCAN Cooperative Management System - Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS mdcan_cooperative
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE mdcan_cooperative;

-- ------------------------------------------------------------
-- Admins (Director & Secretary)
-- ------------------------------------------------------------
CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('director','secretary') NOT NULL,
    phone VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Members
-- ------------------------------------------------------------
CREATE TABLE members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mno VARCHAR(20) UNIQUE NOT NULL COMMENT 'Member Number',
    name VARCHAR(100) NOT NULL,
    department VARCHAR(100),
    gsm VARCHAR(20),
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    bank_name VARCHAR(100),
    account_number VARCHAR(20),
    next_of_kin VARCHAR(100),
    next_of_kin_gsm VARCHAR(20),
    registration_date DATE,
    status ENUM('active','inactive','suspended') DEFAULT 'active',
    profile_photo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Loans
-- ------------------------------------------------------------
CREATE TABLE loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    loan_type ENUM('emergency','soft','essential_commodities','minor_tangible','major_tangible') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    duration_months INT NOT NULL,
    interest_rate DECIMAL(5,2) DEFAULT 0.00,
    purpose TEXT,
    status ENUM('pending','under_review','approved','declined','disbursed','repaying','completed') DEFAULT 'pending',
    requires_guarantor TINYINT(1) DEFAULT 1,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    disbursed_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Loan Payments
-- ------------------------------------------------------------
CREATE TABLE loan_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash','bank_transfer','payroll_deduction') DEFAULT 'payroll_deduction',
    notes TEXT,
    recorded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Loan Guarantors
-- ------------------------------------------------------------
CREATE TABLE loan_guarantors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    guarantor_member_id INT NOT NULL,
    consent_status ENUM('pending','accepted','declined') DEFAULT 'pending',
    consent_token VARCHAR(128),
    consented_at TIMESTAMP NULL DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (guarantor_member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Savings
-- ------------------------------------------------------------
CREATE TABLE savings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    type ENUM('monthly','flexible','payroll') DEFAULT 'monthly',
    month_year VARCHAR(7) COMMENT 'Format: YYYY-MM',
    description TEXT,
    recorded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Savings Withdrawals
-- ------------------------------------------------------------
CREATE TABLE savings_withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    withdrawal_type ENUM('account_closure','loan_liquidation','cash_withdrawal') NOT NULL,
    reason TEXT,
    supporting_document VARCHAR(255),
    status ENUM('pending','approved','declined','processed') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Notifications
-- ------------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('member','admin') NOT NULL,
    title VARCHAR(200),
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    link VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Audit Logs
-- ------------------------------------------------------------
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    user_type ENUM('member','admin') NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- User Activities
-- ------------------------------------------------------------
CREATE TABLE user_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('member','admin') NOT NULL,
    activity VARCHAR(200) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Member Shares
-- ------------------------------------------------------------
CREATE TABLE member_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL UNIQUE,
    shares_count INT DEFAULT 0,
    value_per_share DECIMAL(10,2) DEFAULT 100.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Dividends
-- ------------------------------------------------------------
CREATE TABLE dividends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    year INT NOT NULL,
    description TEXT,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    paid_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (paid_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Payroll Exports
-- ------------------------------------------------------------
CREATE TABLE payroll_exports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    month_year VARCHAR(7) NOT NULL,
    total_members INT DEFAULT 0,
    total_savings DECIMAL(12,2) DEFAULT 0.00,
    total_loan_deductions DECIMAL(12,2) DEFAULT 0.00,
    file_path VARCHAR(255),
    exported_by INT DEFAULT NULL,
    exported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exported_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Default Admin Accounts (password: mdcan2024)
-- ------------------------------------------------------------
INSERT INTO admins (name, email, password, role, phone) VALUES
('MDCAN Director', 'director@mdcan.edu.ng',
 '$2y$10$8K1p/a0dN1CIPRGaFPtU1uqRIqMwRLZBLHRhR.BvHNKP6DM7BxaSi', 'director', '08000000001'),
('MDCAN Secretary', 'secretary@mdcan.edu.ng',
 '$2y$10$8K1p/a0dN1CIPRGaFPtU1uqRIqMwRLZBLHRhR.BvHNKP6DM7BxaSi', 'secretary', '08000000002');

-- Demo member (password: mdcan2024)
INSERT INTO members (mno, name, department, gsm, email, password, bank_name, account_number, next_of_kin, next_of_kin_gsm, registration_date, status) VALUES
('MNO-001', 'Demo Member', 'Administration', '08011111111', 'member@mdcan.edu.ng',
 '$2y$10$8K1p/a0dN1CIPRGaFPtU1uqRIqMwRLZBLHRhR.BvHNKP6DM7BxaSi', 'First Bank', '1234567890', 'Demo Next of Kin', '08022222222', CURDATE(), 'active');
