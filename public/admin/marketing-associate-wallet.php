<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
$admin = Auth::requireAdminPage();
$pdo = Database::connection();
$csrfToken = Security::csrfToken();
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}
$conditions = '';
$parameters = [];
if ($search !== '') {
    $conditions = 'WHERE u.full_name LIKE :name_search OR CAST(ma.id AS CHAR) LIKE :id_search';
    $parameters = ['name_search' => '%' . $search . '%', 'id_search' => '%' . $search . '%'];
}
$statement = $pdo->prepare(
    "SELECT ma.id AS associate_id, ma.approval_status, u.id AS user_id, u.full_name,
            w.cached_balance, profile.profile_photo_path
     FROM marketing_associates ma
     JOIN users u ON u.id = ma.user_id
     LEFT JOIN wallets w ON w.user_id = u.id
     LEFT JOIN marketing_associate_profiles profile ON profile.user_id = u.id
     $conditions
     ORDER BY ma.id DESC LIMIT 100"
);
$statement->execute($parameters);
$associates = $statement->fetchAll();

$selectedId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selected = null;
$transactions = [];
$reversibleCredits = [];
$walletAdminLogs = [];
$availableBalance = '0.00';
$totalCredited = '0.00';
$totalWithdrawn = '0.00';
$pendingAmount = '0.00';
if ($selectedId !== false && $selectedId !== null) {
    $statement = $pdo->prepare(
        'SELECT ma.id AS associate_id, ma.user_id, ma.approval_status, u.full_name, u.phone,
                profile.profile_photo_path, COALESCE(w.id, 0) AS wallet_id,
                COALESCE(w.cached_balance, 0) AS cached_balance
         FROM marketing_associates ma
         JOIN users u ON u.id = ma.user_id
         LEFT JOIN wallets w ON w.user_id = u.id
         LEFT JOIN marketing_associate_profiles profile ON profile.user_id = u.id
         WHERE ma.id = :id'
    );
    $statement->execute(['id' => $selectedId]);
    $selected = $statement->fetch() ?: null;
    if ($selected !== null && (int) $selected['wallet_id'] > 0) {
        $walletId = (int) $selected['wallet_id'];
        $statement = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests
             WHERE user_id = :user_id AND status IN ('requested','approved','processing')"
        );
        $statement->execute(['user_id' => (int) $selected['user_id']]);
        $pendingAmount = (string) $statement->fetchColumn();
        $availableBalance = Money::fromMinorUnits(
            max(0, Money::toMinorUnits((string) $selected['cached_balance']) - Money::toMinorUnits($pendingAmount))
        );
        $statement = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
             WHERE wallet_id = :wallet_id AND transaction_type = 'credit'"
        );
        $statement->execute(['wallet_id' => $walletId]);
        $totalCredited = (string) $statement->fetchColumn();
        $statement = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
             WHERE wallet_id = :wallet_id AND reference_type = 'withdrawal' AND transaction_type = 'debit'"
        );
        $statement->execute(['wallet_id' => $walletId]);
        $totalWithdrawn = (string) $statement->fetchColumn();
        $statement = $pdo->prepare(
            'SELECT id, transaction_type, amount, balance_after, reference_type, description, created_at
             FROM wallet_transactions WHERE wallet_id = :wallet_id ORDER BY id DESC LIMIT 200'
        );
        $statement->execute(['wallet_id' => $walletId]);
        $transactions = $statement->fetchAll();
        $statement = $pdo->prepare(
            "SELECT wt.id, wt.amount, wt.created_at, wt.description
             FROM wallet_transactions wt
             WHERE wt.wallet_id = :wallet_id AND wt.transaction_type = 'credit'
               AND wt.reference_type = 'admin_wallet_credit'
               AND NOT EXISTS (
                 SELECT 1 FROM wallet_transactions reversal
                 WHERE reversal.reference_type = 'wallet_reversal' AND reversal.reference_id = wt.id
               )
             ORDER BY wt.id DESC"
        );
        $statement->execute(['wallet_id' => $walletId]);
        $reversibleCredits = $statement->fetchAll();
        $statement = $pdo->prepare(
            'SELECT log.action, log.amount, log.remarks, log.created_at, actor.full_name AS actor_name
             FROM wallet_admin_audit_logs log LEFT JOIN users actor ON actor.id = log.actor_user_id
             WHERE log.associate_user_id = :user_id ORDER BY log.id DESC LIMIT 100'
        );
        $statement->execute(['user_id' => (int) $selected['user_id']]);
        $walletAdminLogs = $statement->fetchAll();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Marketing Associate Wallets | Admin | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header">
    <a class="brand admin-brand" href="/admin/index.php"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <nav class="admin-user" aria-label="Admin navigation"><a href="/admin/index.php">Dashboard</a><a href="/admin/marketing-associates.php">Marketing Associates</a><a href="/">Website</a></nav>
  </header>
  <main class="admin-content">
    <div class="admin-title"><div><p class="section-kicker">Administration</p><h1>Marketing Associate Wallets</h1></div><a class="button button-dark" href="/admin/marketing-associate-withdrawals.php">Withdrawal Requests</a></div>
    <p class="admin-message" role="status" aria-live="polite"></p>
    <section class="admin-section">
      <h2>Search associates</h2>
      <form class="customer-search" method="get" action="/admin/marketing-associate-wallet.php">
        <label>Associate name or ID<input type="search" name="search" maxlength="100" value="<?= Security::escape($search) ?>" placeholder="Name or associate ID"></label>
        <button class="button button-dark" type="submit">Search</button>
        <a class="button button-outline-dark" href="/admin/marketing-associate-wallet.php">Clear</a>
      </form>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Photo</th><th>Associate</th><th>Associate ID</th><th>Status</th><th>Wallet balance</th><th>Profile</th><th>Wallet</th></tr></thead><tbody>
      <?php foreach ($associates as $associate): ?>
        <tr>
          <td><?php if ($associate['profile_photo_path'] !== null): ?><img class="associate-admin-photo" src="/api/marketing-associate/photo.php?associate_id=<?= (int) $associate['user_id'] ?>" alt="Profile photo"><?php else: ?><span class="associate-avatar associate-avatar-placeholder" aria-hidden="true"><?= Security::escape(strtoupper(substr($associate['full_name'], 0, 1))) ?></span><?php endif; ?></td>
          <td><?= Security::escape($associate['full_name']) ?></td>
          <td>#<?= sprintf('%03d', (int) $associate['associate_id']) ?></td>
          <td><?= Security::escape(ucfirst($associate['approval_status'])) ?></td>
          <td>₹<?= Security::escape(Money::format((string) ($associate['cached_balance'] ?? '0.00'))) ?></td>
          <td><a href="/admin/marketing-associate-profile.php?id=<?= (int) $associate['associate_id'] ?>">Open profile</a></td>
          <td><a href="/admin/marketing-associate-wallet.php?id=<?= (int) $associate['associate_id'] ?>">Manage wallet</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$associates): ?><tr><td colspan="7">No Marketing Associates match this search.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <?php if ($selected !== null): ?>
      <section class="admin-section">
        <div class="admin-title">
          <div><p class="section-kicker">Associate #<?= sprintf('%03d', (int) $selected['associate_id']) ?></p><h2><?= Security::escape($selected['full_name']) ?></h2></div>
          <a class="button button-outline-dark" href="/admin/marketing-associate-profile.php?id=<?= (int) $selected['associate_id'] ?>">View full profile</a>
        </div>
        <p>Wallet status: <?= Security::escape(ucfirst($selected['approval_status'])) ?> · Available balance: ₹<?= Security::escape(Money::format($availableBalance)) ?> · Total credited: ₹<?= Security::escape(Money::format($totalCredited)) ?> · Total withdrawn: ₹<?= Security::escape(Money::format($totalWithdrawn)) ?> · Pending reserve: ₹<?= Security::escape(Money::format($pendingAmount)) ?></p>
        <?php if ($selected['approval_status'] === 'approved'): ?>
          <form class="admin-form admin-grid" data-admin-form action="/api/admin/marketing-associate-wallet.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="associate_id" value="<?= (int) $selected['associate_id'] ?>">
            <input type="hidden" name="action" value="credit">
            <input type="hidden" name="idempotency_token" value="<?= Security::escape(bin2hex(random_bytes(16))) ?>">
            <h3 class="full">Add wallet credit</h3>
            <label>Credit amount (INR)<input name="amount" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" min="0.01" required></label>
            <label>Transaction description / remarks<input name="remarks" maxlength="500" required></label>
            <button class="button button-dark full" type="submit">Confirm Wallet Credit</button>
          </form>
        <?php else: ?>
          <p>Wallet credits are available after administrator approval.</p>
        <?php endif; ?>
        <h3>Wallet transaction history</h3>
        <?php if ((int) $selected['wallet_id'] > 0): ?><p><a class="button button-outline-dark" href="/admin/marketing-associate-wallet-statement.php?id=<?= (int) $selected['associate_id'] ?>">Download wallet statement (CSV)</a></p><?php endif; ?>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Transaction ID</th><th>Date and time (UTC)</th><th>Type</th><th>Description</th><th>Amount</th><th>Running balance</th></tr></thead><tbody>
        <?php foreach ($transactions as $transaction): ?>
          <tr><td>#<?= (int) $transaction['id'] ?></td><td><?= Security::escape($transaction['created_at']) ?></td><td><?= Security::escape(ucfirst($transaction['transaction_type'])) ?></td><td><?= Security::escape((string) $transaction['description']) ?></td><td>₹<?= Security::escape(Money::format((string) $transaction['amount'])) ?></td><td>₹<?= Security::escape(Money::format((string) $transaction['balance_after'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$transactions): ?><tr><td colspan="6">No wallet transactions.</td></tr><?php endif; ?>
        </tbody></table></div>

        <?php if ($reversibleCredits): ?>
          <h3>Reverse an erroneous manual credit</h3>
          <p>Reversals are separate ledger entries. A credit cannot be reversed if the funds are reserved or spent.</p>
          <?php foreach ($reversibleCredits as $credit): ?>
            <form class="admin-form wallet-reversal-form" data-admin-form action="/api/admin/marketing-associate-wallet.php" method="post">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="associate_id" value="<?= (int) $selected['associate_id'] ?>">
              <input type="hidden" name="action" value="reverse">
              <input type="hidden" name="wallet_transaction_id" value="<?= (int) $credit['id'] ?>">
              <span>Credit #<?= (int) $credit['id'] ?> · ₹<?= Security::escape(Money::format((string) $credit['amount'])) ?> · <?= Security::escape($credit['created_at']) ?></span>
              <label>Reversal reason<input name="remarks" maxlength="500" required></label>
              <button class="button button-danger" type="submit">Reverse Credit</button>
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
        <h3>Wallet administration audit log</h3>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date and time (UTC)</th><th>Action</th><th>Amount</th><th>Administrator</th><th>Remarks</th></tr></thead><tbody>
        <?php foreach ($walletAdminLogs as $log): ?><tr><td><?= Security::escape($log['created_at']) ?></td><td><?= Security::escape($log['action']) ?></td><td>₹<?= Security::escape(Money::format((string) $log['amount'])) ?></td><td><?= Security::escape((string) ($log['actor_name'] ?? 'System')) ?></td><td><?= Security::escape($log['remarks']) ?></td></tr><?php endforeach; ?>
        <?php if (!$walletAdminLogs): ?><tr><td colspan="5">No wallet administration actions.</td></tr><?php endif; ?>
        </tbody></table></div>
      </section>
    <?php elseif ($selectedId !== false && $selectedId !== null): ?>
      <section class="admin-section"><p class="empty-state">Marketing Associate not found.</p></section>
    <?php endif; ?>
  </main>
  <script src="/assets/js/admin.js" defer></script>
</body>
</html>
