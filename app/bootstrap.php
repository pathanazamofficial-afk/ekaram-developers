<?php
declare(strict_types=1);

require_once __DIR__ . '/Config/app.php';
require_once __DIR__ . '/Config/database.php';
require_once __DIR__ . '/Core/Database.php';
require_once __DIR__ . '/Core/Security.php';
require_once __DIR__ . '/Core/Response.php';
require_once __DIR__ . '/Core/Money.php';
require_once __DIR__ . '/Core/PlotFacing.php';
require_once __DIR__ . '/Core/PlotStatus.php';
require_once __DIR__ . '/Core/Auth.php';
require_once __DIR__ . '/Services/UploadService.php';
require_once __DIR__ . '/Services/CommissionService.php';
require_once __DIR__ . '/Services/MarketingAssociateHierarchy.php';
require_once __DIR__ . '/Services/BookingService.php';
require_once __DIR__ . '/Services/PayoutEncryption.php';
require_once __DIR__ . '/Services/MarketingAssociateDataProtection.php';
require_once __DIR__ . '/Services/MarketingAssociateWalletService.php';
require_once __DIR__ . '/Services/PublicContentService.php';

$app = appConfig();
date_default_timezone_set($app['timezone']);
ini_set('display_errors', $app['debug'] ? '1' : '0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; frame-src https://www.google.com; script-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'");
if (Security::isHttps()) {
    header('Strict-Transport-Security: max-age=31536000');
}
