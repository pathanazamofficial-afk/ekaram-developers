<?php
declare(strict_types=1);

final class CommissionService
{
    private const RECIPIENT_CODES = [
        'marketing_manager',
        'deputy_manager',
        'assistant_general_manager',
        'deputy_general_manager',
        'deputy_director',
    ];

    public static function createMilestoneBatch(
        PDO $pdo,
        int $bookingId,
        string $stage,
        string $completedAt,
        int $actorId,
        ?string $remarks
    ): array {
        if (!in_array($stage, ['agreement', 'registration'], true)) {
            throw new DomainException('Choose an agreement or registration milestone');
        }

        $bookingStatement = $pdo->prepare(
            'SELECT b.id, b.status, b.agent_id, b.lead_id, b.customer_id, b.plot_id,
                    COALESCE(b.lead_owner_id, l.lead_owner_id) AS lead_owner_id
             FROM bookings b
             JOIN leads l ON l.id = b.lead_id
             WHERE b.id = :id FOR UPDATE'
        );
        $bookingStatement->execute(['id' => $bookingId]);
        $booking = $bookingStatement->fetch();
        if ($booking === false || $booking['status'] !== 'confirmed') {
            throw new DomainException('Only a confirmed booking can have a commission milestone');
        }
        if ($booking['lead_owner_id'] === null) {
            throw new DomainException('Assign and lock the lead owner before recording a commission milestone');
        }

        $duplicateMilestone = $pdo->prepare(
            'SELECT id FROM booking_milestones WHERE booking_id = :booking_id AND milestone = :milestone'
        );
        $duplicateMilestone->execute(['booking_id' => $bookingId, 'milestone' => $stage]);
        if ($duplicateMilestone->fetchColumn() !== false) {
            throw new DomainException('This booking milestone has already been recorded');
        }
        if ($stage === 'registration') {
            $agreementCheck = $pdo->prepare(
                "SELECT completed_at FROM booking_milestones WHERE booking_id = :booking_id AND milestone = 'agreement'"
            );
            $agreementCheck->execute(['booking_id' => $bookingId]);
            $agreementDate = $agreementCheck->fetchColumn();
            if ($agreementDate === false) {
                throw new DomainException('Record the agreement milestone before registration');
            }
            if ($completedAt < (string) $agreementDate) {
                throw new DomainException('Registration cannot be dated before the agreement milestone');
            }
        }

        $pdo->prepare(
            'INSERT INTO booking_milestones (booking_id, milestone, completed_at, recorded_by, remarks)
             VALUES (:booking_id, :milestone, :completed_at, :actor, :remarks)'
        )->execute([
            'booking_id' => $bookingId,
            'milestone' => $stage,
            'completed_at' => $completedAt,
            'actor' => $actorId,
            'remarks' => $remarks,
        ]);
        $milestoneId = (int) $pdo->lastInsertId();

        $collectionStatement = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM plot_collections
             WHERE booking_id = :booking_id AND status = 'verified' AND received_at <= :completed_at"
        );
        $collectionStatement->execute(['booking_id' => $bookingId, 'completed_at' => $completedAt]);
        $collected = (string) $collectionStatement->fetchColumn();

        $previousEligible = '0.00';
        if ($stage === 'registration') {
            $previousStatement = $pdo->prepare(
                "SELECT COALESCE(SUM(eligible_collection), 0)
                 FROM commission_batches WHERE booking_id = :booking_id"
            );
            $previousStatement->execute(['booking_id' => $bookingId]);
            $previousEligible = (string) $previousStatement->fetchColumn();
        }
        $eligibleStatement = $pdo->prepare(
            'SELECT GREATEST(CAST(:collected AS DECIMAL(15,2)) - CAST(:previous AS DECIMAL(15,2)), 0)'
        );
        $eligibleStatement->execute(['collected' => $collected, 'previous' => $previousEligible]);
        $eligible = (string) $eligibleStatement->fetchColumn();
        if ((float) $collected < (float) $previousEligible) {
            throw new DomainException('Verified collections are lower than the amount already commissioned');
        }

        $rules = self::activeRules($pdo, $completedAt);
        $ownerId = (int) $booking['lead_owner_id'];
        $chain = self::reportingChain($pdo, $ownerId);
        $byRole = [];
        foreach ($chain as $person) {
            if ($person['active'] && in_array($person['designation_code'], self::RECIPIENT_CODES, true)) {
                $byRole[$person['designation_code']] = $person['user_id'];
            }
        }

        $pdo->prepare(
            "INSERT INTO commission_batches
             (booking_id, milestone_id, stage, collected_to_date, eligible_collection, rule_version,
              lead_owner_id, status, recorded_by)
             VALUES (:booking_id, :milestone_id, :stage, :collected, :eligible, :version, :owner_id,
                     'pending', :actor)"
        )->execute([
            'booking_id' => $bookingId,
            'milestone_id' => $milestoneId,
            'stage' => $stage,
            'collected' => $collected,
            'eligible' => $eligible,
            'version' => $rules['version'],
            'owner_id' => $ownerId,
            'actor' => $actorId,
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $insertExclusion = $pdo->prepare(
            'INSERT INTO commission_exclusions (batch_id, recipient_code, excluded_user_id, exclusion_reason)
             VALUES (:batch_id, :recipient_code, :user_id, :reason)'
        );
        $insertCommission = $pdo->prepare(
            'INSERT INTO commissions
             (booking_id, batch_id, stage, user_id, recipient_code, rule_version, base_amount,
              collection_amount, lead_owner_id, customer_id, plot_id, percentage, amount, status)
             VALUES (:booking_id, :batch_id, :stage, :user_id, :recipient_code, :version, :base_amount,
                     :collection_amount, :owner_id, :customer_id, :plot_id, :percentage, :amount, :status)'
        );
        $amountStatement = $pdo->prepare(
            'SELECT ROUND(CAST(:base AS DECIMAL(15,2)) * CAST(:percentage AS DECIMAL(5,2)) / 100, 2)'
        );
        $commissionIds = [];
        foreach (self::RECIPIENT_CODES as $recipientCode) {
            $percentage = $rules['rates'][$recipientCode];
            if (!isset($byRole[$recipientCode])) {
                $insertExclusion->execute([
                    'batch_id' => $batchId,
                    'recipient_code' => $recipientCode,
                    'user_id' => null,
                    'reason' => 'No eligible active recipient in the lead owner reporting chain',
                ]);
                continue;
            }
            if ((float) $eligible <= 0 || (float) $percentage <= 0) {
                continue;
            }
            $amountStatement->execute(['base' => $eligible, 'percentage' => $percentage]);
            $amount = (string) $amountStatement->fetchColumn();
            if ((float) $amount <= 0) {
                continue;
            }
            $insertCommission->execute([
                'booking_id' => $bookingId,
                'batch_id' => $batchId,
                'stage' => $stage,
                'user_id' => $byRole[$recipientCode],
                'recipient_code' => $recipientCode,
                'version' => $rules['version'],
                'base_amount' => $eligible,
                'collection_amount' => $eligible,
                'owner_id' => $ownerId,
                'customer_id' => (int) $booking['customer_id'],
                'plot_id' => (int) $booking['plot_id'],
                'percentage' => $percentage,
                'amount' => $amount,
                'status' => 'pending',
            ]);
            $commissionIds[] = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            "INSERT INTO commission_batch_status_history
             (batch_id, actor_user_id, old_status, new_status, remarks)
             VALUES (:batch_id, :actor, NULL, 'pending', :remarks)"
        )->execute(['batch_id' => $batchId, 'actor' => $actorId, 'remarks' => $remarks]);

        return [
            'batch_id' => $batchId,
            'milestone' => $stage,
            'collected_to_date' => $collected,
            'eligible_collection' => $eligible,
            'rule_version' => $rules['version'],
            'commission_ids' => $commissionIds,
        ];
    }

    public static function reviewBatch(
        PDO $pdo,
        int $batchId,
        string $decision,
        int $actorId,
        ?string $remarks
    ): array {
        if (!$pdo->inTransaction()) {
            throw new LogicException('Commission batch review must run inside a database transaction');
        }
        if (!in_array($decision, ['approve', 'hold', 'reject'], true)) {
            throw new DomainException('Choose an approve, hold, or reject decision');
        }

        $batchStatement = $pdo->prepare('SELECT * FROM commission_batches WHERE id = :id FOR UPDATE');
        $batchStatement->execute(['id' => $batchId]);
        $batch = $batchStatement->fetch();
        if ($batch === false || !in_array($batch['status'], ['pending', 'held'], true)) {
            throw new DomainException('Only pending or held commission batches can be reviewed');
        }
        if ($decision === 'reject' && ($remarks === null || trim($remarks) === '')) {
            throw new DomainException('A reason is required to reject a commission batch');
        }

        $newStatus = ['approve' => 'approved', 'hold' => 'held', 'reject' => 'rejected'][$decision];
        $creditedCount = 0;
        if ($decision === 'approve') {
            $commissionsStatement = $pdo->prepare(
                "SELECT c.* FROM commissions c WHERE c.batch_id = :batch_id AND c.status IN ('pending','held') FOR UPDATE"
            );
            $commissionsStatement->execute(['batch_id' => $batchId]);
            foreach ($commissionsStatement->fetchAll() as $commission) {
                self::creditCommission($pdo, $commission, $actorId);
                $creditedCount++;
            }
            $pdo->prepare(
                "UPDATE commissions SET status = 'approved', approved_by = :actor
                 WHERE batch_id = :batch_id AND status IN ('pending','held')"
            )->execute(['actor' => $actorId, 'batch_id' => $batchId]);
        } else {
            $pdo->prepare(
                'UPDATE commissions SET status = :status
                 WHERE batch_id = :batch_id AND status IN (\'pending\',\'held\')'
            )->execute(['status' => $newStatus, 'batch_id' => $batchId]);
        }

        $pdo->prepare(
            'UPDATE commission_batches
             SET status = :status, admin_remarks = :remarks, reviewed_by = :actor, reviewed_at = UTC_TIMESTAMP()
             WHERE id = :id'
        )->execute([
            'status' => $newStatus,
            'remarks' => $remarks,
            'actor' => $actorId,
            'id' => $batchId,
        ]);
        $pdo->prepare(
            'INSERT INTO commission_batch_status_history
             (batch_id, actor_user_id, old_status, new_status, remarks)
             VALUES (:batch_id, :actor, :old_status, :new_status, :remarks)'
        )->execute([
            'batch_id' => $batchId,
            'actor' => $actorId,
            'old_status' => $batch['status'],
            'new_status' => $newStatus,
            'remarks' => $remarks,
        ]);
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
             VALUES (:actor, :action, \'commission_batch\', :entity_id, :before_data, :after_data)'
        )->execute([
            'actor' => $actorId,
            'action' => 'commission_batch_' . $newStatus,
            'entity_id' => $batchId,
            'before_data' => json_encode(['status' => $batch['status']], JSON_THROW_ON_ERROR),
            'after_data' => json_encode(['status' => $newStatus, 'remarks' => $remarks], JSON_THROW_ON_ERROR),
        ]);

        return ['status' => $newStatus, 'credited_count' => $creditedCount];
    }

    private static function creditCommission(PDO $pdo, array $commission, int $adminId): void
    {
        if ($commission['stage'] === 'booking' || $commission['batch_id'] === null) {
            throw new DomainException('Booking-stage commission cannot be credited');
        }
        $recipientCheck = $pdo->prepare(
            'SELECT u.account_status, r.code AS account_type, ma.approval_status
             FROM users u JOIN roles r ON r.id = u.role_id
             JOIN marketing_associates ma ON ma.user_id = u.id
             WHERE u.id = :user_id FOR UPDATE'
        );
        $recipientCheck->execute(['user_id' => (int) $commission['user_id']]);
        $recipient = $recipientCheck->fetch();
        if ($recipient === false || $recipient['account_status'] !== 'active'
            || $recipient['account_type'] !== 'marketing_associate'
            || $recipient['approval_status'] !== 'approved'
        ) {
            throw new DomainException('A commission recipient is inactive or not an approved Marketing Associate');
        }
        $walletInsert = $pdo->prepare(
            'INSERT INTO wallets (user_id) VALUES (:user_id)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
        );
        $walletInsert->execute(['user_id' => (int) $commission['user_id']]);
        $walletStatement = $pdo->prepare('SELECT id FROM wallets WHERE user_id = :user_id FOR UPDATE');
        $walletStatement->execute(['user_id' => (int) $commission['user_id']]);
        $wallet = $walletStatement->fetch();
        if ($wallet === false) {
            throw new RuntimeException('Unable to access the commission recipient wallet');
        }

        $idempotencyKey = 'commission:' . (int) $commission['id'] . ':credit';
        $existing = $pdo->prepare('SELECT id FROM wallet_transactions WHERE idempotency_key = :key');
        $existing->execute(['key' => $idempotencyKey]);
        if ($existing->fetchColumn() !== false) {
            throw new DomainException('A wallet credit already exists for a commission in this batch');
        }
        $pdo->prepare('UPDATE wallets SET cached_balance = cached_balance + :amount WHERE id = :id')
            ->execute(['amount' => $commission['amount'], 'id' => (int) $wallet['id']]);
        $balanceStatement = $pdo->prepare('SELECT cached_balance FROM wallets WHERE id = :id');
        $balanceStatement->execute(['id' => (int) $wallet['id']]);
        $balanceAfter = $balanceStatement->fetchColumn();
        $pdo->prepare(
            "INSERT INTO wallet_transactions
             (wallet_id, transaction_type, amount, balance_after, reference_type, reference_id, idempotency_key, description)
             VALUES (:wallet_id, 'credit', :amount, :balance, 'commission', :reference_id, :idempotency_key, :description)"
        )->execute([
            'wallet_id' => (int) $wallet['id'],
            'amount' => $commission['amount'],
            'balance' => $balanceAfter,
            'reference_id' => (int) $commission['id'],
            'idempotency_key' => $idempotencyKey,
            'description' => 'Approved ' . $commission['stage'] . ' commission for booking #' . (int) $commission['booking_id'],
        ]);
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_data)
             VALUES (:actor, \'commission_wallet_credited\', \'commission\', :entity_id, :after_data)'
        )->execute([
            'actor' => $adminId,
            'entity_id' => (int) $commission['id'],
            'after_data' => json_encode(['amount' => $commission['amount'], 'batch_id' => $commission['batch_id']], JSON_THROW_ON_ERROR),
        ]);
        $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, target_type, target_id)
             VALUES (:user_id, :title, :message, \'commission\', :target_id)'
        )->execute([
            'user_id' => (int) $commission['user_id'],
            'title' => 'Commission credited',
            'message' => '₹' . number_format((float) $commission['amount'], 2) . ' was credited for the '
                . $commission['stage'] . ' milestone.',
            'target_id' => (int) $commission['id'],
        ]);
    }

    private static function activeRules(PDO $pdo, string $effectiveAt): array
    {
        $statement = $pdo->prepare(
            'SELECT version_no, recipient_code, percentage
             FROM commission_rule_versions
             WHERE version_no = (
                 SELECT version_no FROM commission_rule_versions
                 WHERE effective_from <= :effective_at
                 ORDER BY version_no DESC LIMIT 1
             )'
        );
        $statement->execute(['effective_at' => $effectiveAt]);
        $rows = $statement->fetchAll();
        if (count($rows) !== 6) {
            throw new RuntimeException('The effective commission version must contain five tiers and company retention');
        }

        $rates = [];
        $companyRate = null;
        $version = (int) $rows[0]['version_no'];
        $totalBasisPoints = 0;
        foreach ($rows as $row) {
            if ((int) $row['version_no'] !== $version) {
                throw new RuntimeException('Commission configuration contains inconsistent versions');
            }
            $code = (string) $row['recipient_code'];
            $basisPoints = self::basisPoints((string) $row['percentage']);
            if ($basisPoints > 10000) {
                throw new RuntimeException('Commission rates cannot exceed 100%');
            }
            if ($code === 'company') {
                $companyRate = $basisPoints;
            } elseif (in_array($code, self::RECIPIENT_CODES, true) && !isset($rates[$code])) {
                $rates[$code] = (string) $row['percentage'];
                $totalBasisPoints += $basisPoints;
            } else {
                throw new RuntimeException('Commission configuration has invalid recipient tiers');
            }
        }
        if (count($rates) !== count(self::RECIPIENT_CODES) || $companyRate === null
            || $totalBasisPoints > 1200 || $totalBasisPoints + $companyRate !== 10000
        ) {
            throw new RuntimeException('Employee commission rates must total at most 12% and company retention must be the remainder');
        }

        return ['version' => $version, 'rates' => $rates];
    }

    private static function reportingChain(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            "SELECT u.id, u.account_status, r.code AS account_type,
                    ma.approval_status, ma.designation_code, map.reporting_manager_id AS manager_id
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN marketing_associates ma ON ma.user_id = u.id
             LEFT JOIN marketing_associate_profiles map ON map.user_id = u.id
             WHERE u.id = :id"
        );
        $chain = [];
        $seen = [];
        $currentId = $userId;
        for ($depth = 0; $depth < 8 && $currentId > 0; $depth++) {
            if (isset($seen[$currentId])) {
                throw new RuntimeException('Reporting hierarchy contains a cycle');
            }
            $seen[$currentId] = true;
            $statement->execute(['id' => $currentId]);
            $row = $statement->fetch();
            if ($row === false || $row['account_type'] === 'admin') {
                break;
            }
            $chain[] = [
                'user_id' => (int) $row['id'],
                'designation_code' => (string) ($row['designation_code'] ?? ''),
                'active' => $row['account_type'] === 'marketing_associate'
                    && $row['approval_status'] === 'approved'
                    && $row['account_status'] === 'active',
            ];
            $currentId = (int) ($row['manager_id'] ?? 0);
        }
        if ($currentId > 0 && count($seen) >= 8) {
            throw new RuntimeException('Reporting hierarchy exceeds the supported depth');
        }

        return $chain;
    }

    private static function basisPoints(string $percentage): int
    {
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $percentage, $matches)) {
            throw new RuntimeException('Invalid commission percentage format');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '0', 2, '0');
    }
}
