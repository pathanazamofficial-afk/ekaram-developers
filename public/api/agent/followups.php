<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';
Security::startSession();
$employee = Auth::requireEmployeeApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
}
if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    Response::json(['success' => false, 'message' => 'Invalid request token'], 403);
}
$leadId = filter_var($_POST['lead_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$notes = trim((string) ($_POST['notes'] ?? ''));
$outcome = trim((string) ($_POST['outcome'] ?? ''));
$dueInput = trim((string) ($_POST['due_at'] ?? ''));
if ($leadId === false || $notes === '' || mb_strlen($notes) > 2000
    || ($outcome !== '' && mb_strlen($outcome) > 100)
) {
    Response::json(['success' => false, 'message' => 'Enter a lead, note and valid outcome'], 422);
}

$dueAt = null;
if ($dueInput !== '') {
    $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $dueInput, new DateTimeZone(appConfig()['timezone']));
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($dueDate === false || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
        || $dueDate->format('Y-m-d\TH:i') !== $dueInput
    ) {
        Response::json(['success' => false, 'message' => 'Enter a valid follow-up date and time'], 422);
    }
    $dueAt = $dueDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

$visibleIds = Auth::visibleUserIds((int) $employee['id']);
$placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $access = $pdo->prepare("SELECT id FROM leads WHERE id = ? AND assigned_to IN ($placeholders) FOR UPDATE");
    $access->execute(array_merge([(int) $leadId], $visibleIds));
    if ($access->fetchColumn() === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Lead is outside your reporting scope'], 404);
    }
    $pdo->prepare(
        'INSERT INTO lead_followups (lead_id, user_id, due_at, outcome, notes)
         VALUES (:lead_id, :user_id, :due_at, :outcome, :notes)'
    )->execute([
        'lead_id' => (int) $leadId,
        'user_id' => (int) $employee['id'],
        'due_at' => $dueAt,
        'outcome' => $outcome !== '' ? $outcome : null,
        'notes' => $notes,
    ]);
    $followupId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id)
         VALUES (:actor, \'lead_followup_added\', \'lead_followup\', :entity_id)'
    )->execute(['actor' => (int) $employee['id'], 'entity_id' => $followupId]);
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Follow-up saved'], 201);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Lead follow-up failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The follow-up could not be saved'], 500);
}
