<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
$associate = Auth::requireMarketingAssociatePage();
$pdo = Database::connection();
$userId = (int) $associate['id'];
$csrfToken = Security::csrfToken();
$projects = $pdo->query("SELECT id, name FROM projects WHERE status = 'published' ORDER BY name")->fetchAll();
$availablePlots = $pdo->query(
    "SELECT pl.id, pl.project_id, pl.plot_number, pl.area_sqyd,
            COALESCE(pl.total_price, ROUND(pl.area_sqyd * p.price_per_sqyd, 2)) AS total_price
     FROM plots pl JOIN projects p ON p.id = pl.project_id
     WHERE pl.status = 'available' AND p.status = 'published'
     ORDER BY p.name, pl.plot_number"
)->fetchAll();

$statement = $pdo->prepare(
    'SELECT ma.id AS associate_id, ma.aadhaar_last4, ma.pan_last4, ma.account_number_last4,
            ma.designation_code,
            ma.ifsc_encrypted, ma.branch_location_encrypted, ma.approval_status, ma.approved_at, ma.created_at,
            u.phone,
            profile.profile_photo_path, profile.photo_position_x, profile.photo_position_y,
            manager.full_name AS manager_name, manager_ma.designation_code AS manager_designation
     FROM marketing_associates ma
     JOIN users u ON u.id = ma.user_id
     LEFT JOIN marketing_associate_profiles profile ON profile.user_id = ma.user_id
     LEFT JOIN users manager ON manager.id = profile.reporting_manager_id
     LEFT JOIN marketing_associates manager_ma ON manager_ma.user_id = manager.id
     WHERE ma.user_id = :user_id'
);
$statement->execute(['user_id' => $userId]);
$profile = $statement->fetch();
if ($profile === false) {
    http_response_code(404);
    exit('Marketing Associate profile not found.');
}
$profile['ifsc_code'] = is_string($profile['ifsc_encrypted'])
    ? MarketingAssociateDataProtection::decrypt('ifsc_code', $profile['ifsc_encrypted'])
    : null;
$profile['branch_location'] = is_string($profile['branch_location_encrypted'])
    ? MarketingAssociateDataProtection::decrypt('branch_location', $profile['branch_location_encrypted'])
    : null;
$visibleIds = Auth::visibleUserIds($userId);
$teamMemberIds = array_values(array_filter($visibleIds, static fn(int $id): bool => $id !== $userId));
$teamMembers = [];
if ($teamMemberIds) {
    $teamPlaceholders = implode(',', array_fill(0, count($teamMemberIds), '?'));
    $teamStatement = $pdo->prepare(
        "SELECT ma.id AS associate_id, u.id AS user_id, u.full_name, ma.designation_code,
                manager.full_name AS manager_name,
                (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id) AS leads_count,
                (SELECT COUNT(*) FROM site_visits v WHERE v.assigned_to = u.id) AS visits_count,
                (SELECT COUNT(*) FROM bookings b WHERE b.agent_id = u.id) AS bookings_count,
                (SELECT COALESCE(SUM(c.amount), 0) FROM commissions c
                 WHERE c.user_id = u.id AND c.status IN ('approved','paid')) AS commission_total
         FROM users u
         JOIN marketing_associates ma ON ma.user_id = u.id
         LEFT JOIN marketing_associate_profiles profile ON profile.user_id = u.id
         LEFT JOIN users manager ON manager.id = profile.reporting_manager_id
         WHERE u.id IN ($teamPlaceholders)
           AND u.account_status = 'active' AND ma.approval_status = 'approved'
         ORDER BY FIELD(ma.designation_code, 'deputy_director', 'deputy_general_manager',
                        'assistant_general_manager', 'deputy_manager', 'marketing_manager'), u.full_name"
    );
    $teamStatement->execute($teamMemberIds);
    $teamMembers = $teamStatement->fetchAll();
}
$targetDesignation = MarketingAssociateHierarchy::targetDesignation($profile['designation_code']);

$statement = $pdo->prepare(
    "SELECT
       (SELECT COUNT(*) FROM leads WHERE assigned_to = :leads_user) AS total_leads,
       (SELECT COUNT(*) FROM site_visits WHERE assigned_to = :visits_user) AS total_visits,
       (SELECT COUNT(*) FROM site_visits WHERE assigned_to = :completed_user AND status = 'completed') AS completed_visits,
       (SELECT COUNT(*) FROM bookings WHERE agent_id = :bookings_user) AS total_bookings,
       (SELECT COALESCE(SUM(amount), 0) FROM commissions
        WHERE user_id = :commission_user AND status IN ('approved','paid')) AS commission_earned"
);
$statement->execute([
    'leads_user' => $userId,
    'visits_user' => $userId,
    'completed_user' => $userId,
    'bookings_user' => $userId,
    'commission_user' => $userId,
]);
$metrics = $statement->fetch();

$statement = $pdo->prepare('SELECT id, cached_balance FROM wallets WHERE user_id = :user_id');
$statement->execute(['user_id' => $userId]);
$wallet = $statement->fetch();
$walletId = $wallet === false ? null : (int) $wallet['id'];
$balance = $wallet === false ? '0.00' : (string) $wallet['cached_balance'];

$statement = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests
     WHERE user_id = :user_id AND status IN ('requested','approved','processing')"
);
$statement->execute(['user_id' => $userId]);
$pendingWithdrawal = (string) $statement->fetchColumn();
$availableBalance = Money::fromMinorUnits(
    Money::toMinorUnits($balance) - Money::toMinorUnits($pendingWithdrawal)
);

$credited = '0.00';
$withdrawn = '0.00';
$transactions = [];
if ($walletId !== null) {
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
         WHERE wallet_id = :wallet_id AND transaction_type = 'credit'"
    );
    $statement->execute(['wallet_id' => $walletId]);
    $credited = (string) $statement->fetchColumn();
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
         WHERE wallet_id = :wallet_id AND transaction_type = 'debit' AND reference_type = 'withdrawal'"
    );
    $statement->execute(['wallet_id' => $walletId]);
    $withdrawn = (string) $statement->fetchColumn();
    $statement = $pdo->prepare(
        'SELECT wt.id, wt.transaction_type, wt.amount, wt.balance_after, wt.reference_type,
                wt.description, wt.created_at, wr.status AS withdrawal_status, wr.request_reference
         FROM wallet_transactions wt
         LEFT JOIN withdrawal_requests wr
           ON wr.id = wt.reference_id
          AND wt.reference_type IN (\'withdrawal\', \'withdrawal_reservation\', \'withdrawal_release\')
         WHERE wt.wallet_id = :wallet_id
         ORDER BY wt.id DESC LIMIT 100'
    );
    $statement->execute(['wallet_id' => $walletId]);
    $transactions = $statement->fetchAll();
}

$statement = $pdo->prepare(
    "SELECT DATE_FORMAT(performance.period, '%Y-%m') AS report_month,
            SUM(performance.leads_count) AS leads_count,
            SUM(performance.visits_count) AS visits_count,
            SUM(performance.bookings_count) AS bookings_count,
            SUM(performance.commission_amount) AS commission_amount
     FROM (
       SELECT DATE_FORMAT(l.created_at, '%Y-%m-01') AS period, COUNT(*) AS leads_count, 0 AS visits_count,
              0 AS bookings_count, 0 AS commission_amount
       FROM leads l WHERE l.assigned_to = :leads_user AND l.created_at >= UTC_DATE() - INTERVAL 6 MONTH
       GROUP BY DATE_FORMAT(l.created_at, '%Y-%m-01')
       UNION ALL
       SELECT DATE_FORMAT(v.created_at, '%Y-%m-01'), 0, COUNT(*), 0, 0
       FROM site_visits v WHERE v.assigned_to = :visits_user AND v.created_at >= UTC_DATE() - INTERVAL 6 MONTH
       GROUP BY DATE_FORMAT(v.created_at, '%Y-%m-01')
       UNION ALL
       SELECT DATE_FORMAT(b.created_at, '%Y-%m-01'), 0, 0, COUNT(*), 0
       FROM bookings b WHERE b.agent_id = :bookings_user AND b.created_at >= UTC_DATE() - INTERVAL 6 MONTH
       GROUP BY DATE_FORMAT(b.created_at, '%Y-%m-01')
       UNION ALL
       SELECT DATE_FORMAT(c.created_at, '%Y-%m-01'), 0, 0, 0, SUM(c.amount)
       FROM commissions c WHERE c.user_id = :commission_user AND c.status IN ('approved','paid')
         AND c.created_at >= UTC_DATE() - INTERVAL 6 MONTH
       GROUP BY DATE_FORMAT(c.created_at, '%Y-%m-01')
     ) performance
     GROUP BY DATE_FORMAT(performance.period, '%Y-%m')
     ORDER BY report_month DESC"
);
$statement->execute([
    'leads_user' => $userId,
    'visits_user' => $userId,
    'bookings_user' => $userId,
    'commission_user' => $userId,
]);
$monthlyReport = $statement->fetchAll();

$statement = $pdo->prepare(
    'SELECT b.booking_reference, b.status, b.total_price, b.created_at, pl.plot_number, p.name AS project_name
     FROM bookings b JOIN plots pl ON pl.id = b.plot_id JOIN projects p ON p.id = pl.project_id
     WHERE b.agent_id = :user_id ORDER BY b.id DESC LIMIT 50'
);
$statement->execute(['user_id' => $userId]);
$bookings = $statement->fetchAll();

$statement = $pdo->prepare(
    'SELECT v.preferred_start, v.status, v.preferred_food, v.vehicle_model, v.vehicle_number,
            v.driver_name, v.driver_phone, v.pickup_location,
            c.full_name AS customer_name, p.name AS project_name
     FROM site_visits v JOIN customers c ON c.id = v.customer_id JOIN projects p ON p.id = v.project_id
     WHERE v.assigned_to = :user_id ORDER BY v.preferred_start DESC LIMIT 50'
);
$statement->execute(['user_id' => $userId]);
$visits = $statement->fetchAll();
foreach ($visits as &$visit) {
    $visit['preferred_start_local'] = (new DateTimeImmutable($visit['preferred_start'], new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($app['timezone']))->format('d M Y, H:i');
}
unset($visit);

$statement = $pdo->prepare(
    'SELECT title, message, created_at FROM notifications
     WHERE user_id = :user_id ORDER BY id DESC LIMIT 10'
);
$statement->execute(['user_id' => $userId]);
$notifications = $statement->fetchAll();
$photoUrl = '/api/marketing-associate/photo.php?associate_id=' . $userId;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Marketing Associate Dashboard | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page associate-dashboard">
  <header class="admin-header">
    <a class="brand admin-brand" href="/"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <div class="admin-user">
      <?php if ($profile['profile_photo_path'] !== null): ?><img class="associate-avatar" src="<?= Security::escape($photoUrl) ?>" alt="Your profile photo" style="object-position: <?= (int) $profile['photo_position_x'] ?>% <?= (int) $profile['photo_position_y'] ?>%"><?php else: ?><span class="associate-avatar associate-avatar-placeholder" aria-hidden="true"><?= Security::escape(strtoupper(substr($associate['full_name'], 0, 1))) ?></span><?php endif; ?>
      <span><?= Security::escape($associate['full_name']) ?> · Associate #<?= sprintf('%03d', (int) $profile['associate_id']) ?></span>
      <form action="/marketing-associate/logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>"><button type="submit">Sign out</button></form>
    </div>
  </header>
  <main class="admin-content associate-workspace">
    <aside class="associate-sidebar" aria-label="Associate dashboard navigation">
      <a href="#profile">My Profile</a>
      <a href="#team">My Team</a>
      <a href="#wallet">My Wallet</a>
      <a href="#performance">My Performance</a>
      <a href="#requests">Customer Requests</a>
    </aside>
    <div class="associate-main">
    <div class="associate-dashboard-heading">
      <div>
        <?php if ($profile['profile_photo_path'] !== null): ?><img class="associate-dashboard-photo" src="<?= Security::escape($photoUrl) ?>" alt="Profile photo for <?= Security::escape($associate['full_name']) ?>" style="object-position: <?= (int) $profile['photo_position_x'] ?>% <?= (int) $profile['photo_position_y'] ?>%"><?php else: ?><span class="associate-dashboard-photo associate-avatar-placeholder" aria-hidden="true"><?= Security::escape(strtoupper(substr($associate['full_name'], 0, 1))) ?></span><?php endif; ?>
      </div>
      <div>
        <p class="section-kicker">Marketing Associate Portal</p>
        <h1>Welcome, <?= Security::escape($associate['full_name']) ?></h1>
        <p>Associate #<?= sprintf('%03d', (int) $profile['associate_id']) ?> · <?= Security::escape($profile['phone']) ?></p>
        <p>Designation: <?= Security::escape(MarketingAssociateHierarchy::DESIGNATIONS[$profile['designation_code']]) ?></p>
        <p><span class="associate-status associate-status-approved">Approved</span> · Registered <?= Security::escape($profile['created_at']) ?> UTC</p>
        <p>Reporting manager: <?= Security::escape((string) ($profile['manager_name'] ?? 'Not assigned')) ?><?= $profile['manager_designation'] ? ' · ' . Security::escape(MarketingAssociateHierarchy::DESIGNATIONS[$profile['manager_designation']]) : '' ?></p>
      </div>
    </div>
    <p class="associate-portal-note">All team members use the same Marketing Associate portal. Your designation controls your reporting and team permissions.</p>
    <p class="admin-message" role="status" aria-live="polite"></p>

    <section class="admin-stat-grid associate-stat-grid" aria-label="Performance summary">
      <div><strong><?= (int) $metrics['total_leads'] ?></strong><span>Total Leads</span></div>
      <div><strong><?= (int) $metrics['total_visits'] ?></strong><span>Total Site Visits</span></div>
      <div><strong><?= (int) $metrics['total_bookings'] ?></strong><span>Total Bookings</span></div>
      <div><strong>₹<?= Security::escape(Money::format((string) $metrics['commission_earned'])) ?></strong><span>Total Commission Earned</span></div>
      <div><strong>₹<?= Security::escape(Money::format($availableBalance)) ?></strong><span>Available Wallet Balance</span></div>
      <div><strong>₹<?= Security::escape(Money::format($withdrawn)) ?></strong><span>Total Withdrawal Amount</span></div>
    </section>

    <section class="admin-section" id="team">
      <h2>My Team</h2>
      <?php if ($targetDesignation !== null): ?>
        <p>You may invite candidates for the next designation, <?= Security::escape(MarketingAssociateHierarchy::DESIGNATIONS[$targetDesignation]) ?>. Admin assigns the final designation and approves or rejects every registration.</p>
      <?php else: ?>
        <p>You may invite Marketing Associate candidates. Admin assigns a designation and approves or rejects each registration.</p>
      <?php endif; ?>
      <form class="admin-form" data-admin-form data-admin-stay action="/api/agent/recruitments.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <button class="button button-dark" type="submit">Create a Marketing Associate invitation</button>
      </form>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Associate ID</th><th>Team member</th><th>Designation</th><th>Reporting manager</th><th>Leads</th><th>Site visits</th><th>Bookings</th><th>Commission earned</th></tr></thead><tbody>
      <?php foreach ($teamMembers as $member): ?>
        <tr>
          <td>#<?= sprintf('%03d', (int) $member['associate_id']) ?></td>
          <td><?= Security::escape($member['full_name']) ?></td>
          <td><?= Security::escape(MarketingAssociateHierarchy::DESIGNATIONS[$member['designation_code']]) ?></td>
          <td><?= Security::escape((string) ($member['manager_name'] ?? 'Not assigned')) ?></td>
          <td><?= (int) $member['leads_count'] ?></td>
          <td><?= (int) $member['visits_count'] ?></td>
          <td><?= (int) $member['bookings_count'] ?></td>
          <td>₹<?= Security::escape(Money::format((string) $member['commission_total'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$teamMembers): ?><tr><td colspan="8">No approved team members are currently assigned below you.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="admin-section" id="profile">
      <h2>My Profile</h2>
      <form class="admin-form admin-grid associate-profile-form" data-admin-form action="/api/marketing-associate/profile.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <label>Full name<input name="full_name" value="<?= Security::escape($associate['full_name']) ?>" maxlength="150" required></label>
        <label>Update profile photo (JPG, PNG or WebP; up to 5 MB)
          <input name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" data-photo-input>
          <?php if ($profile['profile_photo_path'] !== null): ?><img class="associate-photo-preview" data-photo-preview data-photo-position src="<?= Security::escape($photoUrl) ?>" alt="Current profile photo" style="object-position: <?= (int) $profile['photo_position_x'] ?>% <?= (int) $profile['photo_position_y'] ?>%"><?php else: ?><img class="associate-photo-preview" data-photo-preview data-photo-position alt="Selected profile photo preview" hidden><?php endif; ?>
        </label>
        <label>Photo horizontal position: <span data-position-value="x"><?= (int) $profile['photo_position_x'] ?>%</span>
          <input type="range" name="photo_position_x" min="0" max="100" value="<?= (int) $profile['photo_position_x'] ?>" data-position-control="x">
        </label>
        <label>Photo vertical position: <span data-position-value="y"><?= (int) $profile['photo_position_y'] ?>%</span>
          <input type="range" name="photo_position_y" min="0" max="100" value="<?= (int) $profile['photo_position_y'] ?>" data-position-control="y">
        </label>
        <label>Contact number<input value="<?= Security::escape($profile['phone']) ?>" readonly></label>
        <label>Designation<input value="<?= Security::escape(MarketingAssociateHierarchy::DESIGNATIONS[$profile['designation_code']]) ?>" readonly></label>
        <label>Aadhaar number<input value="<?= $profile['aadhaar_last4'] === null ? 'Not provided' : 'XXXX XXXX ' . Security::escape($profile['aadhaar_last4']) ?>" readonly></label>
        <label>PAN<input value="<?= $profile['pan_last4'] === null ? 'Not provided' : '******' . Security::escape($profile['pan_last4']) ?>" readonly></label>
        <label>Bank account<input value="<?= $profile['account_number_last4'] === null ? 'Not provided' : '•••• ' . Security::escape($profile['account_number_last4']) ?>" readonly></label>
        <label>IFSC code<input value="<?= Security::escape($profile['ifsc_code'] ?? 'Not provided') ?>" readonly></label>
        <label>Branch location<input value="<?= Security::escape($profile['branch_location'] ?? 'Not provided') ?>" readonly></label>
        <label>Associate ID<input value="#<?= sprintf('%03d', (int) $profile['associate_id']) ?>" readonly></label>
        <label>Reporting manager<input value="<?= Security::escape((string) ($profile['manager_name'] ?? 'Not assigned')) ?><?= $profile['manager_designation'] ? ' · ' . Security::escape(MarketingAssociateHierarchy::DESIGNATIONS[$profile['manager_designation']]) : '' ?>" readonly></label>
        <label>Registration date<input value="<?= Security::escape($profile['created_at']) ?> UTC" readonly></label>
        <label>Approval status<input value="Approved" readonly></label>
        <p class="full">Only your name and profile photo can be changed here. Identity and bank detail corrections require administrator verification.</p>
        <button class="button button-dark full" type="submit">Save Profile</button>
      </form>
    </section>

    <section class="admin-section" id="requests">
      <h2>Customer booking and site visit requests</h2>
      <p>Booking requests reserve an available plot for 10 hours and remain subject to administrator confirmation.</p>
      <h3>Submit a plot booking request</h3>
      <?php if ($projects && $availablePlots): ?>
      <form class="admin-form admin-grid" data-admin-form action="/api/marketing-associate/requests.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="booking_request">
        <label>Customer name<input name="customer_name" required maxlength="150"></label>
        <label>Contact number<input name="customer_phone" required maxlength="25"></label>
        <label>Email (optional)<input name="customer_email" type="email" maxlength="190"></label>
        <label>Project<select name="project_id" data-request-project required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
        <label>Available plot<select name="plot_id" data-request-plot required><?php foreach ($availablePlots as $plot): ?><option value="<?= (int) $plot['id'] ?>" data-project-id="<?= (int) $plot['project_id'] ?>">Plot <?= Security::escape($plot['plot_number']) ?> · <?= $plot['area_sqyd'] === null ? 'Area not specified' : Security::escape((string) $plot['area_sqyd']) . ' sq. yds.' ?> · <?= $plot['total_price'] === null ? 'Price unavailable' : '₹' . Security::escape(Money::format((string) $plot['total_price'])) ?></option><?php endforeach; ?></select></label>
        <label class="full">Customer address<input name="address" maxlength="500"></label>
        <label class="full">Request notes<textarea name="message" rows="2" maxlength="2000"></textarea></label>
        <button class="button button-dark full" type="submit">Submit booking request</button>
      </form>
      <?php else: ?><p class="empty-state">No published projects with available plots can currently accept booking requests.</p><?php endif; ?>

      <h3>Request a customer site visit</h3>
      <?php if ($projects): ?>
      <form class="admin-form admin-grid" data-admin-form action="/api/marketing-associate/requests.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="site_visit_request">
        <label>Customer name<input name="customer_name" required maxlength="150"></label>
        <label>Contact number<input name="customer_phone" required maxlength="25"></label>
        <label>Email (optional)<input name="customer_email" type="email" maxlength="190"></label>
        <label>Project<select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
        <label>Preferred date and time<input name="preferred_start" type="datetime-local" required></label>
        <label>Visitors<input name="visitor_count" type="number" min="1" max="50" value="1" required></label>
        <label>Food preference<select name="preferred_food"><option value="unspecified">Unspecified</option><option value="veg">Vegetarian</option><option value="non_veg">Non-vegetarian</option></select></label>
        <label class="full">Customer address<input name="address" required maxlength="500"></label>
        <label class="full">Request notes<textarea name="message" rows="2" maxlength="2000"></textarea></label>
        <button class="button button-dark full" type="submit">Submit site visit request</button>
      </form>
      <?php else: ?><p class="empty-state">No published projects are available for site visit requests.</p><?php endif; ?>
    </section>

    <section class="admin-section" id="wallet">
      <h2>My Wallet</h2>
      <div class="admin-stat-grid wallet-summary-grid">
        <div><strong>₹<?= Security::escape(Money::format($credited)) ?></strong><span>Total money credited</span></div>
        <div><strong>₹<?= Security::escape(Money::format($withdrawn)) ?></strong><span>Total money withdrawn</span></div>
        <div><strong>₹<?= Security::escape(Money::format($pendingWithdrawal)) ?></strong><span>Pending withdrawal reserve</span></div>
        <div><strong>₹<?= Security::escape(Money::format($availableBalance)) ?></strong><span>Available balance</span></div>
      </div>
      <?php if ($profile['account_number_last4'] !== null && $profile['ifsc_code'] !== null): ?>
        <p>Verified payout account: •••• <?= Security::escape($profile['account_number_last4']) ?> · IFSC <?= Security::escape($profile['ifsc_code']) ?> · <?= Security::escape($profile['branch_location'] ?? '') ?></p>
      <?php else: ?>
        <p>Your payout bank details are not on file. Contact Admin to securely add or verify them before requesting a withdrawal.</p>
      <?php endif; ?>
      <h3>Request a withdrawal</h3>
      <?php if ($profile['account_number_last4'] !== null && $profile['ifsc_code'] !== null): ?>
      <form class="admin-form admin-grid" data-admin-form action="/api/marketing-associate/wallet.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="request_withdrawal">
        <label>Available balance<input value="₹<?= Security::escape(Money::format($availableBalance)) ?>" readonly></label>
        <label>Withdrawal amount (INR)<input name="amount" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" max="<?= Security::escape($availableBalance) ?>" required></label>
        <label class="full">Withdrawal remarks (optional)<textarea name="remarks" maxlength="500" rows="2"></textarea></label>
        <button class="button button-dark full" type="submit" <?= Money::toMinorUnits($availableBalance) < 1 ? 'disabled' : '' ?>>Submit Withdrawal Request</button>
      </form>
      <?php else: ?>
        <p class="empty-state">Withdrawal requests are unavailable until Admin verifies your payout account.</p>
      <?php endif; ?>
      <h3>Wallet transaction history</h3>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Transaction ID</th><th>Date and time (UTC)</th><th>Type</th><th>Description</th><th>Credit</th><th>Debit</th><th>Status</th><th>Running balance</th></tr></thead><tbody>
      <?php foreach ($transactions as $transaction):
          $type = (string) $transaction['transaction_type'];
          $isDebit = $type === 'debit' || ($type === 'adjustment' && $transaction['reference_type'] === 'wallet_reversal');
          $status = $transaction['withdrawal_status'] !== null
              ? ucfirst($transaction['withdrawal_status'] === 'requested' ? 'pending' : $transaction['withdrawal_status'])
              : 'Posted';
      ?>
        <tr>
          <td>#<?= (int) $transaction['id'] ?></td>
          <td><?= Security::escape($transaction['created_at']) ?></td>
          <td><?= Security::escape(ucfirst($type)) ?></td>
          <td><?= Security::escape((string) $transaction['description']) ?><?= $transaction['request_reference'] ? ' · ' . Security::escape($transaction['request_reference']) : '' ?></td>
          <td><?= $type === 'credit' ? '₹' . Security::escape(Money::format((string) $transaction['amount'])) : '—' ?></td>
          <td><?= $isDebit ? '₹' . Security::escape(Money::format((string) $transaction['amount'])) : '—' ?></td>
          <td><?= Security::escape($status) ?></td>
          <td>₹<?= Security::escape(Money::format((string) $transaction['balance_after'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$transactions): ?><tr><td colspan="8">No wallet transactions yet.</td></tr><?php endif; ?>
      </tbody></table></div>
      <h3>Withdrawal history</h3>
      <?php
      $statement = $pdo->prepare(
          'SELECT id, request_reference, amount, status, requested_at, payment_reference, admin_remarks
           FROM withdrawal_requests WHERE user_id = :user_id ORDER BY id DESC LIMIT 50'
      );
      $statement->execute(['user_id' => $userId]);
      $withdrawals = $statement->fetchAll();
      ?>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Request ID</th><th>Requested (UTC)</th><th>Amount</th><th>Status</th><th>Payment reference / remarks</th></tr></thead><tbody>
      <?php foreach ($withdrawals as $withdrawal): ?>
        <tr><td><?= Security::escape((string) $withdrawal['request_reference']) ?></td><td><?= Security::escape($withdrawal['requested_at']) ?></td><td>₹<?= Security::escape(Money::format((string) $withdrawal['amount'])) ?></td><td><?= Security::escape(ucfirst($withdrawal['status'] === 'requested' ? 'pending' : $withdrawal['status'])) ?></td><td><?= Security::escape((string) ($withdrawal['payment_reference'] ?: $withdrawal['admin_remarks'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$withdrawals): ?><tr><td colspan="5">No withdrawal requests yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="admin-section" id="performance">
      <h2>My Performance</h2>
      <p>Completed site visits: <?= (int) $metrics['completed_visits'] ?> · Commission earned: ₹<?= Security::escape(Money::format((string) $metrics['commission_earned'])) ?></p>
      <h3>Monthly performance report (last six months)</h3>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Month</th><th>Leads</th><th>Site visits</th><th>Bookings</th><th>Commission earned</th></tr></thead><tbody>
      <?php foreach ($monthlyReport as $month): ?><tr><td><?= Security::escape($month['report_month']) ?></td><td><?= (int) $month['leads_count'] ?></td><td><?= (int) $month['visits_count'] ?></td><td><?= (int) $month['bookings_count'] ?></td><td>₹<?= Security::escape(Money::format((string) $month['commission_amount'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$monthlyReport): ?><tr><td colspan="5">No performance activity recorded yet.</td></tr><?php endif; ?>
      </tbody></table></div>
      <h3>Booking history</h3>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Booking</th><th>Project</th><th>Plot</th><th>Booked on (UTC)</th><th>Amount</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($bookings as $booking): ?><tr><td><?= Security::escape($booking['booking_reference']) ?></td><td><?= Security::escape($booking['project_name']) ?></td><td><?= Security::escape($booking['plot_number']) ?></td><td><?= Security::escape($booking['created_at']) ?></td><td>₹<?= Security::escape(Money::format((string) $booking['total_price'])) ?></td><td><?= Security::escape($booking['status']) ?></td></tr><?php endforeach; ?>
      <?php if (!$bookings): ?><tr><td colspan="6">No bookings yet.</td></tr><?php endif; ?>
      </tbody></table></div>
      <h3>Site visit history</h3>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Customer</th><th>Project / time</th><th>Status / food</th><th>Visit transport</th></tr></thead><tbody>
      <?php foreach ($visits as $visit): ?><tr><td><?= Security::escape($visit['customer_name']) ?></td><td><?= Security::escape($visit['project_name']) ?><br><?= Security::escape($visit['preferred_start_local']) ?></td><td><?= Security::escape($visit['status']) ?><br><?= Security::escape($visit['preferred_food']) ?></td><td><?= Security::escape(trim((string) $visit['vehicle_model'] . ' ' . (string) $visit['vehicle_number'])) ?><br><?= Security::escape((string) $visit['driver_name']) ?> <?= Security::escape((string) $visit['driver_phone']) ?><br><?= Security::escape((string) $visit['pickup_location']) ?></td></tr><?php endforeach; ?>
      <?php if (!$visits): ?><tr><td colspan="4">No site visits yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="admin-section">
      <h2>Notifications</h2>
      <div class="admin-record-list">
        <?php foreach ($notifications as $notification): ?><article class="admin-record notification-row"><strong><?= Security::escape($notification['title']) ?></strong><p><?= Security::escape($notification['message']) ?></p><small><?= Security::escape($notification['created_at']) ?> UTC</small></article><?php endforeach; ?>
        <?php if (!$notifications): ?><p class="empty-state">No notifications.</p><?php endif; ?>
      </div>
    </section>
    </div>
  </main>
  <script src="/assets/js/marketing-associate-profile.js" defer></script>
  <script src="/assets/js/marketing-associate-requests.js" defer></script>
  <script src="/assets/js/admin.js" defer></script>
</body>
</html>
