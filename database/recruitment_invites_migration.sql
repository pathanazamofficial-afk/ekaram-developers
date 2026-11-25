-- Add one-time, rank-constrained recruitment invitations.
-- Apply to existing databases after commission_and_team_workflows_migration.sql.
-- Fresh installations using dev_baseline.sql already include this table.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE recruitment_invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  manager_user_id BIGINT UNSIGNED NOT NULL,
  target_role_code VARCHAR(40) NOT NULL,
  token_hash BINARY(32) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_by BIGINT UNSIGNED NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recruitment_invite_token_hash (token_hash),
  KEY idx_recruitment_invite_manager_created (manager_user_id, created_at),
  KEY idx_recruitment_invite_expiry (expires_at, used_at),
  CONSTRAINT fk_recruitment_invite_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recruitment_invite_used_by FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
