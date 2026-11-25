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
if ($id === false) {
    Response::json(['success' => false, 'message' => 'Select a withdrawal request'], 422);
}

$statement = Database::connection()->prepare(
    'SELECT payout_details_encrypted FROM withdrawal_requests WHERE id = :id'
);
$statement->execute(['id' => $id]);
$encrypted = $statement->fetchColumn();
if ($encrypted === false || $encrypted === null) {
    Response::json(['success' => false, 'message' => 'Payout details are unavailable'], 404);
}
Database::connection()->prepare(
    "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id)
     VALUES (:actor, 'withdrawal_details_viewed', 'withdrawal', :entity_id)"
)->execute(['actor' => (int) $admin['id'], 'entity_id' => (int) $id]);
try {
    Response::json(['success' => true, 'details' => PayoutEncryption::decrypt((string) $encrypted)]);
} catch (RuntimeException | JsonException $exception) {
    error_log('Payout detail decryption failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'Payout details could not be decrypted'], 500);
}
