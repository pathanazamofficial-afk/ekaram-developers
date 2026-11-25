<?php
declare(strict_types=1);

final class BookingService
{
    public static function reserve(PDO $pdo, int $agentId, int $leadId, int $plotId): array
    {
        $visibleIds = Auth::visibleUserIds($agentId);
        if (!$visibleIds) {
            throw new DomainException('Employee reporting scope is not configured');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
            $leadStatement = $pdo->prepare(
                "SELECT id, customer_id, project_id,
                        COALESCE(lead_owner_id, assigned_to, ?) AS resolved_owner_id,
                        lead_owner_id, ownership_locked_at
                 FROM leads WHERE id = ? AND assigned_to IN ($placeholders) FOR UPDATE"
            );
            $leadStatement->execute(array_merge([$agentId, $leadId], $visibleIds));
            $lead = $leadStatement->fetch();
            if ($lead === false || $lead['project_id'] === null || $lead['resolved_owner_id'] === null) {
                throw new DomainException('Select a lead assigned to your reporting team');
            }
            $leadOwnerId = (int) $lead['resolved_owner_id'];
            if ($lead['lead_owner_id'] === null) {
                $pdo->prepare(
                    'UPDATE leads SET lead_owner_id = :owner_id, ownership_locked_at = UTC_TIMESTAMP() WHERE id = :id'
                )->execute(['owner_id' => $leadOwnerId, 'id' => $leadId]);
                $pdo->prepare(
                    'INSERT INTO lead_ownership_history (lead_id, old_owner_id, new_owner_id, changed_by, reason)
                     VALUES (:lead_id, NULL, :owner_id, :actor, :reason)'
                )->execute([
                    'lead_id' => $leadId,
                    'owner_id' => $leadOwnerId,
                    'actor' => $agentId,
                    'reason' => 'Lead owner locked when booking was requested',
                ]);
            }

            $plotStatement = $pdo->prepare(
                'SELECT pl.id, pl.project_id, pl.status, pl.total_price, pl.area_sqyd, p.price_per_sqyd,
                        COALESCE(pl.total_price, ROUND(pl.area_sqyd * p.price_per_sqyd, 2)) AS booking_price
                 FROM plots pl JOIN projects p ON p.id = pl.project_id
                 WHERE pl.id = :id FOR UPDATE'
            );
            $plotStatement->execute(['id' => $plotId]);
            $plot = $plotStatement->fetch();
            if ($plot === false || (int) $plot['project_id'] !== (int) $lead['project_id']) {
                throw new DomainException('The selected plot is not part of this lead project');
            }
            if ($plot['status'] === 'reserved') {
                self::expirePlotReservation($pdo, $plotId);
                $plotStatement->execute(['id' => $plotId]);
                $plot = $plotStatement->fetch();
            }
            if ($plot['status'] !== 'available') {
                throw new DomainException('The plot is no longer available');
            }
            if ($plot['booking_price'] === null || (float) $plot['booking_price'] <= 0) {
                throw new DomainException('Set a valid plot price before reserving it');
            }

            $activeBooking = $pdo->prepare(
                "SELECT id FROM bookings
                 WHERE plot_id = :plot_id AND status IN ('reserved','pending_approval')
                   AND reservation_expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE"
            );
            $activeBooking->execute(['plot_id' => $plotId]);
            if ($activeBooking->fetchColumn() !== false) {
                throw new DomainException('The plot already has an active reservation');
            }

            $reference = 'EKA-' . strtoupper(bin2hex(random_bytes(6)));
            $insert = $pdo->prepare(
                "INSERT INTO bookings
                 (booking_reference, plot_id, customer_id, lead_id, agent_id, lead_owner_id, status, reserved_at, reservation_expires_at, total_price)
                 VALUES (:reference, :plot_id, :customer_id, :lead_id, :agent_id, :lead_owner_id, 'reserved', UTC_TIMESTAMP(),
                         DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 HOUR), :total_price)"
            );
            $insert->execute([
                'reference' => $reference,
                'plot_id' => $plotId,
                'customer_id' => (int) $lead['customer_id'],
                'lead_id' => $leadId,
                'agent_id' => $agentId,
                'lead_owner_id' => $leadOwnerId,
                'total_price' => $plot['booking_price'],
            ]);
            $bookingId = (int) $pdo->lastInsertId();
            $plotUpdate = $pdo->prepare("UPDATE plots SET status = 'reserved' WHERE id = :id AND status = 'available'");
            $plotUpdate->execute(['id' => $plotId]);
            if ($plotUpdate->rowCount() !== 1) {
                throw new DomainException('The plot was reserved by another request');
            }

            $pdo->prepare(
                "INSERT INTO plot_status_history (plot_id, old_status, new_status, changed_by)
                 VALUES (:plot_id, 'available', 'reserved', :actor)"
            )->execute(['plot_id' => $plotId, 'actor' => $agentId]);
            $pdo->prepare(
                "INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by)
                 VALUES (:booking_id, NULL, 'reserved', :actor)"
            )->execute(['booking_id' => $bookingId, 'actor' => $agentId]);
            $pdo->prepare(
                'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_data)
                 VALUES (:actor, \'plot_reserved\', \'booking\', :entity_id, :after_data)'
            )->execute([
                'actor' => $agentId,
                'entity_id' => $bookingId,
                'after_data' => json_encode(['plot_id' => $plotId, 'expires_in_hours' => 10], JSON_THROW_ON_ERROR),
            ]);
            $expiryStatement = $pdo->prepare('SELECT reservation_expires_at FROM bookings WHERE id = :id');
            $expiryStatement->execute(['id' => $bookingId]);
            $expires = $expiryStatement->fetchColumn();
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['id' => $bookingId, 'reference' => $reference, 'expires_at_utc' => $expires];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function expirePlotReservation(PDO $pdo, int $plotId): void
    {
        $plotLock = $pdo->prepare('SELECT id FROM plots WHERE id = :id FOR UPDATE');
        $plotLock->execute(['id' => $plotId]);
        if ($plotLock->fetchColumn() === false) {
            return;
        }
        $statement = $pdo->prepare(
            "SELECT id, status FROM bookings
             WHERE plot_id = :plot_id AND status IN ('reserved','pending_approval')
               AND reservation_expires_at <= UTC_TIMESTAMP()
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['plot_id' => $plotId]);
        $booking = $statement->fetch();
        if ($booking === false) {
            return;
        }

        $bookingId = (int) $booking['id'];
        $pdo->prepare("UPDATE bookings SET status = 'expired' WHERE id = :id")
            ->execute(['id' => $bookingId]);
        $pdo->prepare(
            'INSERT INTO booking_status_history (booking_id, old_status, new_status, note)
             VALUES (:booking_id, :old_status, \'expired\', \'Reservation expired\')'
        )->execute(['booking_id' => $bookingId, 'old_status' => $booking['status']]);
        $plot = $pdo->prepare('SELECT status FROM plots WHERE id = :id FOR UPDATE');
        $plot->execute(['id' => $plotId]);
        $plotStatus = $plot->fetchColumn();
        if ($plotStatus === 'reserved') {
            $pdo->prepare("UPDATE plots SET status = 'available' WHERE id = :id")
                ->execute(['id' => $plotId]);
            $pdo->prepare(
                "INSERT INTO plot_status_history (plot_id, old_status, new_status, note)
                 VALUES (:plot_id, 'reserved', 'available', 'Reservation expired')"
            )->execute(['plot_id' => $plotId]);
        }
    }

    public static function releaseExpired(PDO $pdo): int
    {
        $pdo->beginTransaction();
        try {
            $plots = $pdo->query(
                "SELECT DISTINCT plot_id FROM bookings
                 WHERE status IN ('reserved','pending_approval') AND reservation_expires_at <= UTC_TIMESTAMP()
                 ORDER BY plot_id"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($plots as $plotId) {
                self::expirePlotReservation($pdo, (int) $plotId);
            }
            $pdo->commit();
            return count($plots);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
