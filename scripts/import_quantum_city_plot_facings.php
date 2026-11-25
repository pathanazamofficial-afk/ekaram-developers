<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ranges = [
    [1, 1, 'North-West'],
    [2, 2, 'North-East'],
    [3, 3, 'East'],
    [4, 6, 'West'],
    [7, 7, 'West & North'],
    [8, 8, 'North-East'],
    [9, 13, 'East'],
    [14, 18, 'West'],
    [19, 19, 'North-West'],
    [20, 20, 'North-East'],
    [21, 25, 'East'],
    [26, 32, 'West'],
    [33, 33, 'North-West'],
    [34, 34, 'North-East'],
    [35, 41, 'East'],
    [42, 48, 'West'],
    [49, 49, 'North-West'],
    [50, 50, 'North-East'],
    [51, 57, 'East'],
    [58, 64, 'West'],
    [65, 65, 'North-West'],
    [66, 66, 'North-East'],
    [67, 73, 'East'],
    [74, 81, 'West'],
    [82, 82, 'North-West'],
    [83, 83, 'North-East'],
    [84, 91, 'East'],
    [92, 99, 'West'],
    [100, 100, 'North-West'],
    [101, 101, 'North-East'],
    [102, 112, 'East'],
    [113, 123, 'West'],
    [124, 124, 'North-West'],
    [125, 125, 'North-East'],
    [126, 138, 'East'],
    [139, 141, 'West'],
    [142, 142, 'North-West'],
    [143, 143, 'South-West'],
    [144, 155, 'West'],
    [156, 156, 'North-West'],
    [157, 157, 'North-East'],
    [158, 170, 'East'],
    [171, 171, 'North-East'],
    [172, 177, 'East'],
    [178, 183, 'West'],
    [184, 184, 'North-West'],
    [185, 185, 'South-West'],
    [186, 197, 'West'],
    [198, 198, 'North-West'],
    [199, 199, 'North-East'],
    [200, 211, 'East'],
    [212, 212, 'South-East'],
    [213, 213, 'North-East'],
    [214, 219, 'East'],
    [220, 225, 'West'],
    [226, 226, 'North-West'],
    [227, 227, 'South-West'],
    [228, 229, 'West'],
    [230, 230, 'East'],
    [231, 231, 'South-East'],
    [232, 232, 'North-East'],
    [233, 235, 'East'],
];

$directions = [];
foreach ($ranges as [$start, $end, $direction]) {
    if ($start < 1 || $end > 235 || $start > $end || !PlotFacing::isValid($direction)) {
        throw new RuntimeException('Invalid plot facing range.');
    }
    for ($number = $start; $number <= $end; $number++) {
        if (isset($directions[$number])) {
            throw new RuntimeException('Plot ' . $number . ' appears more than once in the facing data.');
        }
        $directions[$number] = $direction;
    }
}
ksort($directions);
if (array_keys($directions) !== range(1, 235)) {
    throw new RuntimeException('Facing data must cover each plot from 1 to 235 exactly once.');
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $projectQuery = $pdo->prepare('SELECT id FROM projects WHERE slug = :slug FOR UPDATE');
    $projectQuery->execute(['slug' => 'quantum-city']);
    $projectId = $projectQuery->fetchColumn();
    if ($projectId === false) {
        throw new RuntimeException('The Quantum City project must exist before importing plot facings.');
    }

    $plotQuery = $pdo->prepare(
        'SELECT id, plot_number FROM plots WHERE project_id = :project_id FOR UPDATE'
    );
    $plotQuery->execute(['project_id' => (int) $projectId]);
    $plotsByNumber = [];
    foreach ($plotQuery->fetchAll() as $plot) {
        $number = (string) $plot['plot_number'];
        if (!ctype_digit($number) || (string) (int) $number !== $number || isset($plotsByNumber[(int) $number])) {
            throw new RuntimeException('Unexpected or duplicate plot number; no facing data was changed.');
        }
        $plotsByNumber[(int) $number] = (int) $plot['id'];
    }
    ksort($plotsByNumber);
    if (array_keys($plotsByNumber) !== range(1, 235)) {
        throw new RuntimeException('Quantum City must contain exactly plots 1 to 235; no facing data was changed.');
    }

    $update = $pdo->prepare(
        'UPDATE plots SET facing_direction = :direction WHERE id = :id'
    );
    foreach ($directions as $number => $direction) {
        $update->execute([
            'direction' => $direction,
            'id' => $plotsByNumber[$number],
        ]);
    }

    $verify = $pdo->prepare(
        'SELECT plot_number, facing_direction FROM plots WHERE project_id = :project_id'
    );
    $verify->execute(['project_id' => (int) $projectId]);
    foreach ($verify->fetchAll() as $plot) {
        $number = (int) $plot['plot_number'];
        if (!isset($directions[$number]) || $plot['facing_direction'] !== $directions[$number]) {
            throw new RuntimeException('Facing verification failed for plot ' . $plot['plot_number'] . '.');
        }
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

$distribution = array_count_values($directions);
ksort($distribution);
echo json_encode([
    'plots_updated' => count($directions),
    'unique_plots' => count(array_unique(array_keys($directions))),
    'directions' => $distribution,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
