<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();

$error = '';
$statusCode = 200;
$currentUser = Auth::currentUser();
if ($currentUser !== null && $currentUser['role_code'] === 'marketing_associate') {
    $profile = Database::connection()->prepare(
        'SELECT ma.approval_status, ma.designation_code
         FROM marketing_associates ma
         WHERE ma.user_id = :user_id'
    );
    $profile->execute(['user_id' => (int) $currentUser['id']]);
    $currentAssignment = $profile->fetch();
    if ($currentAssignment !== false
        && $currentAssignment['approval_status'] === 'approved'
        && is_string($currentAssignment['designation_code'])
        && array_key_exists($currentAssignment['designation_code'], MarketingAssociateHierarchy::DESIGNATIONS)
    ) {
        header('Location: /marketing-associate/index.php', true, 303);
        exit;
    }
    unset($_SESSION['user_id'], $_SESSION['role_code']);
    $error = 'Your account needs administrator approval and an assigned designation.';
    $statusCode = 403;
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
             WHERE action IN ('marketing_associate_login_failed', 'marketing_associate_login_denied')
               AND ip_address = :ip
               AND created_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE"
        );
        $limit->execute(['ip' => $ip]);

        if ((int) $limit->fetchColumn() >= 10) {
            $error = 'Too many attempts. Try again later.';
            $statusCode = 429;
        } elseif ($identity !== '' && mb_strlen($identity) <= 190 && strlen($password) <= 200) {
            $phoneIdentity = preg_match('/^[6-9][0-9]{9}$/', $identity)
                ? '+91' . $identity
                : $identity;
            $statement = $pdo->prepare(
                "SELECT u.id, u.password_hash, u.account_status, u.full_name,
                        ma.approval_status, ma.designation_code,
                        r.code AS role_code
                 FROM users u
                 JOIN roles r ON r.id = u.role_id
                 JOIN marketing_associates ma ON ma.user_id = u.id
                 WHERE (u.phone = :identity_phone OR u.email = :identity_email)
                   AND r.code = 'marketing_associate'
                 LIMIT 1"
            );
            $statement->execute(['identity_phone' => $phoneIdentity, 'identity_email' => $identity]);
            $associate = $statement->fetch();

            if ($associate !== false && password_verify($password, $associate['password_hash'])) {
                if ($associate['approval_status'] === 'approved'
                    && $associate['account_status'] === 'active'
                    && is_string($associate['designation_code'])
                    && array_key_exists($associate['designation_code'], MarketingAssociateHierarchy::DESIGNATIONS)
                ) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $associate['id'];
                    $_SESSION['role_code'] = 'marketing_associate';
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')
                        ->execute(['id' => (int) $associate['id']]);
                    $pdo->prepare(
                        "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address)
                         VALUES (:actor, 'marketing_associate_login_success', 'marketing_associate', :entity_id, :ip)"
                    )->execute([
                        'actor' => (int) $associate['id'],
                        'entity_id' => (int) $associate['id'],
                        'ip' => $ip !== '' ? $ip : null,
                    ]);
                    header('Location: /marketing-associate/index.php', true, 303);
                    exit;
                }

                $statusMessages = [
                    'pending' => 'Your registration is awaiting admin approval.',
                    'rejected' => 'Your registration was rejected. Please contact the administrator.',
                    'blocked' => 'Your account is blocked. Please contact the administrator.',
                ];
                $error = $statusMessages[$associate['approval_status']]
                    ?? 'Your account is not active or needs an Admin-assigned designation.';
                $statusCode = 403;
                $pdo->prepare(
                    "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address)
                     VALUES (:actor, 'marketing_associate_login_denied', 'marketing_associate', :entity_id, :ip)"
                )->execute([
                    'actor' => (int) $associate['id'],
                    'entity_id' => (int) $associate['id'],
                    'ip' => $ip !== '' ? $ip : null,
                ]);
            } else {
                $error = 'Invalid email/contact number or password.';
                $statusCode = 401;
                $pdo->prepare(
                    "INSERT INTO audit_logs (action, entity_type, ip_address)
                     VALUES ('marketing_associate_login_failed', 'marketing_associate', :ip)"
                )->execute(['ip' => $ip !== '' ? $ip : null]);
            }
        } else {
            $error = 'Enter your email or contact number and password.';
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
  <title>Marketing Associate Login | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <main class="admin-auth associate-auth">
    <a class="brand admin-brand" href="/"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <p class="section-kicker">Marketing Associate Portal</p>
    <h1>Login</h1>
    <?php if ($error !== ''): ?><p class="admin-alert" role="alert"><?= Security::escape($error) ?></p><?php endif; ?>
    <form method="post" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= Security::escape(Security::csrfToken()) ?>">
      <label>Email or Contact Number<input name="identity" type="text" maxlength="190" autocomplete="username" required></label>
      <label>Password<input name="password" type="password" maxlength="200" autocomplete="current-password" required></label>
      <button class="button button-dark" type="submit">Login</button>
    </form>
    <p><a href="/marketing-associate/forgot-password.php">Forgot Password?</a></p>
    <p>Not registered? <a href="/marketing-associate/signup.php">Sign Up</a></p>
    <p><a href="/">Return to website</a></p>
  </main>
</body>
</html>
