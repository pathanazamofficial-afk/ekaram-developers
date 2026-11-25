<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
Auth::requireAdminPage();

$associateId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($associateId === false) {
    http_response_code(400);
    exit('A Marketing Associate ID is required.');
}
$statement = Database::connection()->prepare(
    'SELECT u.full_name, w.id AS wallet_id
     FROM marketing_associates ma
     JOIN users u ON u.id = ma.user_id
     LEFT JOIN wallets w ON w.user_id = u.id
     WHERE ma.id = :id'
);
$statement->execute(['id' => $associateId]);
$associate = $statement->fetch();
if ($associate === false || $associate['wallet_id'] === null) {
    http_response_code(404);
    exit('Wallet statement not found.');
}
$statement = Database::connection()->prepare(
    'SELECT id, created_at, transaction_type, reference_type, amount, balance_after, description
     FROM wallet_transactions WHERE wallet_id = :wallet_id ORDER BY id ASC'
);
$statement->execute(['wallet_id' => (int) $associate['wallet_id']]);

$safeCell = static function (string $value): string {
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
};
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="marketing-associate-wallet-' . (int) $associateId . '.csv"');
header('Cache-Control: no-store, private');
$output = fopen('php://output', 'wb');
if ($output === false) {
    throw new RuntimeException('Unable to generate wallet statement');
}
fputcsv($output, ['Marketing Associate', $safeCell((string) $associate['full_name'])]);
fputcsv($output, ['Transaction ID', 'Date and time (UTC)', 'Type', 'Reference type', 'Amount (INR)', 'Running balance (INR)', 'Description']);
while ($row = $statement->fetch()) {
    fputcsv($output, [
        (int) $row['id'],
        $safeCell((string) $row['created_at']),
        $safeCell((string) $row['transaction_type']),
        $safeCell((string) $row['reference_type']),
        (string) $row['amount'],
        (string) $row['balance_after'],
        $safeCell((string) $row['description']),
    ]);
}
fclose($output);
