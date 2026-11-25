-- EK ARAM local development baseline.
-- Import into an empty database. This is a newly proposed development schema,
-- not the missing previously approved 24-table migration.
-- The seed rows below are role/configuration data only; no customer or ledger data is seeded.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE roles (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(100) NOT NULL,
  hierarchy_level SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id SMALLINT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(25) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  account_status ENUM('pending','active','inactive','blocked') NOT NULL DEFAULT 'pending',
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_role_status (role_id, account_status),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_users_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  reporting_manager_id BIGINT UNSIGNED NULL,
  recruited_by_id BIGINT UNSIGNED NULL,
  employee_code VARCHAR(40) NULL,
  joined_at DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_employee_code (employee_code),
  KEY idx_employee_manager (reporting_manager_id),
  KEY idx_employee_recruiter (recruited_by_id),
  CONSTRAINT fk_employee_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_employee_manager FOREIGN KEY (reporting_manager_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_employee_recruiter FOREIGN KEY (recruited_by_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_challenges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  phone VARCHAR(25) NOT NULL,
  purpose ENUM('login','registration','password_reset') NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  request_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_otp_phone_purpose_expiry (phone, purpose, expires_at),
  KEY idx_otp_user_created (user_id, created_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  location VARCHAR(255) NOT NULL,
  total_land_area DECIMAL(10,2) NOT NULL DEFAULT 0,
  plot_count INT UNSIGNED NOT NULL DEFAULT 0,
  price_per_sqyd DECIMAL(12,2) NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  description TEXT NULL,
  brochure_path VARCHAR(500) NULL,
  map_embed_url VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_slug (slug),
  KEY idx_projects_status (status),
  CONSTRAINT fk_projects_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_specifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  spec_value VARCHAR(500) NOT NULL,
  display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project_specs_display (project_id, published, display_order),
  CONSTRAINT fk_project_specs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE amenities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(500) NULL,
  image_path VARCHAR(500) NULL,
  display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_amenities_project_published (project_id, published, display_order),
  CONSTRAINT fk_amenities_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_gallery (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(500) NOT NULL,
  alt_text VARCHAR(200) NOT NULL,
  display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gallery_project_published (project_id, published, display_order),
  CONSTRAINT fk_gallery_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  plot_number VARCHAR(50) NOT NULL,
  dimensions VARCHAR(100) NOT NULL,
  length_ft DECIMAL(8,2) NULL,
  width_ft DECIMAL(8,2) NULL,
  area_sqyd DECIMAL(10,2) NULL,
  facing_direction VARCHAR(20) NULL,
  status ENUM('available','reserved','booked','recent_agreement','sold','blocked') NOT NULL DEFAULT 'available',
  total_price DECIMAL(14,2) NULL,
  map_x DECIMAL(8,3) NULL,
  map_y DECIMAL(8,3) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plots_project_number (project_id, plot_number),
  KEY idx_plots_project_status (project_id, status),
  CONSTRAINT fk_plots_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plot_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plot_id INT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  changed_by BIGINT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_plot_history_plot_created (plot_id, created_at),
  CONSTRAINT fk_plot_history_plot FOREIGN KEY (plot_id) REFERENCES plots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_plot_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(25) NOT NULL,
  email VARCHAR(190) NULL,
  address VARCHAR(500) NULL,
  consent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customers_phone (phone),
  KEY idx_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id BIGINT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  assigned_to BIGINT UNSIGNED NULL,
  lead_owner_id BIGINT UNSIGNED NULL,
  ownership_locked_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'public_website',
  enquiry_type ENUM('enquiry','callback','site_visit','booking_request') NOT NULL DEFAULT 'enquiry',
  interest VARCHAR(40) NOT NULL DEFAULT 'general',
  message VARCHAR(2000) NULL,
  status ENUM('new','contacted','qualified','converted','lost') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_leads_assignee_status_created (assigned_to, status, created_at),
  KEY idx_leads_owner_created (lead_owner_id, created_at),
  KEY idx_leads_customer_created (customer_id, created_at),
  CONSTRAINT fk_leads_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_leads_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_owner FOREIGN KEY (lead_owner_id) REFERENCES users(id) ON DELETE SET NULL
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

CREATE TABLE lead_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  assigned_to BIGINT UNSIGNED NULL,
  assigned_by BIGINT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lead_assignments_lead_created (lead_id, created_at),
  CONSTRAINT fk_lead_assignments_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE RESTRICT,
  CONSTRAINT fk_lead_assignments_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_lead_assignments_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_followups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  due_at DATETIME NULL,
  outcome VARCHAR(100) NULL,
  notes VARCHAR(2000) NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lead_followups_due (user_id, due_at, completed_at),
  CONSTRAINT fk_followups_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE RESTRICT,
  CONSTRAINT fk_followups_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_visits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  plot_id INT UNSIGNED NULL,
  assigned_to BIGINT UNSIGNED NULL,
  preferred_food ENUM('veg','non_veg','unspecified') NOT NULL DEFAULT 'unspecified',
  vehicle_model VARCHAR(100) NULL,
  vehicle_number VARCHAR(30) NULL,
  driver_name VARCHAR(150) NULL,
  driver_phone VARCHAR(25) NULL,
  pickup_location VARCHAR(500) NULL,
  special_instructions VARCHAR(2000) NULL,
  visit_address VARCHAR(500) NULL,
  preferred_start DATETIME NOT NULL,
  preferred_end DATETIME NULL,
  status ENUM('requested','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'requested',
  notes VARCHAR(2000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_visits_assignee_date_status (assigned_to, preferred_start, status),
  KEY idx_visits_lead_created (lead_id, created_at),
  CONSTRAINT fk_visits_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE RESTRICT,
  CONSTRAINT fk_visits_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_visits_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_visits_plot FOREIGN KEY (plot_id) REFERENCES plots(id) ON DELETE SET NULL,
  CONSTRAINT fk_visits_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_reference VARCHAR(40) NOT NULL,
  plot_id INT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  lead_id BIGINT UNSIGNED NULL,
  agent_id BIGINT UNSIGNED NULL,
  status ENUM('reserved','pending_approval','confirmed','cancelled','expired') NOT NULL DEFAULT 'reserved',
  reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reservation_expires_at DATETIME NOT NULL,
  lead_owner_id BIGINT UNSIGNED NULL,
  confirmed_by BIGINT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  total_price DECIMAL(14,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_reference (booking_reference),
  KEY idx_bookings_plot_status_expiry (plot_id, status, reservation_expires_at),
  KEY idx_bookings_agent_status_created (agent_id, status, created_at),
  KEY idx_bookings_lead_owner (lead_owner_id),
  CONSTRAINT fk_bookings_plot FOREIGN KEY (plot_id) REFERENCES plots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_approver FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_lead_owner FOREIGN KEY (lead_owner_id) REFERENCES users(id) ON DELETE SET NULL
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

CREATE TABLE booking_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  changed_by BIGINT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_booking_history_booking_created (booking_id, created_at),
  CONSTRAINT fk_booking_history_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commission_rule_versions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_no INT UNSIGNED NOT NULL,
  recipient_code VARCHAR(40) NOT NULL,
  percentage DECIMAL(5,2) NOT NULL,
  effective_from DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_commission_rule_version_recipient (version_no, recipient_code),
  KEY idx_commission_rules_effective (effective_from, version_no),
  CONSTRAINT fk_commission_rules_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
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

CREATE TABLE commissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  stage ENUM('booking','agreement','registration') NOT NULL DEFAULT 'booking',
  user_id BIGINT UNSIGNED NOT NULL,
  recipient_code VARCHAR(40) NOT NULL,
  rule_version INT UNSIGNED NOT NULL,
  base_amount DECIMAL(14,2) NOT NULL,
  collection_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  lead_owner_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL,
  plot_id INT UNSIGNED NULL,
  percentage DECIMAL(5,2) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  status ENUM('pending','held','approved','rejected','paid','reversed') NOT NULL DEFAULT 'pending',
  approved_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_commissions_batch_user_role (batch_id, user_id, recipient_code),
  KEY idx_commissions_booking (booking_id),
  KEY idx_commissions_user_status_created (user_id, status, created_at),
  KEY idx_commissions_stage_status_created (stage, status, created_at),
  CONSTRAINT fk_commissions_batch FOREIGN KEY (batch_id) REFERENCES commission_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commissions_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commissions_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_commissions_lead_owner FOREIGN KEY (lead_owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_commissions_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_commissions_plot FOREIGN KEY (plot_id) REFERENCES plots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE wallets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  cached_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallets_user (user_id),
  CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wallet_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wallet_id BIGINT UNSIGNED NOT NULL,
  transaction_type ENUM('credit','debit','adjustment') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  balance_after DECIMAL(14,2) NOT NULL,
  reference_type VARCHAR(40) NOT NULL,
  reference_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  description VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_tx_idempotency (idempotency_key),
  KEY idx_wallet_tx_wallet_created (wallet_id, created_at),
  CONSTRAINT fk_wallet_tx_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE withdrawal_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  status ENUM('requested','approved','rejected','paid') NOT NULL DEFAULT 'requested',
  payout_details_encrypted VARBINARY(2048) NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  payment_reference VARCHAR(100) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_withdrawals_user_status_date (user_id, status, requested_at),
  KEY idx_withdrawals_status_date (status, requested_at),
  CONSTRAINT fk_withdrawals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_withdrawals_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  message VARCHAR(1000) NOT NULL,
  target_type VARCHAR(40) NULL,
  target_id BIGINT UNSIGNED NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read_created (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  before_data LONGTEXT NULL,
  after_data LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  request_id VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity_created (entity_type, entity_id, created_at),
  KEY idx_audit_actor_created (actor_user_id, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (code, name, hierarchy_level, is_system) VALUES
  ('admin', 'Administrator', 0, 1),
  ('marketing_manager', 'Marketing Manager', 1, 1),
  ('deputy_manager', 'Deputy Manager', 2, 1),
  ('assistant_general_manager', 'Assistant General Manager', 3, 1),
  ('deputy_general_manager', 'Deputy General Manager', 4, 1),
  ('deputy_director', 'Deputy Director', 5, 1);

INSERT INTO commission_rule_versions (version_no, recipient_code, percentage, effective_from) VALUES
  (1, 'marketing_manager', 7.00, UTC_TIMESTAMP()),
  (1, 'deputy_manager', 2.00, UTC_TIMESTAMP()),
  (1, 'assistant_general_manager', 1.00, UTC_TIMESTAMP()),
  (1, 'deputy_general_manager', 1.00, UTC_TIMESTAMP()),
  (1, 'deputy_director', 1.00, UTC_TIMESTAMP()),
  (1, 'company', 88.00, UTC_TIMESTAMP());
