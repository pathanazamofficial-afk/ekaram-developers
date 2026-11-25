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

$withdrawalId = filter_var($_POST['withdrawal_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = trim((string) ($_POST['action'] ?? ''));
if ($withdrawalId === false) {
    Response::json(['success' => false, 'message' => 'Select a withdrawal request'], 422);
}

try {
    MarketingAssociateWalletService::transitionWithdrawal(
        Database::connection(),
        (int) $withdrawalId,
        (int) $admin['id'],
        $action,
        trim((string) ($_POST['remarks'] ?? '')),
        trim((string) ($_POST['payment_reference'] ?? '')),
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null
    );
    Response::json(['success' => true, 'message' => 'Withdrawal status updated']);
} catch (DomainException $exception) {
    Response::json(['success' => false, 'message' => $exception->getMessage()], 409);
} catch (InvalidArgumentException $exception) {
    Response::json(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Marketing Associate withdrawal update failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The withdrawal request could not be updated'], 500);
}
