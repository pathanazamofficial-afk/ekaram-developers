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
$action = (string) ($_POST['action'] ?? '');
if ($id === false || !in_array($action, ['approve', 'reject', 'pay'], true)) {
    Response::json(['success' => false, 'message' => 'Choose a valid withdrawal action'], 422);
}
$paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
if ($action === 'pay' && ($paymentReference === '' || strlen($paymentReference) > 100)) {
    Response::json(['success' => false, 'message' => 'Enter a valid payment reference'], 422);
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $ownerStatement = $pdo->prepare('SELECT user_id FROM withdrawal_requests WHERE id = :id');
    $ownerStatement->execute(['id' => $id]);
    $ownerId = $ownerStatement->fetchColumn();
    if ($ownerId === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Withdrawal request not found'], 404);
    }
    $walletStatement = $pdo->prepare('SELECT id, cached_balance FROM wallets WHERE user_id = :user_id FOR UPDATE');
    $walletStatement->execute(['user_id' => (int) $ownerId]);
    $wallet = $walletStatement->fetch();
    if ($wallet === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Employee wallet is unavailable'], 409);
    }
    $statement = $pdo->prepare('SELECT * FROM withdrawal_requests WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $id]);
    $withdrawal = $statement->fetch();
    if ($withdrawal === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Withdrawal request not found'], 404);
    }

    if ($action === 'approve' || $action === 'reject') {
        if ($withdrawal['status'] !== 'requested') {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Only requested withdrawals can be reviewed'], 409);
        }
        if ($action === 'approve') {
            $reserved = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests
                 WHERE user_id = :user_id AND status IN ('requested', 'approved')"
            );
            $reserved->execute(['user_id' => (int) $withdrawal['user_id']]);
            if (Money::toMinorUnits((string) $wallet['cached_balance']) < Money::toMinorUnits((string) $reserved->fetchColumn())) {
                $pdo->rollBack();
                Response::json(['success' => false, 'message' => 'Employee wallet funds are insufficient for this request'], 409);
            }
        }
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $pdo->prepare(
            'UPDATE withdrawal_requests SET status = :status, reviewed_by = :admin, reviewed_at = UTC_TIMESTAMP()
             WHERE id = :id'
        )->execute(['status' => $newStatus, 'admin' => (int) $admin['id'], 'id' => (int) $id]);
    } else {
        if ($withdrawal['status'] !== 'approved') {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Only approved withdrawals can be marked paid'], 409);
        }
        $reserved = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests
             WHERE user_id = :user_id AND status IN ('requested', 'approved')"
        );
        $reserved->execute(['user_id' => (int) $withdrawal['user_id']]);
        if ($wallet === false || Money::toMinorUnits((string) $wallet['cached_balance']) < Money::toMinorUnits((string) $reserved->fetchColumn())) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Employee wallet funds are insufficient for this payment'], 409);
        }
        $idempotencyKey = 'withdrawal:' . (int) $id . ':payout';
        $exists = $pdo->prepare('SELECT id FROM wallet_transactions WHERE idempotency_key = :key');
        $exists->execute(['key' => $idempotencyKey]);
        if ($exists->fetchColumn() !== false) {
            throw new RuntimeException('A payout ledger entry already exists');
        }
        $pdo->prepare('UPDATE wallets SET cached_balance = cached_balance - :amount WHERE id = :id')
            ->execute(['amount' => $withdrawal['amount'], 'id' => (int) $wallet['id']]);
        $afterBalance = $pdo->prepare('SELECT cached_balance FROM wallets WHERE id = :id');
        $afterBalance->execute(['id' => (int) $wallet['id']]);
        $pdo->prepare(
            "INSERT INTO wallet_transactions
             (wallet_id, transaction_type, amount, balance_after, reference_type, reference_id, idempotency_key, description)
             VALUES (:wallet_id, 'debit', :amount, :balance, 'withdrawal', :reference_id, :key, 'Approved employee payout')"
        )->execute([
            'wallet_id' => (int) $wallet['id'],
            'amount' => $withdrawal['amount'],
            'balance' => $afterBalance->fetchColumn(),
            'reference_id' => (int) $id,
            'key' => $idempotencyKey,
        ]);
        $pdo->prepare(
            'UPDATE withdrawal_requests
             SET status = \'paid\', reviewed_by = :admin, reviewed_at = UTC_TIMESTAMP(),
                 payment_reference = :reference, paid_at = UTC_TIMESTAMP()
             WHERE id = :id'
        )->execute(['admin' => (int) $admin['id'], 'reference' => $paymentReference, 'id' => (int) $id]);
        $newStatus = 'paid';
    }

    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, :action, \'withdrawal\', :entity_id, :before_data, :after_data)'
    )->execute([
        'actor' => (int) $admin['id'],
        'action' => 'withdrawal_' . $newStatus,
        'entity_id' => (int) $id,
        'before_data' => json_encode(['status' => $withdrawal['status']], JSON_THROW_ON_ERROR),
        'after_data' => json_encode(['status' => $newStatus, 'payment_reference' => $action === 'pay' ? $paymentReference : null], JSON_THROW_ON_ERROR),
    ]);
    $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, target_type, target_id)
         VALUES (:user_id, :title, :message, \'withdrawal\', :target_id)'
    )->execute([
        'user_id' => (int) $withdrawal['user_id'],
        'title' => 'Withdrawal ' . $newStatus,
        'message' => 'Your withdrawal request status is now ' . $newStatus . '.',
        'target_id' => (int) $id,
    ]);
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Withdrawal ' . $newStatus]);
} catch (PDOException | RuntimeException | InvalidArgumentException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Withdrawal update failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The withdrawal could not be updated'], 500);
}
