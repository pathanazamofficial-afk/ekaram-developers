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

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$status = (string) ($_POST['status'] ?? '');
$assignee = trim((string) ($_POST['assigned_to'] ?? ''));
$ownerInput = trim((string) ($_POST['lead_owner_id'] ?? ''));
$ownerReason = trim((string) ($_POST['owner_change_reason'] ?? ''));
if ($id === false || !in_array($status, ['new', 'contacted', 'qualified', 'converted', 'lost'], true)) {
    Response::json(['success' => false, 'message' => 'Choose a valid lead and status'], 422);
}
$assigneeId = null;
if ($assignee !== '') {
    $assigneeId = filter_var($assignee, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($assigneeId === false) {
        Response::json(['success' => false, 'message' => 'Select a valid employee'], 422);
    }
}
$ownerId = null;
if ($ownerInput !== '') {
    $ownerId = filter_var($ownerInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($ownerId === false) {
        Response::json(['success' => false, 'message' => 'Select a valid lead owner'], 422);
    }
}
if (mb_strlen($ownerReason) > 1000) {
    Response::json(['success' => false, 'message' => 'Owner change reason must be 1000 characters or fewer'], 422);
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('SELECT * FROM leads WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $id]);
    $before = $statement->fetch();
    if ($before === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Lead not found'], 404);
    }
    if ($ownerId === null && $before['lead_owner_id'] === null && $assigneeId !== null) {
        $ownerId = $assigneeId;
    } elseif ($ownerId === null) {
        $ownerId = $before['lead_owner_id'] === null ? null : (int) $before['lead_owner_id'];
    }
    if ($ownerId !== null && (int) ($before['lead_owner_id'] ?? 0) !== $ownerId) {
        $ownerCheck = $pdo->prepare(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.account_status = 'active' AND r.code = 'marketing_associate'
               AND EXISTS (
                 SELECT 1 FROM marketing_associates ma
                 WHERE ma.user_id = u.id AND ma.approval_status = 'approved'
                   AND ma.designation_code IS NOT NULL
               )"
        );
        $ownerCheck->execute(['id' => $ownerId]);
        if ($ownerCheck->fetchColumn() === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Select an active, approved Marketing Associate as lead owner'], 422);
        }
        if ($before['lead_owner_id'] !== null && $ownerReason === '') {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'A reason is required to correct a locked lead owner'], 422);
        }
    }
    if ($assigneeId !== null) {
        $employeeCheck = $pdo->prepare(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.account_status = 'active' AND r.code = 'marketing_associate'
               AND EXISTS (
                 SELECT 1 FROM marketing_associates ma
                 WHERE ma.user_id = u.id AND ma.approval_status = 'approved'
                   AND ma.designation_code IS NOT NULL
               )"
        );
        $employeeCheck->execute(['id' => $assigneeId]);
        if ($employeeCheck->fetchColumn() === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Select an active, approved Marketing Associate'], 422);
        }
    }

    $pdo->prepare(
        'UPDATE leads
         SET status = :status, assigned_to = :assigned_to, lead_owner_id = :owner_id,
             ownership_locked_at = CASE WHEN :owner_is_set = 1
               THEN COALESCE(ownership_locked_at, UTC_TIMESTAMP()) ELSE ownership_locked_at END
         WHERE id = :id'
    )->execute([
        'status' => $status,
        'assigned_to' => $assigneeId,
        'owner_id' => $ownerId,
        'owner_is_set' => $ownerId === null ? 0 : 1,
        'id' => $id,
    ]);
    if ((int) ($before['assigned_to'] ?? 0) !== (int) ($assigneeId ?? 0)) {
        $pdo->prepare(
            'INSERT INTO lead_assignments (lead_id, assigned_to, assigned_by, note)
             VALUES (:lead_id, :assigned_to, :assigned_by, :note)'
        )->execute([
            'lead_id' => (int) $id,
            'assigned_to' => $assigneeId,
            'assigned_by' => (int) $admin['id'],
            'note' => 'Updated by administrator',
        ]);
    }
    if ((int) ($before['lead_owner_id'] ?? 0) !== (int) ($ownerId ?? 0)) {
        $pdo->prepare(
            'INSERT INTO lead_ownership_history (lead_id, old_owner_id, new_owner_id, changed_by, reason)
             VALUES (:lead_id, :old_owner, :new_owner, :actor, :reason)'
        )->execute([
            'lead_id' => (int) $id,
            'old_owner' => $before['lead_owner_id'],
            'new_owner' => $ownerId,
            'actor' => (int) $admin['id'],
            'reason' => $ownerReason !== '' ? $ownerReason : 'Initial lead owner assignment',
        ]);
    }
    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, \'lead_updated\', \'lead\', :entity_id, :before_data, :after_data)'
    )->execute([
        'actor' => (int) $admin['id'],
        'entity_id' => (int) $id,
        'before_data' => json_encode($before, JSON_THROW_ON_ERROR),
        'after_data' => json_encode(['status' => $status, 'assigned_to' => $assigneeId, 'lead_owner_id' => $ownerId], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Lead updated']);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Lead update failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The lead could not be updated'], 500);
}
