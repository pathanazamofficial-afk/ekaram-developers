<?php
declare(strict_types=1);

final class MarketingAssociateWalletService
{
    public static function credit(
        PDO $pdo,
        int $associateUserId,
        int $adminId,
        int $amountMinor,
        string $remarks,
        string $idempotencyToken,
        ?string $ipAddress
    ): void {
        if ($amountMinor < 1 || $remarks === '' || mb_strlen($remarks) > 500
            || !preg_match('/^[a-f0-9]{32}$/', $idempotencyToken)
        ) {
            throw new InvalidArgumentException('Enter a positive credit amount and a description of 500 characters or fewer');
        }

        $pdo->beginTransaction();
        try {
            $wallet = self::lockApprovedAssociateWallet($pdo, $associateUserId);
            $key = 'marketing-associate-credit:' . $idempotencyToken;
            $existing = $pdo->prepare('SELECT id FROM wallet_transactions WHERE idempotency_key = :key');
            $existing->execute(['key' => $key]);
            if ($existing->fetchColumn() !== false) {
                throw new DomainException('This wallet credit form has already been submitted');
            }
            $amount = Money::fromMinorUnits($amountMinor);
            $pdo->prepare('UPDATE wallets SET cached_balance = cached_balance + :amount WHERE id = :id')
                ->execute(['amount' => $amount, 'id' => (int) $wallet['id']]);
            $balanceAfter = self::walletBalance($pdo, (int) $wallet['id']);
            $pdo->prepare(
                "INSERT INTO wallet_transactions
                 (wallet_id, transaction_type, amount, balance_after, reference_type, reference_id, idempotency_key, description)
                 VALUES (:wallet_id, 'credit', :amount, :balance, 'admin_wallet_credit', :associate_id, :idempotency_key, :description)"
            )->execute([
                'wallet_id' => (int) $wallet['id'],
                'amount' => $amount,
                'balance' => $balanceAfter,
                'associate_id' => $associateUserId,
                'idempotency_key' => $key,
                'description' => $remarks,
            ]);
            $transactionId = (int) $pdo->lastInsertId();
            self::insertWalletAudit($pdo, $wallet, $associateUserId, $adminId, $transactionId, 'credit', $amount, $remarks, $ipAddress);
            self::insertAuditLog($pdo, $adminId, 'marketing_associate_wallet_credited', 'wallet_transaction', $transactionId, null, [
                'associate_user_id' => $associateUserId,
                'amount' => $amount,
                'remarks' => $remarks,
            ]);
            self::notify($pdo, $associateUserId, 'Wallet credit', '₹' . $amount . ' was credited to your wallet.', 'wallet_transaction', $transactionId);
            $pdo->commit();
        } catch (Throwable $exception) {
            self::rollBack($pdo);
            throw $exception;
        }
    }

    public static function reverseCredit(
        PDO $pdo,
        int $associateUserId,
        int $adminId,
        int $transactionId,
        string $remarks,
        ?string $ipAddress
    ): void {
        if ($transactionId < 1 || $remarks === '' || mb_strlen($remarks) > 500) {
            throw new InvalidArgumentException('Select a credit and enter a reversal reason');
        }

        $pdo->beginTransaction();
        try {
            $wallet = self::lockApprovedAssociateWallet($pdo, $associateUserId);
            $statement = $pdo->prepare(
                "SELECT id, amount FROM wallet_transactions
                 WHERE id = :id AND wallet_id = :wallet_id
                   AND transaction_type = 'credit' AND reference_type = 'admin_wallet_credit'
                 FOR UPDATE"
            );
            $statement->execute(['id' => $transactionId, 'wallet_id' => (int) $wallet['id']]);
            $credit = $statement->fetch();
            if ($credit === false) {
                throw new DomainException('Only a manual administrator credit can be reversed');
            }

            $key = 'marketing-associate-reversal:' . $transactionId;
            $existing = $pdo->prepare('SELECT id FROM wallet_transactions WHERE idempotency_key = :key');
            $existing->execute(['key' => $key]);
            if ($existing->fetchColumn() !== false) {
                throw new DomainException('This credit has already been reversed');
            }

            $amountMinor = Money::toMinorUnits((string) $credit['amount']);
            $reservedMinor = self::reservedAmount($pdo, $associateUserId);
            $balanceMinor = Money::toMinorUnits((string) $wallet['cached_balance']);
            if ($balanceMinor - $reservedMinor < $amountMinor) {
                throw new DomainException('The credit cannot be reversed while the funds are reserved or spent');
            }

            $amount = Money::fromMinorUnits($amountMinor);
            $pdo->prepare('UPDATE wallets SET cached_balance = cached_balance - :amount WHERE id = :id')
                ->execute(['amount' => $amount, 'id' => (int) $wallet['id']]);
            $balanceAfter = self::walletBalance($pdo, (int) $wallet['id']);
            $pdo->prepare(
                "INSERT INTO wallet_transactions
                 (wallet_id, transaction_type, amount, balance_after, reference_type, reference_id, idempotency_key, description)
                 VALUES (:wallet_id, 'adjustment', :amount, :balance, 'wallet_reversal', :reference_id, :idempotency_key, :description)"
            )->execute([
                'wallet_id' => (int) $wallet['id'],
                'amount' => $amount,
                'balance' => $balanceAfter,
                'reference_id' => $transactionId,
                'idempotency_key' => $key,
                'description' => 'Reversal of credit #' . $transactionId . ': ' . $remarks,
            ]);
            $reversalId = (int) $pdo->lastInsertId();
            self::insertWalletAudit($pdo, $wallet, $associateUserId, $adminId, $reversalId, 'reversal', $amount, $remarks, $ipAddress);
            self::insertAuditLog($pdo, $adminId, 'marketing_associate_wallet_credit_reversed', 'wallet_transaction', $reversalId, [
                'credited_transaction_id' => $transactionId,
            ], ['amount' => $amount, 'remarks' => $remarks]);
            $pdo->commit();
        } catch (Throwable $exception) {
            self::rollBack($pdo);
            throw $exception;
        }
    }

    public static function requestWithdrawal(PDO $pdo, int $associateUserId, int $amountMinor, string $remarks): string
    {
        if ($amountMinor < 1 || mb_strlen($remarks) > 500) {
            throw new InvalidArgumentException('Enter a positive withdrawal amount and a remark of 500 characters or fewer');
        }

        $pdo->beginTransaction();
        try {
            $wallet = self::lockApprovedAssociateWallet($pdo, $associateUserId);
            $activeRequest = $pdo->prepare(
                "SELECT id FROM withdrawal_requests
                 WHERE user_id = :user_id AND status IN ('requested','approved','processing')
                 LIMIT 1 FOR UPDATE"
            );
            $activeRequest->execute(['user_id' => $associateUserId]);
            if ($activeRequest->fetchColumn() !== false) {
                throw new DomainException('An active withdrawal request is already open');
            }
            $availableMinor = Money::toMinorUnits((string) $wallet['cached_balance'])
                - self::reservedAmount($pdo, $associateUserId);
            if ($amountMinor > $availableMinor) {
                throw new DomainException('The requested amount exceeds your available wallet balance');
            }

            $statement = $pdo->prepare(
                'SELECT u.full_name, ma.account_number_encrypted, ma.ifsc_encrypted, ma.branch_location_encrypted
                 FROM users u JOIN marketing_associates ma ON ma.user_id = u.id
                 WHERE u.id = :user_id AND ma.approval_status = \'approved\''
            );
            $statement->execute(['user_id' => $associateUserId]);
            $profile = $statement->fetch();
            if ($profile === false) {
                throw new DomainException('An approved Marketing Associate profile is required');
            }
            if (!is_string($profile['account_number_encrypted'])
                || !is_string($profile['ifsc_encrypted'])
                || !is_string($profile['branch_location_encrypted'])
            ) {
                throw new DomainException('Verified payout bank details are required before requesting a withdrawal');
            }
            $payoutDetails = PayoutEncryption::encrypt([
                'account_name' => $profile['full_name'],
                'account_number' => MarketingAssociateDataProtection::decrypt(
                    'account_number',
                    $profile['account_number_encrypted']
                ),
                'ifsc' => MarketingAssociateDataProtection::decrypt('ifsc_code', $profile['ifsc_encrypted']),
                'branch_location' => MarketingAssociateDataProtection::decrypt('branch_location', $profile['branch_location_encrypted']),
            ]);
            $reference = 'MA-WD-' . strtoupper(bin2hex(random_bytes(6)));
            $amount = Money::fromMinorUnits($amountMinor);
            $pdo->prepare(
                "INSERT INTO withdrawal_requests
                 (request_reference, user_id, amount, payout_details_encrypted, request_remarks, status)
                 VALUES (:reference, :user_id, :amount, :payout, :remarks, 'requested')"
            )->execute([
                'reference' => $reference,
                'user_id' => $associateUserId,
                'amount' => $amount,
                'payout' => $payoutDetails,
                'remarks' => $remarks !== '' ? $remarks : null,
            ]);
            $withdrawalId = (int) $pdo->lastInsertId();
            self::insertReservationTransaction(
                $pdo,
                $wallet,
                $withdrawalId,
                'reserve',
                $amount,
                'Reserved for Marketing Associate withdrawal ' . $reference
            );
            self::insertWithdrawalHistory($pdo, $withdrawalId, $associateUserId, null, 'requested', $remarks);
            self::insertAuditLog($pdo, $associateUserId, 'marketing_associate_withdrawal_requested', 'withdrawal', $withdrawalId, null, [
                'amount' => $amount,
                'request_reference' => $reference,
            ]);
            $adminIds = $pdo->query(
                "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE r.code = 'admin' AND u.account_status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($adminIds as $adminId) {
                self::notify(
                    $pdo,
                    (int) $adminId,
                    'Marketing Associate withdrawal request',
                    $profile['full_name'] . ' submitted a withdrawal request.',
                    'withdrawal',
                    $withdrawalId
                );
            }
            $pdo->commit();
            return $reference;
        } catch (Throwable $exception) {
            self::rollBack($pdo);
            throw $exception;
        }
    }

    public static function transitionWithdrawal(
        PDO $pdo,
        int $withdrawalId,
        int $adminId,
        string $action,
        string $remarks,
        string $paymentReference,
        ?string $ipAddress
    ): void {
        if (!in_array($action, ['approve', 'reject', 'processing', 'pay'], true)) {
            throw new InvalidArgumentException('Choose a valid withdrawal action');
        }
        if (mb_strlen($remarks) > 1000 || mb_strlen($paymentReference) > 100) {
            throw new InvalidArgumentException('Remarks or payment reference is too long');
        }
        if ($action === 'reject' && $remarks === '') {
            throw new InvalidArgumentException('A reason is required to reject a withdrawal');
        }
        if ($action === 'pay' && $paymentReference === '') {
            throw new InvalidArgumentException('Enter the bank transfer reference before marking a withdrawal paid');
        }

        $pdo->beginTransaction();
        try {
            $ownerStatement = $pdo->prepare('SELECT user_id FROM withdrawal_requests WHERE id = :id');
            $ownerStatement->execute(['id' => $withdrawalId]);
            $ownerId = $ownerStatement->fetchColumn();
            if ($ownerId === false) {
                throw new DomainException('Withdrawal request not found');
            }
            $wallet = self::lockApprovedAssociateWallet($pdo, (int) $ownerId, false);
            $statement = $pdo->prepare('SELECT * FROM withdrawal_requests WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $withdrawalId]);
            $withdrawal = $statement->fetch();
            if ($withdrawal === false) {
                throw new DomainException('Withdrawal request not found');
            }

            $transitions = [
                'approve' => ['requested', 'approved'],
                'reject' => [['requested', 'approved'], 'rejected'],
                'processing' => ['approved', 'processing'],
                'pay' => ['processing', 'paid'],
            ];
            [$expected, $newStatus] = $transitions[$action];
            $allowed = is_array($expected)
                ? in_array($withdrawal['status'], $expected, true)
                : $withdrawal['status'] === $expected;
            if (!$allowed) {
                throw new DomainException('This withdrawal cannot be moved to the selected status');
            }

            $amount = (string) $withdrawal['amount'];
            $transactionId = null;
            if ($action === 'reject') {
                self::insertReservationTransaction(
                    $pdo,
                    $wallet,
                    $withdrawalId,
                    'release',
                    $amount,
                    'Released after rejection of withdrawal ' . $withdrawal['request_reference']
                );
            }
            if ($action === 'pay') {
                $balanceMinor = Money::toMinorUnits((string) $wallet['cached_balance']);
                $amountMinor = Money::toMinorUnits($amount);
                if ($balanceMinor < $amountMinor) {
                    throw new DomainException('Wallet funds are insufficient to complete this payout');
                }
                $key = 'marketing-associate-withdrawal:' . $withdrawalId . ':paid';
                $existing = $pdo->prepare('SELECT id FROM wallet_transactions WHERE idempotency_key = :key');
                $existing->execute(['key' => $key]);
                if ($existing->fetchColumn() !== false) {
                    throw new DomainException('This withdrawal already has a payout ledger entry');
                }
                self::insertReservationTransaction(
                    $pdo,
                    $wallet,
                    $withdrawalId,
                    'release',
                    $amount,
                    'Released for payment of withdrawal ' . $withdrawal['request_reference']
                );
                $pdo->prepare('UPDATE wallets SET cached_balance = cached_balance - :amount WHERE id = :id')
                    ->execute(['amount' => $amount, 'id' => (int) $wallet['id']]);
                $balanceAfter = self::walletBalance($pdo, (int) $wallet['id']);
                $pdo->prepare(
                    "INSERT INTO wallet_transactions
                     (wallet_id, transaction_type, amount, balance_after, reference_type, reference_id, idempotency_key, description)
                     VALUES (:wallet_id, 'debit', :amount, :balance, 'withdrawal', :reference_id, :idempotency_key, :description)"
                )->execute([
                    'wallet_id' => (int) $wallet['id'],
                    'amount' => $amount,
                    'balance' => $balanceAfter,
                    'reference_id' => $withdrawalId,
                    'idempotency_key' => $key,
                    'description' => 'Paid Marketing Associate withdrawal ' . $withdrawal['request_reference'],
                ]);
                $transactionId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'UPDATE withdrawal_requests
                 SET status = :status, reviewed_by = :admin, reviewed_at = UTC_TIMESTAMP(),
                     admin_remarks = :remarks,
                     payment_reference = CASE WHEN :has_payment_reference = 1 THEN :payment_reference ELSE payment_reference END,
                     paid_at = CASE WHEN :is_paid = 1 THEN UTC_TIMESTAMP() ELSE paid_at END
                 WHERE id = :id'
            )->execute([
                'status' => $newStatus,
                'admin' => $adminId,
                'remarks' => $remarks !== '' ? $remarks : null,
                'has_payment_reference' => $paymentReference !== '' ? 1 : 0,
                'payment_reference' => $paymentReference,
                'is_paid' => $newStatus === 'paid' ? 1 : 0,
                'id' => $withdrawalId,
            ]);
            self::insertWithdrawalHistory($pdo, $withdrawalId, $adminId, $withdrawal['status'], $newStatus, $remarks);
            if ($transactionId !== null) {
                self::insertWalletAudit(
                    $pdo,
                    $wallet,
                    (int) $ownerId,
                    $adminId,
                    $transactionId,
                    'withdrawal_paid',
                    $amount,
                    $remarks !== '' ? $remarks : 'Withdrawal paid',
                    $ipAddress
                );
            }
            self::insertAuditLog($pdo, $adminId, 'marketing_associate_withdrawal_' . $newStatus, 'withdrawal', $withdrawalId, [
                'status' => $withdrawal['status'],
            ], [
                'status' => $newStatus,
                'payment_reference' => $action === 'pay' ? $paymentReference : null,
                'remarks' => $remarks,
            ]);
            self::notify(
                $pdo,
                (int) $ownerId,
                'Withdrawal ' . $newStatus,
                'Your withdrawal request ' . $withdrawal['request_reference'] . ' is now ' . $newStatus . '.',
                'withdrawal',
                $withdrawalId
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            self::rollBack($pdo);
            throw $exception;
        }
    }

    private static function lockApprovedAssociateWallet(PDO $pdo, int $userId, bool $requireApproved = true): array
    {
        $associate = $pdo->prepare(
            "SELECT ma.approval_status, u.account_status FROM marketing_associates ma
             JOIN users u ON u.id = ma.user_id WHERE ma.user_id = :user_id FOR UPDATE"
        );
        $associate->execute(['user_id' => $userId]);
        $status = $associate->fetch();
        if ($status === false || ($requireApproved && ($status['approval_status'] !== 'approved' || $status['account_status'] !== 'active'))) {
            throw new DomainException('An approved, active Marketing Associate account is required');
        }
        $pdo->prepare('INSERT INTO wallets (user_id) VALUES (:user_id) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)')
            ->execute(['user_id' => $userId]);
        $statement = $pdo->prepare('SELECT id, cached_balance FROM wallets WHERE user_id = :user_id FOR UPDATE');
        $statement->execute(['user_id' => $userId]);
        $wallet = $statement->fetch();
        if ($wallet === false) {
            throw new RuntimeException('Unable to access the associate wallet');
        }

        return $wallet;
    }

    private static function reservedAmount(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests
             WHERE user_id = :user_id AND status IN ('requested', 'approved', 'processing')"
        );
        $statement->execute(['user_id' => $userId]);

        return Money::toMinorUnits((string) $statement->fetchColumn());
    }

    private static function walletBalance(PDO $pdo, int $walletId): string
    {
        $statement = $pdo->prepare('SELECT cached_balance FROM wallets WHERE id = :id');
        $statement->execute(['id' => $walletId]);

        return (string) $statement->fetchColumn();
    }

    private static function insertReservationTransaction(
        PDO $pdo,
        array $wallet,
        int $withdrawalId,
        string $type,
        string $amount,
        string $description
    ): void {
        $key = 'marketing-associate-withdrawal:' . $withdrawalId . ':' . $type;
        $pdo->prepare(
            'INSERT INTO wallet_transactions
             (wallet_id, transaction_type, amount, balance_after, reference_type, reference_id, idempotency_key, description)
             VALUES (:wallet_id, :type, :amount, :balance, :reference_type, :reference_id, :idempotency_key, :description)'
        )->execute([
            'wallet_id' => (int) $wallet['id'],
            'type' => $type,
            'amount' => $amount,
            'balance' => (string) $wallet['cached_balance'],
            'reference_type' => $type === 'reserve' ? 'withdrawal_reservation' : 'withdrawal_release',
            'reference_id' => $withdrawalId,
            'idempotency_key' => $key,
            'description' => $description,
        ]);
    }

    private static function insertWalletAudit(
        PDO $pdo,
        array $wallet,
        int $associateUserId,
        int $adminId,
        int $transactionId,
        string $action,
        string $amount,
        string $remarks,
        ?string $ipAddress
    ): void {
        $pdo->prepare(
            'INSERT INTO wallet_admin_audit_logs
             (wallet_id, associate_user_id, actor_user_id, wallet_transaction_id, action, amount, remarks, ip_address)
             VALUES (:wallet_id, :associate_id, :actor, :transaction_id, :action, :amount, :remarks, :ip)'
        )->execute([
            'wallet_id' => (int) $wallet['id'],
            'associate_id' => $associateUserId,
            'actor' => $adminId,
            'transaction_id' => $transactionId,
            'action' => $action,
            'amount' => $amount,
            'remarks' => $remarks,
            'ip' => $ipAddress,
        ]);
    }

    private static function insertWithdrawalHistory(
        PDO $pdo,
        int $withdrawalId,
        int $actorId,
        ?string $oldStatus,
        string $newStatus,
        string $remarks
    ): void {
        $pdo->prepare(
            'INSERT INTO withdrawal_status_history (withdrawal_id, actor_user_id, old_status, new_status, remarks)
             VALUES (:withdrawal_id, :actor, :old_status, :new_status, :remarks)'
        )->execute([
            'withdrawal_id' => $withdrawalId,
            'actor' => $actorId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'remarks' => $remarks !== '' ? $remarks : null,
        ]);
    }

    private static function insertAuditLog(
        PDO $pdo,
        int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $before,
        array $after
    ): void {
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data)
             VALUES (:actor, :action, :entity_type, :entity_id, :before_data, :after_data)'
        )->execute([
            'actor' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }

    private static function notify(PDO $pdo, int $userId, string $title, string $message, string $targetType, int $targetId): void
    {
        $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, target_type, target_id)
             VALUES (:user_id, :title, :message, :target_type, :target_id)'
        )->execute([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);
    }

    private static function rollBack(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
