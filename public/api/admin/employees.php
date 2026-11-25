<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';
Security::startSession();
Auth::requireAdminApi();
Response::json([
    'success' => false,
    'message' => 'Manage team accounts and designations in Marketing Associates.',
], 410);
