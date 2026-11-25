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
if ($id === false || !in_array($action, ['confirm', 'cancel'], true)) {
    Response::json(['success' => false, 'message' => 'Choose a valid booking action'], 422);
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $plotIdStatement = $pdo->prepare('SELECT plot_id FROM bookings WHERE id = :id');
    $plotIdStatement->execute(['id' => $id]);
    $plotId = $plotIdStatement->fetchColumn();
    if ($plotId === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Booking not found'], 404);
    }
    $plotStatement = $pdo->prepare('SELECT status FROM plots WHERE id = :id FOR UPDATE');
    $plotStatement->execute(['id' => (int) $plotId]);
    $plotStatus = $plotStatement->fetchColumn();
    if ($plotStatus === false) {
        throw new RuntimeException('Booking has no associated plot');
    }
    $statement = $pdo->prepare(
        'SELECT *, (reservation_expires_at <= UTC_TIMESTAMP()) AS is_expired
         FROM bookings WHERE id = :id FOR UPDATE'
    );
    $statement->execute(['id' => $id]);
    $booking = $statement->fetch();
    if ($booking === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Booking not found'], 404);
    }

    if ((int) $booking['plot_id'] !== (int) $plotId) {
        throw new RuntimeException('Booking plot changed during approval');
    }

    if ($action === 'confirm') {
        if (!in_array($booking['status'], ['reserved', 'pending_approval'], true) || (int) $booking['is_expired'] === 1) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'This reservation has expired or is no longer awaiting approval'], 409);
        }
        if ($plotStatus !== 'reserved') {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'The plot is not in the reserved state'], 409);
        }
        $pdo->prepare(
            "UPDATE bookings SET status = 'confirmed', confirmed_by = :admin, confirmed_at = UTC_TIMESTAMP()
             WHERE id = :id"
        )->execute(['admin' => (int) $admin['id'], 'id' => (int) $id]);
        $pdo->prepare("UPDATE plots SET status = 'booked' WHERE id = :id")
            ->execute(['id' => (int) $booking['plot_id']]);
        $pdo->prepare(
            "INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by)
             VALUES (:booking_id, :old_status, 'confirmed', :actor)"
        )->execute(['booking_id' => (int) $id, 'old_status' => $booking['status'], 'actor' => (int) $admin['id']]);
        $pdo->prepare(
            "INSERT INTO plot_status_history (plot_id, old_status, new_status, changed_by)
             VALUES (:plot_id, 'reserved', 'booked', :actor)"
        )->execute(['plot_id' => (int) $booking['plot_id'], 'actor' => (int) $admin['id']]);

        $pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
             VALUES (:actor, \'booking_confirmed\', \'booking\', :entity_id, :before_data, :after_data)'
        )->execute([
            'actor' => (int) $admin['id'],
            'entity_id' => (int) $id,
            'before_data' => json_encode(['status' => $booking['status']], JSON_THROW_ON_ERROR),
            'after_data' => json_encode(['status' => 'confirmed'], JSON_THROW_ON_ERROR),
        ]);
        if ($booking['agent_id'] !== null) {
            $pdo->prepare(
                'INSERT INTO notifications (user_id, title, message, target_type, target_id)
                 VALUES (:user_id, :title, :message, \'booking\', :target_id)'
            )->execute([
                'user_id' => (int) $booking['agent_id'],
                'title' => 'Plot booking confirmed',
                'message' => 'Booking ' . $booking['booking_reference'] . ' was confirmed.',
                'target_id' => (int) $id,
            ]);
        }
    } else {
        if (!in_array($booking['status'], ['reserved', 'pending_approval'], true)) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Only unconfirmed reservations can be cancelled'], 409);
        }
        $newStatus = (int) $booking['is_expired'] === 1 ? 'expired' : 'cancelled';
        $pdo->prepare('UPDATE bookings SET status = :status WHERE id = :id')
            ->execute(['status' => $newStatus, 'id' => (int) $id]);
        if ($plotStatus === 'reserved') {
            $pdo->prepare("UPDATE plots SET status = 'available' WHERE id = :id")
                ->execute(['id' => (int) $booking['plot_id']]);
            $pdo->prepare(
                "INSERT INTO plot_status_history (plot_id, old_status, new_status, changed_by, note)
                 VALUES (:plot_id, 'reserved', 'available', :actor, 'Reservation cancelled or expired')"
            )->execute(['plot_id' => (int) $booking['plot_id'], 'actor' => (int) $admin['id']]);
        }
        $pdo->prepare(
            'INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by)
             VALUES (:booking_id, :old_status, :new_status, :actor)'
        )->execute([
            'booking_id' => (int) $id,
            'old_status' => $booking['status'],
            'new_status' => $newStatus,
            'actor' => (int) $admin['id'],
        ]);
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
             VALUES (:actor, \'booking_cancelled\', \'booking\', :entity_id, :before_data, :after_data)'
        )->execute([
            'actor' => (int) $admin['id'],
            'entity_id' => (int) $id,
            'before_data' => json_encode(['status' => $booking['status']], JSON_THROW_ON_ERROR),
            'after_data' => json_encode(['status' => $newStatus], JSON_THROW_ON_ERROR),
        ]);
    }

    $pdo->commit();
    Response::json(['success' => true, 'message' => $action === 'confirm' ? 'Booking confirmed' : 'Reservation cancelled']);
} catch (PDOException | RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Booking approval failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The booking could not be updated'], 500);
}
