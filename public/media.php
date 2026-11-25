<?php
declare(strict_types=1);

$relativePath = (string) ($_GET['file'] ?? '');
if (!preg_match('~^(amenities|gallery|brochures)/[a-f0-9]{32}\.(jpg|png|pdf)$~', $relativePath, $matches)) {
    http_response_code(404);
    exit;
}

$storageRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads');
$filePath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
if ($storageRoot === false || $filePath === false || !is_file($filePath)) {
    http_response_code(404);
    exit;
}

$normalizedRoot = strtolower($storageRoot . DIRECTORY_SEPARATOR);
$normalizedFile = strtolower($filePath);
if (strpos($normalizedFile, $normalizedRoot) !== 0) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
$allowedTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
$extension = strtolower((string) $matches[2]);
if (!isset($allowedTypes[$extension]) || $mime !== $allowedTypes[$extension]) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, max-age=3600');
header($extension === 'pdf'
    ? 'Content-Disposition: attachment; filename="project-brochure.pdf"'
    : 'Content-Disposition: inline');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
