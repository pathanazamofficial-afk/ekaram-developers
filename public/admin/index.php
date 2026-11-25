<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
$admin = Auth::requireAdminPage();
$pdo = Database::connection();
$csrfToken = Security::csrfToken();
$approvalNotice = $_SESSION['associate_approval_notice'] ?? null;
unset($_SESSION['associate_approval_notice']);

$projects = $pdo->query('SELECT * FROM projects ORDER BY id DESC')->fetchAll();
$amenities = $pdo->query(
    'SELECT a.*, p.name AS project_name
     FROM amenities a JOIN projects p ON p.id = a.project_id
     ORDER BY p.id DESC, a.display_order, a.id'
)->fetchAll();
$specifications = $pdo->query(
    'SELECT s.*, p.name AS project_name
     FROM project_specifications s JOIN projects p ON p.id = s.project_id
     ORDER BY p.id DESC, s.display_order, s.id'
)->fetchAll();
$plots = $pdo->query(
    'SELECT pl.*, p.name AS project_name, p.price_per_sqyd,
            CASE
              WHEN pl.area_sqyd IS NULL OR p.price_per_sqyd IS NULL THEN NULL
              ELSE ROUND(pl.area_sqyd * p.price_per_sqyd, 2)
            END AS calculated_total_price
     FROM plots pl JOIN projects p ON p.id = pl.project_id
     ORDER BY p.id DESC, pl.plot_number'
)->fetchAll();
$gallery = $pdo->query(
    'SELECT g.*, p.name AS project_name
     FROM project_gallery g JOIN projects p ON p.id = g.project_id
     ORDER BY p.id DESC, g.display_order, g.id'
)->fetchAll();
$leads = $pdo->query(
    'SELECT l.id, l.enquiry_type, l.interest, l.status, l.assigned_to, l.lead_owner_id,
            l.created_at, c.full_name, c.phone, c.email
     FROM leads l JOIN customers c ON c.id = l.customer_id
     ORDER BY l.id DESC LIMIT 50'
)->fetchAll();
$visits = $pdo->query(
    'SELECT v.id, v.preferred_start, v.status, v.assigned_to, v.visit_address, v.notes,
            v.preferred_food, v.vehicle_model, v.vehicle_number, v.driver_name, v.driver_phone,
            v.pickup_location, v.special_instructions,
            c.full_name, c.phone, p.name AS project_name
     FROM site_visits v
     JOIN customers c ON c.id = v.customer_id
     JOIN projects p ON p.id = v.project_id
     ORDER BY v.preferred_start DESC LIMIT 50'
)->fetchAll();
foreach ($visits as &$visit) {
     $visit['preferred_start_local'] = (new DateTimeImmutable($visit['preferred_start'], new DateTimeZone('UTC')))
         ->setTimezone(new DateTimeZone($app['timezone']))->format('Y-m-d\TH:i');
     $visit['request_details'] = [];
     if (is_string($visit['notes']) && $visit['notes'] !== '') {
         $details = json_decode($visit['notes'], true);
         if (is_array($details)) {
             $visit['request_details'] = $details;
         }
     }
}
unset($visit);
$employees = $pdo->query(
    "SELECT u.id, u.full_name, CONCAT(designation.name, ' · Marketing Associate') AS role_name
     FROM users u
     JOIN roles account_role ON account_role.id = u.role_id AND account_role.code = 'marketing_associate'
     JOIN marketing_associates ma ON ma.user_id = u.id AND ma.approval_status = 'approved'
     JOIN roles designation ON designation.code = ma.designation_code
     WHERE u.account_status = 'active'
     ORDER BY designation.hierarchy_level, u.full_name"
)->fetchAll();
$bookings = $pdo->query(
    'SELECT b.id, b.booking_reference, b.status, b.reservation_expires_at, b.total_price,
            c.full_name, c.phone, pl.plot_number, p.name AS project_name
     FROM bookings b
     JOIN customers c ON c.id = b.customer_id
     JOIN plots pl ON pl.id = b.plot_id
     JOIN projects p ON p.id = pl.project_id
     ORDER BY b.id DESC LIMIT 50'
)->fetchAll();
$commissionRules = $pdo->query(
    'SELECT recipient_code, percentage FROM commission_rule_versions
     WHERE version_no = (
       SELECT version_no FROM commission_rule_versions
       WHERE effective_from <= UTC_TIMESTAMP() ORDER BY version_no DESC LIMIT 1
     )
     ORDER BY recipient_code'
)->fetchAll();
$commissionRates = [];
$employeeCommissionRate = 0.0;
foreach ($commissionRules as $rule) {
    if ($rule['recipient_code'] !== 'company') {
        $commissionRates[$rule['recipient_code']] = $rule['percentage'];
        $employeeCommissionRate += (float) $rule['percentage'];
    }
}
$companyRetentionRate = number_format(100 - $employeeCommissionRate, 2, '.', '');
$confirmedBookings = $pdo->query(
    "SELECT b.id, b.booking_reference, c.full_name, pl.plot_number, p.name AS project_name
     FROM bookings b JOIN customers c ON c.id = b.customer_id
     JOIN plots pl ON pl.id = b.plot_id JOIN projects p ON p.id = pl.project_id
     WHERE b.status = 'confirmed' ORDER BY b.id DESC LIMIT 200"
)->fetchAll();
$pendingCollections = $pdo->query(
    "SELECT pc.id, pc.amount, pc.received_at, pc.payment_method, pc.payment_reference,
            b.booking_reference, c.full_name, pl.plot_number
     FROM plot_collections pc JOIN bookings b ON b.id = pc.booking_id
     JOIN customers c ON c.id = b.customer_id JOIN plots pl ON pl.id = b.plot_id
     WHERE pc.status = 'pending' ORDER BY pc.id DESC LIMIT 100"
)->fetchAll();
$pendingBatches = $pdo->query(
    "SELECT cb.id, cb.stage, cb.collected_to_date, cb.eligible_collection, cb.rule_version,
            b.booking_reference, c.full_name, pl.plot_number,
            COUNT(cm.id) AS recipient_count, COALESCE(SUM(cm.amount), 0) AS total_commission
     FROM commission_batches cb JOIN bookings b ON b.id = cb.booking_id
     JOIN customers c ON c.id = b.customer_id JOIN plots pl ON pl.id = b.plot_id
     LEFT JOIN commissions cm ON cm.batch_id = cb.id
     WHERE cb.status = 'pending'
     GROUP BY cb.id
     ORDER BY cb.created_at DESC LIMIT 100"
)->fetchAll();
$withdrawalRows = $pdo->query(
    "SELECT w.id, w.amount, w.status, w.requested_at, w.payment_reference, u.full_name
     FROM withdrawal_requests w
     JOIN users u ON u.id = w.user_id
     JOIN roles r ON r.id = u.role_id
     WHERE r.code = 'marketing_associate'
     ORDER BY w.id DESC LIMIT 100"
)->fetchAll();
$stats = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM users u
        JOIN roles r ON r.id = u.role_id AND r.code = 'marketing_associate'
        JOIN marketing_associates ma ON ma.user_id = u.id AND ma.approval_status = 'approved'
        WHERE u.account_status = 'active' AND ma.designation_code IS NOT NULL) AS active_employees,
       (SELECT COUNT(*) FROM leads WHERE status = 'new') AS new_leads,
       (SELECT COUNT(*) FROM plots WHERE status = 'available') AS available_plots,
       (SELECT COUNT(*) FROM plots WHERE status = 'recent_agreement') AS recent_agreement_plots,
       (SELECT COUNT(*) FROM plots WHERE status = 'booked') AS agreement_plots,
       (SELECT COUNT(*) FROM plots WHERE status = 'sold') AS sold_plots,
       (SELECT COUNT(*) FROM bookings WHERE status IN ('reserved','pending_approval')) AS awaiting_bookings,
       (SELECT COUNT(*) FROM commissions WHERE status = 'pending') AS pending_commissions,
       (SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'requested') AS pending_withdrawals"
)->fetch();
$notifications = $pdo->prepare(
    'SELECT id, title, message, read_at, created_at FROM notifications
     WHERE user_id = :user_id ORDER BY id DESC LIMIT 10'
);
$notifications->execute(['user_id' => (int) $admin['id']]);
$notifications = $notifications->fetchAll();
$recentAssociates = $pdo->query(
    'SELECT ma.id, ma.designation_code, ma.approval_status, u.full_name
     FROM marketing_associates ma JOIN users u ON u.id = ma.user_id
     ORDER BY ma.created_at DESC, ma.id DESC LIMIT 10'
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin dashboard | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header">
    <a class="brand admin-brand" href="/"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <div class="admin-user"><span><?= Security::escape($admin['full_name']) ?></span><form action="/admin/logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>"><button type="submit">Sign out</button></form></div>
  </header>
  <main class="admin-content">
    <div class="admin-title"><div><p class="section-kicker">Administration</p><h1>Website dashboard</h1></div>
      <div class="admin-actions"><a class="button button-dark" href="/admin/marketing-associates.php">Marketing Associates</a><a class="button button-dark" href="/admin/marketing-associate-wallet.php">Associate Wallets</a><a class="button button-dark" href="/admin/marketing-associate-withdrawals.php">Associate Withdrawals</a><a class="button button-dark" href="/admin/customers.php">Customers</a><a class="button button-dark" href="/admin/reports.php">Reports</a><a class="button button-dark" href="/" target="_blank" rel="noopener">View website</a></div>
    </div>
    <p class="admin-message" role="status" aria-live="polite"></p>
    <?php if (is_string($approvalNotice)): ?><p class="admin-success-notice" role="status"><?= Security::escape($approvalNotice) ?></p><?php endif; ?>
    <div class="admin-stat-grid">
      <?php foreach (['active_employees' => 'Active Marketing Associates', 'new_leads' => 'New leads', 'available_plots' => 'Available plots', 'recent_agreement_plots' => 'Recent Agreements', 'agreement_plots' => 'Agreements', 'sold_plots' => 'Sold plots', 'awaiting_bookings' => 'Awaiting bookings', 'pending_commissions' => 'Pending commissions', 'pending_withdrawals' => 'Withdrawal requests'] as $statKey => $statLabel): ?>
        <div><strong><?= (int) $stats[$statKey] ?></strong><span><?= Security::escape($statLabel) ?></span></div>
      <?php endforeach; ?>
    </div>
    <section class="admin-section">
      <h2>Notifications</h2>
      <div class="admin-record-list">
        <?php foreach ($notifications as $notification): ?>
          <div class="admin-record notification-row"><strong><?= Security::escape($notification['title']) ?></strong><p><?= Security::escape($notification['message']) ?></p><small><?= Security::escape($notification['created_at']) ?> UTC · <?= $notification['read_at'] === null ? 'Unread' : 'Read' ?></small>
            <?php if ($notification['read_at'] === null): ?><form data-admin-form action="/api/notifications.php" method="post"><input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>"><input type="hidden" name="id" value="<?= (int) $notification['id'] ?>"><button type="submit">Mark as read</button></form><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$notifications): ?><p class="empty-state">No notifications.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-section">
      <h2>Marketing Associate accounts and designations</h2>
      <p>All team members register through the Marketing Associate portal. Save a designation, then approve or reject the profile.</p>
      <a class="button button-dark" href="/admin/marketing-associates.php">Review Marketing Associates</a>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Associate</th><th>Designation</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($recentAssociates as $associate): ?><tr><td><a href="/admin/marketing-associate-profile.php?id=<?= (int) $associate['id'] ?>"><?= Security::escape($associate['full_name']) ?> (Associate #<?= sprintf('%03d', (int) $associate['id']) ?>)</a></td><td><?= Security::escape(MarketingAssociateHierarchy::DESIGNATIONS[$associate['designation_code']] ?? 'Not assigned') ?></td><td><?= Security::escape(ucfirst($associate['approval_status'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$recentAssociates): ?><tr><td colspan="3">No Marketing Associate registrations.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="admin-section">
      <h2>Projects</h2>
      <form class="admin-form admin-grid" data-admin-form action="/api/admin/projects.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="create">
        <h3 class="full">Add a project</h3>
        <label>Project name<input name="name" required maxlength="150"></label>
        <label>URL slug<input name="slug" maxlength="180" placeholder="project-name"></label>
        <label>Location<input name="location" required maxlength="255"></label>
        <label>Total land area (acres)<input name="total_land_area" type="number" min="0" step="0.01" value="0" required></label>
        <label>Price per sq. yd.<input name="price_per_sqyd" type="number" min="0.01" step="0.01" value="22000"></label>
        <p class="full empty-state">Plot prices are calculated from area and this rate.</p>
        <label>Status<select name="status"><option value="draft">Draft</option><option value="published">Published</option></select></label>
        <label class="full">Google Maps embed URL<input name="map_embed_url" type="url" maxlength="1000" placeholder="https://www.google.com/maps/..."></label>
        <label class="full">Project description<textarea name="description" rows="4" maxlength="10000"></textarea></label>
        <label class="full">Brochure PDF (up to 10 MB)<input name="brochure" type="file" accept="application/pdf"></label>
        <button class="button button-dark full" type="submit">Create project</button>
      </form>

      <div class="admin-record-list">
        <?php foreach ($projects as $project): ?>
          <details class="admin-record">
            <summary><?= Security::escape($project['name']) ?> <span><?= Security::escape($project['status']) ?></span></summary>
            <form class="admin-form admin-grid" data-admin-form action="/api/admin/projects.php" method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
              <label>Project name<input name="name" value="<?= Security::escape($project['name']) ?>" required maxlength="150"></label>
              <label>URL slug<input name="slug" value="<?= Security::escape($project['slug']) ?>" required maxlength="180"></label>
              <label>Location<input name="location" value="<?= Security::escape($project['location']) ?>" required maxlength="255"></label>
              <label>Total land area (acres)<input name="total_land_area" type="number" min="0" step="0.01" value="<?= Security::escape((string) $project['total_land_area']) ?>" required></label>
              <label>Price per sq. yd.<input name="price_per_sqyd" type="number" min="0.01" step="0.01" value="<?= Security::escape((string) $project['price_per_sqyd']) ?>"></label>
              <p class="full empty-state">Saving a new rate recalculates every plot price. Existing booking amounts remain unchanged.</p>
              <label>Status<select name="status"><option value="draft" <?= $project['status'] === 'draft' ? 'selected' : '' ?>>Draft</option><option value="published" <?= $project['status'] === 'published' ? 'selected' : '' ?>>Published</option><option value="archived" <?= $project['status'] === 'archived' ? 'selected' : '' ?>>Archived</option></select></label>
              <label class="full">Google Maps embed URL<input name="map_embed_url" type="url" maxlength="1000" value="<?= Security::escape((string) $project['map_embed_url']) ?>"></label>
              <label class="full">Project description<textarea name="description" rows="4" maxlength="10000"><?= Security::escape((string) $project['description']) ?></textarea></label>
              <label class="full">Replace brochure PDF (optional)<input name="brochure" type="file" accept="application/pdf"></label>
              <button class="button button-dark full" type="submit">Save project</button>
            </form>
          </details>
        <?php endforeach; ?>
        <?php if (!$projects): ?><p class="empty-state">No projects yet. Add one to publish website content.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-section">
      <h2>Project specifications</h2>
      <p>Homepage location cards use labels in the format <code>Location: Place name</code> with the brochure distance as the value. Homepage highlight cards use <code>Highlight: Feature name</code> with a short note. Keep details verified against the project source material.</p>
      <?php if ($projects): ?>
      <form class="admin-form admin-grid" data-admin-form action="/api/admin/specifications.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="save">
        <h3 class="full">Add a project highlight or specification</h3>
        <label>Project<select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
        <label>Label<input name="label" required maxlength="120"></label>
        <label class="full">Value<input name="spec_value" required maxlength="500"></label>
        <label>Display order<input name="display_order" type="number" min="0" max="65535" value="0" required></label>
        <label class="admin-check"><input name="published" type="checkbox" value="1" checked> Published</label>
        <button class="button button-dark full" type="submit">Add specification</button>
      </form>
      <?php else: ?>
        <p class="empty-state">Create a project before adding specifications.</p>
      <?php endif; ?>
      <div class="admin-record-list">
        <?php foreach ($specifications as $specification): ?>
          <details class="admin-record">
            <summary><?= Security::escape($specification['label']) ?> — <?= Security::escape($specification['project_name']) ?> <span><?= $specification['published'] ? 'Published' : 'Hidden' ?></span></summary>
            <form class="admin-form admin-grid" data-admin-form action="/api/admin/specifications.php" method="post">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="id" value="<?= (int) $specification['id'] ?>">
              <label>Project<select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>" <?= (int) $specification['project_id'] === (int) $project['id'] ? 'selected' : '' ?>><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
              <label>Label<input name="label" value="<?= Security::escape($specification['label']) ?>" required maxlength="120"></label>
              <label class="full">Value<input name="spec_value" value="<?= Security::escape($specification['spec_value']) ?>" required maxlength="500"></label>
              <label>Display order<input name="display_order" type="number" min="0" max="65535" value="<?= (int) $specification['display_order'] ?>" required></label>
              <label class="admin-check"><input name="published" type="checkbox" value="1" <?= $specification['published'] ? 'checked' : '' ?>> Published</label>
              <button class="button button-dark full" type="submit">Save specification</button>
            </form>
            <form class="admin-delete-form" data-admin-form action="/api/admin/specifications.php" method="post">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $specification['id'] ?>">
              <button class="button button-danger" type="submit">Delete specification</button>
            </form>
          </details>
        <?php endforeach; ?>
        <?php if (!$specifications): ?><p class="empty-state">No project specifications have been added.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-section">
      <h2>Plot inventory</h2>
      <?php if ($projects): ?>
      <form class="admin-form admin-grid" data-admin-form action="/api/admin/plots.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="create">
        <h3 class="full">Add a plot</h3>
        <label>Project<select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
        <label>Plot number<input name="plot_number" required maxlength="50"></label>
        <label>Dimensions<input name="dimensions" required maxlength="100" placeholder="30 x 50 ft"></label>
        <label>Area (sq. yd.)<input name="area_sqyd" type="number" min="0" step="0.01"></label>
        <label>Facing direction<select name="facing_direction"><option value="">Not specified</option><?php foreach (PlotFacing::DIRECTIONS as $direction): ?><option value="<?= Security::escape($direction) ?>"><?= Security::escape($direction) ?></option><?php endforeach; ?></select></label>
        <label>Length (ft)<input name="length_ft" type="number" min="0" step="0.01"></label>
        <label>Width (ft)<input name="width_ft" type="number" min="0" step="0.01"></label>
        <label>Inventory status<select name="status"><option value="available">Available</option><option value="blocked">Blocked</option></select></label>
        <p class="full empty-state">Total price is calculated from the selected project’s price per square yard and the area entered.</p>
        <button class="button button-dark full" type="submit">Add plot</button>
      </form>
      <?php else: ?>
        <p class="empty-state">Create a project before adding plots.</p>
      <?php endif; ?>
      <div class="admin-record-list">
        <?php foreach ($plots as $plot): ?>
          <details class="admin-record">
            <summary>Plot <?= Security::escape($plot['plot_number']) ?> — <?= Security::escape($plot['project_name']) ?> <span><?= Security::escape(PlotStatus::LABELS[$plot['status']] ?? $plot['status']) ?></span></summary>
            <form class="admin-form admin-grid" data-admin-form action="/api/admin/plots.php" method="post">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int) $plot['id'] ?>">
              <label>Project<select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>" <?= (int) $plot['project_id'] === (int) $project['id'] ? 'selected' : '' ?>><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
              <label>Plot number<input name="plot_number" value="<?= Security::escape($plot['plot_number']) ?>" required maxlength="50"></label>
              <label>Dimensions<input name="dimensions" value="<?= Security::escape($plot['dimensions']) ?>" required maxlength="100"></label>
              <label>Area (sq. yd.)<input name="area_sqyd" type="number" min="0" step="0.01" value="<?= Security::escape((string) $plot['area_sqyd']) ?>"></label>
              <label>Facing direction<select name="facing_direction"><option value="" <?= $plot['facing_direction'] === null ? 'selected' : '' ?>>Not specified</option><?php foreach (PlotFacing::DIRECTIONS as $direction): ?><option value="<?= Security::escape($direction) ?>" <?= $plot['facing_direction'] === $direction ? 'selected' : '' ?>><?= Security::escape($direction) ?></option><?php endforeach; ?></select></label>
              <label>Length (ft)<input name="length_ft" type="number" min="0" step="0.01" value="<?= Security::escape((string) $plot['length_ft']) ?>"></label>
              <label>Width (ft)<input name="width_ft" type="number" min="0" step="0.01" value="<?= Security::escape((string) $plot['width_ft']) ?>"></label>
              <p class="full empty-state">Calculated total price: <?= $plot['calculated_total_price'] !== null ? '₹' . Security::escape(Money::formatIndian((string) $plot['calculated_total_price'])) : 'Price Not Available' ?> (rate <?= $plot['price_per_sqyd'] !== null ? '₹' . Security::escape(Money::formatIndian((string) $plot['price_per_sqyd'])) . '/sq. yd.' : 'not set' ?>)</p>
              <?php if ($plot['status'] === 'reserved'): ?>
                <input type="hidden" name="status" value="<?= Security::escape($plot['status']) ?>">
                <label>Inventory status<input value="<?= Security::escape(PlotStatus::LABELS[$plot['status']]) ?> (booking managed)" disabled></label>
              <?php else: ?>
                <label>Inventory status<select name="status"><?php foreach (PlotStatus::ADMIN_EDITABLE as $status): ?><option value="<?= Security::escape($status) ?>" <?= $plot['status'] === $status ? 'selected' : '' ?>><?= Security::escape(PlotStatus::LABELS[$status]) ?></option><?php endforeach; ?></select></label>
              <?php endif; ?>
              <button class="button button-dark full" type="submit">Save plot</button>
            </form>
          </details>
        <?php endforeach; ?>
        <?php if (!$plots): ?><p class="empty-state">No plots have been added.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-section">
      <h2>Amenities</h2>
      <?php if ($projects): ?>
      <form class="admin-form admin-grid" data-admin-form action="/api/admin/amenities.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="save">
        <h3 class="full">Add an amenity</h3>
        <label>Project<select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
        <label>Amenity name<input name="name" required maxlength="150"></label>
        <label>Display order<input name="display_order" type="number" min="0" max="65535" value="0" required></label>
        <label>Image (JPEG or PNG, up to 5 MB)<input name="image" type="file" accept="image/jpeg,image/png" required></label>
        <label class="full">Description<input name="description" maxlength="500"></label>
        <label class="admin-check"><input name="published" type="checkbox" value="1" checked> Published</label>
        <button class="button button-dark full" type="submit">Add amenity</button>
      </form>
      <?php else: ?>
        <p class="empty-state">Create a project before adding amenities.</p>
      <?php endif; ?>
      <div class="admin-record-list">
        <?php foreach ($amenities as $amenity): ?>
          <details class="admin-record">
            <summary><?= Security::escape($amenity['name']) ?> — <?= Security::escape($amenity['project_name']) ?> <span><?= $amenity['published'] ? 'Published' : 'Hidden' ?></span></summary>
            <form class="admin-form admin-grid" data-admin-form action="/api/admin/amenities.php" method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="id" value="<?= (int) $amenity['id'] ?>">
              <label>Project<select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>" <?= (int) $amenity['project_id'] === (int) $project['id'] ? 'selected' : '' ?>><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
              <label>Amenity name<input name="name" value="<?= Security::escape($amenity['name']) ?>" required maxlength="150"></label>
              <label>Display order<input name="display_order" type="number" min="0" max="65535" value="<?= (int) $amenity['display_order'] ?>" required></label>
              <label>Replace image (optional)<input name="image" type="file" accept="image/jpeg,image/png"></label>
              <label class="full">Description<input name="description" value="<?= Security::escape((string) $amenity['description']) ?>" maxlength="500"></label>
              <label class="admin-check"><input name="published" type="checkbox" value="1" <?= $amenity['published'] ? 'checked' : '' ?>> Published</label>
              <button class="button button-dark full" type="submit">Save amenity</button>
            </form>
            <form class="admin-delete-form" data-admin-form action="/api/admin/amenities.php" method="post">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $amenity['id'] ?>">
              <button class="button button-danger" type="submit">Delete amenity</button>
            </form>
          </details>
        <?php endforeach; ?>
        <?php if (!$amenities): ?><p class="empty-state">No amenities have been added.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-section">
      <h2>Project gallery</h2>
      <?php if ($projects): ?>
      <form class="admin-form admin-grid" data-admin-form action="/api/admin/gallery.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="save">
        <h3 class="full">Add a gallery image</h3>
        <label>Project<select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
        <label>Alt text<input name="alt_text" required maxlength="200"></label>
        <label>Display order<input name="display_order" type="number" min="0" max="65535" value="0" required></label>
        <label>Image (JPEG or PNG, up to 5 MB)<input name="image" type="file" accept="image/jpeg,image/png" required></label>
        <label class="admin-check"><input name="published" type="checkbox" value="1" checked> Published</label>
        <button class="button button-dark full" type="submit">Add gallery image</button>
      </form>
      <?php endif; ?>
      <div class="admin-record-list">
        <?php foreach ($gallery as $image): ?>
          <details class="admin-record">
            <summary><?= Security::escape($image['alt_text']) ?> — <?= Security::escape($image['project_name']) ?> <span><?= $image['published'] ? 'Published' : 'Hidden' ?></span></summary>
            <form class="admin-form admin-grid" data-admin-form action="/api/admin/gallery.php" method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="id" value="<?= (int) $image['id'] ?>">
              <label>Project<select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>" <?= (int) $image['project_id'] === (int) $project['id'] ? 'selected' : '' ?>><?= Security::escape($project['name']) ?></option><?php endforeach; ?></select></label>
              <label>Alt text<input name="alt_text" value="<?= Security::escape($image['alt_text']) ?>" required maxlength="200"></label>
              <label>Display order<input name="display_order" type="number" min="0" max="65535" value="<?= (int) $image['display_order'] ?>" required></label>
              <label>Replace image (optional)<input name="image" type="file" accept="image/jpeg,image/png"></label>
              <label class="admin-check"><input name="published" type="checkbox" value="1" <?= $image['published'] ? 'checked' : '' ?>> Published</label>
              <button class="button button-dark full" type="submit">Save gallery image</button>
            </form>
            <form class="admin-delete-form" data-admin-form action="/api/admin/gallery.php" method="post">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $image['id'] ?>">
              <button class="button button-danger" type="submit">Delete gallery image</button>
            </form>
          </details>
        <?php endforeach; ?>
        <?php if (!$gallery): ?><p class="empty-state">No gallery images have been added.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-section">
      <h2>Plot bookings</h2>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Reference</th><th>Customer</th><th>Project / plot</th><th>Amount</th><th>Expires (UTC)</th><th>Status / action</th></tr></thead><tbody>
      <?php foreach ($bookings as $booking): ?>
        <tr><td><?= Security::escape($booking['booking_reference']) ?></td><td><?= Security::escape($booking['full_name']) ?> · <?= Security::escape($booking['phone']) ?></td>
          <td><?= Security::escape($booking['project_name'] . ' / ' . $booking['plot_number']) ?></td><td>₹<?= number_format((float) $booking['total_price'], 2) ?></td>
          <td><?= Security::escape($booking['reservation_expires_at']) ?></td><td>
          <?php if (in_array($booking['status'], ['reserved','pending_approval'], true)): ?>
            <form data-admin-form action="/api/admin/bookings.php" method="post">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="id" value="<?= (int) $booking['id'] ?>">
              <input type="hidden" name="action" value="confirm">
              <button type="submit">Confirm</button>
            </form>
            <form data-admin-form action="/api/admin/bookings.php" method="post">
              <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
              <input type="hidden" name="id" value="<?= (int) $booking['id'] ?>">
              <input type="hidden" name="action" value="cancel">
              <button type="submit">Cancel</button>
            </form>
          <?php else: ?><?= Security::escape($booking['status']) ?><?php endif; ?>
          </td></tr>
      <?php endforeach; ?>
      <?php if (!$bookings): ?><tr><td colspan="6">No bookings yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="admin-section">
      <h2>Collection-based commission rules</h2>
      <p><a class="button" href="/admin/commission-report.php">Open commission reports and CSV export</a></p>
      <p>Commissions are calculated only from verified customer collections at the agreement and registration milestones. Booking confirmation alone does not create commission. Employee shares may total up to 12%; missing hierarchy shares remain with the company.</p>
      <form class="inline-admin-form" data-admin-form action="/api/admin/commission-rules.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <?php foreach ($commissionRates as $recipientCode => $percentage): ?>
          <label><?= Security::escape(ucwords(str_replace('_', ' ', $recipientCode))) ?> (%)<input type="number" name="rates[<?= Security::escape($recipientCode) ?>]" value="<?= Security::escape($percentage) ?>" min="0" max="12" step="0.01" required></label>
        <?php endforeach; ?>
        <p>Employee allocation: <?= number_format($employeeCommissionRate, 2) ?>% · Company retention: <?= Security::escape($companyRetentionRate) ?>%</p>
        <button type="submit">Save new version</button>
      </form>

      <h3>Record customer collection</h3>
      <?php if ($confirmedBookings): ?>
      <form class="admin-form admin-grid" data-admin-form action="/api/admin/commission-workflow.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="record_collection">
        <label>Confirmed booking<select name="booking_id" required><?php foreach ($confirmedBookings as $booking): ?><option value="<?= (int) $booking['id'] ?>"><?= Security::escape($booking['booking_reference'] . ' · ' . $booking['full_name'] . ' · Plot ' . $booking['plot_number']) ?></option><?php endforeach; ?></select></label>
        <label>Actual amount received (₹)<input type="number" name="amount" min="0.01" step="0.01" required></label>
        <label>Received at<input type="datetime-local" name="received_at" value="<?= Security::escape((new DateTimeImmutable('now', new DateTimeZone($app['timezone'])))->format('Y-m-d\TH:i')) ?>" required></label>
        <label>Payment method<input name="payment_method" maxlength="50"></label>
        <label>Payment reference<input name="payment_reference" maxlength="150"></label>
        <button class="button button-dark full" type="submit">Record for verification</button>
      </form>
      <?php else: ?><p class="empty-state">Only confirmed bookings can receive collection entries.</p><?php endif; ?>

      <h3>Collections awaiting verification</h3>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Customer / booking</th><th>Received</th><th>Amount</th><th>Reference</th><th>Review</th></tr></thead><tbody>
      <?php foreach ($pendingCollections as $collection): ?>
        <tr><td><?= Security::escape($collection['full_name']) ?><br><?= Security::escape($collection['booking_reference']) ?> · Plot <?= Security::escape($collection['plot_number']) ?></td>
          <td><?= Security::escape($collection['received_at']) ?> UTC</td><td>₹<?= number_format((float) $collection['amount'], 2) ?></td>
          <td><?= Security::escape(trim((string) $collection['payment_method'] . ' ' . (string) $collection['payment_reference'])) ?></td>
          <td><form data-admin-form action="/api/admin/commission-workflow.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="action" value="review_collection"><input type="hidden" name="collection_id" value="<?= (int) $collection['id'] ?>">
            <input type="hidden" name="status" value="verified"><button type="submit">Verify collection</button>
          </form>
          <form data-admin-form action="/api/admin/commission-workflow.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="action" value="review_collection"><input type="hidden" name="collection_id" value="<?= (int) $collection['id'] ?>">
            <input type="hidden" name="status" value="rejected"><input name="remarks" maxlength="1000" placeholder="Rejection reason" required><button type="submit">Reject</button>
          </form></td></tr>
      <?php endforeach; ?>
      <?php if (!$pendingCollections): ?><tr><td colspan="5">No collections awaiting verification.</td></tr><?php endif; ?>
      </tbody></table></div>

      <h3>Record agreement or registration milestone</h3>
      <?php if ($confirmedBookings): ?>
      <form class="admin-form admin-grid" data-admin-form action="/api/admin/commission-workflow.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="complete_milestone">
        <label>Confirmed booking<select name="booking_id" required><?php foreach ($confirmedBookings as $booking): ?><option value="<?= (int) $booking['id'] ?>"><?= Security::escape($booking['booking_reference'] . ' · ' . $booking['full_name'] . ' · Plot ' . $booking['plot_number']) ?></option><?php endforeach; ?></select></label>
        <label>Milestone<select name="stage" required><option value="agreement">Agreement</option><option value="registration">Registration</option></select></label>
        <label>Completed at<input type="datetime-local" name="completed_at" value="<?= Security::escape((new DateTimeImmutable('now', new DateTimeZone($app['timezone'])))->format('Y-m-d\TH:i')) ?>" required></label>
        <label>Remarks<input name="remarks" maxlength="1000"></label>
        <button class="button button-dark full" type="submit">Create commission batch</button>
      </form>
      <?php endif; ?>

      <h3>Commission batches awaiting review</h3>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Stage</th><th>Booking / customer</th><th>Collections to date</th><th>Eligible this stage</th><th>Recipients / total</th><th>Review</th></tr></thead><tbody>
      <?php foreach ($pendingBatches as $batch): ?>
        <tr><td><?= Security::escape(ucfirst($batch['stage'])) ?><br>Rule v<?= (int) $batch['rule_version'] ?></td>
          <td><?= Security::escape($batch['booking_reference']) ?><br><?= Security::escape($batch['full_name']) ?> · Plot <?= Security::escape($batch['plot_number']) ?></td>
          <td>₹<?= number_format((float) $batch['collected_to_date'], 2) ?></td>
          <td>₹<?= number_format((float) $batch['eligible_collection'], 2) ?></td>
          <td><?= (int) $batch['recipient_count'] ?> · ₹<?= number_format((float) $batch['total_commission'], 2) ?></td>
          <td><form data-admin-form action="/api/admin/commission-workflow.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="action" value="review_batch"><input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
            <input type="hidden" name="decision" value="approve"><button type="submit">Approve and credit</button>
          </form>
          <form data-admin-form action="/api/admin/commission-workflow.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="action" value="review_batch"><input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
            <input type="hidden" name="decision" value="hold"><input name="remarks" maxlength="1000" placeholder="Hold reason" required><button type="submit">Hold</button>
          </form>
          <form data-admin-form action="/api/admin/commission-workflow.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="action" value="review_batch"><input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
            <input type="hidden" name="decision" value="reject"><input name="remarks" maxlength="1000" placeholder="Rejection reason" required><button type="submit">Reject</button>
          </form></td></tr>
      <?php endforeach; ?>
      <?php if (!$pendingBatches): ?><tr><td colspan="6">No commission batches awaiting approval.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="admin-section">
      <h2>Employee withdrawals</h2>
      <p>Reveal sensitive payout details only when needed to review or pay a request.</p>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Employee</th><th>Requested</th><th>Amount</th><th>Payout details</th><th>Review / payment</th></tr></thead><tbody>
      <?php foreach ($withdrawalRows as $withdrawal): ?>
        <tr><td><?= Security::escape($withdrawal['full_name']) ?></td><td><?= Security::escape($withdrawal['requested_at']) ?></td><td>₹<?= number_format((float) $withdrawal['amount'], 2) ?></td>
          <td>
            <?php if (in_array($withdrawal['status'], ['requested','approved'], true)): ?>
              <form data-withdrawal-details action="/api/admin/withdrawal-details.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= (int) $withdrawal['id'] ?>">
                <button type="submit">Reveal details</button>
              </form>
              <span data-withdrawal-output aria-live="polite"></span>
            <?php else: ?><?= Security::escape((string) $withdrawal['payment_reference']) ?><?php endif; ?>
          </td>
          <td>
            <?php if ($withdrawal['status'] === 'requested'): ?>
              <?php foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $withdrawalAction => $label): ?>
                <form data-admin-form action="/api/admin/withdrawals.php" method="post">
                  <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= (int) $withdrawal['id'] ?>">
                  <input type="hidden" name="action" value="<?= $withdrawalAction ?>">
                  <button type="submit"><?= $label ?></button>
                </form>
              <?php endforeach; ?>
            <?php elseif ($withdrawal['status'] === 'approved'): ?>
              <form data-admin-form action="/api/admin/withdrawals.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= (int) $withdrawal['id'] ?>">
                <input type="hidden" name="action" value="pay">
                <label>Payment reference<input name="payment_reference" maxlength="100" required></label>
                <button type="submit">Mark paid</button>
              </form>
            <?php else: ?><?= Security::escape($withdrawal['status']) ?><?php endif; ?>
          </td></tr>
      <?php endforeach; ?>
      <?php if (!$withdrawalRows): ?><tr><td colspan="5">No withdrawal requests.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="admin-section">
      <h2>Recent enquiries</h2>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Customer</th><th>Phone</th><th>Type</th><th>Received</th><th>Assign / status</th></tr></thead><tbody>
      <?php foreach ($leads as $lead): ?>
        <tr><td><?= Security::escape($lead['full_name']) ?></td><td><?= Security::escape($lead['phone']) ?></td><td><?= Security::escape($lead['enquiry_type']) ?></td><td><?= Security::escape($lead['created_at']) ?></td>
          <td><form class="visit-update-form" data-admin-form action="/api/admin/leads.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
            <select name="status"><?php foreach (['new','contacted','qualified','converted','lost'] as $leadStatus): ?><option value="<?= $leadStatus ?>" <?= $lead['status'] === $leadStatus ? 'selected' : '' ?>><?= Security::escape($leadStatus) ?></option><?php endforeach; ?></select>
            <select name="assigned_to"><option value="">Unassigned</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (int) $lead['assigned_to'] === (int) $employee['id'] ? 'selected' : '' ?>><?= Security::escape($employee['full_name'] . ' (' . $employee['role_name'] . ')') ?></option><?php endforeach; ?></select>
            <select name="lead_owner_id"><option value="">Owner follows first assignment</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (int) $lead['lead_owner_id'] === (int) $employee['id'] ? 'selected' : '' ?>>Owner: <?= Security::escape($employee['full_name'] . ' (' . $employee['role_name'] . ')') ?></option><?php endforeach; ?></select>
            <input name="owner_change_reason" maxlength="1000" placeholder="Reason required to change existing owner">
            <button type="submit">Save</button>
          </form></td></tr>
      <?php endforeach; ?>
      <?php if (!$leads): ?><tr><td colspan="5">No enquiries received.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="admin-section">
      <h2>Site visit requests</h2>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Customer</th><th>Phone</th><th>Project</th><th>Visit details</th><th>Update visit, logistics and notification</th></tr></thead><tbody>
      <?php foreach ($visits as $visit): ?>
        <tr>
          <td><?= Security::escape($visit['full_name']) ?></td><td><?= Security::escape($visit['phone']) ?></td><td><?= Security::escape($visit['project_name']) ?></td>
          <td><?= Security::escape((string) ($visit['visit_address'] ?? '')) ?><br>Visitors: <?= (int) ($visit['request_details']['visitor_count'] ?? 1) ?>
            <?php if (!empty($visit['request_details']['reference_name'])): ?><br>Reference: <?= Security::escape((string) $visit['request_details']['reference_name']) ?> · <?= Security::escape((string) ($visit['request_details']['reference_contact'] ?? '')) ?><?php endif; ?>
          <td><form class="visit-update-form" data-admin-form action="/api/admin/site-visits.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="id" value="<?= (int) $visit['id'] ?>">
            <select name="status"><?php foreach (['requested','confirmed','completed','cancelled','no_show'] as $visitStatus): ?><option value="<?= $visitStatus ?>" <?= $visit['status'] === $visitStatus ? 'selected' : '' ?>><?= Security::escape($visitStatus) ?></option><?php endforeach; ?></select>
            <select name="assigned_to"><option value="">Unassigned</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (int) $visit['assigned_to'] === (int) $employee['id'] ? 'selected' : '' ?>><?= Security::escape($employee['full_name'] . ' (' . $employee['role_name'] . ')') ?></option><?php endforeach; ?></select>
            <label>Visit time<input type="datetime-local" name="preferred_start" value="<?= Security::escape($visit['preferred_start_local']) ?>" required></label>
            <label>Food preference<select name="preferred_food"><option value="unspecified" <?= $visit['preferred_food'] === 'unspecified' ? 'selected' : '' ?>>Unspecified</option><option value="veg" <?= $visit['preferred_food'] === 'veg' ? 'selected' : '' ?>>Vegetarian</option><option value="non_veg" <?= $visit['preferred_food'] === 'non_veg' ? 'selected' : '' ?>>Non-vegetarian</option></select></label>
            <label>Vehicle model<input name="vehicle_model" value="<?= Security::escape((string) $visit['vehicle_model']) ?>" maxlength="100"></label>
            <label>Vehicle number<input name="vehicle_number" value="<?= Security::escape((string) $visit['vehicle_number']) ?>" maxlength="30"></label>
            <label>Driver name<input name="driver_name" value="<?= Security::escape((string) $visit['driver_name']) ?>" maxlength="150"></label>
            <label>Driver phone<input name="driver_phone" value="<?= Security::escape((string) $visit['driver_phone']) ?>" maxlength="25"></label>
            <label>Pickup location<input name="pickup_location" value="<?= Security::escape((string) $visit['pickup_location']) ?>" maxlength="500"></label>
            <label>Instructions<input name="special_instructions" value="<?= Security::escape((string) $visit['special_instructions']) ?>" maxlength="2000"></label>
            <button type="submit">Save</button>
          </form></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$visits): ?><tr><td colspan="5">No site visit requests.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </main>
  <script src="/assets/js/admin.js" defer></script>
  <script src="/assets/js/admin-hierarchy.js" defer></script>
  <script src="/assets/js/withdrawals.js" defer></script>
</body>
</html>
