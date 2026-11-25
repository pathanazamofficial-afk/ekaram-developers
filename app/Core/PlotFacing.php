<?php
declare(strict_types=1);

final class PlotFacing
{
    public const DIRECTIONS = [
        'North',
        'South',
        'East',
        'West',
        'North-East',
        'North-West',
        'South-East',
        'South-West',
        'West & North',
    ];

    public static function isValid(string $direction): bool
    {
        return in_array($direction, self::DIRECTIONS, true);
    }
}
