<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
Auth::requireAdminPage();

$timezone = new DateTimeZone(appConfig()['timezone']);
$today = new DateTimeImmutable('today', $timezone);
$fromInput = trim((string) ($_GET['from'] ?? $today->modify('-29 days')->format('Y-m-d')));
$toInput = trim((string) ($_GET['to'] ?? $today->format('Y-m-d')));
$reportType = (string) ($_GET['type'] ?? 'leads');
$reports = [
    'leads' => [
        'label' => 'Leads',
        'date_column' => 'l.created_at',
        'from' => 'FROM leads l JOIN customers c ON c.id = l.customer_id LEFT JOIN projects p ON p.id = l.project_id',
        'columns' => ['id', 'customer', 'phone', 'project', 'type', 'status', 'created_at'],
        'select' => 'SELECT l.id, c.full_name AS customer, c.phone, p.name AS project, l.enquiry_type AS type, l.status, l.created_at',
    ],
    'bookings' => [
        'label' => 'Bookings',
        'date_column' => 'b.created_at',
        'from' => 'FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN plots pl ON pl.id = b.plot_id JOIN projects p ON p.id = pl.project_id',
        'columns' => ['booking_reference', 'customer', 'phone', 'project', 'plot', 'total_price', 'status', 'created_at'],
        'select' => 'SELECT b.booking_reference, c.full_name AS customer, c.phone, p.name AS project, pl.plot_number AS plot, b.total_price, b.status, b.created_at',
    ],
    'commissions' => [
        'label' => 'Commissions',
        'date_column' => 'c.created_at',
        'from' => 'FROM commissions c JOIN users u ON u.id = c.user_id JOIN bookings b ON b.id = c.booking_id',
        'columns' => ['id', 'employee', 'recipient_code', 'booking_reference', 'base_amount', 'percentage', 'amount', 'status', 'created_at'],
        'select' => 'SELECT c.id, u.full_name AS employee, c.recipient_code, b.booking_reference, c.base_amount, c.percentage, c.amount, c.status, c.created_at',
    ],
    'withdrawals' => [
        'label' => 'Withdrawals',
        'date_column' => 'w.requested_at',
        'from' => 'FROM withdrawal_requests w JOIN users u ON u.id = w.user_id',
        'columns' => ['id', 'employee', 'amount', 'status', 'payment_reference', 'requested_at', 'paid_at'],
        'select' => 'SELECT w.id, u.full_name AS employee, w.amount, w.status, w.payment_reference, w.requested_at, w.paid_at',
    ],
];
if (!isset($reports[$reportType])) {
    http_response_code(422);
    exit('Invalid report type');
}
$fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $fromInput, $timezone);
$toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $toInput, $timezone);
$dateErrors = DateTimeImmutable::getLastErrors();
if ($fromDate === false || $toDate === false
    || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
    || $fromDate->format('Y-m-d') !== $fromInput || $toDate->format('Y-m-d') !== $toInput
    || $fromDate > $toDate || $fromDate->diff($toDate)->days > 365
) {
    http_response_code(422);
    exit('Choose a valid date range of no more than 366 days');
}
$fromUtc = $fromDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$untilUtc = $toDate->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$report = $reports[$reportType];
$pdo = Database::connection();
$countStatement = $pdo->prepare(
    'SELECT COUNT(*) ' . $report['from'] .
    ' WHERE ' . $report['date_column'] . ' >= :from_date AND ' . $report['date_column'] . ' < :until_date'
);
$countStatement->execute(['from_date' => $fromUtc, 'until_date' => $untilUtc]);
$recordCount = (int) $countStatement->fetchColumn();
$statement = $pdo->prepare(
    $report['select'] . ' ' . $report['from'] . ' WHERE ' . $report['date_column'] . ' >= :from_date AND ' .
    $report['date_column'] . ' < :until_date ORDER BY ' . $report['date_column'] . ' DESC LIMIT 5000'
);
$statement->execute(['from_date' => $fromUtc, 'until_date' => $untilUtc]);
$rows = $statement->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ekaram-' . $reportType . '-' . $fromInput . '-to-' . $toInput . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to open the report output stream');
    }
    fputcsv($output, $report['columns'], ',', '"', '');
    foreach ($rows as $row) {
        $csvRow = [];
        foreach ($report['columns'] as $column) {
            $value = (string) ($row[$column] ?? '');
            if ($value !== '' && preg_match('/^[\s]*[=+\-@]/', $value)) {
                $value = "'" . $value;
            }
            $csvRow[] = $value;
        }
        fputcsv($output, $csvRow, ',', '"', '');
    }
    fclose($output);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reports | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header"><a class="brand admin-brand" href="/admin/index.php"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a><a class="button button-outline" href="/admin/index.php">Dashboard</a></header>
  <main class="admin-content">
    <div class="admin-title"><div><p class="section-kicker">Operations</p><h1>Reports</h1></div></div>
    <form class="report-filter" method="get">
      <label>Report<select name="type"><?php foreach ($reports as $key => $option): ?><option value="<?= Security::escape($key) ?>" <?= $reportType === $key ? 'selected' : '' ?>><?= Security::escape($option['label']) ?></option><?php endforeach; ?></select></label>
      <label>From date<input type="date" name="from" value="<?= Security::escape($fromInput) ?>" required></label>
      <label>To date<input type="date" name="to" value="<?= Security::escape($toInput) ?>" required></label>
      <button class="button button-dark" type="submit">Run report</button>
      <button class="button button-outline" type="submit" name="export" value="csv">Download CSV</button>
    </form>
    <section class="admin-section">
      <h2><?= Security::escape($report['label']) ?></h2>
      <p><?= $recordCount ?> record(s) · Times shown in UTC · Display/export limited to the newest 5,000 records in the selected date range.</p>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><?php foreach ($report['columns'] as $column): ?><th><?= Security::escape(ucwords(str_replace('_', ' ', $column))) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($rows as $row): ?><tr><?php foreach ($report['columns'] as $column): ?><td><?= Security::escape((string) ($row[$column] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?= count($report['columns']) ?>">No records in this range.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </main>
</body>
</html>
