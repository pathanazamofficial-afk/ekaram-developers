<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
$count = BookingService::releaseExpired(Database::connection());
fwrite(STDOUT, "Released {$count} expired reservation(s).\n");
