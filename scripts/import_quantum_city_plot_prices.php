<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$csvPath = __DIR__ . '/../database/quantum_city_235_plot_prices.csv';
$handle = fopen($csvPath, 'rb');
if ($handle === false) {
    throw new RuntimeException('Unable to open the Quantum City plot price CSV.');
}

$header = fgetcsv($handle);
if (!is_array($header) || count($header) !== 4) {
    fclose($handle);
    throw new RuntimeException('The plot price CSV is empty.');
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
if ($header[0] !== 'Plot No.'
    || $header[1] !== 'Area (Sq. Yards)'
    || strpos((string) $header[2], 'Rate (') !== 0
    || strpos((string) $header[2], 'Sq. Yard)') === false
    || strpos((string) $header[3], 'Plot Price (') !== 0
) {
    fclose($handle);
    throw new RuntimeException('The plot price CSV does not have the expected columns.');
}

$rows = [];
$unspecifiedAreas = [];
$lineNumber = 1;
while (($columns = fgetcsv($handle)) !== false) {
    $lineNumber++;
    if (count($columns) !== 4) {
        fclose($handle);
        throw new RuntimeException('Invalid CSV column count on line ' . $lineNumber . '.');
    }

    [$numberText, $areaText, $rateText, $priceText] = array_map('trim', $columns);
    $number = filter_var($numberText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 235]]);
    if ($number === false || (string) $number !== $numberText || isset($rows[$number])) {
        fclose($handle);
        throw new RuntimeException('Invalid or duplicate plot number on line ' . $lineNumber . '.');
    }
    if ($rateText !== '22000') {
        fclose($handle);
        throw new RuntimeException('Unexpected price rate for plot ' . $number . '.');
    }

    if (strcasecmp($areaText, 'Not specified') === 0) {
        if ($priceText !== 'Not calculated') {
            fclose($handle);
            throw new RuntimeException('Plot ' . $number . ' has an invented price for an unspecified area.');
        }
        $area = null;
        $price = null;
        $unspecifiedAreas[] = $number;
    } else {
        if (!preg_match('/^\d{1,4}\.\d{2}$/', $areaText)) {
            fclose($handle);
            throw new RuntimeException('Invalid area for plot ' . $number . '.');
        }
        $expectedPriceCents = Money::toMinorUnits($areaText) * 22000;
        $csvPriceCents = str_replace([',', '.'], '', $priceText);
        if (!preg_match('/^\d{1,3}(?:,\d{3})*\.00$/', $priceText)
            || $csvPriceCents !== (string) $expectedPriceCents
        ) {
            fclose($handle);
            throw new RuntimeException('The supplied price does not match the area and rate for plot ' . $number . '.');
        }
        $area = $areaText;
        $price = Money::fromMinorUnits($expectedPriceCents);
    }

    $rows[$number] = ['area' => $area, 'price' => $price];
}
fclose($handle);

ksort($rows);
sort($unspecifiedAreas);
if (count($rows) !== 235 || array_keys($rows) !== range(1, 235) || $unspecifiedAreas !== range(188, 197)) {
    throw new RuntimeException('The CSV must contain plots 1–235, with only plots 188–197 missing area.');
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $projectQuery = $pdo->prepare('SELECT id FROM projects WHERE slug = :slug FOR UPDATE');
    $projectQuery->execute(['slug' => 'quantum-city']);
    $projectId = $projectQuery->fetchColumn();
    if ($projectId === false) {
        throw new RuntimeException('The Quantum City project must exist before importing plot prices.');
    }

    $existingQuery = $pdo->prepare('SELECT plot_number FROM plots WHERE project_id = :project_id');
    $existingQuery->execute(['project_id' => (int) $projectId]);
    foreach ($existingQuery->fetchAll(PDO::FETCH_COLUMN) as $existingNumber) {
        if (!ctype_digit((string) $existingNumber)
            || (int) $existingNumber < 1
            || (int) $existingNumber > 235
        ) {
            throw new RuntimeException('Unexpected existing plot number; no inventory was changed.');
        }
    }

    $pdo->prepare(
        'UPDATE projects SET price_per_sqyd = :rate, plot_count = 235 WHERE id = :id'
    )->execute(['rate' => '22000.00', 'id' => (int) $projectId]);

    $findPlot = $pdo->prepare(
        'SELECT id FROM plots WHERE project_id = :project_id AND plot_number = :plot_number FOR UPDATE'
    );
    $insertPlot = $pdo->prepare(
        "INSERT INTO plots (project_id, plot_number, dimensions, area_sqyd, total_price, status)
         VALUES (:project_id, :plot_number, 'Not provided', :area, :price, 'available')"
    );
    $updatePlot = $pdo->prepare(
        'UPDATE plots SET area_sqyd = :area, total_price = :price
         WHERE project_id = :project_id AND plot_number = :plot_number'
    );
    $history = $pdo->prepare(
        "INSERT INTO plot_status_history (plot_id, old_status, new_status, note)
         VALUES (:plot_id, NULL, 'available', :note)"
    );

    foreach ($rows as $number => $plotData) {
        $findPlot->execute(['project_id' => (int) $projectId, 'plot_number' => (string) $number]);
        $plotId = $findPlot->fetchColumn();
        if ($plotId === false) {
            $insertPlot->execute([
                'project_id' => (int) $projectId,
                'plot_number' => (string) $number,
                'area' => $plotData['area'],
                'price' => $plotData['price'],
            ]);
            $plotId = (int) $pdo->lastInsertId();
            $history->execute([
                'plot_id' => $plotId,
                'note' => 'Imported from the supplied 235-plot price sheet; initial availability confirmed by administrator.',
            ]);
        } else {
            $updatePlot->execute([
                'area' => $plotData['area'],
                'price' => $plotData['price'],
                'project_id' => (int) $projectId,
                'plot_number' => (string) $number,
            ]);
        }
    }

    $countQuery = $pdo->prepare('SELECT COUNT(*) FROM plots WHERE project_id = :project_id');
    $countQuery->execute(['project_id' => (int) $projectId]);
    if ((int) $countQuery->fetchColumn() !== 235) {
        throw new RuntimeException('The imported Quantum City inventory does not contain exactly 235 plots.');
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

echo 'Imported 235 verified Quantum City plot records at ₹22,000 per square yard.' . PHP_EOL;
echo 'Plots 188–197 retain unspecified areas and unavailable prices.' . PHP_EOL;
