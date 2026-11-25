<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request');
}

$userId = isset($_SESSION['user_id']) && is_int($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
if ($userId !== null) {
    Database::connection()->prepare(
        "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address)
         VALUES (:actor, 'marketing_associate_logout', 'marketing_associate', :entity_id, :ip)"
    )->execute([
        'actor' => $userId,
        'entity_id' => $userId,
        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);
}

$_SESSION = [];
$params = session_get_cookie_params();
if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
header('Location: /marketing-associate/login.php', true, 303);
