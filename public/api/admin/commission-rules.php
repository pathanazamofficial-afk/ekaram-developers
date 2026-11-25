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

$requiredCodes = [
    'marketing_manager',
    'deputy_manager',
    'assistant_general_manager',
    'deputy_general_manager',
    'deputy_director',
];
$rates = $_POST['rates'] ?? null;
if (!is_array($rates) || array_diff($requiredCodes, array_keys($rates)) || array_diff(array_keys($rates), $requiredCodes)) {
    Response::json(['success' => false, 'message' => 'Provide a percentage for every employee tier'], 422);
}

$basisPoints = 0;
foreach ($requiredCodes as $code) {
    if (!is_string($rates[$code]) && !is_int($rates[$code]) && !is_float($rates[$code])) {
        Response::json(['success' => false, 'message' => 'Enter valid commission percentages'], 422);
    }
    $value = trim((string) $rates[$code]);
    if (!preg_match('/^(?:\d{1,3})(?:\.\d{1,2})?$/', $value)) {
        Response::json(['success' => false, 'message' => 'Percentages must be between 0 and 100 with up to 2 decimals'], 422);
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
    $rateBasisPoints = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    if ($rateBasisPoints > 10000) {
        Response::json(['success' => false, 'message' => 'Percentages must be between 0 and 100'], 422);
    }
    $basisPoints += $rateBasisPoints;
    $rates[$code] = number_format($rateBasisPoints / 100, 2, '.', '');
}
if ($basisPoints > 1200) {
    Response::json(['success' => false, 'message' => 'Employee commission shares must not exceed the 12% commission pool'], 422);
}
$rates['company'] = number_format((10000 - $basisPoints) / 100, 2, '.', '');

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $version = (int) $pdo->query('SELECT COALESCE(MAX(version_no), 0) + 1 FROM commission_rule_versions')
        ->fetchColumn();
    $insert = $pdo->prepare(
        'INSERT INTO commission_rule_versions (version_no, recipient_code, percentage, effective_from, created_by)
         VALUES (:version, :recipient, :percentage, UTC_TIMESTAMP(), :actor)'
    );
    foreach ($requiredCodes as $code) {
        $insert->execute([
            'version' => $version,
            'recipient' => $code,
            'percentage' => $rates[$code],
            'actor' => (int) $admin['id'],
        ]);
    }
    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_data)
         VALUES (:actor, \'commission_rules_created\', \'commission_rule_version\', :entity_id, :after_data)'
    )->execute([
        'actor' => (int) $admin['id'],
        'entity_id' => $version,
        'after_data' => json_encode($rates, JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Commission version ' . $version . ' is now active']);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Commission rule update failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The commission rules could not be saved'], 500);
}
