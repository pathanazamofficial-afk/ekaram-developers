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

$action = (string) ($_POST['action'] ?? 'save');
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$pdo = Database::connection();
$newImagePath = null;

if ($action === 'delete') {
    if ($id === false) {
        Response::json(['success' => false, 'message' => 'Select an amenity'], 422);
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM amenities WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $before = $statement->fetch();
        if ($before === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Amenity not found'], 404);
        }

        $pdo->prepare('DELETE FROM amenities WHERE id = :id')->execute(['id' => $id]);
        $audit = $pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data)
             VALUES (:actor, \'amenity_deleted\', \'amenity\', :entity_id, :before_data)'
        );
        $audit->execute([
            'actor' => (int) $admin['id'],
            'entity_id' => (int) $id,
            'before_data' => json_encode($before, JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
        if (!empty($before['image_path']) && !UploadService::deleteStoredFile((string) $before['image_path'])) {
            error_log('Deleted amenity image cleanup failed for amenity ' . $id);
        }
        Response::json(['success' => true, 'message' => 'Amenity deleted']);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Amenity deletion failed: ' . $exception->getMessage());
        Response::json(['success' => false, 'message' => 'The amenity could not be deleted'], 500);
    }
}

if ($action !== 'save') {
    Response::json(['success' => false, 'message' => 'Invalid amenity action'], 422);
}

$projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$displayOrder = filter_var($_POST['display_order'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
$published = isset($_POST['published']) && $_POST['published'] === '1' ? 1 : 0;

if ($projectId === false || $name === '' || mb_strlen($name) > 150 || mb_strlen($description) > 500 || $displayOrder === false) {
    Response::json(['success' => false, 'message' => 'Enter valid amenity details'], 422);
}
$projectStatement = $pdo->prepare('SELECT id FROM projects WHERE id = :id');
$projectStatement->execute(['id' => $projectId]);
if ($projectStatement->fetchColumn() === false) {
    Response::json(['success' => false, 'message' => 'Select a valid project'], 422);
}

$hasImage = isset($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($id === false && !$hasImage) {
    Response::json(['success' => false, 'message' => 'Upload an amenity image'], 422);
}
if ($hasImage) {
    try {
        $newImagePath = UploadService::storeAmenityImage($_FILES['image']);
    } catch (InvalidArgumentException $exception) {
        Response::json(['success' => false, 'message' => $exception->getMessage()], 422);
    }
}

$pdo->beginTransaction();
try {
    $before = null;
    if ($id !== false) {
        $statement = $pdo->prepare('SELECT * FROM amenities WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $before = $statement->fetch();
        if ($before === false) {
            $pdo->rollBack();
            if ($newImagePath !== null && !UploadService::deleteStoredFile($newImagePath)) {
                error_log('Failed to clean up image after amenity was not found');
            }
            Response::json(['success' => false, 'message' => 'Amenity not found'], 404);
        }
        $imagePath = $newImagePath ?? $before['image_path'];
        $statement = $pdo->prepare(
            'UPDATE amenities
             SET project_id = :project_id, name = :name, description = :description,
                 image_path = :image_path, display_order = :display_order, published = :published
             WHERE id = :id'
        );
        $statement->execute([
            'project_id' => $projectId,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'image_path' => $imagePath,
            'display_order' => $displayOrder,
            'published' => $published,
            'id' => $id,
        ]);
        $amenityId = (int) $id;
        $auditAction = 'amenity_updated';
    } else {
        $statement = $pdo->prepare(
            'INSERT INTO amenities (project_id, name, description, image_path, display_order, published)
             VALUES (:project_id, :name, :description, :image_path, :display_order, :published)'
        );
        $statement->execute([
            'project_id' => $projectId,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'image_path' => $newImagePath,
            'display_order' => $displayOrder,
            'published' => $published,
        ]);
        $amenityId = (int) $pdo->lastInsertId();
        $auditAction = 'amenity_created';
    }

    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, :action, \'amenity\', :entity_id, :before_data, :after_data)'
    );
    $audit->execute([
        'actor' => (int) $admin['id'],
        'action' => $auditAction,
        'entity_id' => $amenityId,
        'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        'after_data' => json_encode([
            'project_id' => $projectId,
            'name' => $name,
            'display_order' => $displayOrder,
            'published' => $published,
        ], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    if ($newImagePath !== null && $before !== null && !empty($before['image_path'])
        && !UploadService::deleteStoredFile((string) $before['image_path'])
    ) {
        error_log('Replaced amenity image cleanup failed for amenity ' . $amenityId);
    }
    Response::json(['success' => true, 'message' => 'Amenity saved', 'amenity_id' => $amenityId]);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newImagePath !== null) {
        if (!UploadService::deleteStoredFile($newImagePath)) {
            error_log('Failed to clean up image after amenity save failure');
        }
    }
    error_log('Amenity save failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The amenity could not be saved'], 500);
}
