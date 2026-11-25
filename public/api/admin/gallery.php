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

if ($action === 'delete') {
    if ($id === false) {
        Response::json(['success' => false, 'message' => 'Select a gallery image'], 422);
    }
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM project_gallery WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $before = $statement->fetch();
        if ($before === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Gallery image not found'], 404);
        }
        $pdo->prepare('DELETE FROM project_gallery WHERE id = :id')->execute(['id' => $id]);
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data)
             VALUES (:actor, \'gallery_image_deleted\', \'gallery_image\', :entity_id, :before_data)'
        )->execute([
            'actor' => (int) $admin['id'],
            'entity_id' => (int) $id,
            'before_data' => json_encode($before, JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
        if (!UploadService::deleteStoredFile((string) $before['image_path'])) {
            error_log('Gallery image cleanup failed for record ' . $id);
        }
        Response::json(['success' => true, 'message' => 'Gallery image deleted']);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Gallery image deletion failed: ' . $exception->getMessage());
        Response::json(['success' => false, 'message' => 'The gallery image could not be deleted'], 500);
    }
}

if ($action !== 'save') {
    Response::json(['success' => false, 'message' => 'Invalid gallery action'], 422);
}

$projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$altText = trim((string) ($_POST['alt_text'] ?? ''));
$displayOrder = filter_var($_POST['display_order'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
$published = isset($_POST['published']) && $_POST['published'] === '1' ? 1 : 0;
if ($projectId === false || $altText === '' || mb_strlen($altText) > 200 || $displayOrder === false) {
    Response::json(['success' => false, 'message' => 'Enter valid gallery details'], 422);
}
$projectStatement = $pdo->prepare('SELECT id FROM projects WHERE id = :id');
$projectStatement->execute(['id' => $projectId]);
if ($projectStatement->fetchColumn() === false) {
    Response::json(['success' => false, 'message' => 'Select a valid project'], 422);
}

$newImagePath = null;
if (isset($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $newImagePath = UploadService::storeImage($_FILES['image'], 'gallery');
    } catch (InvalidArgumentException $exception) {
        Response::json(['success' => false, 'message' => $exception->getMessage()], 422);
    }
}
if ($id === false && $newImagePath === null) {
    Response::json(['success' => false, 'message' => 'Upload a gallery image'], 422);
}

$pdo->beginTransaction();
try {
    $before = null;
    if ($id !== false) {
        $statement = $pdo->prepare('SELECT * FROM project_gallery WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $before = $statement->fetch();
        if ($before === false) {
            $pdo->rollBack();
            if ($newImagePath !== null && !UploadService::deleteStoredFile($newImagePath)) {
                error_log('Failed to clean up image after gallery record was not found');
            }
            Response::json(['success' => false, 'message' => 'Gallery image not found'], 404);
        }
        $imagePath = $newImagePath ?? $before['image_path'];
        $pdo->prepare(
            'UPDATE project_gallery SET project_id = :project_id, image_path = :image_path,
                alt_text = :alt_text, display_order = :display_order, published = :published
             WHERE id = :id'
        )->execute([
            'project_id' => $projectId,
            'image_path' => $imagePath,
            'alt_text' => $altText,
            'display_order' => $displayOrder,
            'published' => $published,
            'id' => $id,
        ]);
        $entityId = (int) $id;
        $actionName = 'gallery_image_updated';
    } else {
        $pdo->prepare(
            'INSERT INTO project_gallery (project_id, image_path, alt_text, display_order, published)
             VALUES (:project_id, :image_path, :alt_text, :display_order, :published)'
        )->execute([
            'project_id' => $projectId,
            'image_path' => $newImagePath,
            'alt_text' => $altText,
            'display_order' => $displayOrder,
            'published' => $published,
        ]);
        $entityId = (int) $pdo->lastInsertId();
        $actionName = 'gallery_image_created';
    }

    $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, :action, \'gallery_image\', :entity_id, :before_data, :after_data)'
    )->execute([
        'actor' => (int) $admin['id'],
        'action' => $actionName,
        'entity_id' => $entityId,
        'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        'after_data' => json_encode(['project_id' => $projectId, 'alt_text' => $altText, 'published' => $published], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    if ($newImagePath !== null && $before !== null && !empty($before['image_path'])
        && !UploadService::deleteStoredFile((string) $before['image_path'])
    ) {
        error_log('Previous gallery image cleanup failed for record ' . $entityId);
    }
    Response::json(['success' => true, 'message' => 'Gallery image saved']);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newImagePath !== null && !UploadService::deleteStoredFile($newImagePath)) {
        error_log('Failed to clean up image after gallery save failure');
    }
    error_log('Gallery image save failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The gallery image could not be saved'], 500);
}
