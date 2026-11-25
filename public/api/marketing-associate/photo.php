<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';
Security::startSession();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$viewer = Auth::currentUser();
if ($viewer === null) {
    http_response_code(401);
    exit;
}

$associateId = filter_var($_GET['associate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($associateId === false) {
    http_response_code(404);
    exit;
}

$pdo = Database::connection();
$statement = $pdo->prepare(
    'SELECT p.profile_photo_path, ma.approval_status
     FROM marketing_associate_profiles p
     JOIN marketing_associates ma ON ma.user_id = p.user_id
     WHERE p.user_id = :user_id'
);
$statement->execute(['user_id' => (int) $associateId]);
$profile = $statement->fetch();
if ($profile === false || $profile['profile_photo_path'] === null) {
    http_response_code(404);
    exit;
}

$isAdmin = $viewer['role_code'] === 'admin';
$isOwner = $viewer['role_code'] === 'marketing_associate'
    && (int) $viewer['id'] === (int) $associateId
    && $profile['approval_status'] === 'approved';
if (!$isAdmin && !$isOwner) {
    http_response_code(403);
    exit;
}

$relativePath = (string) $profile['profile_photo_path'];
if (!preg_match('~^marketing-associates/[a-f0-9]{32}\.(jpg|png)$~', $relativePath)) {
    http_response_code(404);
    exit;
}
$root = realpath(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads');
$file = realpath(
    dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads'
    . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)
);
if ($root === false || $file === false || strpos(strtolower($file), strtolower($root . DIRECTORY_SEPARATOR)) !== 0) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
header('Content-Disposition: inline; filename="associate-profile.' . ($mime === 'image/png' ? 'png' : 'jpg') . '"');
readfile($file);
