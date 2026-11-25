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

$action = (string) ($_POST['action'] ?? '');
$name = trim((string) ($_POST['customer_name'] ?? ''));
$phone = trim((string) ($_POST['customer_phone'] ?? ''));
$email = trim((string) ($_POST['customer_email'] ?? ''));
$address = trim((string) ($_POST['address'] ?? ''));
$projectId = positiveRequestId($_POST['project_id'] ?? null);
$message = trim((string) ($_POST['message'] ?? ''));
if (!in_array($action, ['booking_request', 'site_visit_request'], true)
    || $projectId === null || mb_strlen($name) < 2 || mb_strlen($name) > 150
    || !preg_match('/^[0-9+()\-\s]{8,25}$/', $phone)
    || ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190))
    || mb_strlen($address) > 500 || mb_strlen($message) > 2000
) {
    Response::json(['success' => false, 'message' => 'Enter valid customer contact and project details'], 422);
}

$pdo = Database::connection();
$userId = (int) $associate['id'];
try {
    $pdo->beginTransaction();
    $projectCheck = $pdo->prepare("SELECT id FROM projects WHERE id = :id AND status = 'published' FOR UPDATE");
    $projectCheck->execute(['id' => $projectId]);
    if ($projectCheck->fetchColumn() === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'The selected project is unavailable'], 422);
    }

    $customerStatement = $pdo->prepare('SELECT id FROM customers WHERE phone = :phone ORDER BY id LIMIT 1 FOR UPDATE');
    $customerStatement->execute(['phone' => $phone]);
    $customerId = $customerStatement->fetchColumn();
    if ($customerId === false) {
        $pdo->prepare(
            'INSERT INTO customers (full_name, phone, email, address, consent_at)
             VALUES (:name, :phone, :email, :address, UTC_TIMESTAMP())'
        )->execute([
            'name' => $name,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'address' => $address !== '' ? $address : null,
        ]);
        $customerId = (int) $pdo->lastInsertId();
    } else {
        $customerId = (int) $customerId;
    }

    $pdo->prepare(
        'INSERT INTO leads
         (customer_id, project_id, source, enquiry_type, interest, message, status, assigned_to,
          lead_owner_id, ownership_locked_at, created_by, created_at, updated_at)
         VALUES (:customer_id, :project_id, \'marketing_associate\', :enquiry_type, :interest, :message,
                 \'new\', :assigned_to, :owner_id, UTC_TIMESTAMP(), :creator, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'customer_id' => $customerId,
        'project_id' => $projectId,
        'enquiry_type' => $action === 'booking_request' ? 'booking_request' : 'site_visit',
        'interest' => $action === 'booking_request' ? 'plot' : 'site_visit',
        'message' => $message !== '' ? $message : null,
        'assigned_to' => $userId,
        'owner_id' => $userId,
        'creator' => $userId,
    ]);
    $leadId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO lead_ownership_history
         (lead_id, old_owner_id, new_owner_id, changed_by, reason)
         VALUES (:lead_id, NULL, :owner_id, :actor, 'Created and owned by Marketing Associate')"
    )->execute(['lead_id' => $leadId, 'owner_id' => $userId, 'actor' => $userId]);

    if ($action === 'booking_request') {
        $plotId = positiveRequestId($_POST['plot_id'] ?? null);
        if ($plotId === null) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Select an available plot'], 422);
        }
        $booking = BookingService::reserve($pdo, $userId, $leadId, $plotId);
        $adminIds = $pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.code = 'admin' AND u.account_status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $notify = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, target_type, target_id)
             VALUES (:user_id, 'Marketing Associate booking request', :message, 'booking', :target_id)"
        );
        foreach ($adminIds as $adminId) {
            $notify->execute([
                'user_id' => (int) $adminId,
                'message' => $associate['full_name'] . ' submitted booking request ' . $booking['reference'] . ' for admin confirmation.',
                'target_id' => (int) $booking['id'],
            ]);
        }
        $pdo->commit();
        Response::json([
            'success' => true,
            'message' => 'Booking request submitted. The plot is reserved for 10 hours while an administrator reviews it.',
            'booking_reference' => $booking['reference'],
        ], 201);
    }

    $visitStart = parseRequestDateTime((string) ($_POST['preferred_start'] ?? ''), $app['timezone']);
    $food = (string) ($_POST['preferred_food'] ?? 'unspecified');
    $visitorCount = filter_var($_POST['visitor_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
    if ($visitStart === null || $visitorCount === false || mb_strlen($address) < 3
        || !in_array($food, ['veg', 'non_veg', 'unspecified'], true)
        || new DateTimeImmutable($visitStart, new DateTimeZone('UTC')) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))
    ) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Enter a future visit time, address, visitor count and food preference'], 422);
    }
    $visitEnd = (new DateTimeImmutable($visitStart, new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s');
    $pdo->prepare(
        "INSERT INTO site_visits
         (lead_id, customer_id, project_id, assigned_to, preferred_food, visit_address,
          preferred_start, preferred_end, status, notes, created_at, updated_at)
         VALUES (:lead_id, :customer_id, :project_id, :assigned_to, :food, :address,
                 :preferred_start, :preferred_end, 'requested', :notes, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    )->execute([
        'lead_id' => $leadId,
        'customer_id' => $customerId,
        'project_id' => $projectId,
        'assigned_to' => $userId,
        'food' => $food,
        'address' => $address,
        'preferred_start' => $visitStart,
        'preferred_end' => $visitEnd,
        'notes' => json_encode(['visitor_count' => $visitorCount, 'requested_by_role' => 'marketing_associate'], JSON_THROW_ON_ERROR),
    ]);
    $visitId = (int) $pdo->lastInsertId();
    $adminIds = $pdo->query(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.code = 'admin' AND u.account_status = 'active'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $notify = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, message, target_type, target_id)
         VALUES (:user_id, 'Marketing Associate site visit request', :message, 'site_visit', :target_id)"
    );
    foreach ($adminIds as $adminId) {
        $notify->execute([
            'user_id' => (int) $adminId,
            'message' => $associate['full_name'] . ' requested a site visit for ' . $name . '.',
            'target_id' => $visitId,
        ]);
    }
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Site visit request submitted for admin confirmation'], 201);
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Response::json(['success' => false, 'message' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Marketing Associate request failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'Your request could not be submitted'], 500);
}

function positiveRequestId(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function parseRequestDateTime(string $value, string $timezone): ?string
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
