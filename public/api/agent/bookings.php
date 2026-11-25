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

$leadId = filter_var($_POST['lead_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$plotId = filter_var($_POST['plot_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($leadId === false || $plotId === false) {
    Response::json(['success' => false, 'message' => 'Choose a lead and plot'], 422);
}

try {
    $booking = BookingService::reserve(Database::connection(), (int) $employee['id'], (int) $leadId, (int) $plotId);
    Response::json([
        'success' => true,
        'message' => 'The plot is reserved for 10 hours, pending administrator approval.',
        'booking' => $booking,
    ], 201);
} catch (DomainException $exception) {
    Response::json(['success' => false, 'message' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    error_log('Plot reservation failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The plot could not be reserved'], 500);
}
