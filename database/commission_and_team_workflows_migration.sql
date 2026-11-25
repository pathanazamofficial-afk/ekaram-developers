-- Add staged customer collections, commission milestones, and audited hierarchy workflows.
-- Apply after the development baseline and associate migrations.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE employee_profiles
  ADD COLUMN recruited_by_id BIGINT UNSIGNED NULL AFTER reporting_manager_id,
  ADD KEY idx_employee_recruiter (recruited_by_id),
  ADD CONSTRAINT fk_employee_recruiter FOREIGN KEY (recruited_by_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE leads
  MODIFY enquiry_type ENUM('enquiry','callback','site_visit','booking_request') NOT NULL DEFAULT 'enquiry',
  ADD COLUMN lead_owner_id BIGINT UNSIGNED NULL AFTER assigned_to,
  ADD COLUMN ownership_locked_at DATETIME NULL AFTER lead_owner_id,
  ADD KEY idx_leads_owner_created (lead_owner_id, created_at),
  ADD CONSTRAINT fk_leads_owner FOREIGN KEY (lead_owner_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE bookings
  ADD COLUMN lead_owner_id BIGINT UNSIGNED NULL AFTER agent_id,
  ADD KEY idx_bookings_lead_owner (lead_owner_id),
  ADD CONSTRAINT fk_bookings_lead_owner FOREIGN KEY (lead_owner_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE site_visits
  ADD COLUMN preferred_food ENUM('veg','non_veg','unspecified') NOT NULL DEFAULT 'unspecified' AFTER preferred_end,
  ADD COLUMN vehicle_model VARCHAR(100) NULL AFTER assigned_to,
  ADD COLUMN vehicle_number VARCHAR(30) NULL AFTER vehicle_model,
  ADD COLUMN driver_name VARCHAR(150) NULL AFTER vehicle_number,
  ADD COLUMN driver_phone VARCHAR(25) NULL AFTER driver_name,
  ADD COLUMN pickup_location VARCHAR(500) NULL AFTER driver_phone,
  ADD COLUMN special_instructions VARCHAR(2000) NULL AFTER pickup_location;

ALTER TABLE marketing_associate_profiles
  ADD COLUMN photo_position_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
  ADD COLUMN photo_position_y TINYINT UNSIGNED NOT NULL DEFAULT 50;

CREATE TABLE recruitment_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_user_id BIGINT UNSIGNED NOT NULL,
  recruited_by BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  old_role_code VARCHAR(40) NULL,
  new_role_code VARCHAR(40) NULL,
  old_manager_id BIGINT UNSIGNED NULL,
  new_manager_id BIGINT UNSIGNED NULL,
  remarks VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recruitment_member_created (member_user_id, created_at),
  KEY idx_recruitment_recruiter_created (recruited_by, created_at),
  CONSTRAINT fk_recruitment_member FOREIGN KEY (member_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recruitment_recruiter FOREIGN KEY (recruited_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_recruitment_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_recruitment_old_manager FOREIGN KEY (old_manager_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_recruitment_new_manager FOREIGN KEY (new_manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_ownership_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  old_owner_id BIGINT UNSIGNED NULL,
  new_owner_id BIGINT UNSIGNED NULL,
  changed_by BIGINT UNSIGNED NULL,
  reason VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lead_owner_history_lead_created (lead_id, created_at),
  CONSTRAINT fk_lead_owner_history_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE RESTRICT,
  CONSTRAINT fk_lead_owner_history_old_owner FOREIGN KEY (old_owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_lead_owner_history_new_owner FOREIGN KEY (new_owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_lead_owner_history_actor FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plot_collections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  received_at DATETIME NOT NULL,
  payment_method VARCHAR(50) NULL,
  payment_reference VARCHAR(150) NULL,
  status ENUM('pending','verified','rejected','reversed') NOT NULL DEFAULT 'pending',
  recorded_by BIGINT UNSIGNED NULL,
  verified_by BIGINT UNSIGNED NULL,
  verified_at DATETIME NULL,
  admin_remarks VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_plot_collections_booking_status_received (booking_id, status, received_at),
  KEY idx_plot_collections_received_status (received_at, status),
  CONSTRAINT fk_plot_collections_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_plot_collections_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_plot_collections_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_milestones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  milestone ENUM('agreement','registration') NOT NULL,
  completed_at DATETIME NOT NULL,
  recorded_by BIGINT UNSIGNED NULL,
  remarks VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_milestone (booking_id, milestone),
  CONSTRAINT fk_booking_milestone_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_milestone_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commission_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  milestone_id BIGINT UNSIGNED NOT NULL,
  stage ENUM('agreement','registration') NOT NULL,
  collected_to_date DECIMAL(15,2) NOT NULL,
  eligible_collection DECIMAL(15,2) NOT NULL,
  rule_version INT UNSIGNED NOT NULL,
  lead_owner_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','held','approved','rejected') NOT NULL DEFAULT 'pending',
  admin_reference VARCHAR(100) NULL,
  admin_remarks VARCHAR(1000) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_commission_batch_booking_stage (booking_id, stage),
  KEY idx_commission_batch_status_created (status, created_at),
  KEY idx_commission_batch_stage_created (stage, created_at),
  CONSTRAINT fk_commission_batch_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_batch_milestone FOREIGN KEY (milestone_id) REFERENCES booking_milestones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_batch_owner FOREIGN KEY (lead_owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_batch_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_commission_batch_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commission_exclusions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL,
  recipient_code VARCHAR(40) NOT NULL,
  excluded_user_id BIGINT UNSIGNED NULL,
  exclusion_reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_commission_exclusion (batch_id, recipient_code, excluded_user_id),
  CONSTRAINT fk_commission_exclusion_batch FOREIGN KEY (batch_id) REFERENCES commission_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_exclusion_user FOREIGN KEY (excluded_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE commissions
  DROP INDEX uq_commission_booking_user_role,
  ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER booking_id,
  ADD COLUMN stage ENUM('booking','agreement','registration') NOT NULL DEFAULT 'booking' AFTER batch_id,
  ADD COLUMN collection_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER base_amount,
  ADD COLUMN lead_owner_id BIGINT UNSIGNED NULL AFTER collection_amount,
  ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER lead_owner_id,
  ADD COLUMN plot_id INT UNSIGNED NULL AFTER customer_id,
  ADD UNIQUE KEY uq_commissions_batch_user_role (batch_id, user_id, recipient_code),
  ADD KEY idx_commissions_booking (booking_id),
  ADD KEY idx_commissions_stage_status_created (stage, status, created_at),
  ADD CONSTRAINT fk_commissions_batch FOREIGN KEY (batch_id) REFERENCES commission_batches(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_commissions_lead_owner FOREIGN KEY (lead_owner_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_commissions_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_commissions_plot FOREIGN KEY (plot_id) REFERENCES plots(id) ON DELETE SET NULL,
  MODIFY status ENUM('pending','held','approved','rejected','paid','reversed') NOT NULL DEFAULT 'pending';

CREATE TABLE commission_batch_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  old_status VARCHAR(20) NULL,
  new_status VARCHAR(20) NOT NULL,
  remarks VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_commission_batch_history_batch_created (batch_id, created_at),
  CONSTRAINT fk_commission_batch_history_batch FOREIGN KEY (batch_id) REFERENCES commission_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_batch_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing booking-value commissions are not collection-backed and must not be approved.
UPDATE commissions SET status = 'held' WHERE stage = 'booking' AND status = 'pending';
