<?php
declare(strict_types=1);

require __DIR__ . '/../config/security.php';
require __DIR__ . '/../config/db.php';

security_headers();

$db = DB::getConnection();

$stmt = $db->query("SELECT DISTINCT country FROM users WHERE country IS NOT NULL AND country != '' ORDER BY country");
$countries = $stmt->fetchAll(PDO::FETCH_COLUMN);

json_response([
    'countries' => $countries,
    'playstyles' => ['Chill', 'Competitive', 'Tryhard', 'Toxic-Free'],
]);
