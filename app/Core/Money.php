<?php
declare(strict_types=1);

final class Money
{
    public static function toMinorUnits(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts)) {
            throw new InvalidArgumentException('Invalid monetary amount');
        }

        return ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    public static function fromMinorUnits(int $amount): string
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0');
    }

    public static function format(string $amount): string
    {
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts)) {
            throw new InvalidArgumentException('Invalid monetary amount');
        }

        return $parts[1] . number_format((int) $parts[2], 0, '.', ',')
            . '.' . str_pad($parts[3] ?? '0', 2, '0');
    }

    public static function formatIndian(string $amount): string
    {
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts)) {
            throw new InvalidArgumentException('Invalid monetary amount');
        }

        $integer = ltrim($parts[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $lastThree = substr($integer, -3);
        $prefix = substr($integer, 0, -3);
        if ($prefix !== '') {
            $prefix = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $prefix) . ',';
        }

        $fraction = str_pad($parts[3] ?? '', 2, '0');
        $fraction = $fraction === '00' ? '' : '.' . $fraction;

        return $parts[1] . $prefix . $lastThree . $fraction;
    }
}
