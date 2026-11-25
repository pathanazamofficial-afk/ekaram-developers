<?php
declare(strict_types=1);

final class PlotStatus
{
    public const LABELS = [
        'available' => 'Available',
        'recent_agreement' => 'Recent Agreement',
        'booked' => 'Agreement',
        'sold' => 'Sold',
        'reserved' => 'Temporarily reserved',
        'blocked' => 'Admin-blocked',
    ];

    public const ADMIN_EDITABLE = [
        'available',
        'recent_agreement',
        'booked',
        'sold',
        'blocked',
    ];

    public static function isValid(string $status): bool
    {
        return isset(self::LABELS[$status]);
    }
}
