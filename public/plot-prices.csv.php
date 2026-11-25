<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$project = Database::connection()->query(
    "SELECT id, name, price_per_sqyd
     FROM projects
     WHERE status = 'published'
     ORDER BY id DESC
     LIMIT 1"
)->fetch();
if ($project === false) {
    http_response_code(404);
    exit('No published project plot prices are available.');
}

$statement = Database::connection()->prepare(
    'SELECT plot_number, area_sqyd,
            CASE
              WHEN area_sqyd IS NULL OR :rate IS NULL THEN NULL
              ELSE ROUND(area_sqyd * :rate_for_price, 2)
            END AS total_price
     FROM plots
     WHERE project_id = :project_id
     ORDER BY CAST(plot_number AS UNSIGNED), plot_number'
);
$statement->execute([
    'rate' => $project['price_per_sqyd'],
    'rate_for_price' => $project['price_per_sqyd'],
    'project_id' => (int) $project['id'],
]);

$safeCell = static function (string $value): string {
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
};
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="quantum-city-plot-prices.csv"');
header('Cache-Control: no-store, private');
$output = fopen('php://output', 'wb');
if ($output === false) {
    throw new RuntimeException('Unable to generate plot price CSV.');
}
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['Plot No.', 'Area (Sq. Yards)', 'Rate (₹/Sq. Yard)', 'Plot Price (₹)']);
while ($row = $statement->fetch()) {
    $area = $row['area_sqyd'] !== null
        ? number_format((float) $row['area_sqyd'], 2, '.', '')
        : 'Area Not Specified';
    $rate = $project['price_per_sqyd'] !== null ? (string) $project['price_per_sqyd'] : 'Rate Not Set';
    $price = $row['total_price'] !== null
        ? Money::formatIndian((string) $row['total_price'])
        : 'Price Not Available';
    fputcsv($output, [
        $safeCell((string) $row['plot_number']),
        $safeCell($area),
        $safeCell($rate),
        $safeCell($price),
    ]);
}
fclose($output);
