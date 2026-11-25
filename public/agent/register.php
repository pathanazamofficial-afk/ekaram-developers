<?php
declare(strict_types=1);

$invite = is_string($_GET['invite'] ?? null) ? trim($_GET['invite']) : '';
$destination = '/marketing-associate/signup.php';
if (preg_match('/^[a-f0-9]{64}$/', $invite)) {
    $destination .= '?invite=' . rawurlencode($invite);
}
header('Location: ' . $destination, true, 303);
exit;
