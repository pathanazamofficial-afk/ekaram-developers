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

$action = trim((string) ($_POST['action'] ?? ''));
$pdo = Database::connection();

try {
    if ($action === 'record_collection') {
        $bookingId = positiveId($_POST['booking_id'] ?? null);
        $amountInput = trim((string) ($_POST['amount'] ?? ''));
        $receivedAt = parseLocalDateTime((string) ($_POST['received_at'] ?? ''), $app['timezone']);
        $method = trim((string) ($_POST['payment_method'] ?? ''));
        $reference = trim((string) ($_POST['payment_reference'] ?? ''));
        if ($bookingId === null || !preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $amountInput)
            || (float) $amountInput <= 0 || (float) $amountInput > 9999999999999.99
            || $receivedAt === null || $receivedAt > gmdate('Y-m-d H:i:s')
            || mb_strlen($method) > 50 || mb_strlen($reference) > 150
        ) {
            Response::json(['success' => false, 'message' => 'Enter a confirmed booking, valid amount, collection date and payment reference'], 422);
        }

        $pdo->beginTransaction();
        $bookingStatement = $pdo->prepare("SELECT status, total_price FROM bookings WHERE id = :id FOR UPDATE");
        $bookingStatement->execute(['id' => $bookingId]);
        $booking = $bookingStatement->fetch();
        if ($booking === false || $booking['status'] !== 'confirmed') {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Collections can only be recorded for confirmed bookings'], 409);
        }
        $collectedStatement = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM plot_collections
             WHERE booking_id = :booking_id AND status IN ('pending','verified')"
        );
        $collectedStatement->execute(['booking_id' => $bookingId]);
        if ((float) $collectedStatement->fetchColumn() + (float) $amountInput > (float) $booking['total_price']) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Recorded collections cannot exceed the booking value'], 409);
        }
        $pdo->prepare(
            "INSERT INTO plot_collections
             (booking_id, amount, received_at, payment_method, payment_reference, status, recorded_by)
             VALUES (:booking_id, :amount, :received_at, :payment_method, :payment_reference, 'pending', :actor)"
        )->execute([
            'booking_id' => $bookingId,
            'amount' => number_format((float) $amountInput, 2, '.', ''),
            'received_at' => $receivedAt,
            'payment_method' => $method !== '' ? $method : null,
            'payment_reference' => $reference !== '' ? $reference : null,
            'actor' => (int) $admin['id'],
        ]);
        $collectionId = (int) $pdo->lastInsertId();
        writeAudit($pdo, (int) $admin['id'], 'plot_collection_recorded', 'plot_collection', $collectionId, null, [
            'booking_id' => $bookingId,
            'amount' => number_format((float) $amountInput, 2, '.', ''),
            'status' => 'pending',
        ]);
        $pdo->commit();
        Response::json(['success' => true, 'message' => 'Collection recorded and awaiting verification']);
    }

    if ($action === 'review_collection') {
        $collectionId = positiveId($_POST['collection_id'] ?? null);
        $status = (string) ($_POST['status'] ?? '');
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if ($collectionId === null || !in_array($status, ['verified', 'rejected', 'reversed'], true)
            || mb_strlen($remarks) > 1000 || (in_array($status, ['rejected', 'reversed'], true) && $remarks === '')
        ) {
            Response::json(['success' => false, 'message' => 'Choose a valid collection action and provide remarks when rejecting or reversing'], 422);
        }
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT * FROM plot_collections WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $collectionId]);
        $collection = $statement->fetch();
        if ($collection === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Collection not found'], 404);
        }
        $allowed = ($collection['status'] === 'pending' && in_array($status, ['verified', 'rejected'], true))
            || ($collection['status'] === 'verified' && $status === 'reversed');
        if (!$allowed) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'This collection can no longer be changed to the selected status'], 409);
        }
        if ($status === 'reversed') {
            $batchCheck = $pdo->prepare(
                'SELECT COUNT(*) FROM commission_batches cb
                 JOIN booking_milestones bm ON bm.id = cb.milestone_id
                 WHERE cb.booking_id = :booking_id AND bm.completed_at >= :received_at'
            );
            $batchCheck->execute([
                'booking_id' => (int) $collection['booking_id'],
                'received_at' => $collection['received_at'],
            ]);
            if ((int) $batchCheck->fetchColumn() > 0) {
                $pdo->rollBack();
                Response::json(['success' => false, 'message' => 'This collection is included in a commission milestone and cannot be reversed without a separate audited commission correction'], 409);
            }
        }
        $pdo->prepare(
            'UPDATE plot_collections
             SET status = :status, verified_by = :actor,
                 verified_at = IF(:is_verified = 1, UTC_TIMESTAMP(), verified_at),
                 admin_remarks = :remarks
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'is_verified' => $status === 'verified' ? 1 : 0,
            'actor' => (int) $admin['id'],
            'remarks' => $remarks !== '' ? $remarks : null,
            'id' => $collectionId,
        ]);
        writeAudit($pdo, (int) $admin['id'], 'plot_collection_' . $status, 'plot_collection', $collectionId, [
            'status' => $collection['status'],
            'amount' => $collection['amount'],
        ], ['status' => $status, 'remarks' => $remarks]);
        $pdo->commit();
        Response::json(['success' => true, 'message' => 'Collection status updated']);
    }

    if ($action === 'complete_milestone') {
        $bookingId = positiveId($_POST['booking_id'] ?? null);
        $stage = (string) ($_POST['stage'] ?? '');
        $completedAt = parseLocalDateTime((string) ($_POST['completed_at'] ?? ''), $app['timezone']);
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if ($bookingId === null || !in_array($stage, ['agreement', 'registration'], true)
            || $completedAt === null || mb_strlen($remarks) > 1000
        ) {
            Response::json(['success' => false, 'message' => 'Enter a valid booking, milestone, completion date and remarks'], 422);
        }
        $pdo->beginTransaction();
        $summary = CommissionService::createMilestoneBatch(
            $pdo,
            $bookingId,
            $stage,
            $completedAt,
            (int) $admin['id'],
            $remarks !== '' ? $remarks : null
        );
        writeAudit($pdo, (int) $admin['id'], 'commission_milestone_recorded', 'commission_batch', $summary['batch_id'], null, $summary);
        $pdo->commit();
        Response::json(['success' => true, 'message' => 'Milestone recorded; commission batch is awaiting review', 'batch' => $summary]);
    }

    if ($action === 'review_batch') {
        $batchId = positiveId($_POST['batch_id'] ?? null);
        $decision = (string) ($_POST['decision'] ?? '');
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if ($batchId === null || !in_array($decision, ['approve', 'hold', 'reject'], true)
            || mb_strlen($remarks) > 1000 || ($decision === 'reject' && $remarks === '')
        ) {
            Response::json(['success' => false, 'message' => 'Choose a valid batch action and provide a reason when rejecting'], 422);
        }

        $pdo->beginTransaction();
        $result = CommissionService::reviewBatch(
            $pdo,
            $batchId,
            $decision,
            (int) $admin['id'],
            $remarks !== '' ? $remarks : null
        );
        $pdo->commit();
        Response::json([
            'success' => true,
            'message' => 'Commission batch ' . $result['status'],
            'credited_count' => $result['credited_count'],
        ]);
    }

    Response::json(['success' => false, 'message' => 'Invalid commission workflow action'], 422);
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Response::json(['success' => false, 'message' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Commission workflow failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The commission workflow could not be completed'], 500);
}

function positiveId(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function parseLocalDateTime(string $value, string $timezone): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
        return null;
    }
    $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone($timezone));
    $errors = DateTimeImmutable::getLastErrors();
    if ($local === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function writeAudit(PDO $pdo, int $actorId, string $action, string $entityType, int $entityId, ?array $before, array $after): void
{
    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, :action, :entity_type, :entity_id, :before_data, :after_data)'
    )->execute([
        'actor' => $actorId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        'after_data' => json_encode($after, JSON_THROW_ON_ERROR),
    ]);
}
