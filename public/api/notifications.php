<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
$user = Auth::currentUser();
if ($user === null) {
    Response::json(['success' => false, 'message' => 'Authentication required'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
}
if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    Response::json(['success' => false, 'message' => 'Invalid request token'], 403);
}
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    Response::json(['success' => false, 'message' => 'Select a notification'], 422);
}

$statement = Database::connection()->prepare(
    'UPDATE notifications SET read_at = UTC_TIMESTAMP()
     WHERE id = :id AND user_id = :user_id AND read_at IS NULL'
);
$statement->execute(['id' => $id, 'user_id' => (int) $user['id']]);
if ($statement->rowCount() === 0) {
    $exists = Database::connection()->prepare(
        'SELECT id FROM notifications WHERE id = :id AND user_id = :user_id'
    );
    $exists->execute(['id' => $id, 'user_id' => (int) $user['id']]);
    if ($exists->fetchColumn() === false) {
        Response::json(['success' => false, 'message' => 'Notification not found'], 404);
    }
}
Response::json(['success' => true, 'message' => 'Notification marked as read']);
