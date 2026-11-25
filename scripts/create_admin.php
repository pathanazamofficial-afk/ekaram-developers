<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$name = trim((string) getenv('ADMIN_NAME'));
$email = strtolower(trim((string) getenv('ADMIN_EMAIL')));
$phone = trim((string) getenv('ADMIN_PHONE'));
$password = (string) getenv('ADMIN_PASSWORD');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[0-9+()\-\s]{8,25}$/', $phone)
    || strlen($password) < 12
    || strlen($password) > 200
) {
    fwrite(STDERR, "Set ADMIN_NAME, ADMIN_EMAIL, ADMIN_PHONE, and a 12-200 character ADMIN_PASSWORD in the current terminal environment.\n");
    exit(2);
}

$pdo = Database::connection();
$adminCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin'"
)->fetchColumn();
if ($adminCount > 0) {
    fwrite(STDERR, "An administrator already exists; use the admin dashboard to manage accounts.\n");
    exit(3);
}

$roleId = $pdo->query("SELECT id FROM roles WHERE code = 'admin'")->fetchColumn();
if ($roleId === false) {
    throw new RuntimeException('Administrator role is missing from the development schema');
}

$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        "INSERT INTO users (role_id, full_name, email, phone, password_hash, account_status, approved_at)
         VALUES (:role_id, :full_name, :email, :phone, :password_hash, 'active', UTC_TIMESTAMP())"
    );
    $statement->execute([
        'role_id' => (int) $roleId,
        'full_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();

    $audit = $pdo->prepare(
        "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_data)
         VALUES (:actor, 'initial_admin_created', 'user', :entity_id, :after_data)"
    );
    $audit->execute([
        'actor' => $userId,
        'entity_id' => $userId,
        'after_data' => json_encode(['role' => 'admin'], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    fwrite(STDOUT, "Administrator account created. Sign in at /admin/login.php.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
} finally {
    putenv('ADMIN_PASSWORD');
}
