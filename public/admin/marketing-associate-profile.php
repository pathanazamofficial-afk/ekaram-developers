<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
$admin = Auth::requireAdminPage();
$pdo = Database::connection();
$csrfToken = Security::csrfToken();
$associateId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($associateId === false) {
    http_response_code(400);
    exit('A Marketing Associate ID is required.');
}

$statement = $pdo->prepare(
    'SELECT ma.id AS associate_id, ma.user_id, ma.aadhaar_last4, ma.pan_last4, ma.account_number_last4,
            ma.ifsc_encrypted, ma.branch_location_encrypted, ma.designation_code,
            ma.approval_status, ma.approved_at, ma.approved_by, ma.rejected_by, ma.rejected_at,
            ma.rejection_reason, ma.admin_remarks, ma.created_at,
            u.full_name, u.phone, u.account_status, profile.profile_photo_path,
            profile.reporting_manager_id, manager.full_name AS manager_name,
            manager_ma.designation_code AS manager_designation,
            approver.full_name AS approver_name, rejector.full_name AS rejector_name
     FROM marketing_associates ma
     JOIN users u ON u.id = ma.user_id
     LEFT JOIN marketing_associate_profiles profile ON profile.user_id = ma.user_id
     LEFT JOIN users manager ON manager.id = profile.reporting_manager_id
     LEFT JOIN marketing_associates manager_ma ON manager_ma.user_id = manager.id
     LEFT JOIN users approver ON approver.id = ma.approved_by
     LEFT JOIN users rejector ON rejector.id = ma.rejected_by
     WHERE ma.id = :id'
);
$statement->execute(['id' => $associateId]);
$associate = $statement->fetch();
if ($associate === false) {
    http_response_code(404);
    exit('Marketing Associate not found.');
}
$userId = (int) $associate['user_id'];
$assignmentValid = is_string($associate['designation_code'])
    && array_key_exists($associate['designation_code'], MarketingAssociateHierarchy::DESIGNATIONS);
$activeVersion = $pdo->query(
    'SELECT version_no FROM commission_rule_versions
     WHERE effective_from <= UTC_TIMESTAMP() ORDER BY version_no DESC LIMIT 1'
)->fetchColumn();
$designationRates = [];
if ($activeVersion !== false) {
    $rateStatement = $pdo->prepare(
        'SELECT recipient_code, percentage FROM commission_rule_versions WHERE version_no = :version'
    );
    $rateStatement->execute(['version' => (int) $activeVersion]);
    foreach ($rateStatement->fetchAll() as $rate) {
        $designationRates[$rate['recipient_code']] = (string) $rate['percentage'];
    }
}
$statement = $pdo->prepare(
    "SELECT
       (SELECT COUNT(*) FROM leads WHERE assigned_to = :leads_user) AS leads_count,
       (SELECT COUNT(*) FROM site_visits WHERE assigned_to = :visits_user) AS visits_count,
       (SELECT COUNT(*) FROM bookings WHERE agent_id = :bookings_user) AS bookings_count,
       (SELECT COALESCE(SUM(amount),0) FROM commissions
        WHERE user_id = :commission_user AND status IN ('approved','paid')) AS commission_total"
);
$statement->execute([
    'leads_user' => $userId,
    'visits_user' => $userId,
    'bookings_user' => $userId,
    'commission_user' => $userId,
]);
$metrics = $statement->fetch();
$statement = $pdo->prepare('SELECT id, cached_balance FROM wallets WHERE user_id = :user_id');
$statement->execute(['user_id' => $userId]);
$wallet = $statement->fetch();
$walletId = $wallet === false ? null : (int) $wallet['id'];
$walletBalance = $wallet === false ? '0.00' : (string) $wallet['cached_balance'];
$walletAudit = [];
if ($walletId !== null) {
    $statement = $pdo->prepare(
        'SELECT log.action, log.amount, log.remarks, log.created_at, actor.full_name AS actor_name
         FROM wallet_admin_audit_logs log LEFT JOIN users actor ON actor.id = log.actor_user_id
         WHERE log.associate_user_id = :user_id ORDER BY log.id DESC LIMIT 100'
    );
    $statement->execute(['user_id' => $userId]);
    $walletAudit = $statement->fetchAll();
}
$statement = $pdo->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM withdrawal_requests
     WHERE user_id = :user_id AND status IN ('requested','approved','processing')"
);
$statement->execute(['user_id' => $userId]);
$reserved = (string) $statement->fetchColumn();
$available = Money::fromMinorUnits(
    max(0, Money::toMinorUnits($walletBalance) - Money::toMinorUnits($reserved))
);
$statement = $pdo->prepare(
    'SELECT id, request_reference, amount, status, requested_at, payment_reference, admin_remarks
     FROM withdrawal_requests WHERE user_id = :user_id ORDER BY id DESC LIMIT 50'
);
$statement->execute(['user_id' => $userId]);
$withdrawals = $statement->fetchAll();
$statement = $pdo->prepare(
    'SELECT log.action, log.created_at, actor.full_name AS actor_name
     FROM audit_logs log LEFT JOIN users actor ON actor.id = log.actor_user_id
     WHERE (log.entity_type = \'marketing_associate\' AND log.entity_id = :associate_id)
        OR (log.entity_type = \'user\' AND log.entity_id = :user_id)
     ORDER BY log.id DESC LIMIT 100'
);
$statement->execute(['associate_id' => (int) $associateId, 'user_id' => $userId]);
$auditLogs = $statement->fetchAll();
$statement = $pdo->prepare(
    "SELECT recruiter.full_name AS recruiter_name, history.new_role_code AS suggested_designation
     FROM recruitment_history history
     LEFT JOIN users recruiter ON recruiter.id = history.recruited_by
     WHERE history.member_user_id = :user_id AND history.action = 'invited_registration'
     ORDER BY history.id DESC LIMIT 1"
);
$statement->execute(['user_id' => (int) $associate['user_id']]);
$recruitment = $statement->fetch();
$suggestedDesignation = $recruitment !== false
    && is_string($recruitment['suggested_designation'])
    && array_key_exists($recruitment['suggested_designation'], MarketingAssociateHierarchy::DESIGNATIONS)
    ? $recruitment['suggested_designation']
    : null;
$assignmentDesignations = array_reverse(MarketingAssociateHierarchy::DESIGNATIONS, true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Marketing Associate Profile | Admin | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header">
    <a class="brand admin-brand" href="/admin/index.php"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <nav class="admin-user" aria-label="Admin navigation"><a href="/admin/index.php">Dashboard</a><a href="/admin/marketing-associates.php">Marketing Associates</a><a href="/admin/marketing-associate-wallet.php?id=<?= (int) $associateId ?>">Wallet</a></nav>
  </header>
  <main class="admin-content">
    <div class="admin-title">
      <div><p class="section-kicker">Associate #<?= sprintf('%03d', (int) $associate['associate_id']) ?></p><h1><?= Security::escape($associate['full_name']) ?></h1></div>
      <a class="button button-outline-dark" href="/admin/marketing-associates.php">Back to registrations</a>
    </div>
    <p class="admin-message" role="status" aria-live="polite"></p>
    <section class="admin-section">
      <div class="associate-dashboard-heading">
        <div>
          <?php if ($associate['profile_photo_path'] !== null): ?><img class="associate-dashboard-photo" src="/api/marketing-associate/photo.php?associate_id=<?= $userId ?>" alt="Profile photo for <?= Security::escape($associate['full_name']) ?>"><?php else: ?><span class="associate-dashboard-photo associate-avatar-placeholder" aria-hidden="true"><?= Security::escape(strtoupper(substr($associate['full_name'], 0, 1))) ?></span><?php endif; ?>
        </div>
        <div>
          <p>Associate ID #<?= sprintf('%03d', (int) $associate['associate_id']) ?> · <?= Security::escape($associate['phone']) ?></p>
          <p>Designation: <?= Security::escape($associate['designation_code'] === null ? 'Not assigned' : MarketingAssociateHierarchy::DESIGNATIONS[$associate['designation_code']]) ?></p>
          <p>Account status: <span class="associate-status associate-status-<?= Security::escape($associate['approval_status']) ?>"><?= Security::escape(ucfirst($associate['approval_status'])) ?></span></p>
          <p>Registered <?= Security::escape($associate['created_at']) ?> UTC</p>
        </div>
      </div>
      <h2>Personal and bank information</h2>
      <dl class="associate-masked-details">
        <div><dt>Full name</dt><dd><?= Security::escape($associate['full_name']) ?></dd></div>
        <div><dt>Contact number</dt><dd><?= Security::escape($associate['phone']) ?></dd></div>
        <div><dt>Aadhaar</dt><dd><?= $associate['aadhaar_last4'] === null ? 'Not provided' : 'XXXX XXXX ' . Security::escape($associate['aadhaar_last4']) ?></dd></div>
        <div><dt>PAN</dt><dd><?= $associate['pan_last4'] === null ? 'Not provided' : '******' . Security::escape($associate['pan_last4']) ?></dd></div>
        <div><dt>Bank account</dt><dd><?= $associate['account_number_last4'] === null ? 'Not provided' : '•••• ' . Security::escape($associate['account_number_last4']) ?></dd></div>
        <div><dt>IFSC code</dt><dd><?= $associate['ifsc_encrypted'] === null ? 'Not provided' : 'Encrypted · reveal to view' ?></dd></div>
        <div><dt>Branch location</dt><dd><?= $associate['branch_location_encrypted'] === null ? 'Not provided' : 'Encrypted · reveal to view' ?></dd></div>
        <div><dt>Registration date</dt><dd><?= Security::escape($associate['created_at']) ?> UTC</dd></div>
        <div><dt>Approval date</dt><dd><?= Security::escape((string) ($associate['approved_at'] ?? 'Not approved')) ?></dd></div>
      </dl>
      <form class="associate-reveal-form" data-sensitive-form action="/api/admin/marketing-associates.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="reveal">
        <input type="hidden" name="associate_id" value="<?= (int) $associateId ?>">
        <button type="submit">Reveal full identity and account numbers</button>
        <div class="sensitive-output" data-sensitive-output role="status" aria-live="polite"></div>
      </form>

      <h3>Step 1: Allocate Designation</h3>
      <?php if ($associate['approval_status'] === 'pending' && !$assignmentValid): ?>
        <p>Save a designation before approving this profile.</p>
      <?php endif; ?>
      <?php if ($recruitment !== false): ?>
        <p>Recruited by: <?= Security::escape((string) ($recruitment['recruiter_name'] ?? 'Unknown recruiter')) ?><?= $suggestedDesignation !== null ? ' · Suggested designation: ' . Security::escape(MarketingAssociateHierarchy::DESIGNATIONS[$suggestedDesignation]) : ' · No designation suggestion' ?></p>
      <?php endif; ?>
      <p>Current designation: <?= Security::escape($associate['designation_code'] === null ? 'Not assigned' : MarketingAssociateHierarchy::DESIGNATIONS[$associate['designation_code']]) ?></p>
      <p>Current manager: <?= Security::escape((string) ($associate['manager_name'] ?? 'Not assigned')) ?><?= $associate['manager_designation'] ? ' · ' . Security::escape(MarketingAssociateHierarchy::DESIGNATIONS[$associate['manager_designation']]) : '' ?></p>
      <form class="admin-form admin-grid" data-admin-form data-confirm="Save this designation?" action="/api/admin/marketing-associates.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="associate_id" value="<?= (int) $associateId ?>">
        <input type="hidden" name="action" value="set_assignment">
        <label>Designation<select name="designation_code" required><option value="">Select designation</option><?php foreach ($assignmentDesignations as $code => $name): ?><?php $rateLabel = isset($designationRates[$code]) ? rtrim(rtrim(number_format((float) $designationRates[$code], 2, '.', ''), '0'), '.') : 'Not configured'; ?><option value="<?= Security::escape($code) ?>" <?= $associate['designation_code'] === $code ? 'selected' : '' ?>><?= Security::escape($name . ' – ' . $rateLabel . (isset($designationRates[$code]) ? '%' : '')) ?></option><?php endforeach; ?></select></label>
        <?php if ($associate['designation_code'] !== null): ?><label>Reason (required when changing designation)<textarea name="reason" maxlength="1000" rows="2"></textarea></label><?php endif; ?>
        <button class="button button-dark full" type="submit">SAVE DESIGNATION</button>
        <p class="full" data-admin-message role="status" aria-live="polite"></p>
      </form>
    </section>

    <section class="admin-section">
      <h2>Business and wallet summary</h2>
      <div class="admin-stat-grid">
        <div><strong><?= (int) $metrics['leads_count'] ?></strong><span>Total leads</span></div>
        <div><strong><?= (int) $metrics['visits_count'] ?></strong><span>Total site visits</span></div>
        <div><strong><?= (int) $metrics['bookings_count'] ?></strong><span>Total bookings</span></div>
        <div><strong>₹<?= Security::escape(Money::format((string) $metrics['commission_total'])) ?></strong><span>Commission earned</span></div>
        <div><strong>₹<?= Security::escape(Money::format($walletBalance)) ?></strong><span>Wallet balance</span></div>
        <div><strong>₹<?= Security::escape(Money::format($available)) ?></strong><span>Available balance</span></div>
      </div>
      <p><a class="button button-dark" href="/admin/marketing-associate-wallet.php?id=<?= (int) $associateId ?>">Manage Wallet</a> <a class="button button-outline-dark" href="/admin/marketing-associate-withdrawals.php?search=<?= (int) $associateId ?>">Review Withdrawal Requests</a></p>
      <h3>Withdrawal history</h3>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Request ID</th><th>Requested (UTC)</th><th>Amount</th><th>Status</th><th>Payment reference / remarks</th></tr></thead><tbody>
      <?php foreach ($withdrawals as $withdrawal): ?><tr><td><?= Security::escape((string) $withdrawal['request_reference']) ?></td><td><?= Security::escape($withdrawal['requested_at']) ?></td><td>₹<?= Security::escape(Money::format((string) $withdrawal['amount'])) ?></td><td><?= Security::escape(ucfirst($withdrawal['status'] === 'requested' ? 'pending' : $withdrawal['status'])) ?></td><td><?= Security::escape((string) ($withdrawal['payment_reference'] ?: $withdrawal['admin_remarks'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$withdrawals): ?><tr><td colspan="5">No withdrawal requests.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="admin-section">
      <h2>Step 2: Profile approval</h2>
      <p>Profile status: <span class="associate-status associate-status-<?= Security::escape($associate['approval_status']) ?>"><?= Security::escape(ucfirst($associate['approval_status'])) ?></span></p>
      <?php if ($associate['approval_status'] === 'rejected'): ?>
        <p>Rejected <?= Security::escape((string) ($associate['rejected_at'] ?? 'Date unavailable')) ?><?= $associate['rejected_at'] ? ' UTC' : '' ?><?= $associate['rejector_name'] ? ' by ' . Security::escape($associate['rejector_name']) : '' ?></p>
        <p><strong>Rejection reason:</strong> <?= Security::escape((string) ($associate['rejection_reason'] ?? 'No reason recorded for this historical rejection')) ?></p>
      <?php elseif ($associate['approval_status'] === 'approved' || $associate['approval_status'] === 'blocked'): ?>
        <p>Approved <?= Security::escape((string) ($associate['approved_at'] ?? 'Date unavailable')) ?><?= $associate['approved_at'] ? ' UTC' : '' ?><?= $associate['approver_name'] ? ' by ' . Security::escape($associate['approver_name']) : '' ?></p>
      <?php endif; ?>
      <?php if ($associate['approval_status'] !== 'pending'): ?>
      <form class="admin-form associate-review-form" data-admin-form action="/api/admin/marketing-associates.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="associate_id" value="<?= (int) $associateId ?>">
        <input type="hidden" name="action" value="save_remarks">
        <label>Admin remarks<textarea name="admin_remarks" maxlength="2000" rows="3"><?= Security::escape((string) $associate['admin_remarks']) ?></textarea></label>
        <button class="button button-outline-dark" type="submit">Save remarks</button>
      </form>
      <?php elseif ($associate['admin_remarks']): ?><p>Admin remarks: <?= Security::escape($associate['admin_remarks']) ?></p><?php endif; ?>
      <?php if ($associate['approval_status'] === 'pending'): ?>
        <div class="admin-form admin-grid associate-review-form">
          <form data-admin-form data-confirm="Approve this Marketing Associate profile?" action="/api/admin/marketing-associates.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="associate_id" value="<?= (int) $associateId ?>">
            <input type="hidden" name="action" value="approve">
            <button class="button button-dark" type="submit" <?= $assignmentValid ? '' : 'disabled' ?>>Approve Profile</button>
            <p data-admin-message role="status" aria-live="polite"></p>
          </form>
          <form data-admin-form data-confirm="Reject this profile? This cannot be undone." action="/api/admin/marketing-associates.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="associate_id" value="<?= (int) $associateId ?>">
            <input type="hidden" name="action" value="reject">
            <label>Rejection reason (required)<textarea name="admin_remarks" maxlength="2000" rows="3" required></textarea></label>
            <button class="button button-outline-dark" type="submit">Reject Profile</button>
            <p data-admin-message role="status" aria-live="polite"></p>
          </form>
        </div>
      <?php elseif ($associate['approval_status'] === 'approved'): ?>
        <form class="admin-form" data-admin-form data-confirm="Block this Marketing Associate profile?" action="/api/admin/marketing-associates.php" method="post">
          <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
          <input type="hidden" name="associate_id" value="<?= (int) $associateId ?>">
          <input type="hidden" name="action" value="block">
          <button class="button button-outline-dark" type="submit">Block Profile</button>
        </form>
      <?php elseif ($associate['approval_status'] === 'blocked'): ?>
        <form class="admin-form" data-admin-form data-confirm="Unblock this Marketing Associate profile?" action="/api/admin/marketing-associates.php" method="post">
          <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
          <input type="hidden" name="associate_id" value="<?= (int) $associateId ?>">
          <input type="hidden" name="action" value="unblock">
          <button class="button button-dark" type="submit" <?= $assignmentValid ? '' : 'disabled' ?>>Unblock Profile</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="admin-section">
      <h2>Audit history</h2>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date and time (UTC)</th><th>Action</th><th>Administrator / actor</th></tr></thead><tbody>
      <?php foreach ($auditLogs as $log): ?><tr><td><?= Security::escape($log['created_at']) ?></td><td><?= Security::escape($log['action']) ?></td><td><?= Security::escape((string) ($log['actor_name'] ?? 'System')) ?></td></tr><?php endforeach; ?>
      <?php if (!$auditLogs): ?><tr><td colspan="3">No audit records.</td></tr><?php endif; ?>
      </tbody></table></div>
      <h3>Wallet administration history</h3>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date and time (UTC)</th><th>Action</th><th>Amount</th><th>Administrator</th><th>Remarks</th></tr></thead><tbody>
      <?php foreach ($walletAudit as $log): ?><tr><td><?= Security::escape($log['created_at']) ?></td><td><?= Security::escape($log['action']) ?></td><td>₹<?= Security::escape(Money::format((string) $log['amount'])) ?></td><td><?= Security::escape((string) ($log['actor_name'] ?? 'System')) ?></td><td><?= Security::escape($log['remarks']) ?></td></tr><?php endforeach; ?>
      <?php if (!$walletAudit): ?><tr><td colspan="5">No wallet administration actions.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </main>
  <script src="/assets/js/marketing-associates.js" defer></script>
  <script src="/assets/js/admin.js" defer></script>
</body>
</html>
