-- Persist Marketing Associate rejection details and the rejecting administrator.
-- Apply after unified_marketing_associate_migration.sql.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE marketing_associates
  ADD COLUMN rejected_by BIGINT UNSIGNED NULL AFTER approved_by,
  ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by,
  ADD COLUMN rejection_reason VARCHAR(2000) NULL AFTER admin_remarks,
  ADD KEY idx_marketing_associates_rejected_by (rejected_by),
  ADD CONSTRAINT fk_marketing_associates_rejected_by
    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE marketing_associates
SET rejection_reason = admin_remarks
WHERE approval_status = 'rejected'
  AND admin_remarks IS NOT NULL
  AND rejection_reason IS NULL;
