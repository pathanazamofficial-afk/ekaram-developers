<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$rows = $pdo->query(
    'SELECT user_id, ifsc_code, branch_location, ifsc_encrypted, branch_location_encrypted
     FROM marketing_associates
     WHERE ifsc_encrypted IS NULL OR branch_location_encrypted IS NULL'
)->fetchAll();

foreach ($rows as $row) {
    $ifsc = $row['ifsc_encrypted'] !== null
        ? $row['ifsc_encrypted']
        : trim((string) $row['ifsc_code']);
    $branch = $row['branch_location_encrypted'] !== null
        ? $row['branch_location_encrypted']
        : trim((string) $row['branch_location']);
    if ($ifsc === '' || $branch === '') {
        throw new RuntimeException('An existing associate has incomplete bank details; resolve it before migration.');
    }

    $ifscEncrypted = $row['ifsc_encrypted'] !== null
        ? $row['ifsc_encrypted']
        : MarketingAssociateDataProtection::encrypt('ifsc_code', $ifsc);
    $branchEncrypted = $row['branch_location_encrypted'] !== null
        ? $row['branch_location_encrypted']
        : MarketingAssociateDataProtection::encrypt('branch_location', $branch);
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE marketing_associates
             SET ifsc_encrypted = :ifsc_encrypted, branch_location_encrypted = :branch_encrypted,
                 ifsc_code = NULL, branch_location = NULL
             WHERE user_id = :user_id'
        )->execute([
            'ifsc_encrypted' => $ifscEncrypted,
            'branch_encrypted' => $branchEncrypted,
            'user_id' => (int) $row['user_id'],
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

echo 'Encrypted bank branch data for ' . count($rows) . ' Marketing Associate profile(s).' . PHP_EOL;
