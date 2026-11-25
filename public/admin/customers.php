<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
Auth::requireAdminPage();
$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}
$pdo = Database::connection();
$statement = $pdo->prepare(
    'SELECT c.id, c.full_name, c.phone, c.email, c.address, c.created_at,
            COALESCE(l.lead_count, 0) AS lead_count, l.last_lead_at
     FROM customers c
     LEFT JOIN (
       SELECT customer_id, COUNT(*) AS lead_count, MAX(created_at) AS last_lead_at
       FROM leads GROUP BY customer_id
     ) l ON l.customer_id = c.id
     WHERE (:search_name = \'\'
       OR c.full_name LIKE :name_query
       OR c.phone LIKE :phone_query
       OR c.email LIKE :email_query)
     ORDER BY c.id DESC LIMIT 100'
);
$needle = '%' . $search . '%';
$statement->execute([
    'search_name' => $search,
    'name_query' => $needle,
    'phone_query' => $needle,
    'email_query' => $needle,
]);
$customers = $statement->fetchAll();
$csrfToken = Security::csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Customers | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header">
    <a class="brand admin-brand" href="/admin/index.php"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <a class="button button-outline" href="/admin/index.php">Dashboard</a>
  </header>
  <main class="admin-content">
    <div class="admin-title"><div><p class="section-kicker">Customer records</p><h1>Customers</h1></div></div>
    <p class="admin-message" role="status" aria-live="polite"></p>
    <form class="customer-search" method="get">
      <label for="customer-query">Search name, phone or email</label>
      <input id="customer-query" name="q" value="<?= Security::escape($search) ?>" maxlength="100">
      <button class="button button-dark" type="submit">Search</button>
    </form>
    <section class="admin-section">
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Customer contact</th><th>Address</th><th>Leads</th><th>Last enquiry (UTC)</th><th>Update contact</th></tr></thead><tbody>
      <?php foreach ($customers as $customer): ?>
        <tr><td><?= Security::escape($customer['full_name']) ?><br><?= Security::escape($customer['phone']) ?><br><?= Security::escape((string) $customer['email']) ?></td>
          <td><?= Security::escape((string) $customer['address']) ?></td><td><?= (int) $customer['lead_count'] ?></td><td><?= Security::escape((string) $customer['last_lead_at']) ?></td>
          <td><details><summary>Edit contact</summary><form class="admin-form" data-admin-form action="/api/admin/customers.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= Security::escape($csrfToken) ?>">
            <input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
            <label>Full name<input name="full_name" value="<?= Security::escape($customer['full_name']) ?>" maxlength="150" required></label>
            <label>Phone<input name="phone" value="<?= Security::escape($customer['phone']) ?>" maxlength="25" required></label>
            <label>Email<input name="email" type="email" value="<?= Security::escape((string) $customer['email']) ?>" maxlength="190"></label>
            <label>Address<textarea name="address" maxlength="500"><?= Security::escape((string) $customer['address']) ?></textarea></label>
            <button type="submit">Save contact details</button>
          </form></details></td></tr>
      <?php endforeach; ?>
      <?php if (!$customers): ?><tr><td colspan="5">No customer records found.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </main>
  <script src="/assets/js/admin.js" defer></script>
</body>
</html>
