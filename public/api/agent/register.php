<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';
Security::startSession();
Response::json([
    'success' => false,
    'message' => 'All members use the Marketing Associate registration form.',
], 410);
