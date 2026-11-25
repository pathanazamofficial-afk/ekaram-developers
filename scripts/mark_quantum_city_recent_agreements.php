<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$plotNumbers = [92, 105, 106, 107, 108, 117, 118, 119, 120, 169, 171, 184, 209, 213, 214, 215, 218, 232];
if (count(array_unique($plotNumbers)) !== 18) {
    throw new RuntimeException('The Recent Agreement plot list must contain 18 unique numbers.');
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $projectQuery = $pdo->prepare('SELECT id FROM projects WHERE slug = :slug FOR UPDATE');
    $projectQuery->execute(['slug' => 'quantum-city']);
    $projectId = $projectQuery->fetchColumn();
    if ($projectId === false) {
        throw new RuntimeException('The Quantum City project must exist before updating plot statuses.');
    }

    $placeholders = implode(',', array_fill(0, count($plotNumbers), '?'));
    $plotQuery = $pdo->prepare(
        'SELECT id, plot_number, status FROM plots
         WHERE project_id = ? AND plot_number IN (' . $placeholders . ')
         FOR UPDATE'
    );
    $plotQuery->execute(array_merge([(int) $projectId], array_map('strval', $plotNumbers)));
    $plotsByNumber = [];
    foreach ($plotQuery->fetchAll() as $plot) {
        $plotsByNumber[(int) $plot['plot_number']] = $plot;
    }
    if (count($plotsByNumber) !== count($plotNumbers)) {
        throw new RuntimeException('One or more requested plots are missing; no statuses were changed.');
    }

    $update = $pdo->prepare(
        "UPDATE plots SET status = 'recent_agreement' WHERE id = :id AND status = :old_status"
    );
    $history = $pdo->prepare(
        'INSERT INTO plot_status_history (plot_id, old_status, new_status, changed_by, note)
         VALUES (:plot_id, :old_status, \'recent_agreement\', NULL, :note)'
    );
    $updated = 0;
    foreach ($plotNumbers as $number) {
        $plot = $plotsByNumber[$number];
        if ($plot['status'] === 'recent_agreement') {
            continue;
        }
        $update->execute([
            'id' => (int) $plot['id'],
            'old_status' => $plot['status'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Concurrent status change detected for plot ' . $number . '.');
        }
        $history->execute([
            'plot_id' => (int) $plot['id'],
            'old_status' => $plot['status'],
            'note' => 'Marked Recent Agreement at the website owner\'s direction.',
        ]);
        $updated++;
    }

    $verify = $pdo->prepare(
        'SELECT COUNT(*) FROM plots
         WHERE project_id = ? AND plot_number IN (' . $placeholders . ') AND status = \'recent_agreement\''
    );
    $verify->execute(array_merge([(int) $projectId], array_map('strval', $plotNumbers)));
    if ((int) $verify->fetchColumn() !== count($plotNumbers)) {
        throw new RuntimeException('Recent Agreement status verification failed.');
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

echo json_encode([
    'target_plots' => count($plotNumbers),
    'plots_updated' => $updated,
    'status' => PlotStatus::LABELS['recent_agreement'],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
