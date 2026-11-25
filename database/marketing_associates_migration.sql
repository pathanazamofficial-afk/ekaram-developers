-- Additive Marketing Associate portal schema.
-- Import after database/dev_baseline.sql. Existing CRM and agent tables are unchanged.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT INTO roles (code, name, hierarchy_level, is_system)
VALUES ('marketing_associate', 'Marketing Associate', 6, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  hierarchy_level = VALUES(hierarchy_level),
  is_system = VALUES(is_system);

CREATE TABLE IF NOT EXISTS marketing_associates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  aadhaar_encrypted VARBINARY(256) NOT NULL,
  aadhaar_fingerprint BINARY(32) NOT NULL,
  aadhaar_last4 CHAR(4) NOT NULL,
  pan_encrypted VARBINARY(256) NOT NULL,
  pan_fingerprint BINARY(32) NOT NULL,
  pan_last4 CHAR(4) NOT NULL,
  account_number_encrypted VARBINARY(256) NOT NULL,
  account_number_last4 CHAR(4) NOT NULL,
  ifsc_code CHAR(11) NOT NULL,
  branch_location VARCHAR(150) NOT NULL,
  approval_status ENUM('pending','approved','rejected','blocked') NOT NULL DEFAULT 'pending',
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  admin_remarks VARCHAR(2000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marketing_associates_user (user_id),
  UNIQUE KEY uq_marketing_associates_aadhaar (aadhaar_fingerprint),
  UNIQUE KEY uq_marketing_associates_pan (pan_fingerprint),
  KEY idx_marketing_associates_status_created (approval_status, created_at),
  KEY idx_marketing_associates_approver (approved_by),
  CONSTRAINT fk_marketing_associates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_marketing_associates_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
