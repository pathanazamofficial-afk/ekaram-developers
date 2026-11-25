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

$amountInput = trim((string) ($_POST['amount'] ?? ''));
if (!preg_match('/^([1-9]\d{0,11})(?:\.(\d{1,2}))?$/', $amountInput, $amountParts)) {
    Response::json(['success' => false, 'message' => 'Enter a positive amount with up to 2 decimal places'], 422);
}
$amountCents = ((int) $amountParts[1] * 100) + (int) str_pad($amountParts[2] ?? '0', 2, '0');
$amount = Money::fromMinorUnits($amountCents);
$accountName = trim((string) ($_POST['account_name'] ?? ''));
$accountNumber = preg_replace('/[\s-]/', '', (string) ($_POST['account_number'] ?? ''));
$bankName = trim((string) ($_POST['bank_name'] ?? ''));
$ifsc = strtoupper(trim((string) ($_POST['ifsc'] ?? '')));
if ($accountName === '' || mb_strlen($accountName) > 120
    || !preg_match('/^\d{6,34}$/', $accountNumber)
    || $bankName === '' || mb_strlen($bankName) > 120
    || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)
) {
    Response::json(['success' => false, 'message' => 'Enter valid bank account details and IFSC'], 422);
}

$pdo = Database::connection();
try {
    $encryptedDetails = PayoutEncryption::encrypt([
        'account_name' => $accountName,
        'account_number' => $accountNumber,
        'bank_name' => $bankName,
        'ifsc' => $ifsc,
    ]);
} catch (RuntimeException $exception) {
    error_log('Payout encryption unavailable: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'Payout encryption is not configured. Contact the administrator.'], 503);
}

$pdo->beginTransaction();
try {
    $walletStatement = $pdo->prepare('SELECT id, cached_balance FROM wallets WHERE user_id = :user_id FOR UPDATE');
    $walletStatement->execute(['user_id' => (int) $employee['id']]);
    $wallet = $walletStatement->fetch();
    if ($wallet === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Your wallet is not active yet'], 409);
    }
    $openStatement = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests
         WHERE user_id = :user_id AND status IN ('requested', 'approved')"
    );
    $openStatement->execute(['user_id' => (int) $employee['id']]);
    $openAmount = (string) $openStatement->fetchColumn();
    if (Money::toMinorUnits((string) $wallet['cached_balance']) - Money::toMinorUnits($openAmount) < $amountCents) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'The requested amount exceeds your available wallet balance'], 409);
    }

    $pdo->prepare(
        "INSERT INTO withdrawal_requests (user_id, amount, payout_details_encrypted, status)
         VALUES (:user_id, :amount, :details, 'requested')"
    )->execute(['user_id' => (int) $employee['id'], 'amount' => $amount, 'details' => $encryptedDetails]);
    $withdrawalId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_data)
         VALUES (:actor, \'withdrawal_requested\', \'withdrawal\', :entity_id, :after_data)'
    )->execute([
        'actor' => (int) $employee['id'],
        'entity_id' => $withdrawalId,
        'after_data' => json_encode(['amount' => $amount], JSON_THROW_ON_ERROR),
    ]);
    $admins = $pdo->query(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.code = 'admin' AND u.account_status = 'active'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $notification = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, target_type, target_id)
         VALUES (:user_id, \'Withdrawal request\', :message, \'withdrawal\', :target_id)'
    );
    foreach ($admins as $adminId) {
        $notification->execute([
            'user_id' => (int) $adminId,
            'message' => 'An employee submitted a withdrawal request.',
            'target_id' => $withdrawalId,
        ]);
    }
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Withdrawal request submitted'], 201);
} catch (PDOException | InvalidArgumentException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Withdrawal request failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The withdrawal request could not be saved'], 500);
}
