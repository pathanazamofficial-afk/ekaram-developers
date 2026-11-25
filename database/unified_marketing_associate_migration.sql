-- Unify employee and Marketing Associate accounts under one portal and account type.
-- Apply after the Marketing Associate, wallet, commission/team, and recruitment-invite migrations.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE marketing_associates
  MODIFY aadhaar_encrypted VARBINARY(256) NULL,
  MODIFY aadhaar_fingerprint BINARY(32) NULL,
  MODIFY aadhaar_last4 CHAR(4) NULL,
  MODIFY pan_encrypted VARBINARY(256) NULL,
  MODIFY pan_fingerprint BINARY(32) NULL,
  MODIFY pan_last4 CHAR(4) NULL,
  MODIFY account_number_encrypted VARBINARY(256) NULL,
  MODIFY account_number_last4 CHAR(4) NULL,
  ADD COLUMN designation_code ENUM(
    'marketing_manager',
    'deputy_manager',
    'assistant_general_manager',
    'deputy_general_manager',
    'deputy_director'
  ) NULL AFTER user_id;

ALTER TABLE recruitment_invites
  ADD COLUMN suggested_designation_code ENUM(
    'marketing_manager',
    'deputy_manager',
    'assistant_general_manager',
    'deputy_general_manager',
    'deputy_director'
  ) NULL AFTER target_role_code;

INSERT INTO marketing_associates
  (user_id, aadhaar_encrypted, aadhaar_fingerprint, aadhaar_last4,
   pan_encrypted, pan_fingerprint, pan_last4,
   account_number_encrypted, account_number_last4, ifsc_code, branch_location,
   ifsc_encrypted, branch_location_encrypted, designation_code,
   approval_status, approved_by, approved_at, created_at)
SELECT u.id, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
       NULL, NULL, r.code,
       CASE
         WHEN u.account_status = 'pending' THEN 'pending'
         WHEN u.account_status = 'blocked' THEN 'blocked'
         ELSE 'approved'
       END,
       u.approved_by, u.approved_at, u.created_at
FROM users u
JOIN roles r ON r.id = u.role_id
WHERE r.code IN (
  'marketing_manager',
  'deputy_manager',
  'assistant_general_manager',
  'deputy_general_manager',
  'deputy_director'
);

INSERT INTO marketing_associate_profiles (user_id, reporting_manager_id)
SELECT ep.user_id, ep.reporting_manager_id
FROM employee_profiles ep
JOIN marketing_associates ma ON ma.user_id = ep.user_id
ON DUPLICATE KEY UPDATE reporting_manager_id = VALUES(reporting_manager_id);

INSERT INTO recruitment_history
  (member_user_id, recruited_by, actor_user_id, action, old_role_code, new_role_code,
   old_manager_id, new_manager_id, remarks)
SELECT u.id, ep.recruited_by_id, u.approved_by, 'legacy_designation_import',
       r.code, r.code, ep.reporting_manager_id, ep.reporting_manager_id,
       'Existing designation migrated to Marketing Associate account type'
FROM users u
JOIN roles r ON r.id = u.role_id
JOIN employee_profiles ep ON ep.user_id = u.id
WHERE r.code IN (
  'marketing_manager',
  'deputy_manager',
  'assistant_general_manager',
  'deputy_general_manager',
  'deputy_director'
);

UPDATE recruitment_invites
SET suggested_designation_code = target_role_code
WHERE target_role_code IN (
  'marketing_manager',
  'deputy_manager',
  'assistant_general_manager',
  'deputy_general_manager',
  'deputy_director'
);

UPDATE recruitment_invites
SET target_role_code = 'marketing_associate';

UPDATE users u
JOIN roles old_role ON old_role.id = u.role_id
JOIN roles associate_role ON associate_role.code = 'marketing_associate'
SET u.role_id = associate_role.id
WHERE old_role.code IN (
  'marketing_manager',
  'deputy_manager',
  'assistant_general_manager',
  'deputy_general_manager',
  'deputy_director'
);

UPDATE users u
JOIN marketing_associates ma ON ma.user_id = u.id
JOIN roles r ON r.id = u.role_id
SET u.account_status = 'pending',
    u.approved_by = NULL,
    u.approved_at = NULL,
    ma.approval_status = 'pending',
    ma.approved_by = NULL,
    ma.approved_at = NULL
WHERE r.code = 'marketing_associate'
  AND ma.approval_status = 'approved'
  AND ma.designation_code IS NULL;

ALTER TABLE marketing_associates
  ADD CONSTRAINT chk_marketing_associate_approved_designation
  CHECK (approval_status <> 'approved' OR designation_code IS NOT NULL);
