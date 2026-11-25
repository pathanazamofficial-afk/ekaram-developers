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

$associateId = filter_var($_POST['associate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = trim((string) ($_POST['action'] ?? ''));
if ($associateId === false || !in_array($action, ['credit', 'reverse'], true)) {
    Response::json(['success' => false, 'message' => 'Select a Marketing Associate and a valid wallet action'], 422);
}

$statement = Database::connection()->prepare(
    'SELECT user_id FROM marketing_associates WHERE id = :id'
);
$statement->execute(['id' => $associateId]);
$associateUserId = $statement->fetchColumn();
if ($associateUserId === false) {
    Response::json(['success' => false, 'message' => 'Marketing Associate not found'], 404);
}

$remarks = trim((string) ($_POST['remarks'] ?? ''));
$idempotencyToken = trim((string) ($_POST['idempotency_token'] ?? ''));
$ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;
try {
    if ($action === 'credit') {
        $amountInput = trim((string) ($_POST['amount'] ?? ''));
        if (!preg_match('/^([1-9]\d{0,12})(?:\.(\d{1,2}))?$/', $amountInput, $parts)) {
            Response::json(['success' => false, 'message' => 'Enter a positive amount with up to 2 decimal places'], 422);
        }
        $amountMinor = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '0', 2, '0');
        MarketingAssociateWalletService::credit(
            Database::connection(),
            (int) $associateUserId,
            (int) $admin['id'],
            $amountMinor,
            $remarks,
            $idempotencyToken,
            $ipAddress
        );
        Response::json(['success' => true, 'message' => 'Wallet credit recorded']);
    }

    $transactionId = filter_var($_POST['wallet_transaction_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($transactionId === false) {
        Response::json(['success' => false, 'message' => 'Select a credit transaction to reverse'], 422);
    }
    MarketingAssociateWalletService::reverseCredit(
        Database::connection(),
        (int) $associateUserId,
        (int) $admin['id'],
        (int) $transactionId,
        $remarks,
        $ipAddress
    );
    Response::json(['success' => true, 'message' => 'Wallet credit reversed through an auditable ledger entry']);
} catch (DomainException $exception) {
    Response::json(['success' => false, 'message' => $exception->getMessage()], 409);
} catch (InvalidArgumentException $exception) {
    Response::json(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Marketing Associate wallet administration failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The wallet transaction could not be completed'], 500);
}
