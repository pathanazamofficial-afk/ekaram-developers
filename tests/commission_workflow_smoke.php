<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
if ($pdo->inTransaction()) {
    throw new RuntimeException('Run this smoke test without an existing transaction');
}

$pdo->beginTransaction();
try {
    $rulesStatement = $pdo->query(
        'SELECT recipient_code, percentage FROM commission_rule_versions
         WHERE version_no = (
           SELECT version_no FROM commission_rule_versions
           WHERE effective_from <= UTC_TIMESTAMP() ORDER BY version_no DESC LIMIT 1
         )'
    );
    $activeRates = [];
    foreach ($rulesStatement->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        $activeRates[$rule['recipient_code']] = (float) $rule['percentage'];
    }
    $employeeRateTotal = 0.0;
    foreach (['marketing_manager', 'deputy_manager', 'assistant_general_manager', 'deputy_general_manager', 'deputy_director'] as $code) {
        if (!isset($activeRates[$code])) {
            throw new RuntimeException('Active commission version is missing tier ' . $code);
        }
        $employeeRateTotal += $activeRates[$code];
    }
    if ($employeeRateTotal > 12.0) {
        throw new RuntimeException('Active employee commission rates exceed the 12% pool');
    }

    $designationCodes = ['deputy_director', 'deputy_general_manager', 'assistant_general_manager', 'deputy_manager', 'marketing_manager'];
    $associateRoleId = $pdo->query("SELECT id FROM roles WHERE code = 'marketing_associate'")->fetchColumn();
    if ($associateRoleId === false) {
        throw new RuntimeException('Missing common Marketing Associate account role');
    }
    $roleIds = array_fill_keys($designationCodes, (int) $associateRoleId);

    $userInsert = $pdo->prepare(
        'INSERT INTO users (role_id, full_name, phone, password_hash, account_status)
         VALUES (:role_id, :name, :phone, :password, :status)'
    );
    $associateInsert = $pdo->prepare(
        'INSERT INTO marketing_associates (user_id, designation_code, approval_status)
         VALUES (:user_id, :designation, :status)'
    );
    $profileInsert = $pdo->prepare(
        'INSERT INTO marketing_associate_profiles (user_id, reporting_manager_id)
         VALUES (:user_id, :manager_id)'
    );
    $sequence = random_int(10000000, 99999999);
    $userIds = [];
    foreach (array_keys($roleIds) as $code) {
        $userInsert->execute([
            'role_id' => $roleIds[$code],
            'name' => 'Commission smoke test ' . $code,
            'phone' => '+1999' . $sequence++,
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'status' => 'active',
        ]);
        $userIds[$code] = (int) $pdo->lastInsertId();
        $associateInsert->execute([
            'user_id' => $userIds[$code],
            'designation' => $code,
            'status' => 'approved',
        ]);
        $rank = ['deputy_director' => null, 'deputy_general_manager' => 'deputy_director',
            'assistant_general_manager' => 'deputy_general_manager', 'deputy_manager' => 'assistant_general_manager',
            'marketing_manager' => 'deputy_manager'][$code];
        $profileInsert->execute([
            'user_id' => $userIds[$code],
            'manager_id' => $rank === null ? null : $userIds[$rank],
        ]);
        if (!MarketingAssociateHierarchy::assignmentIsValid(
            $pdo,
            $code,
            $rank === null ? null : $userIds[$rank],
            $userIds[$code]
        )) {
            throw new RuntimeException('An approved Marketing Associate has an invalid designation/reporting assignment');
        }
    }
    if (MarketingAssociateHierarchy::assignmentIsValid(
        $pdo,
        'marketing_manager',
        $userIds['deputy_director'],
        $userIds['marketing_manager']
    )) {
        throw new RuntimeException('A Marketing Manager must not report directly to the Deputy Director');
    }

    $userInsert->execute([
        'role_id' => (int) $associateRoleId,
        'name' => 'Commission smoke test invitee',
        'phone' => '+1777' . $sequence++,
        'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'status' => 'pending',
    ]);
    $associateUserId = (int) $pdo->lastInsertId();
    $associateInsert->execute([
        'user_id' => $associateUserId,
        'designation' => null,
        'status' => 'pending',
    ]);
    $profileInsert->execute(['user_id' => $associateUserId, 'manager_id' => null]);
    $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute(['id' => $associateUserId]);
    $pdo->prepare(
        "UPDATE marketing_associates SET approval_status = 'approved', designation_code = 'marketing_manager'
         WHERE user_id = :id"
    )->execute(['id' => $associateUserId]);
    Security::startSession();
    $savedSession = $_SESSION;
    $_SESSION['user_id'] = $associateUserId;
    $_SESSION['role_code'] = 'marketing_associate';
    if ((int) Auth::requireMarketingAssociateApi()['id'] !== $associateUserId) {
        throw new RuntimeException('An approved designated associate without a manager cannot access the portal');
    }
    $_SESSION = $savedSession;
    $pdo->prepare("UPDATE users SET account_status = 'pending' WHERE id = :id")->execute(['id' => $associateUserId]);
    $pdo->prepare(
        "UPDATE marketing_associates SET approval_status = 'pending', designation_code = NULL WHERE user_id = :id"
    )->execute(['id' => $associateUserId]);
    $visibleUsers = Auth::visibleUserIds($userIds['deputy_director']);
    foreach ($userIds as $teamUserId) {
        if (!in_array($teamUserId, $visibleUsers, true)) {
            throw new RuntimeException('The common Marketing Associate reporting chain omitted an approved team member');
        }
    }
    $boundary = 9;
    $boundaryCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id IN (:parent_id, :child_id)');
    do {
        $boundaryCheck->execute(['parent_id' => $boundary, 'child_id' => $boundary + 1]);
        if ((int) $boundaryCheck->fetchColumn() === 0) {
            break;
        }
        $boundary = $boundary * 10 + 9;
    } while (true);
    $boundaryInsert = $pdo->prepare(
        'INSERT INTO users (id, role_id, full_name, phone, password_hash, account_status)
         VALUES (:id, :role_id, :name, :phone, :password, :status)'
    );
    foreach ([$boundary, $boundary + 1] as $index => $id) {
        $boundaryInsert->execute([
            'id' => $id,
            'role_id' => (int) $associateRoleId,
            'name' => 'Commission smoke test boundary ' . $index,
            'phone' => '+1888' . $sequence++,
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'status' => 'active',
        ]);
        $profileInsert->execute(['user_id' => $id, 'manager_id' => $index === 0 ? null : $boundary]);
    }
    if (!in_array($boundary + 1, Auth::visibleUserIds($boundary), true)) {
        throw new RuntimeException('The reporting chain omitted a user whose ID has more digits than their manager');
    }
    $inviteToken = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO recruitment_invites
         (manager_user_id, target_role_code, suggested_designation_code, token_hash, expires_at)
         VALUES (:manager_id, :target_role, NULL, :token_hash, UTC_TIMESTAMP() + INTERVAL 7 DAY)'
    )->execute([
        'manager_id' => $userIds['marketing_manager'],
        'target_role' => 'marketing_associate',
        'token_hash' => hash('sha256', $inviteToken, true),
    ]);
    $inviteId = (int) $pdo->lastInsertId();
    $inviteStatement = $pdo->prepare(
        "SELECT id, manager_user_id, target_role_code, suggested_designation_code
         FROM recruitment_invites
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()"
    );
    $inviteStatement->execute(['token_hash' => hash('sha256', $inviteToken, true)]);
    $invite = $inviteStatement->fetch();
    if ($invite === false
        || (int) $invite['manager_user_id'] !== $userIds['marketing_manager']
        || $invite['target_role_code'] !== 'marketing_associate'
        || $invite['suggested_designation_code'] !== null
    ) {
        throw new RuntimeException('The one-time Marketing Associate invitation was not stored correctly');
    }
    $pdo->prepare(
        'UPDATE recruitment_invites SET used_by = :user_id, used_at = UTC_TIMESTAMP() WHERE id = :id'
    )->execute(['user_id' => $associateUserId, 'id' => $inviteId]);
    $inviteStatement->execute(['token_hash' => hash('sha256', $inviteToken, true)]);
    if ($inviteStatement->fetch() !== false) {
        throw new RuntimeException('A consumed recruitment invitation remained usable');
    }

    $pdo->prepare(
        "INSERT INTO projects (name, slug, location, total_land_area, price_per_sqyd, status)
         VALUES (:name, :slug, 'Smoke test only', 1, 22000, 'published')"
    )->execute(['name' => 'Commission smoke test ' . $sequence, 'slug' => 'commission-smoke-' . $sequence]);
    $projectId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO plots (project_id, plot_number, dimensions, area_sqyd, status, total_price)
         VALUES (:project_id, 'SMOKE', '10 x 10', 100, 'available', 100000)"
    )->execute(['project_id' => $projectId]);
    $plotId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO customers (full_name, phone, consent_at) VALUES ('Commission smoke test', :phone, UTC_TIMESTAMP())"
    )->execute(['phone' => '+1888' . $sequence]);
    $customerId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO leads (customer_id, project_id, source, enquiry_type, interest, status, assigned_to,
                            lead_owner_id, ownership_locked_at, created_by)
         VALUES (:customer_id, :project_id, 'smoke_test', 'booking_request', 'plot', 'new',
                 :assigned_to, :owner_id, UTC_TIMESTAMP(), :creator)"
    )->execute([
        'customer_id' => $customerId,
        'project_id' => $projectId,
        'assigned_to' => $userIds['marketing_manager'],
        'owner_id' => $userIds['marketing_manager'],
        'creator' => $userIds['marketing_manager'],
    ]);
    $leadId = (int) $pdo->lastInsertId();
    $reservation = BookingService::reserve($pdo, $userIds['marketing_manager'], $leadId, $plotId);
    $bookingId = (int) $reservation['id'];
    $pdo->prepare(
        "UPDATE bookings SET status = 'confirmed', confirmed_at = UTC_TIMESTAMP() WHERE id = :id"
    )->execute(['id' => $bookingId]);
    $pdo->prepare("UPDATE plots SET status = 'booked' WHERE id = :id")->execute(['id' => $plotId]);
    $commissionCount = $pdo->prepare('SELECT COUNT(*) FROM commissions WHERE booking_id = :booking_id');
    $commissionCount->execute(['booking_id' => $bookingId]);
    if ((int) $commissionCount->fetchColumn() !== 0) {
        throw new RuntimeException('Booking confirmation must not create commission entries');
    }
    $collectionInsert = $pdo->prepare(
        "INSERT INTO plot_collections (booking_id, amount, received_at, status, recorded_by, verified_by, verified_at)
         VALUES (:booking_id, :amount, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND), 'verified', :recorded_by, :verified_by, UTC_TIMESTAMP())"
    );
    $collectionInsert->execute([
        'booking_id' => $bookingId,
        'amount' => '50000.00',
        'recorded_by' => $userIds['marketing_manager'],
        'verified_by' => $userIds['marketing_manager'],
    ]);
    $completedAt = gmdate('Y-m-d H:i:s');
    $agreement = CommissionService::createMilestoneBatch(
        $pdo,
        $bookingId,
        'agreement',
        $completedAt,
        $userIds['marketing_manager'],
        'Smoke test'
    );
    $agreementLines = $pdo->prepare('SELECT COUNT(*), SUM(amount) FROM commissions WHERE batch_id = :batch_id');
    $agreementLines->execute(['batch_id' => $agreement['batch_id']]);
    [$agreementCount, $agreementAmount] = $agreementLines->fetch(PDO::FETCH_NUM);
    $expectedAgreementAmount = round(50000 * $employeeRateTotal / 100, 2);
    if ((int) $agreementCount !== count(array_filter($activeRates, static fn(float $rate, string $code): bool =>
            $code !== 'company' && $rate > 0, ARRAY_FILTER_USE_BOTH))
        || (float) $agreementAmount !== $expectedAgreementAmount
        || (float) $agreement['eligible_collection'] !== 50000.0
    ) {
        throw new RuntimeException('Agreement commission did not calculate from the verified collection');
    }

    $collectionInsert->execute([
        'booking_id' => $bookingId,
        'amount' => '25000.00',
        'recorded_by' => $userIds['marketing_manager'],
        'verified_by' => $userIds['marketing_manager'],
    ]);
    $registration = CommissionService::createMilestoneBatch(
        $pdo,
        $bookingId,
        'registration',
        $completedAt,
        $userIds['marketing_manager'],
        'Smoke test'
    );
    $registrationLines = $pdo->prepare('SELECT COUNT(*), SUM(amount) FROM commissions WHERE batch_id = :batch_id');
    $registrationLines->execute(['batch_id' => $registration['batch_id']]);
    [$registrationCount, $registrationAmount] = $registrationLines->fetch(PDO::FETCH_NUM);
    $expectedRegistrationAmount = round(25000 * $employeeRateTotal / 100, 2);
    if ((int) $registrationCount !== count(array_filter($activeRates, static fn(float $rate, string $code): bool =>
            $code !== 'company' && $rate > 0, ARRAY_FILTER_USE_BOTH))
        || (float) $registrationAmount !== $expectedRegistrationAmount
        || (float) $registration['eligible_collection'] !== 25000.0
    ) {
        throw new RuntimeException('Registration commission did not calculate only the new verified collection');
    }

    $adminId = $pdo->query(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.code = 'admin' ORDER BY u.id LIMIT 1"
    )->fetchColumn();
    if ($adminId === false) {
        throw new RuntimeException('An admin account is required to test batch wallet credit');
    }
    $held = CommissionService::reviewBatch(
        $pdo,
        (int) $registration['batch_id'],
        'hold',
        (int) $adminId,
        'Smoke test hold'
    );
    if ($held['status'] !== 'held' || $held['credited_count'] !== 0) {
        throw new RuntimeException('A held commission batch must not credit the wallet');
    }
    $approved = CommissionService::reviewBatch(
        $pdo,
        (int) $agreement['batch_id'],
        'approve',
        (int) $adminId,
        'Smoke test approval'
    );
    if ($approved['status'] !== 'approved' || $approved['credited_count'] !== (int) $agreementCount) {
        throw new RuntimeException('Approving a batch did not credit every commission exactly once');
    }
    $transactionStatement = $pdo->prepare(
        "SELECT COUNT(*), SUM(wt.amount) FROM wallet_transactions wt
         JOIN commissions cm ON cm.id = wt.reference_id
         WHERE cm.batch_id = :batch_id AND wt.reference_type = 'commission'"
    );
    $transactionStatement->execute(['batch_id' => $agreement['batch_id']]);
    [$creditCount, $creditAmount] = $transactionStatement->fetch(PDO::FETCH_NUM);
    if ((int) $creditCount !== (int) $agreementCount || (float) $creditAmount !== $expectedAgreementAmount) {
        throw new RuntimeException('Wallet ledger credits do not match the approved commission batch');
    }
    CommissionService::reviewBatch(
        $pdo,
        (int) $registration['batch_id'],
        'approve',
        (int) $adminId,
        'Smoke test release'
    );

    try {
        CommissionService::createMilestoneBatch(
            $pdo,
            $bookingId,
            'agreement',
            $completedAt,
            $userIds['marketing_manager'],
            'Duplicate smoke test'
        );
        throw new RuntimeException('Duplicate agreement milestone was unexpectedly accepted');
    } catch (DomainException $expected) {
        if ($expected->getMessage() !== 'This booking milestone has already been recorded') {
            throw $expected;
        }
    }

    echo "PASS: unified-account recruitment invitations are hashed and single-use; designation hierarchy is visible; booking-stage commission is absent; milestones accrue only new verified collections; batch approval/hold and ledger credits are transactional; duplicate milestone rejected." . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
