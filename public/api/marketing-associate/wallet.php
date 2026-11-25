<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';
Security::startSession();
$associate = Auth::requireMarketingAssociateApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
}
if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    Response::json(['success' => false, 'message' => 'Invalid request token'], 403);
}
if ((string) ($_POST['action'] ?? '') !== 'request_withdrawal') {
    Response::json(['success' => false, 'message' => 'Invalid wallet action'], 422);
}

$amountInput = trim((string) ($_POST['amount'] ?? ''));
if (!preg_match('/^([1-9]\d{0,12})(?:\.(\d{1,2}))?$/', $amountInput, $parts)) {
    Response::json(['success' => false, 'message' => 'Enter a positive amount with up to 2 decimal places'], 422);
}
$amountMinor = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '0', 2, '0');
$remarks = trim((string) ($_POST['remarks'] ?? ''));
if (mb_strlen($remarks) > 500) {
    Response::json(['success' => false, 'message' => 'Remarks must be 500 characters or fewer'], 422);
}

try {
    $reference = MarketingAssociateWalletService::requestWithdrawal(
        Database::connection(),
        (int) $associate['id'],
        $amountMinor,
        $remarks
    );
    Response::json([
        'success' => true,
        'message' => 'Withdrawal request ' . $reference . ' submitted for administrator review',
    ], 201);
} catch (DomainException $exception) {
    Response::json(['success' => false, 'message' => $exception->getMessage()], 409);
} catch (InvalidArgumentException $exception) {
    Response::json(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    error_log('Marketing Associate withdrawal request failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'Withdrawal encryption is unavailable. Contact the administrator.'], 503);
} catch (Throwable $exception) {
    error_log('Marketing Associate withdrawal request failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The withdrawal request could not be saved'], 500);
}
