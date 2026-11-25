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
$projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$number = trim((string) ($_POST['plot_number'] ?? ''));
$dimensions = trim((string) ($_POST['dimensions'] ?? ''));
$area = trim((string) ($_POST['area_sqyd'] ?? ''));
$facingWasSubmitted = array_key_exists('facing_direction', $_POST);
$facingInput = trim((string) ($_POST['facing_direction'] ?? ''));
$facingDirection = $facingInput !== '' ? $facingInput : null;
$length = trim((string) ($_POST['length_ft'] ?? ''));
$width = trim((string) ($_POST['width_ft'] ?? ''));
$statusWasSubmitted = array_key_exists('status', $_POST);
$status = (string) ($_POST['status'] ?? 'available');

if (!in_array($action, ['create', 'update'], true)
    || $projectId === false
    || $number === ''
    || mb_strlen($number) > 50
    || $dimensions === ''
    || mb_strlen($dimensions) > 100
    || !PlotStatus::isValid($status)
    || ($facingDirection !== null && !PlotFacing::isValid($facingDirection))
    || ($area !== '' && !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $area))
    || ($length !== '' && !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $length))
    || ($width !== '' && !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $width))
) {
    Response::json(['success' => false, 'message' => 'Enter valid plot details'], 422);
}
if ($action === 'create' && !in_array($status, ['available', 'blocked'], true)) {
    Response::json(['success' => false, 'message' => 'New plots must start as available or blocked'], 422);
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $projectCheck = $pdo->prepare('SELECT id, price_per_sqyd FROM projects WHERE id = :id FOR UPDATE');
    $projectCheck->execute(['id' => $projectId]);
    $project = $projectCheck->fetch();
    if ($project === false) {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Select a valid project'], 422);
    }
    $rate = $project['price_per_sqyd'];

    $before = null;
    if ($action === 'update') {
        if ($id === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Select a valid plot'], 422);
        }
        $statement = $pdo->prepare('SELECT * FROM plots WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $before = $statement->fetch();
        if ($before === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Plot not found'], 404);
        }
        if (!$facingWasSubmitted) {
            $facingDirection = $before['facing_direction'];
        }
        if (!$statusWasSubmitted) {
            $status = (string) $before['status'];
        }
        if (($before['status'] === 'reserved' || $status === 'reserved') && $before['status'] !== $status) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Temporary reservation status is managed by its booking'], 409);
        }
        $statement = $pdo->prepare(
            'UPDATE plots SET project_id = :project_id, plot_number = :plot_number, dimensions = :dimensions,
                length_ft = :length_ft, width_ft = :width_ft, area_sqyd = :area_sqyd, facing_direction = :facing_direction,
                total_price = ROUND(:price_area * :price_rate, 2), status = :status
             WHERE id = :id'
        );
        $statement->execute([
            'project_id' => $projectId,
            'plot_number' => $number,
            'dimensions' => $dimensions,
            'length_ft' => $length !== '' ? $length : null,
            'width_ft' => $width !== '' ? $width : null,
            'area_sqyd' => $area !== '' ? $area : null,
            'facing_direction' => $facingDirection,
            'price_area' => $area !== '' ? $area : null,
            'price_rate' => $rate,
            'status' => $status,
            'id' => $id,
        ]);
        $plotId = (int) $id;
        $oldStatus = (string) $before['status'];
        $actionName = 'plot_updated';
    } else {
        $statement = $pdo->prepare(
            'INSERT INTO plots (project_id, plot_number, dimensions, length_ft, width_ft, area_sqyd, facing_direction, total_price, status)
             VALUES (:project_id, :plot_number, :dimensions, :length_ft, :width_ft, :area_sqyd, :facing_direction,
                     ROUND(:price_area * :price_rate, 2), :status)'
        );
        $statement->execute([
            'project_id' => $projectId,
            'plot_number' => $number,
            'dimensions' => $dimensions,
            'length_ft' => $length !== '' ? $length : null,
            'width_ft' => $width !== '' ? $width : null,
            'area_sqyd' => $area !== '' ? $area : null,
            'facing_direction' => $facingDirection,
            'price_area' => $area !== '' ? $area : null,
            'price_rate' => $rate,
            'status' => $status,
        ]);
        $plotId = (int) $pdo->lastInsertId();
        $oldStatus = '';
        $actionName = 'plot_created';
    }

    if ($before === null || $oldStatus !== $status) {
        $pdo->prepare(
            'INSERT INTO plot_status_history (plot_id, old_status, new_status, changed_by)
             VALUES (:plot_id, :old_status, :new_status, :changed_by)'
        )->execute([
            'plot_id' => $plotId,
            'old_status' => $oldStatus !== '' ? $oldStatus : null,
            'new_status' => $status,
            'changed_by' => (int) $admin['id'],
        ]);
    }

    $priceStatement = $pdo->prepare('SELECT total_price FROM plots WHERE id = :id');
    $priceStatement->execute(['id' => $plotId]);
    $calculatedPrice = $priceStatement->fetchColumn();
    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, :action, \'plot\', :entity_id, :before_data, :after_data)'
    );
    $audit->execute([
        'actor' => (int) $admin['id'],
        'action' => $actionName,
        'entity_id' => $plotId,
        'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        'after_data' => json_encode([
            'project_id' => $projectId,
            'plot_number' => $number,
            'area_sqyd' => $area !== '' ? $area : null,
            'facing_direction' => $facingDirection,
            'price_per_sqyd' => $rate,
            'total_price' => $calculatedPrice,
            'status' => $status,
        ], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Plot saved']);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception->getCode() === '23000') {
        Response::json(['success' => false, 'message' => 'That plot number already exists in this project'], 409);
    }
    error_log('Plot save failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The plot could not be saved'], 500);
}
