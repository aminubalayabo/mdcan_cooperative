-- ============================================================
-- MDCAN Membership Workflow Migration
-- Run this ONCE if you already have the database imported.
-- Fresh installs: use the updated mdcan_cooperative.sql instead.
-- ============================================================

USE mdcan_cooperative;

-- Allow mno to be NULL until director approves
ALTER TABLE members
    MODIFY COLUMN mno VARCHAR(20) NULL DEFAULT NULL;

-- Extend status ENUM for approval workflow
ALTER TABLE members
    MODIFY COLUMN status ENUM(
        'pending_secretary',
        'pending_director',
        'active',
        'inactive',
        'suspended',
        'rejected'
    ) NOT NULL DEFAULT 'pending_secretary';

-- New columns for workflow tracking
ALTER TABLE members
    ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS forwarded_by INT DEFAULT NULL AFTER rejection_reason,
    ADD COLUMN IF NOT EXISTS forwarded_at TIMESTAMP NULL DEFAULT NULL AFTER forwarded_by;

-- Fix existing active members to keep their status
UPDATE members SET status = 'active' WHERE status NOT IN (
    'pending_secretary','pending_director','active','inactive','suspended','rejected'
);
