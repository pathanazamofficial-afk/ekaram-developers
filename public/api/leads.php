<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

Security::startSession();
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    Response::json(['success' => false, 'message' => 'Invalid request token'], 403);
}

if (trim((string) ($_POST['website'] ?? '')) !== '') {
    Response::json(['success' => false, 'message' => 'Unable to accept this request'], 422);
}

$now = time();
if (isset($_SESSION['last_lead_submission']) && $now - (int) $_SESSION['last_lead_submission'] < 10) {
    Response::json(['success' => false, 'message' => 'Please wait before submitting another request'], 429);
}

$name = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$address = trim((string) ($_POST['address'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$visitorCountValue = trim((string) ($_POST['visitor_count'] ?? ''));
$referenceName = trim((string) ($_POST['reference_name'] ?? ''));
$referenceContact = trim((string) ($_POST['reference_contact'] ?? ''));
$interest = trim((string) ($_POST['interest'] ?? 'general'));
$formType = trim((string) ($_POST['form_type'] ?? 'enquiry'));
$projectIdValue = trim((string) ($_POST['project_id'] ?? ''));
$consent = (string) ($_POST['consent'] ?? '');

if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || !preg_match('/^[0-9+()\-\s]{8,25}$/', $phone)) {
    Response::json(['success' => false, 'message' => 'Enter a valid name and mobile number'], 422);
}
if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
    Response::json(['success' => false, 'message' => 'Enter a valid email address'], 422);
}
if (mb_strlen($address) > 500 || mb_strlen($message) > 2000) {
    Response::json(['success' => false, 'message' => 'One or more fields exceed the allowed length'], 422);
}
if (!in_array($formType, ['enquiry', 'callback', 'site_visit'], true)) {
    Response::json(['success' => false, 'message' => 'Invalid request type'], 422);
}
if (!in_array($interest, ['general', 'site_visit', 'plot'], true)) {
    Response::json(['success' => false, 'message' => 'Invalid enquiry interest'], 422);
}
if ($consent !== '1') {
    Response::json(['success' => false, 'message' => 'Consent is required so our team can contact you'], 422);
}

$projectId = null;
if ($projectIdValue !== '') {
    if (!ctype_digit($projectIdValue) || (int) $projectIdValue < 1) {
        Response::json(['success' => false, 'message' => 'Invalid project'], 422);
    }
    $projectId = (int) $projectIdValue;
}

$visitStartUtc = null;
$visitEndUtc = null;
$visitNotes = null;
if ($formType === 'site_visit') {
    $date = trim((string) ($_POST['preferred_date'] ?? ''));
    $time = trim((string) ($_POST['preferred_time'] ?? ''));
    $visitorCount = filter_var($visitorCountValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
    if ($projectId === null || $visitorCount === false || mb_strlen($address) < 3
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !preg_match('/^\d{2}:\d{2}$/', $time)
    ) {
        Response::json(['success' => false, 'message' => 'Enter a project, full address, date, time, and visitor count for your visit'], 422);
    }
    if (mb_strlen($referenceName) > 150
        || ($referenceContact !== '' && !preg_match('/^[0-9+()\-\s]{8,25}$/', $referenceContact))
    ) {
        Response::json(['success' => false, 'message' => 'Enter a valid reference name or contact number'], 422);
    }

    $timezone = new DateTimeZone($app['timezone']);
    $localStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($localStart === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $localStart <= new DateTimeImmutable('now', $timezone)
        || $localStart > new DateTimeImmutable('+365 days', $timezone)
    ) {
        Response::json(['success' => false, 'message' => 'Choose a valid future visit date and time'], 422);
    }
    $visitStartUtc = $localStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $visitEndUtc = $localStart->modify('+1 hour')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $visitNotes = json_encode([
        'visitor_count' => $visitorCount,
        'reference_name' => $referenceName !== '' ? $referenceName : null,
        'reference_contact' => $referenceContact !== '' ? $referenceContact : null,
    ], JSON_THROW_ON_ERROR);
}

try {
    $pdo = Database::connection();
    $remoteIp = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if ($remoteIp !== '') {
        $rateLimit = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action = 'public_lead_created' AND ip_address = :ip
               AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE"
        );
        $rateLimit->execute(['ip' => $remoteIp]);
        if ((int) $rateLimit->fetchColumn() >= 5) {
            Response::json(['success' => false, 'message' => 'Too many requests. Please try again later'], 429);
        }
    }

    $pdo->beginTransaction();
    if ($projectId !== null) {
        $projectStatement = $pdo->prepare("SELECT id FROM projects WHERE id = :id AND status = 'published'");
        $projectStatement->execute(['id' => $projectId]);
        if ($projectStatement->fetchColumn() === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'The selected project is unavailable'], 422);
        }
    }

    $customerStatement = $pdo->prepare('SELECT id FROM customers WHERE phone = :phone ORDER BY id LIMIT 1 FOR UPDATE');
    $customerStatement->execute(['phone' => $phone]);
    $customerId = $customerStatement->fetchColumn();
    if ($customerId === false) {
        $statement = $pdo->prepare(
            'INSERT INTO customers (full_name, phone, email, address, consent_at)
             VALUES (:full_name, :phone, :email, :address, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'full_name' => $name,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'address' => $address !== '' ? $address : null,
        ]);
        $customerId = (int) $pdo->lastInsertId();
    } else {
        $statement = $pdo->prepare(
            'UPDATE customers
             SET full_name = :full_name,
                 email = COALESCE(NULLIF(:email, \'\'), email),
                 address = COALESCE(NULLIF(:address, \'\'), address),
                 consent_at = COALESCE(consent_at, UTC_TIMESTAMP())
             WHERE id = :id'
        );
        $statement->execute([
            'full_name' => $name,
            'email' => $email,
            'address' => $address,
            'id' => (int) $customerId,
        ]);
        $customerId = (int) $customerId;
    }

    $statement = $pdo->prepare(
        'INSERT INTO leads (customer_id, project_id, source, enquiry_type, interest, message, status, created_at, updated_at)
         VALUES (:customer_id, :project_id, :source, :enquiry_type, :interest, :message, \'new\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $statement->execute([
        'customer_id' => $customerId,
        'project_id' => $projectId,
        'source' => 'public_website',
        'enquiry_type' => $formType,
        'interest' => $interest,
        'message' => $message !== '' ? $message : null,
    ]);
    $leadId = (int) $pdo->lastInsertId();

    if ($formType === 'site_visit') {
        $statement = $pdo->prepare(
            "INSERT INTO site_visits
             (lead_id, customer_id, project_id, visit_address, preferred_start, preferred_end, status, notes, created_at, updated_at)
             VALUES (:lead_id, :customer_id, :project_id, :address, :preferred_start, :preferred_end, 'requested', :notes, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute([
            'lead_id' => $leadId,
            'customer_id' => $customerId,
            'project_id' => $projectId,
            'address' => $address !== '' ? $address : null,
            'preferred_start' => $visitStartUtc,
            'preferred_end' => $visitEndUtc,
            'notes' => $visitNotes,
        ]);
        $visitId = (int) $pdo->lastInsertId();
        $adminIds = $pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.code = 'admin' AND u.account_status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $notification = $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, target_type, target_id)
             VALUES (:user_id, :title, :message, \'site_visit\', :target_id)'
        );
        foreach ($adminIds as $adminId) {
            $notification->execute([
                'user_id' => (int) $adminId,
                'title' => 'New site visit request',
                'message' => $name . ' requested a project visit for ' . $localStart->format('d M Y, H:i') . '.',
                'target_id' => $visitId,
            ]);
        }
    }

    $audit = $pdo->prepare(
        "INSERT INTO audit_logs (action, entity_type, entity_id, after_data, ip_address)
         VALUES ('public_lead_created', 'lead', :entity_id, :after_data, :ip_address)"
    );
    $audit->execute([
        'entity_id' => $leadId,
        'after_data' => json_encode(['type' => $formType, 'customer_id' => $customerId], JSON_THROW_ON_ERROR),
        'ip_address' => $remoteIp !== '' ? $remoteIp : null,
    ]);
    $pdo->commit();
    $_SESSION['last_lead_submission'] = $now;

    $successMessage = $formType === 'site_visit'
        ? 'Your site visit request has been received.'
        : ($formType === 'callback' ? 'Your callback request has been received.' : 'Thank you. Our team will contact you shortly.');
    Response::json(['success' => true, 'message' => $successMessage], 201);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Lead submission failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The request could not be submitted. Please try again later'], 500);
}
