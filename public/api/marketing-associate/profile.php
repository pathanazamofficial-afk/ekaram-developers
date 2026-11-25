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

$name = trim((string) ($_POST['full_name'] ?? ''));
if ($name === '' || mb_strlen($name) > 150) {
    Response::json(['success' => false, 'message' => 'Enter a name of 150 characters or fewer'], 422);
}
$photoPositionX = filter_var($_POST['photo_position_x'] ?? '50', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
$photoPositionY = filter_var($_POST['photo_position_y'] ?? '50', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
if ($photoPositionX === false || $photoPositionY === false) {
    Response::json(['success' => false, 'message' => 'Photo position must be between 0 and 100'], 422);
}

$photoPath = null;
try {
    if (isset($_FILES['profile_photo']) && (int) ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $photoPath = UploadService::storeMarketingAssociatePhoto($_FILES['profile_photo']);
    }
} catch (InvalidArgumentException $exception) {
    Response::json(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    error_log('Marketing Associate profile photo upload failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The profile photo could not be saved'], 500);
}

$pdo = Database::connection();
$oldPhotoPath = null;
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        'SELECT profile_photo_path FROM marketing_associate_profiles WHERE user_id = :user_id FOR UPDATE'
    );
    $statement->execute(['user_id' => (int) $associate['id']]);
    $oldPhotoPath = $statement->fetchColumn();
    $pdo->prepare(
        'INSERT INTO marketing_associate_profiles (user_id, photo_position_x, photo_position_y)
         VALUES (:user_id, :position_x, :position_y)
         ON DUPLICATE KEY UPDATE photo_position_x = VALUES(photo_position_x),
                                 photo_position_y = VALUES(photo_position_y)'
    )->execute([
        'user_id' => (int) $associate['id'],
        'position_x' => $photoPositionX,
        'position_y' => $photoPositionY,
    ]);
    $pdo->prepare('UPDATE users SET full_name = :name WHERE id = :id')
        ->execute(['name' => $name, 'id' => (int) $associate['id']]);
    if ($photoPath !== null) {
        $pdo->prepare('UPDATE marketing_associate_profiles SET profile_photo_path = :path WHERE user_id = :id')
            ->execute(['path' => $photoPath, 'id' => (int) $associate['id']]);
    }
    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_data)
         VALUES (:actor, \'marketing_associate_profile_updated\', \'user\', :entity_id, :after_data)'
    )->execute([
        'actor' => (int) $associate['id'],
        'entity_id' => (int) $associate['id'],
        'after_data' => json_encode([
            'name_updated' => true,
            'photo_updated' => $photoPath !== null,
            'photo_position_x' => $photoPositionX,
            'photo_position_y' => $photoPositionY,
        ], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($photoPath !== null && !UploadService::deleteStoredFile($photoPath)) {
        error_log('Unable to remove an unused Marketing Associate profile photo');
    }
    error_log('Marketing Associate profile update failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The profile could not be updated'], 500);
}

if ($photoPath !== null && is_string($oldPhotoPath) && $oldPhotoPath !== '' && !UploadService::deleteStoredFile($oldPhotoPath)) {
    error_log('Unable to remove the previous Marketing Associate profile photo');
}
Response::json(['success' => true, 'message' => 'Profile updated']);
