<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';
Security::startSession();
$manager = Auth::requireEmployeeApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
}
if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    Response::json(['success' => false, 'message' => 'Invalid request token'], 403);
}

$pdo = Database::connection();
$designationStatement = $pdo->prepare(
    'SELECT designation_code FROM marketing_associates WHERE user_id = :user_id AND approval_status = \'approved\''
);
$designationStatement->execute(['user_id' => (int) $manager['id']]);
$managerDesignation = $designationStatement->fetchColumn();
if (!is_string($managerDesignation) || !array_key_exists($managerDesignation, MarketingAssociateHierarchy::DESIGNATIONS)) {
    Response::json(['success' => false, 'message' => 'An Admin-assigned designation is required to recruit'], 403);
}
$suggestedDesignation = MarketingAssociateHierarchy::targetDesignation($managerDesignation);

$token = bin2hex(random_bytes(32));
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        'INSERT INTO recruitment_invites
         (manager_user_id, target_role_code, suggested_designation_code, token_hash, expires_at)
         VALUES (:manager_id, \'marketing_associate\', :suggested_designation, :token_hash,
                 UTC_TIMESTAMP() + INTERVAL 7 DAY)'
    );
    $statement->execute([
        'manager_id' => (int) $manager['id'],
        'suggested_designation' => $suggestedDesignation,
        'token_hash' => hash('sha256', $token, true),
    ]);
    $inviteId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_data)
         VALUES (:actor, 'recruitment_invite_created', 'recruitment_invite', :entity_id, :after_data)"
    )->execute([
        'actor' => (int) $manager['id'],
        'entity_id' => $inviteId,
        'after_data' => json_encode([
            'target_role_code' => 'marketing_associate',
            'suggested_designation_code' => $suggestedDesignation,
        ], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Recruitment invite creation failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The recruitment invitation could not be created'], 500);
}

$inviteUrl = '/marketing-associate/signup.php?invite=' . rawurlencode($token);
$designationLabel = $suggestedDesignation === null
    ? 'Marketing Associate; Admin assigns designation'
    : MarketingAssociateHierarchy::DESIGNATIONS[$suggestedDesignation] . ' suggestion (Admin approval required)';
Response::json([
    'success' => true,
    'message' => 'One-time invitation for ' . $designationLabel . ' created. It expires in seven days.',
    'invite_url' => $inviteUrl,
]);
