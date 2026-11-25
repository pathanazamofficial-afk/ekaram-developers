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
$name = trim((string) ($_POST['full_name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$address = trim((string) ($_POST['address'] ?? ''));
if ($id === false || $name === '' || mb_strlen($name) > 150
    || !preg_match('/^[0-9+()\-\s]{8,25}$/', $phone)
    || ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190))
    || mb_strlen($address) > 500
) {
    Response::json(['success' => false, 'message' => 'Enter valid customer contact details'], 422);
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('SELECT id FROM customers WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $id]);
    if ($statement->fetchColumn() === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Customer not found'], 404);
    }
    $pdo->prepare(
        'UPDATE customers SET full_name = :name, phone = :phone, email = :email, address = :address WHERE id = :id'
    )->execute([
        'name' => $name,
        'phone' => $phone,
        'email' => $email !== '' ? $email : null,
        'address' => $address !== '' ? $address : null,
        'id' => (int) $id,
    ]);
    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_data)
         VALUES (:actor, \'customer_contact_updated\', \'customer\', :entity_id, :after_data)'
    )->execute([
        'actor' => (int) $admin['id'],
        'entity_id' => (int) $id,
        'after_data' => json_encode(['contact_fields' => ['full_name', 'phone', 'email', 'address']], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Customer contact updated']);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Customer update failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The customer could not be updated'], 500);
}
