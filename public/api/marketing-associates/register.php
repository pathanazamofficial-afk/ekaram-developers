<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';
Security::startSession();

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
if ((string) ($_POST['consent'] ?? '') !== '1') {
    Response::json(['success' => false, 'message' => 'Consent is required to submit a registration'], 422);
}

$name = trim((string) ($_POST['full_name'] ?? ''));
$mobile = trim((string) ($_POST['contact_number'] ?? ''));
$aadhaar = trim((string) ($_POST['aadhaar_number'] ?? ''));
$pan = strtoupper(trim((string) ($_POST['pan_number'] ?? '')));
$accountNumber = trim((string) ($_POST['account_number'] ?? ''));
$ifsc = strtoupper(trim((string) ($_POST['ifsc_code'] ?? '')));
$branch = trim((string) ($_POST['branch_location'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($name === '' || mb_strlen($name) > 150
    || !preg_match('/^[6-9][0-9]{9}$/', $mobile)
    || !preg_match('/^[0-9]{12}$/', $aadhaar)
    || !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)
    || !preg_match('/^[0-9]{6,34}$/', $accountNumber)
    || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)
    || $branch === '' || mb_strlen($branch) > 150
    || strlen($password) < 12 || strlen($password) > 200
    || !hash_equals($password, $confirmPassword)
) {
    Response::json(['success' => false, 'message' => 'Check the required fields, document formats, and matching 12-character passwords'], 422);
}

$pdo = Database::connection();
$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
if ($ip !== '') {
    $limit = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE action = 'marketing_associate_registration_attempt'
           AND ip_address = :ip
           AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR"
    );
    $limit->execute(['ip' => $ip]);
    if ((int) $limit->fetchColumn() >= 3) {
        Response::json(['success' => false, 'message' => 'Too many registration requests. Try again later.'], 429);
    }
    $pdo->prepare(
        "INSERT INTO audit_logs (action, entity_type, ip_address)
         VALUES ('marketing_associate_registration_attempt', 'marketing_associate', :ip)"
    )->execute(['ip' => $ip]);
}

$roleId = $pdo->query("SELECT id FROM roles WHERE code = 'marketing_associate'")->fetchColumn();
if ($roleId === false) {
    throw new RuntimeException('Marketing Associate role is not configured. Apply the marketing associates migration.');
}
$inviteInput = $_POST['invite_token'] ?? '';
if (!is_string($inviteInput)) {
    Response::json(['success' => false, 'message' => 'The recruitment invitation is invalid or expired'], 422);
}
$inviteToken = trim($inviteInput);
if ($inviteToken !== '' && !preg_match('/^[a-f0-9]{64}$/', $inviteToken)) {
    Response::json(['success' => false, 'message' => 'The recruitment invitation is invalid or expired'], 422);
}

$profilePhotoPath = null;
try {
    if (isset($_FILES['profile_photo']) && (int) ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $profilePhotoPath = UploadService::storeMarketingAssociatePhoto($_FILES['profile_photo']);
    }
    $aadhaarEncrypted = MarketingAssociateDataProtection::encrypt('aadhaar', $aadhaar);
    $panEncrypted = MarketingAssociateDataProtection::encrypt('pan', $pan);
    $accountEncrypted = MarketingAssociateDataProtection::encrypt('account_number', $accountNumber);
    $ifscEncrypted = MarketingAssociateDataProtection::encrypt('ifsc_code', $ifsc);
    $branchEncrypted = MarketingAssociateDataProtection::encrypt('branch_location', $branch);
    $aadhaarFingerprint = MarketingAssociateDataProtection::fingerprint('aadhaar', $aadhaar);
    $panFingerprint = MarketingAssociateDataProtection::fingerprint('pan', $pan);

    $pdo->beginTransaction();
    $inviteId = null;
    $recruiterId = null;
    $suggestedDesignation = null;
    if ($inviteToken !== '') {
        $inviteStatement = $pdo->prepare(
            "SELECT invite.id, invite.manager_user_id, invite.suggested_designation_code
             FROM recruitment_invites invite
             JOIN users manager ON manager.id = invite.manager_user_id AND manager.account_status = 'active'
             JOIN roles manager_role ON manager_role.id = manager.role_id AND manager_role.code = 'marketing_associate'
             JOIN marketing_associates manager_ma ON manager_ma.user_id = manager.id
                AND manager_ma.approval_status = 'approved'
                AND manager_ma.designation_code IS NOT NULL
             WHERE invite.token_hash = :token_hash AND invite.target_role_code = 'marketing_associate'
               AND invite.used_at IS NULL AND invite.expires_at > UTC_TIMESTAMP()
             FOR UPDATE"
        );
        $inviteStatement->execute(['token_hash' => hash('sha256', $inviteToken, true)]);
        $invite = $inviteStatement->fetch();
        if ($invite === false) {
            $pdo->rollBack();
            if ($profilePhotoPath !== null && !UploadService::deleteStoredFile($profilePhotoPath)) {
                error_log('Unable to remove a profile photo for an invalid recruitment invitation');
            }
            Response::json(['success' => false, 'message' => 'The recruitment invitation is invalid, expired, or already used'], 422);
        }
        $inviteId = (int) $invite['id'];
        $recruiterId = (int) $invite['manager_user_id'];
        $suggestedDesignation = $invite['suggested_designation_code'];
    }

    $statement = $pdo->prepare(
        "INSERT INTO users (role_id, full_name, phone, password_hash, account_status)
         VALUES (:role_id, :full_name, :phone, :password_hash, 'pending')"
    );
    $statement->execute([
        'role_id' => (int) $roleId,
        'full_name' => $name,
        'phone' => '+91' . $mobile,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        "INSERT INTO marketing_associates
         (user_id, aadhaar_encrypted, aadhaar_fingerprint, aadhaar_last4,
          pan_encrypted, pan_fingerprint, pan_last4,
          account_number_encrypted, account_number_last4, ifsc_code, branch_location,
          ifsc_encrypted, branch_location_encrypted)
         VALUES
         (:user_id, :aadhaar_encrypted, :aadhaar_fingerprint, :aadhaar_last4,
          :pan_encrypted, :pan_fingerprint, :pan_last4,
          :account_number_encrypted, :account_number_last4, NULL, NULL,
          :ifsc_encrypted, :branch_encrypted)"
    );
    $statement->execute([
        'user_id' => $userId,
        'aadhaar_encrypted' => $aadhaarEncrypted,
        'aadhaar_fingerprint' => $aadhaarFingerprint,
        'aadhaar_last4' => substr($aadhaar, -4),
        'pan_encrypted' => $panEncrypted,
        'pan_fingerprint' => $panFingerprint,
        'pan_last4' => substr($pan, -4),
        'account_number_encrypted' => $accountEncrypted,
        'account_number_last4' => substr($accountNumber, -4),
        'ifsc_encrypted' => $ifscEncrypted,
        'branch_encrypted' => $branchEncrypted,
    ]);
    $pdo->prepare(
        'INSERT INTO marketing_associate_profiles (user_id, reporting_manager_id, profile_photo_path)
         VALUES (:user_id, :manager_id, :photo_path)'
    )->execute(['user_id' => $userId, 'manager_id' => null, 'photo_path' => $profilePhotoPath]);
    $associateId = (int) $pdo->lastInsertId();

    if ($inviteId !== null) {
        $pdo->prepare(
            'UPDATE recruitment_invites SET used_by = :user_id, used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL'
        )->execute(['user_id' => $userId, 'id' => $inviteId]);
        $pdo->prepare(
            "INSERT INTO recruitment_history
             (member_user_id, recruited_by, actor_user_id, action, new_role_code, new_manager_id, remarks)
             VALUES (:member, :recruiter, :actor, 'invited_registration', :designation, NULL, 'Registered using a manager invitation')"
        )->execute([
            'member' => $userId,
            'recruiter' => $recruiterId,
            'actor' => $userId,
            'designation' => $suggestedDesignation,
        ]);
        $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, target_type, target_id)
             VALUES (:user_id, 'Marketing Associate registration', :message, 'marketing_associate', :target_id)"
        )->execute([
            'user_id' => $recruiterId,
            'message' => $name . ' registered using your invitation. The registration is awaiting administrator approval.',
            'target_id' => $associateId,
        ]);
    }

    $pdo->prepare(
        "INSERT INTO audit_logs (action, entity_type, entity_id, after_data, ip_address)
         VALUES ('marketing_associate_registration_requested', 'marketing_associate', :entity_id, :after_data, :ip)"
    )->execute([
        'entity_id' => $associateId,
        'after_data' => json_encode([
            'status' => 'pending',
            'user_id' => $userId,
            'recruited_by' => $recruiterId,
            'suggested_designation_code' => $suggestedDesignation,
        ], JSON_THROW_ON_ERROR),
        'ip' => $ip !== '' ? $ip : null,
    ]);

    $adminIds = $pdo->query(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.code = 'admin' AND u.account_status = 'active'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $notification = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, message, target_type, target_id)
         VALUES (:user_id, 'Marketing Associate registration', :message, 'marketing_associate', :target_id)"
    );
    foreach ($adminIds as $adminId) {
        $notification->execute([
            'user_id' => (int) $adminId,
            'message' => $name . ' submitted a Marketing Associate registration for review.',
            'target_id' => $associateId,
        ]);
    }

    $pdo->commit();
    Response::json([
        'success' => true,
        'message' => 'Your registration has been submitted successfully. Your account is awaiting admin approval. You will be able to log in once your registration is approved.',
    ], 201);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($profilePhotoPath !== null && !UploadService::deleteStoredFile($profilePhotoPath)) {
        error_log('Unable to remove an unused Marketing Associate registration photo');
    }
    if ($exception->getCode() === '23000') {
        Response::json(['success' => false, 'message' => 'That mobile number, Aadhaar, or PAN is already registered'], 409);
    }
    error_log('Marketing Associate registration failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'Registration could not be completed. Please try again later.'], 500);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($profilePhotoPath !== null && !UploadService::deleteStoredFile($profilePhotoPath)) {
        error_log('Unable to remove an unused Marketing Associate registration photo');
    }
    if ($exception instanceof InvalidArgumentException) {
        Response::json(['success' => false, 'message' => $exception->getMessage()], 422);
    }
    error_log('Marketing Associate registration failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'Registration could not be completed. Please try again later.'], 500);
}
