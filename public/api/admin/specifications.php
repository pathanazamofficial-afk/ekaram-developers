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
$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $before = null;
    if ($action === 'delete' || $id !== false) {
        if ($id === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Select a valid specification'], 422);
        }
        $statement = $pdo->prepare('SELECT * FROM project_specifications WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $before = $statement->fetch();
        if ($before === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Specification not found'], 404);
        }
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM project_specifications WHERE id = :id')->execute(['id' => $id]);
        $actionName = 'project_specification_deleted';
        $entityId = (int) $id;
        $after = null;
    } elseif ($action === 'save') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $value = trim((string) ($_POST['spec_value'] ?? ''));
        $displayOrder = filter_var($_POST['display_order'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
        $published = isset($_POST['published']) && $_POST['published'] === '1' ? 1 : 0;
        if ($projectId === false || $label === '' || mb_strlen($label) > 120 || $value === '' || mb_strlen($value) > 500 || $displayOrder === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Enter valid project specification details'], 422);
        }
        $projectStatement = $pdo->prepare('SELECT id FROM projects WHERE id = :id');
        $projectStatement->execute(['id' => $projectId]);
        if ($projectStatement->fetchColumn() === false) {
            $pdo->rollBack();
            Response::json(['success' => false, 'message' => 'Select a valid project'], 422);
        }

        if ($before !== null) {
            $statement = $pdo->prepare(
                'UPDATE project_specifications
                 SET project_id = :project_id, label = :label, spec_value = :spec_value,
                     display_order = :display_order, published = :published
                 WHERE id = :id'
            );
            $statement->execute([
                'project_id' => $projectId,
                'label' => $label,
                'spec_value' => $value,
                'display_order' => $displayOrder,
                'published' => $published,
                'id' => $id,
            ]);
            $entityId = (int) $id;
            $actionName = 'project_specification_updated';
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO project_specifications (project_id, label, spec_value, display_order, published)
                 VALUES (:project_id, :label, :spec_value, :display_order, :published)'
            );
            $statement->execute([
                'project_id' => $projectId,
                'label' => $label,
                'spec_value' => $value,
                'display_order' => $displayOrder,
                'published' => $published,
            ]);
            $entityId = (int) $pdo->lastInsertId();
            $actionName = 'project_specification_created';
        }
        $after = ['project_id' => $projectId, 'label' => $label, 'spec_value' => $value, 'published' => $published];
    } else {
        $pdo->rollBack();
        Response::json(['success' => false, 'message' => 'Invalid specification action'], 422);
    }

    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
         VALUES (:actor, :action, \'project_specification\', :entity_id, :before_data, :after_data)'
    );
    $audit->execute([
        'actor' => (int) $admin['id'],
        'action' => $actionName,
        'entity_id' => $entityId,
        'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    Response::json(['success' => true, 'message' => 'Project specification saved']);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Project specification update failed: ' . $exception->getMessage());
    Response::json(['success' => false, 'message' => 'The specification could not be saved'], 500);
}
