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
