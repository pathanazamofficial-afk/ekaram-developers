<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
$admin = Auth::requireAdminPage();
$pdo = Database::connection();

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$stage = trim((string) ($_GET['stage'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$ownerId = trim((string) ($_GET['owner_id'] ?? ''));
$recipientId = trim((string) ($_GET['recipient_id'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$conditions = [];
$params = [];
if ($from !== '') {
    if (!validDate($from)) {
        http_response_code(422);
        exit('Invalid start date');
    }
    $conditions[] = 'bm.completed_at >= :date_from';
    $params['date_from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    if (!validDate($to)) {
        http_response_code(422);
        exit('Invalid end date');
    }
    $conditions[] = 'bm.completed_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
    $params['date_to'] = $to . ' 00:00:00';
}
if ($stage !== '') {
    if (!in_array($stage, ['agreement', 'registration'], true)) {
        http_response_code(422);
        exit('Invalid commission stage');
    }
    $conditions[] = 'cb.stage = :stage';
    $params['stage'] = $stage;
}
if ($status !== '') {
    if (!in_array($status, ['pending', 'held', 'approved', 'rejected'], true)) {
        http_response_code(422);
        exit('Invalid batch status');
    }
    $conditions[] = 'cb.status = :status';
    $params['status'] = $status;
}
if ($ownerId !== '') {
    $validated = filter_var($ownerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validated === false) {
        http_response_code(422);
        exit('Invalid lead owner');
    }
    $conditions[] = 'cb.lead_owner_id = :owner_id';
    $params['owner_id'] = (int) $validated;
}
if ($recipientId !== '') {
    $validated = filter_var($recipientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validated === false) {
        http_response_code(422);
        exit('Invalid commission recipient');
    }
    $conditions[] = 'cm.user_id = :recipient_id';
    $params['recipient_id'] = (int) $validated;
}
if ($search !== '') {
    if (mb_strlen($search) > 150) {
        http_response_code(422);
        exit('Search text is too long');
    }
    $conditions[] = '(customer.full_name LIKE :search_name OR customer.phone LIKE :search_phone OR plot.plot_number LIKE :search_plot)';
    $params['search_name'] = '%' . $search . '%';
    $params['search_phone'] = '%' . $search . '%';
    $params['search_plot'] = '%' . $search . '%';
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$query = "SELECT cb.id AS batch_id, cb.stage, cb.status AS batch_status, cb.collected_to_date,
                 cb.eligible_collection, cb.rule_version, bm.completed_at, booking.booking_reference,
                 customer.full_name AS customer_name, customer.phone AS customer_phone,
                 plot.plot_number, owner.full_name AS lead_owner_name,
                 company_rule.percentage AS company_percentage,
                 ROUND(cb.eligible_collection * company_rule.percentage / 100, 2) AS company_retained,
                 cm.recipient_code, recipient.full_name AS recipient_name,
                 cm.percentage, cm.amount AS commission_amount, cm.status AS commission_status
          FROM commission_batches cb
          JOIN booking_milestones bm ON bm.id = cb.milestone_id
          JOIN bookings booking ON booking.id = cb.booking_id
          JOIN customers customer ON customer.id = booking.customer_id
          JOIN plots plot ON plot.id = booking.plot_id
          JOIN users owner ON owner.id = cb.lead_owner_id
          LEFT JOIN commission_rule_versions company_rule
            ON company_rule.version_no = cb.rule_version AND company_rule.recipient_code = 'company'
          LEFT JOIN commissions cm ON cm.batch_id = cb.id
          LEFT JOIN users recipient ON recipient.id = cm.user_id
          $where
          ORDER BY bm.completed_at DESC, cb.id DESC, cm.id";
$statement = $pdo->prepare($query);
$statement->execute($params);
$rows = $statement->fetchAll();

$employeeOptions = $pdo->query(
    "SELECT DISTINCT u.id, u.full_name, r.name AS role_name
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE r.code <> 'admin' ORDER BY u.full_name"
)->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ekaram-commission-report.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, [
        'Batch ID', 'Completed At UTC', 'Stage', 'Batch Status', 'Booking Reference',
        'Customer', 'Customer Phone', 'Plot Number', 'Lead Owner', 'Collected To Date',
        'Eligible Collection', 'Company Rate (%)', 'Company Retained', 'Rule Version', 'Recipient', 'Designation', 'Rate (%)',
        'Commission Amount', 'Commission Status',
    ]);
    foreach ($rows as $row) {
        fputcsv($output, array_map('csvSafe', [
            $row['batch_id'], $row['completed_at'], $row['stage'], $row['batch_status'],
            $row['booking_reference'], $row['customer_name'], $row['customer_phone'],
            $row['plot_number'], $row['lead_owner_name'], $row['collected_to_date'],
            $row['eligible_collection'], $row['company_percentage'], $row['company_retained'],
            $row['rule_version'], $row['recipient_name'],
            $row['recipient_code'], $row['percentage'], $row['commission_amount'],
            $row['commission_status'],
        ]));
    }
    fclose($output);
    exit;
}

$csrfToken = Security::csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Commission reports | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header">
    <a class="brand admin-brand" href="/admin/index.php"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <span><?= Security::escape($admin['full_name']) ?> · Admin</span>
  </header>
  <main class="admin-content">
    <div class="admin-title"><div><p class="section-kicker">Finance and performance</p><h1>Commission reports</h1></div><a class="button button-dark" href="/admin/index.php">Back to dashboard</a></div>
    <section class="admin-section">
      <form class="admin-form admin-grid" method="get">
        <label>From date<input type="date" name="from" value="<?= Security::escape($from) ?>"></label>
        <label>To date<input type="date" name="to" value="<?= Security::escape($to) ?>"></label>
        <label>Stage<select name="stage"><option value="">All stages</option><option value="agreement" <?= $stage === 'agreement' ? 'selected' : '' ?>>Agreement</option><option value="registration" <?= $stage === 'registration' ? 'selected' : '' ?>>Registration</option></select></label>
        <label>Batch status<select name="status"><option value="">All statuses</option><?php foreach (['pending','held','approved','rejected'] as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option><?php endforeach; ?></select></label>
        <label>Lead owner<select name="owner_id"><option value="">All owners</option><?php foreach ($employeeOptions as $option): ?><option value="<?= (int) $option['id'] ?>" <?= $ownerId === (string) $option['id'] ? 'selected' : '' ?>><?= Security::escape($option['full_name'] . ' (' . $option['role_name'] . ')') ?></option><?php endforeach; ?></select></label>
        <label>Commission recipient<select name="recipient_id"><option value="">All recipients</option><?php foreach ($employeeOptions as $option): ?><option value="<?= (int) $option['id'] ?>" <?= $recipientId === (string) $option['id'] ? 'selected' : '' ?>><?= Security::escape($option['full_name'] . ' (' . $option['role_name'] . ')') ?></option><?php endforeach; ?></select></label>
        <label class="full">Customer, phone or plot search<input name="search" value="<?= Security::escape($search) ?>" maxlength="150"></label>
        <button class="button button-dark" type="submit">Apply filters</button>
        <button class="button" type="submit" name="export" value="csv">Download CSV</button>
      </form>
      <p><?= count($rows) ?> commission detail rows. Rows are calculated at verified agreement/registration milestones, not at booking confirmation.</p>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Completed (UTC)</th><th>Stage / Status</th><th>Booking / customer</th><th>Plot</th><th>Lead owner</th><th>Collected</th><th>Eligible</th><th>Company retained</th><th>Recipient</th><th>Rate</th><th>Commission</th></tr></thead><tbody>
      <?php foreach ($rows as $row): ?>
        <tr><td><?= Security::escape($row['completed_at']) ?></td>
          <td><?= Security::escape(ucfirst($row['stage'])) ?> · <?= Security::escape($row['batch_status']) ?></td>
          <td><?= Security::escape($row['booking_reference']) ?><br><?= Security::escape($row['customer_name']) ?> · <?= Security::escape($row['customer_phone']) ?></td>
          <td><?= Security::escape($row['plot_number']) ?></td><td><?= Security::escape($row['lead_owner_name']) ?></td>
          <td>₹<?= Security::escape(Money::format((string) $row['collected_to_date'])) ?></td>
          <td>₹<?= Security::escape(Money::format((string) $row['eligible_collection'])) ?></td>
          <td><?= $row['company_retained'] === null ? '—' : '₹' . Security::escape(Money::format((string) $row['company_retained'])) ?></td>
          <td><?= $row['recipient_name'] === null ? 'No eligible recipient' : Security::escape($row['recipient_name'] . ' · ' . $row['recipient_code']) ?></td>
          <td><?= $row['percentage'] === null ? '—' : Security::escape($row['percentage']) . '%' ?></td>
          <td><?= $row['commission_amount'] === null ? '—' : '₹' . Security::escape(Money::format((string) $row['commission_amount'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="11">No matching commission batches.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </main>
</body>
</html>
<?php
function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function csvSafe(mixed $value): string
{
    $text = (string) ($value ?? '');
    return preg_match('/^[=+\-@]/', $text) ? "'" . $text : $text;
}
