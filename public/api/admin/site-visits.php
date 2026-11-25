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
$status = (string) ($_POST['status'] ?? '');
$assignee = trim((string) ($_POST['assigned_to'] ?? ''));
$preferredStartInput = trim((string) ($_POST['preferred_start'] ?? ''));
$food = (string) ($_POST['preferred_food'] ?? 'unspecified');
$vehicleModel = trim((string) ($_POST['vehicle_model'] ?? ''));
$vehicleNumber = strtoupper(trim((string) ($_POST['vehicle_number'] ?? '')));
$driverName = trim((string) ($_POST['driver_name'] ?? ''));
$driverPhone = trim((string) ($_POST['driver_phone'] ?? ''));
$pickupLocation = trim((string) ($_POST['pickup_location'] ?? ''));
$instructions = trim((string) ($_POST['special_instructions'] ?? ''));
if ($id === false || !in_array($status, ['requested', 'confirmed', 'completed', 'cancelled', 'no_show'], true)) {
    Response::json(['success' => false, 'message' => 'Choose a valid visit and status'], 422);
}
$preferredStart = parseVisitStart($preferredStartInput, $app['timezone']);
if ($preferredStart === null || !in_array($food, ['veg', 'non_veg', 'unspecified'], true)
    || mb_strlen($vehicleModel) > 100 || mb_strlen($vehicleNumber) > 30
    || mb_strlen($driverName) > 150 || mb_strlen($pickupLocation) > 500 || mb_strlen($instructions) > 2000
    || ($driverPhone !== '' && !preg_match('/^[0-9+()\-\s]{8,25}$/', $driverPhone))
) {
    Response::json(['success' => false, 'message' => 'Enter valid visit time, transport and dietary details'], 422);
}

$assigneeId = null;
if ($assignee !== '') {
    $assigneeId = filter_var($assignee, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($assigneeId === false) {
        Response::json(['success' => false, 'message' => 'Select a valid employee'], 422);
    }
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('SELECT * FROM site_visits WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $id]);
    $before = $statement->fetch();
    if ($before === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Site visit not found'], 404);
    }

    $transitions = [
        'requested' => ['requested', 'confirmed', 'cancelled'],
        'confirmed' => ['confirmed', 'completed', 'cancelled', 'no_show'],
        'completed' => ['completed'],
        'cancelled' => ['cancelled'],
        'no_show' => ['no_show'],
    ];
    if (!in_array($status, $transitions[$before['status']] ?? [], true)) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'That visit status transition is not allowed'], 409);
    }
    if ($assigneeId !== null) {
        $employeeCheck = $pdo->prepare(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.account_status = 'active' AND r.code = 'marketing_associate'
               AND EXISTS (
                 SELECT 1 FROM marketing_associates ma
                 WHERE ma.user_id = u.id AND ma.approval_status = 'approved'
                   AND ma.designation_code IS NOT NULL
               )"
        );
        $employeeCheck->execute(['id' => $assigneeId]);
        if ($employeeCheck->fetchColumn() === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Select an active, approved Marketing Associate'], 422);
        }
    }

    $preferredEnd = (new DateTimeImmutable($preferredStart, new DateTimeZone('UTC')))
        ->modify('+1 hour')->format('Y-m-d H:i:s');
    $pdo->prepare(
        'UPDATE site_visits
         SET status = :status, assigned_to = :assigned_to, preferred_start = :preferred_start,
             preferred_end = :preferred_end, preferred_food = :preferred_food, vehicle_model = :vehicle_model,
             vehicle_number = :vehicle_number, driver_name = :driver_name, driver_phone = :driver_phone,
             pickup_location = :pickup_location, special_instructions = :instructions, updated_at = UTC_TIMESTAMP()
         WHERE id = :id'
    )->execute([
        'status' => $status,
        'assigned_to' => $assigneeId,
        'preferred_start' => $preferredStart,
        'preferred_end' => $preferredEnd,
        'preferred_food' => $food,
        'vehicle_model' => $vehicleModel !== '' ? $vehicleModel : null,
        'vehicle_number' => $vehicleNumber !== '' ? $vehicleNumber : null,
        'driver_name' => $driverName !== '' ? $driverName : null,
        'driver_phone' => $driverPhone !== '' ? $driverPhone : null,
        'pickup_location' => $pickupLocation !== '' ? $pickupLocation : null,
        'instructions' => $instructions !== '' ? $instructions : null,
        'id' => (int) $id,
    ]);
    $after = [
        'status' => $status,
        'assigned_to' => $assigneeId,
        'preferred_start' => $preferredStart,
        'preferred_food' => $food,
        'vehicle_model' => $vehicleModel,
        'vehicle_number' => $vehicleNumber,
        'driver_name' => $driverName,
        'pickup_location' => $pickupLocation,
        'special_instructions' => $instructions,
    ];
    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, \'site_visit_updated\', \'site_visit\', :entity_id, :before_data, :after_data)'
    )->execute([
        'actor' => (int) $admin['id'],
        'entity_id' => (int) $id,
        'before_data' => json_encode($before, JSON_THROW_ON_ERROR),
        'after_data' => json_encode($after, JSON_THROW_ON_ERROR),
    ]);
    $notifyUserId = $assigneeId ?? (int) ($before['assigned_to'] ?? 0);
    if ($notifyUserId > 0) {
        $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, target_type, target_id)
             VALUES (:user_id, :title, :message, \'site_visit\', :target_id)'
        )->execute([
            'user_id' => $notifyUserId,
            'title' => 'Site visit updated',
            'message' => 'Visit #' . (int) $id . ' is ' . str_replace('_', ' ', $status)
                . ' for ' . (new DateTimeImmutable($preferredStart, new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone($app['timezone']))->format('d M Y, H:i')
                . ($vehicleNumber !== '' ? '. Vehicle: ' . $vehicleNumber : ''),
            'target_id' => (int) $id,
        ]);
    }
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Site visit updated']);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    function parseVisitStart(string $value, string $timezone): ?string
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
    error_log('Site visit update failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The site visit could not be updated'], 500);
}
