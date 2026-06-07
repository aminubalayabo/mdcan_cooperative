-- ============================================================
-- MDCAN Membership Workflow Migration
-- Run this ONCE in phpMyAdmin → SQL tab if you already
-- imported the original mdcan_cooperative.sql.
-- Compatible with MySQL 5.7 and MySQL 8.0+
-- ============================================================

USE mdcan_cooperative;

-- 1. Allow MNO to be NULL until Director approves
ALTER TABLE members
    MODIFY COLUMN mno VARCHAR(20) NULL DEFAULT NULL;

-- 2. Extend status ENUM for the approval workflow
ALTER TABLE members
    MODIFY COLUMN status ENUM(
        'pending_secretary',
        'pending_director',
        'active',
        'inactive',
        'suspended',
        'rejected'
    ) NOT NULL DEFAULT 'pending_secretary';

-- 3. Add workflow tracking columns (safe to run even if columns exist —
--    the procedure checks first, so no error on re-run)
DROP PROCEDURE IF EXISTS mdcan_add_columns;
DELIMITER $$
CREATE PROCEDURE mdcan_add_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'members'
          AND COLUMN_NAME  = 'rejection_reason'
    ) THEN
        ALTER TABLE members ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER status;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'members'
          AND COLUMN_NAME  = 'forwarded_by'
    ) THEN
        ALTER TABLE members ADD COLUMN forwarded_by INT DEFAULT NULL AFTER rejection_reason;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'members'
          AND COLUMN_NAME  = 'forwarded_at'
    ) THEN
        ALTER TABLE members ADD COLUMN forwarded_at TIMESTAMP NULL DEFAULT NULL AFTER forwarded_by;
    END IF;
END$$
DELIMITER ;

CALL mdcan_add_columns();
DROP PROCEDURE IF EXISTS mdcan_add_columns;

-- 4. Keep existing active/inactive/suspended members as-is
UPDATE members
SET status = 'active'
WHERE status NOT IN ('pending_secretary','pending_director','active','inactive','suspended','rejected');

-- ============================================================
-- Withdrawal Workflow Migration (secretary review step)
-- Run this ONCE if savings_withdrawals already exists without
-- the 'under_review' status.
-- ============================================================

-- 5. Add 'under_review' and 'review_notes' to savings_withdrawals
ALTER TABLE savings_withdrawals
    MODIFY COLUMN status ENUM('pending','under_review','approved','declined','processed') DEFAULT 'pending';

DROP PROCEDURE IF EXISTS mdcan_add_withdrawal_columns;
DELIMITER $$
CREATE PROCEDURE mdcan_add_withdrawal_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'savings_withdrawals'
          AND COLUMN_NAME  = 'review_notes'
    ) THEN
        ALTER TABLE savings_withdrawals ADD COLUMN review_notes TEXT DEFAULT NULL AFTER reviewed_at;
    END IF;
END$$
DELIMITER ;

CALL mdcan_add_withdrawal_columns();
DROP PROCEDURE IF EXISTS mdcan_add_withdrawal_columns;

-- 6. Add 'withdrawal' type to savings table so processed withdrawals appear in savings ledger
ALTER TABLE savings
    MODIFY COLUMN type ENUM('monthly','flexible','payroll','withdrawal') DEFAULT 'monthly';

-- 7. Add payslip column to loans for member document upload
ALTER TABLE loans ADD COLUMN IF NOT EXISTS payslip VARCHAR(255) DEFAULT NULL AFTER purpose;

-- 8. Dividend records table for year-end appropriation
CREATE TABLE IF NOT EXISTS dividend_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year YEAR NOT NULL,
    appropriated_amount DECIMAL(14,2) NOT NULL,
    total_savings DECIMAL(14,2) NOT NULL,
    members_count INT NOT NULL DEFAULT 0,
    generated_by INT NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES admins(id) ON DELETE CASCADE
);
