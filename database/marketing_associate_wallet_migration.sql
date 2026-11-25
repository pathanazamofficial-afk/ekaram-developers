-- Extend the separate Marketing Associate portal with profiles and wallet operations.
-- Import after dev_baseline.sql and marketing_associates_migration.sql.
-- Existing wallets, wallet_transactions, and withdrawal_requests remain the shared ledger.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS marketing_associate_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  profile_photo_path VARCHAR(255) NULL,
  reporting_manager_id BIGINT UNSIGNED NULL,
  photo_position_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
  photo_position_y TINYINT UNSIGNED NOT NULL DEFAULT 50,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_marketing_associate_profile_manager (reporting_manager_id),
  CONSTRAINT fk_marketing_associate_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_marketing_associate_profile_manager FOREIGN KEY (reporting_manager_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_associates
  MODIFY ifsc_code CHAR(11) NULL,
  MODIFY branch_location VARCHAR(150) NULL,
  ADD COLUMN ifsc_encrypted VARBINARY(256) NULL AFTER branch_location,
  ADD COLUMN branch_location_encrypted VARBINARY(512) NULL AFTER ifsc_encrypted;

ALTER TABLE wallets
  MODIFY cached_balance DECIMAL(15,2) NOT NULL DEFAULT 0;

ALTER TABLE wallet_transactions
  MODIFY transaction_type ENUM('credit','debit','adjustment','reserve','release') NOT NULL,
  MODIFY amount DECIMAL(15,2) NOT NULL,
  MODIFY balance_after DECIMAL(15,2) NOT NULL;

ALTER TABLE withdrawal_requests
  MODIFY amount DECIMAL(15,2) NOT NULL,
  MODIFY status ENUM('requested','approved','rejected','processing','paid') NOT NULL DEFAULT 'requested',
  ADD COLUMN request_reference VARCHAR(40) NULL AFTER id,
  ADD COLUMN request_remarks VARCHAR(500) NULL AFTER payout_details_encrypted,
  ADD COLUMN admin_remarks VARCHAR(1000) NULL AFTER request_remarks,
  ADD UNIQUE KEY uq_withdrawal_request_reference (request_reference);

CREATE TABLE IF NOT EXISTS withdrawal_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  withdrawal_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  old_status VARCHAR(20) NULL,
  new_status VARCHAR(20) NOT NULL,
  remarks VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_withdrawal_history_withdrawal_created (withdrawal_id, created_at),
  KEY idx_withdrawal_history_actor (actor_user_id),
  CONSTRAINT fk_withdrawal_history_request FOREIGN KEY (withdrawal_id) REFERENCES withdrawal_requests(id) ON DELETE RESTRICT,
  CONSTRAINT fk_withdrawal_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_admin_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wallet_id BIGINT UNSIGNED NOT NULL,
  associate_user_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  wallet_transaction_id BIGINT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  remarks VARCHAR(500) NOT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wallet_admin_audit_associate_created (associate_user_id, created_at),
  KEY idx_wallet_admin_audit_actor_created (actor_user_id, created_at),
  CONSTRAINT fk_wallet_admin_audit_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_wallet_admin_audit_associate FOREIGN KEY (associate_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_wallet_admin_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_wallet_admin_audit_transaction FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
