<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';
Security::startSession();
$admin = Auth::requireAdminApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
}
if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    Response::json(['success' => false, 'message' => 'Invalid request token'], 403);
}

$action = trim((string) ($_POST['action'] ?? ''));
$associateId = filter_var($_POST['associate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($associateId === false) {
    Response::json(['success' => false, 'message' => 'Select a Marketing Associate registration'], 422);
}

$pdo = Database::connection();
if ($action === 'set_assignment') {
    $designationInput = trim((string) ($_POST['designation_code'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if (mb_strlen($reason) > 1000 || !array_key_exists($designationInput, MarketingAssociateHierarchy::DESIGNATIONS)) {
        Response::json(['success' => false, 'message' => 'Choose a valid designation and a reason of at most 1000 characters'], 422);
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT ma.user_id, ma.designation_code, ma.approval_status, profile.reporting_manager_id
             FROM marketing_associates ma
             LEFT JOIN marketing_associate_profiles profile ON profile.user_id = ma.user_id
             WHERE ma.id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $associateId]);
        $before = $statement->fetch();
        if ($before === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Marketing Associate not found'], 404);
        }
        $userId = (int) $before['user_id'];
        $oldDesignation = $before['designation_code'];
        $oldManagerId = $before['reporting_manager_id'] === null ? null : (int) $before['reporting_manager_id'];
        if ($oldDesignation !== null && $oldDesignation !== $designationInput && $reason === '') {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'A reason is required when changing an existing designation'], 422);
        }
        $rateStatement = $pdo->prepare(
            'SELECT version_no, percentage FROM commission_rule_versions
             WHERE recipient_code = :designation AND version_no = (
                 SELECT version_no FROM commission_rule_versions
                 WHERE effective_from <= UTC_TIMESTAMP() ORDER BY version_no DESC LIMIT 1
             )'
        );
        $rateStatement->execute(['designation' => $designationInput]);
        $rate = $rateStatement->fetch();
        if ($rate === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'The commission rate for this designation is not configured'], 409);
        }

        $pdo->prepare('UPDATE marketing_associates SET designation_code = :designation WHERE id = :id')
            ->execute(['designation' => $designationInput, 'id' => $associateId]);
        $pdo->prepare(
            "INSERT INTO recruitment_history
             (member_user_id, recruited_by, actor_user_id, action, old_role_code, new_role_code,
              old_manager_id, new_manager_id, remarks)
             VALUES (:member, NULL, :actor, 'designation_assignment_updated', :old_designation,
                     :new_designation, :old_manager, :new_manager, :remarks)"
        )->execute([
            'member' => $userId,
            'actor' => (int) $admin['id'],
            'old_designation' => $oldDesignation,
            'new_designation' => $designationInput,
            'old_manager' => $oldManagerId,
            'new_manager' => $oldManagerId,
            'remarks' => $reason !== '' ? $reason : ($oldDesignation === null ? 'Initial designation allocation' : 'Designation confirmed'),
        ]);
        $pdo->prepare(
            "INSERT INTO audit_logs
             (actor_user_id, action, entity_type, entity_id, before_data, after_data)
             VALUES (:actor, 'marketing_associate_designation_assignment_updated',
                     'marketing_associate', :entity_id, :before_data, :after_data)"
        )->execute([
            'actor' => (int) $admin['id'],
            'entity_id' => (int) $associateId,
            'before_data' => json_encode([
                'designation_code' => $oldDesignation,
                'reporting_manager_id' => $oldManagerId,
                'approval_status' => $before['approval_status'],
            ], JSON_THROW_ON_ERROR),
            'after_data' => json_encode([
                'designation_code' => $designationInput,
                'reporting_manager_id' => $oldManagerId,
                'approval_status' => $before['approval_status'],
                'reason' => $reason !== '' ? $reason : ($oldDesignation === null ? 'Initial designation allocation' : 'Designation confirmed'),
                'commission_rule_version' => (int) $rate['version_no'],
                'commission_percentage' => $rate['percentage'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
        Response::json(['success' => true, 'message' => 'Designation saved']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Marketing Associate designation update failed: ' . $exception->getMessage());
        Response::json(['success' => false, 'message' => 'The designation could not be saved'], 500);
    }
}

if ($action === 'reveal') {
    $statement = $pdo->prepare(
        'SELECT id, aadhaar_encrypted, pan_encrypted, account_number_encrypted,
                ifsc_encrypted, branch_location_encrypted
         FROM marketing_associates WHERE id = :id'
    );
    $statement->execute(['id' => $associateId]);
    $associate = $statement->fetch();
    if ($associate === false) {
        Response::json(['success' => false, 'message' => 'Registration not found'], 404);
    }

    try {
        $decryptOptional = static function (string $field, $payload): ?string {
            return is_string($payload) ? MarketingAssociateDataProtection::decrypt($field, $payload) : null;
        };
        $details = [
            'aadhaar' => $decryptOptional('aadhaar', $associate['aadhaar_encrypted']),
            'pan' => $decryptOptional('pan', $associate['pan_encrypted']),
            'account_number' => $decryptOptional('account_number', $associate['account_number_encrypted']),
            'ifsc_code' => $decryptOptional('ifsc_code', $associate['ifsc_encrypted']),
            'branch_location' => $decryptOptional('branch_location', $associate['branch_location_encrypted']),
        ];
        $pdo->prepare(
            "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_data, ip_address)
             VALUES (:actor, 'marketing_associate_sensitive_data_revealed', 'marketing_associate', :entity_id, :after_data, :ip)"
        )->execute([
            'actor' => (int) $admin['id'],
            'entity_id' => (int) $associate['id'],
            'after_data' => json_encode(['fields' => array_keys($details)], JSON_THROW_ON_ERROR),
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
        Response::json(['success' => true, 'details' => $details]);
    } catch (Throwable $exception) {
        error_log('Marketing Associate details reveal failed: ' . $exception->getMessage());
        Response::json(['success' => false, 'message' => 'Sensitive details could not be retrieved'], 500);
    }
}

$adminRemarks = trim((string) ($_POST['admin_remarks'] ?? ''));
if (mb_strlen($adminRemarks) > 2000) {
    Response::json(['success' => false, 'message' => 'Admin remarks must be 2000 characters or fewer'], 422);
}
if (!in_array($action, ['approve', 'reject', 'block', 'unblock', 'save_remarks'], true)) {
    Response::json(['success' => false, 'message' => 'Invalid review action'], 422);
}
if ($action === 'reject' && $adminRemarks === '') {
    Response::json(['success' => false, 'message' => 'A rejection reason is required'], 422);
}

$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        'SELECT ma.id, ma.user_id, ma.approval_status, ma.designation_code, u.account_status, u.full_name
         FROM marketing_associates ma JOIN users u ON u.id = ma.user_id
         WHERE ma.id = :id FOR UPDATE'
    );
    $statement->execute(['id' => $associateId]);
    $before = $statement->fetch();
    if ($before === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Registration not found'], 404);
    }

    $oldStatus = $before['approval_status'];
    $newStatus = $oldStatus;
    if ($action === 'approve' || $action === 'reject') {
        if ($oldStatus !== 'pending') {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Only pending registrations can be approved or rejected'], 409);
        }
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        if ($action === 'approve') {
            if (!is_string($before['designation_code']) || !array_key_exists($before['designation_code'], MarketingAssociateHierarchy::DESIGNATIONS)) {
                $pdo->rollBack();
                Response::json(['success' => false, 'message' => 'Save a valid designation before approving this associate'], 409);
            }
            $pdo->prepare(
                "UPDATE users SET account_status = 'active', approved_by = :admin, approved_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            )->execute(['admin' => (int) $admin['id'], 'id' => (int) $before['user_id']]);
            $pdo->prepare(
                "UPDATE marketing_associates
                 SET approval_status = 'approved', approved_by = :admin, approved_at = UTC_TIMESTAMP(),
                     rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL,
                     admin_remarks = COALESCE(:remarks, admin_remarks)
                 WHERE id = :id"
            )->execute(['admin' => (int) $admin['id'], 'remarks' => $adminRemarks !== '' ? $adminRemarks : null, 'id' => (int) $associateId]);
            $pdo->prepare('INSERT INTO wallets (user_id) VALUES (:user_id) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)')
                ->execute(['user_id' => (int) $before['user_id']]);
        } else {
            $pdo->prepare("UPDATE users SET account_status = 'inactive' WHERE id = :id")
                ->execute(['id' => (int) $before['user_id']]);
            $pdo->prepare(
                "UPDATE marketing_associates
                 SET approval_status = 'rejected', approved_by = NULL, approved_at = NULL,
                     rejected_by = :admin, rejected_at = UTC_TIMESTAMP(),
                     rejection_reason = :reason, admin_remarks = :remarks
                 WHERE id = :id"
            )->execute([
                'admin' => (int) $admin['id'],
                'reason' => $adminRemarks,
                'remarks' => $adminRemarks,
                'id' => (int) $associateId,
            ]);
        }
    } elseif ($action === 'block' || $action === 'unblock') {
        if ($action === 'unblock') {
            if ($oldStatus !== 'blocked') {
                $pdo->rollBack();
                Response::json(['success' => false, 'message' => 'Only blocked associates can be unblocked'], 409);
            }
            if (!is_string($before['designation_code']) || !array_key_exists($before['designation_code'], MarketingAssociateHierarchy::DESIGNATIONS)) {
                $pdo->rollBack();
                Response::json(['success' => false, 'message' => 'Save a valid designation before unblocking this associate'], 409);
            }
            $newStatus = 'approved';
            $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")
                ->execute(['id' => (int) $before['user_id']]);
            $pdo->prepare(
                "UPDATE marketing_associates
                 SET approval_status = 'approved', admin_remarks = COALESCE(:remarks, admin_remarks)
                 WHERE id = :id"
            )->execute(['remarks' => $adminRemarks !== '' ? $adminRemarks : null, 'id' => (int) $associateId]);
            $pdo->prepare('INSERT INTO wallets (user_id) VALUES (:user_id) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)')
                ->execute(['user_id' => (int) $before['user_id']]);
        } else {
        if ($oldStatus !== 'approved') {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Only approved associates can be blocked'], 409);
        }
        $newStatus = 'blocked';
        $pdo->prepare("UPDATE users SET account_status = 'blocked' WHERE id = :id")
            ->execute(['id' => (int) $before['user_id']]);
        $pdo->prepare(
            "UPDATE marketing_associates
             SET approval_status = 'blocked', admin_remarks = COALESCE(:remarks, admin_remarks)
             WHERE id = :id"
        )->execute(['remarks' => $adminRemarks !== '' ? $adminRemarks : null, 'id' => (int) $associateId]);
        }
    } else {
        $pdo->prepare('UPDATE marketing_associates SET admin_remarks = :remarks WHERE id = :id')
            ->execute(['remarks' => $adminRemarks !== '' ? $adminRemarks : null, 'id' => (int) $associateId]);
    }

    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, :action, \'marketing_associate\', :entity_id, :before_data, :after_data)'
    )->execute([
        'actor' => (int) $admin['id'],
        'action' => $action === 'save_remarks' ? 'marketing_associate_remarks_updated' : 'marketing_associate_' . $action,
        'entity_id' => (int) $associateId,
        'before_data' => json_encode(['approval_status' => $oldStatus], JSON_THROW_ON_ERROR),
        'after_data' => json_encode([
            'approval_status' => $newStatus,
            'rejection_reason' => $action === 'reject' ? $adminRemarks : null,
        ], JSON_THROW_ON_ERROR),
    ]);

    if ($action !== 'save_remarks') {
        $message = [
            'approved' => 'Your Marketing Associate registration has been approved.',
            'rejected' => 'Your Marketing Associate registration has been rejected. Please contact the administrator.',
            'blocked' => 'Your Marketing Associate account has been blocked. Please contact the administrator.',
        ][$newStatus];
        $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, target_type, target_id)
             VALUES (:user_id, 'Marketing Associate account update', :message, 'marketing_associate', :target_id)"
        )->execute([
            'user_id' => (int) $before['user_id'],
            'message' => $message,
            'target_id' => (int) $associateId,
        ]);
    }

    if ($action === 'approve') {
        $approvalNotice = $before['full_name'] . ' (Associate #' . sprintf('%03d', (int) $associateId) . ') has been successfully approved.';
        $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, target_type, target_id)
             VALUES (:user_id, 'Associate approved', :message, 'marketing_associate', :target_id)"
        )->execute([
            'user_id' => (int) $admin['id'],
            'message' => $approvalNotice,
            'target_id' => (int) $associateId,
        ]);
    }

    $pdo->commit();
    if ($action === 'approve') {
        $_SESSION['associate_approval_notice'] = $approvalNotice;
    }
    Response::json([
        'success' => true,
        'message' => 'Marketing Associate registration updated',
        'redirect_url' => $action === 'approve' ? '/admin/index.php' : null,
    ]);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Marketing Associate review failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The registration could not be updated'], 500);
}
