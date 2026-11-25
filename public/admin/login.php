<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

Security::startSession();
$error = '';
$statusCode = 200;

if (Auth::currentUser() !== null && ($_SESSION['role_code'] ?? '') === 'admin') {
    header('Location: /admin/index.php', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
        $statusCode = 403;
    } else {
        $identity = trim((string) ($_POST['identity'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $pdo = Database::connection();
        $limit = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action = 'admin_login_failed' AND ip_address = :ip
               AND created_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE"
        );
        $limit->execute(['ip' => $ip]);

        if ((int) $limit->fetchColumn() >= 10) {
            $error = 'Too many attempts. Try again later.';
            $statusCode = 429;
        } elseif ($identity !== '' && strlen($identity) <= 190 && strlen($password) <= 200) {
            $statement = $pdo->prepare(
                'SELECT u.id, u.password_hash, u.account_status, u.full_name, r.code AS role_code
                 FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE u.email = :identity_email OR u.phone = :identity_phone
                 LIMIT 1'
            );
            $statement->execute(['identity_email' => $identity, 'identity_phone' => $identity]);
            $user = $statement->fetch();
            if ($user !== false && $user['role_code'] === 'admin' && $user['account_status'] === 'active'
                && password_verify($password, $user['password_hash'])
            ) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['role_code'] = 'admin';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->execute(['id' => (int) $user['id']]);
                $pdo->prepare(
                    "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address)
                     VALUES (:actor, 'admin_login_success', 'user', :entity_id, :ip)"
                )->execute(['actor' => (int) $user['id'], 'entity_id' => (int) $user['id'], 'ip' => $ip]);
                header('Location: /admin/index.php', true, 303);
                exit;
            }

            $pdo->prepare(
                "INSERT INTO audit_logs (action, entity_type, ip_address)
                 VALUES ('admin_login_failed', 'user', :ip)"
            )->execute(['ip' => $ip]);
            $error = 'Invalid credentials or inactive account.';
            $statusCode = 401;
        } else {
            $error = 'Enter a valid email or phone and password.';
            $statusCode = 422;
        }
    }
}

http_response_code($statusCode);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin sign in | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <main class="admin-auth">
    <a class="brand admin-brand" href="/"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <h1>Administrator sign in</h1>
    <?php if ($error !== ''): ?><p class="admin-alert" role="alert"><?= Security::escape($error) ?></p><?php endif; ?>
    <form method="post" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= Security::escape(Security::csrfToken()) ?>">
      <label>Email or phone<input name="identity" required maxlength="190" autocomplete="username"></label>
      <label>Password<input type="password" name="password" required maxlength="200" autocomplete="current-password"></label>
      <button class="button button-dark" type="submit">Sign in</button>
    </form>
    <p><a href="/">Return to website</a></p>
  </main>
</body>
</html>
