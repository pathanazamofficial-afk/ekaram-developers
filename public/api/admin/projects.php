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

$action = (string) ($_POST['action'] ?? 'create');
if (!in_array($action, ['create', 'update'], true)) {
    Response::json(['success' => false, 'message' => 'Invalid project action'], 422);
}

$name = trim((string) ($_POST['name'] ?? ''));
$location = trim((string) ($_POST['location'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$status = (string) ($_POST['status'] ?? 'draft');
$area = trim((string) ($_POST['total_land_area'] ?? ''));
$price = trim((string) ($_POST['price_per_sqyd'] ?? ''));
$mapUrl = trim((string) ($_POST['map_embed_url'] ?? ''));
$slugInput = strtolower(trim((string) ($_POST['slug'] ?? '')));
$slug = $slugInput !== '' ? $slugInput : trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

if ($name === '' || mb_strlen($name) > 150 || $location === '' || mb_strlen($location) > 255 || mb_strlen($description) > 10000) {
    Response::json(['success' => false, 'message' => 'Enter a project name, location, and valid description'], 422);
}
if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || mb_strlen($slug) > 180) {
    Response::json(['success' => false, 'message' => 'Project URL slug may contain lowercase letters, numbers, and hyphens'], 422);
}
if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $area)) {
    Response::json(['success' => false, 'message' => 'Enter a valid land area'], 422);
}
if ($price !== '' && (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $price) || Money::toMinorUnits($price) === 0)) {
    Response::json(['success' => false, 'message' => 'Enter a valid price per square yard'], 422);
}
if (!in_array($status, ['draft', 'published', 'archived'], true)) {
    Response::json(['success' => false, 'message' => 'Invalid project status'], 422);
}
if ($mapUrl !== '') {
    $parts = parse_url($mapUrl);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || !in_array(strtolower((string) ($parts['host'] ?? '')), ['www.google.com', 'maps.google.com'], true)
    ) {
        Response::json(['success' => false, 'message' => 'Use a secure Google Maps embed URL'], 422);
    }
}

$newBrochurePath = null;
if (isset($_FILES['brochure']) && (int) ($_FILES['brochure']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $newBrochurePath = UploadService::storeBrochure($_FILES['brochure']);
    } catch (InvalidArgumentException $exception) {
        Response::json(['success' => false, 'message' => $exception->getMessage()], 422);
    }
}

$pdo = Database::connection();
$id = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('SELECT id FROM projects WHERE slug = :slug AND (:exclude_id_null IS NULL OR id <> :exclude_id) LIMIT 1');
    $statement->execute([
        'slug' => $slug,
        'exclude_id_null' => $id ?: null,
        'exclude_id' => $id ?: null,
    ]);
    if ($statement->fetchColumn() !== false) {
        $pdo->rollBack();
        if ($newBrochurePath !== null && !UploadService::deleteStoredFile($newBrochurePath)) {
            error_log('Failed to clean up brochure after slug conflict');
        }
        Response::json(['success' => false, 'message' => 'That project URL is already in use'], 409);
    }

    $before = null;
    $previousBrochurePath = null;
    if ($action === 'update') {
        if ($id === false) {
            $pdo->rollBack();
            if ($newBrochurePath !== null && !UploadService::deleteStoredFile($newBrochurePath)) {
                error_log('Failed to clean up brochure after invalid project update');
            }
            Response::json(['success' => false, 'message' => 'Select a valid project'], 422);
        }
        $statement = $pdo->prepare('SELECT * FROM projects WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $before = $statement->fetch();
        if ($before === false) {
            $pdo->rollBack();
            if ($newBrochurePath !== null && !UploadService::deleteStoredFile($newBrochurePath)) {
                error_log('Failed to clean up brochure after project was not found');
            }
            Response::json(['success' => false, 'message' => 'Project not found'], 404);
        }
        $previousBrochurePath = $before['brochure_path'];
        $statement = $pdo->prepare(
            'UPDATE projects
             SET name = :name, slug = :slug, location = :location, total_land_area = :area,
                 price_per_sqyd = :price, description = :description, map_embed_url = :map_url,
                 brochure_path = COALESCE(:brochure_path, brochure_path), status = :status
             WHERE id = :id'
        );
        $statement->execute([
            'name' => $name,
            'slug' => $slug,
            'location' => $location,
            'area' => $area,
            'price' => $price !== '' ? $price : null,
            'description' => $description !== '' ? $description : null,
            'map_url' => $mapUrl !== '' ? $mapUrl : null,
            'brochure_path' => $newBrochurePath,
            'status' => $status,
            'id' => $id,
        ]);
        $projectId = (int) $id;
    } else {
        $statement = $pdo->prepare(
            'INSERT INTO projects (name, slug, location, total_land_area, price_per_sqyd, description, map_embed_url, brochure_path, status, created_by)
             VALUES (:name, :slug, :location, :area, :price, :description, :map_url, :brochure_path, :status, :created_by)'
        );
        $statement->execute([
            'name' => $name,
            'slug' => $slug,
            'location' => $location,
            'area' => $area,
            'price' => $price !== '' ? $price : null,
            'description' => $description !== '' ? $description : null,
            'map_url' => $mapUrl !== '' ? $mapUrl : null,
            'brochure_path' => $newBrochurePath,
            'status' => $status,
            'created_by' => (int) $admin['id'],
        ]);
        $projectId = (int) $pdo->lastInsertId();
    }

    $recalculatePrices = $pdo->prepare(
        'UPDATE plots
         SET total_price = ROUND(area_sqyd * :price_per_sqyd, 2)
         WHERE project_id = :project_id'
    );
    $recalculatePrices->execute([
        'price_per_sqyd' => $price !== '' ? $price : null,
        'project_id' => $projectId,
    ]);

    $after = [
        'id' => $projectId,
        'name' => $name,
        'slug' => $slug,
        'status' => $status,
        'price_per_sqyd' => $price !== '' ? $price : null,
    ];
    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, :action, \'project\', :entity_id, :before_data, :after_data)'
    );
    $audit->execute([
        'actor' => (int) $admin['id'],
        'action' => 'project_' . $action,
        'entity_id' => $projectId,
        'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        'after_data' => json_encode($after, JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    if ($newBrochurePath !== null && $previousBrochurePath !== null && !UploadService::deleteStoredFile((string) $previousBrochurePath)) {
        error_log('Previous brochure cleanup failed for project ' . $projectId);
    }
    Response::json(['success' => true, 'message' => 'Project saved', 'project_id' => $projectId], 200);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newBrochurePath !== null) {
        if (!UploadService::deleteStoredFile($newBrochurePath)) {
            error_log('Failed to clean up brochure after project save failure');
        }
    }
    error_log('Project update failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The project could not be saved'], 500);
}
