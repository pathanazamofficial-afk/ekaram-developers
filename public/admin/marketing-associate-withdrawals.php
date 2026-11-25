<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
Auth::requireAdminPage();
$pdo = Database::connection();
$csrfToken = Security::csrfToken();
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$statusMap = [
    'pending' => 'requested',
    'approved' => 'approved',
    'rejected' => 'rejected',
    'processing' => 'processing',
    'paid' => 'paid',
];
if (!array_key_exists($statusFilter, $statusMap)) {
    $statusFilter = '';
}
$conditions = [];
$parameters = [];
if ($search !== '') {
    $conditions[] = '(u.full_name LIKE :name_search OR CAST(ma.id AS CHAR) LIKE :associate_search OR wr.request_reference LIKE :request_search)';
    $parameters['name_search'] = '%' . $search . '%';
    $parameters['associate_search'] = '%' . $search . '%';
    $parameters['request_search'] = '%' . $search . '%';
}
if ($statusFilter !== '') {
    $conditions[] = 'wr.status = :status';
    $parameters['status'] = $statusMap[$statusFilter];
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$statement = $pdo->prepare(
    "SELECT wr.id, wr.request_reference, wr.amount, wr.status, wr.request_remarks, wr.admin_remarks,
            wr.payment_reference, wr.requested_at, wr.paid_at,
            ma.id AS associate_id, ma.approval_status, u.id AS user_id, u.full_name,
            u.phone, profile.profile_photo_path
     FROM withdrawal_requests wr
     JOIN users u ON u.id = wr.user_id
     JOIN roles r ON r.id = u.role_id
     JOIN marketing_associates ma ON ma.user_id = u.id
     LEFT JOIN marketing_associate_profiles profile ON profile.user_id = u.id
     $where " . ($where ? ' AND ' : 'WHERE ') . "r.code = 'marketing_associate'
     ORDER BY CASE wr.status WHEN 'requested' THEN 0 WHEN 'approved' THEN 1 WHEN 'processing' THEN 2 ELSE 3 END,
              wr.requested_at DESC LIMIT 200"
);
$statement->execute($parameters);
$withdrawals = $statement->fetchAll();
$historyStatement = $pdo->prepare(
    'SELECT history.old_status, history.new_status, history.remarks, history.created_at, actor.full_name AS actor_name
     FROM withdrawal_status_history history
     LEFT JOIN users actor ON actor.id = history.actor_user_id
     WHERE history.withdrawal_id = :withdrawal_id ORDER BY history.id DESC'
);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Marketing Associate Withdrawal Requests | Admin | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header">
    <a class="brand admin-brand" href="/admin/index.php"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <nav class="admin-user" aria-label="Admin navigation"><a href="/admin/index.php">Dashboard</a><a href="/admin/marketing-associate-wallet.php">Wallets</a><a href="/admin/marketing-associates.php">Marketing Associates</a></nav>
  </header>
  <main class="admin-content">
    <div class="admin-title"><div><p class="section-kicker">Administration</p><h1>Withdrawal Requests</h1></div><a class="button button-dark" href="/admin/marketing-associate-wallet.php">Wallet Management</a></div>
    <p class="admin-message" role="status" aria-live="polite"></p>
    <section class="admin-section">
      <h2>Marketing Associate withdrawals</h2>
      <p>Bank transfers are processed manually by an administrator. A request reserves funds until it is rejected or paid.</p>
      <form class="customer-search" method="get" action="/admin/marketing-associate-withdrawals.php">
        <label>Associate name, associate ID or request ID<input type="search" name="search" maxlength="100" value="<?= Security::escape($search) ?>"></label>
        <label>Status<select name="status"><option value="">All statuses</option><?php foreach (array_keys($statusMap) as $status): ?><option value="<?= Security::escape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= Security::escape(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
        <button class="button button-dark" type="submit">Filter</button>
        <a class="button button-outline-dark" href="/admin/marketing-associate-withdrawals.php">Clear</a>
      </form>
      <div class="admin-record-list">
        <?php foreach ($withdrawals as $withdrawal):
            $displayStatus = $withdrawal['status'] === 'requested' ? 'pending' : $withdrawal['status'];
            $historyStatement->execute(['withdrawal_id' => (int) $withdrawal['id']]);
            $history = $historyStatement->fetchAll();
        ?>
          <details class="admin-record">
            <summary>
              <?= Security::escape($withdrawal['full_name']) ?> · <?= Security::escape((string) ($withdrawal['request_reference'] ?: '#' . $withdrawal['id'])) ?>
              <span class="associate-status associate-status-<?= Security::escape($displayStatus) ?>"><?= Security::escape(ucfirst($displayStatus)) ?> · ₹<?= Security::escape(Money::format((string) $withdrawal['amount'])) ?></span>
            </summary>
            <div class="associate-review-details">
              <div class="associate-dashboard-heading">
                <div><?php if ($withdrawal['profile_photo_path'] !== null): ?><img class="associate-admin-photo" src="/api/marketing-associate/photo.php?associate_id=<?= (int) $withdrawal['user_id'] ?>" alt="Profile photo"><?php else: ?><span class="associate-avatar associate-avatar-placeholder" aria-hidden="true"><?= Security::escape(strtoupper(substr($withdrawal['full_name'], 0, 1))) ?></span><?php endif; ?></div>
                <div><strong><?= Security::escape($withdrawal['full_name']) ?></strong><p>Associate #<?= sprintf('%03d', (int) $withdrawal['associate_id']) ?> · <?= Security::escape($withdrawal['phone']) ?></p><p><a href="/admin/marketing-associate-profile.php?id=<?= (int) $withdrawal['associate_id'] ?>">Open full associate profile</a></p></div>
              </div>
              <p>Requested <?= Security::escape($withdrawal['requested_at']) ?> UTC · Amount ₹<?= Security::escape(Money::format((string) $withdrawal['amount'])) ?></p>
              <?php if ($withdrawal['request_remarks']): ?><p><strong>Associate remarks:</strong> <?= Security::escape($withdrawal['request_remarks']) ?></p><?php endif; ?>
              <div class="withdrawal-bank-details">
                <form data-withdrawal-details action="/api/admin/withdrawal-details.php" method="post">
                  <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= (int) $withdrawal['id'] ?>">
                  <button type="submit">Reveal verified bank details</button>
                </form>
                <span data-withdrawal-output aria-live="polite"></span>
              </div>
              <div class="wallet-action-row">
                <?php if ($withdrawal['status'] === 'requested'): ?>
                  <form class="withdrawal-action-form" data-admin-form action="/api/admin/marketing-associate-withdrawals.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>"><input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['id'] ?>"><input type="hidden" name="action" value="approve">
                    <button class="button button-dark" type="submit">Approve</button>
                  </form>
                  <form class="withdrawal-action-form" data-admin-form action="/api/admin/marketing-associate-withdrawals.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>"><input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['id'] ?>"><input type="hidden" name="action" value="reject">
                    <label>Rejection reason<textarea name="remarks" maxlength="1000" rows="2" required></textarea></label><button class="button button-danger" type="submit">Reject</button>
                  </form>
                <?php elseif ($withdrawal['status'] === 'approved'): ?>
                  <form class="withdrawal-action-form" data-admin-form action="/api/admin/marketing-associate-withdrawals.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>"><input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['id'] ?>"><input type="hidden" name="action" value="processing">
                    <button class="button button-dark" type="submit">Mark Processing</button>
                  </form>
                <?php elseif ($withdrawal['status'] === 'processing'): ?>
                  <form class="withdrawal-action-form" data-admin-form action="/api/admin/marketing-associate-withdrawals.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>"><input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['id'] ?>"><input type="hidden" name="action" value="pay">
                    <label>UTR / payment reference<input name="payment_reference" maxlength="100" required></label>
                    <label>Payment remarks<textarea name="remarks" maxlength="1000" rows="2"></textarea></label><button class="button button-dark" type="submit">Mark Paid</button>
                  </form>
                <?php elseif ($withdrawal['status'] === 'paid'): ?>
                  <p>Paid <?= Security::escape((string) $withdrawal['paid_at']) ?> UTC · UTR <?= Security::escape((string) $withdrawal['payment_reference']) ?></p>
                <?php else: ?>
                  <p>Rejected · <?= Security::escape((string) $withdrawal['admin_remarks']) ?></p>
                <?php endif; ?>
              </div>
              <details><summary>Status history</summary>
                <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date (UTC)</th><th>Transition</th><th>Actor</th><th>Remarks</th></tr></thead><tbody>
                  <?php foreach ($history as $entry): ?><tr><td><?= Security::escape($entry['created_at']) ?></td><td><?= Security::escape((string) ($entry['old_status'] ?? 'New') . ' → ' . $entry['new_status']) ?></td><td><?= Security::escape((string) ($entry['actor_name'] ?? 'System')) ?></td><td><?= Security::escape((string) $entry['remarks']) ?></td></tr><?php endforeach; ?>
                  <?php if (!$history): ?><tr><td colspan="4">No status history.</td></tr><?php endif; ?>
                </tbody></table></div>
              </details>
            </div>
          </details>
        <?php endforeach; ?>
        <?php if (!$withdrawals): ?><p class="empty-state">No withdrawal requests match these filters.</p><?php endif; ?>
      </div>
    </section>
  </main>
  <script src="/assets/js/admin.js" defer></script>
  <script src="/assets/js/withdrawals.js" defer></script>
</body>
</html>
