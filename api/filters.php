<?php
// api/filters.php
session_start();
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$db = DB::getConnection();

// Get unique countries
$stmt = $db->query("SELECT DISTINCT country FROM users WHERE country IS NOT NULL AND country != ''");
$countries = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Predefined playstyles
$playstyles = ['Chill', 'Competitive', 'Tryhard', 'Toxic-Free'];

echo json_encode([
    'countries' => $countries,
    'playstyles' => $playstyles
]);
