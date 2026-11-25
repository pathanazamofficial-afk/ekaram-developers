<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
$admin = Auth::requireAdminPage();
$csrfToken = Security::csrfToken();

$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$validStatuses = ['pending', 'approved', 'rejected', 'blocked'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$conditions = [];
$parameters = [];
if ($search !== '') {
    $conditions[] = '(u.full_name LIKE :name_search OR u.phone LIKE :phone_search)';
    $parameters['name_search'] = '%' . $search . '%';
    $parameters['phone_search'] = '%' . $search . '%';
}
if ($statusFilter !== '') {
    $conditions[] = 'ma.approval_status = :approval_status';
    $parameters['approval_status'] = $statusFilter;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$statement = Database::connection()->prepare(
    "SELECT ma.id, ma.user_id, ma.aadhaar_last4, ma.pan_last4, ma.account_number_last4,
            ma.designation_code,
            ma.approval_status, ma.approved_by, ma.approved_at,
            ma.admin_remarks, ma.created_at, u.full_name, u.phone, approver.full_name AS approver_name,
            profile.profile_photo_path
     FROM marketing_associates ma
     JOIN users u ON u.id = ma.user_id
     LEFT JOIN users approver ON approver.id = ma.approved_by
     LEFT JOIN marketing_associate_profiles profile ON profile.user_id = ma.user_id
     $where
     ORDER BY CASE ma.approval_status
       WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 ELSE 3
     END, ma.created_at DESC"
);
$statement->execute($parameters);
$associates = $statement->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Marketing Associates | Admin | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header">
    <a class="brand admin-brand" href="/admin/index.php"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <nav class="admin-user" aria-label="Admin navigation"><a href="/admin/index.php">Dashboard</a><a href="/">Website</a></nav>
  </header>
  <main class="admin-content">
    <div class="admin-title">
      <div><p class="section-kicker">Administration</p><h1>Marketing Associates</h1></div>
      <a class="button button-dark" href="/admin/index.php">Back to dashboard</a>
    </div>
    <p class="admin-message" role="status" aria-live="polite"></p>
    <section class="admin-section">
      <h2>Registrations</h2>
      <form class="customer-search" method="get" action="/admin/marketing-associates.php">
        <label>Search name or mobile number<input type="search" name="search" maxlength="100" value="<?= Security::escape($search) ?>" placeholder="Name or +91 mobile"></label>
        <label>Status<select name="status"><option value="">All statuses</option><?php foreach ($validStatuses as $status): ?><option value="<?= Security::escape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= Security::escape(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
        <button class="button button-dark" type="submit">Search</button>
        <a class="button button-outline-dark" href="/admin/marketing-associates.php">Clear</a>
      </form>
      <p><?= count($associates) ?> registration<?= count($associates) === 1 ? '' : 's' ?> shown. Sensitive identity and bank numbers are masked until an administrator explicitly reveals them.</p>
      <div class="admin-record-list associate-review-list">
        <?php foreach ($associates as $associate): ?>
          <details class="admin-record">
            <summary>
              <span class="associate-record-heading">
                <?php if ($associate['profile_photo_path'] !== null): ?><img class="associate-avatar" src="/api/marketing-associate/photo.php?associate_id=<?= (int) $associate['user_id'] ?>" alt="Profile photo for <?= Security::escape($associate['full_name']) ?>"><?php else: ?><span class="associate-avatar associate-avatar-placeholder" aria-hidden="true"><?= Security::escape(strtoupper(substr($associate['full_name'], 0, 1))) ?></span><?php endif; ?>
                <span><?= Security::escape($associate['full_name']) ?> · Associate #<?= sprintf('%03d', (int) $associate['id']) ?> · <?= Security::escape($associate['designation_code'] === null ? 'Designation pending' : MarketingAssociateHierarchy::DESIGNATIONS[$associate['designation_code']]) ?></span>
              </span>
              <span class="associate-status associate-status-<?= Security::escape($associate['approval_status']) ?>"><?= Security::escape($associate['approval_status']) ?></span>
            </summary>
            <div class="associate-review-details">
              <p><a class="button button-outline-dark" href="/admin/marketing-associate-profile.php?id=<?= (int) $associate['id'] ?>"><?= $associate['approval_status'] === 'pending' ? 'Assign designation and review' : 'View full profile' ?></a></p>
              <dl class="associate-masked-details">
                <div><dt>Mobile</dt><dd>+91 ******<?= Security::escape(substr($associate['phone'], -4)) ?></dd></div>
                <div><dt>Aadhaar</dt><dd><?= $associate['aadhaar_last4'] === null ? 'Not provided' : 'XXXX XXXX ' . Security::escape($associate['aadhaar_last4']) ?></dd></div>
                <div><dt>PAN</dt><dd><?= $associate['pan_last4'] === null ? 'Not provided' : '******' . Security::escape($associate['pan_last4']) ?></dd></div>
                <div><dt>Bank account</dt><dd><?= $associate['account_number_last4'] === null ? 'Not provided' : '•••• ' . Security::escape($associate['account_number_last4']) ?></dd></div>
                <div><dt>Registered</dt><dd><?= Security::escape($associate['created_at']) ?> UTC</dd></div>
                <div><dt>Approval</dt><dd><?= Security::escape(ucfirst($associate['approval_status'])) ?><?= $associate['approved_at'] ? ' · ' . Security::escape($associate['approved_at']) . ' UTC' : '' ?></dd></div>
                <?php if ($associate['approved_by'] !== null): ?><div><dt>Approved by</dt><dd><?= Security::escape((string) $associate['approver_name']) ?></dd></div><?php endif; ?>
              </dl>
              <p>IFSC code and branch details are encrypted. Reveal them only from the audited full-profile view when needed.</p>
              <p><strong>Contact:</strong> <?= Security::escape($associate['full_name']) ?> · +91 ******<?= Security::escape(substr($associate['phone'], -4)) ?></p>
              <form class="associate-reveal-form" data-sensitive-form action="/api/admin/marketing-associates.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
                <input type="hidden" name="action" value="reveal">
                <input type="hidden" name="associate_id" value="<?= (int) $associate['id'] ?>">
                <button type="submit">Reveal full identity and account numbers</button>
                <div class="sensitive-output" data-sensitive-output role="status" aria-live="polite"></div>
              </form>
              <?php if ($associate['approval_status'] === 'pending'): ?><p>Save a designation, then approve or reject the profile.</p><?php endif; ?>
            </div>
          </details>
        <?php endforeach; ?>
        <?php if (!$associates): ?><p class="empty-state">No Marketing Associate registrations match this search.</p><?php endif; ?>
      </div>
    </section>
  </main>
  <script src="/assets/js/admin.js" defer></script>
  <script src="/assets/js/marketing-associates.js" defer></script>
</body>
</html>
