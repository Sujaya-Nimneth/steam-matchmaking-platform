<?php
// api/profile.php
session_start();
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['steam_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$steamId = $_SESSION['steam_id'];
$db = DB::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT steam_id, name, avatar, discord_handle, playstyle, languages, age, country, setup_complete FROM users WHERE steam_id = ?");
    $stmt->execute([$steamId]);
    $user = $stmt->fetch();
    echo json_encode($user);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $discord = $data['discord_handle'] ?? '';
    $playstyle = $data['playstyle'] ?? '';
    $languages = isset($data['languages']) ? json_encode($data['languages']) : '[]';
    $age = isset($data['age']) ? (int)$data['age'] : null;
    $country = $data['country'] ?? '';

    if (empty($discord)) {
        echo json_encode(['success' => false, 'error' => 'Discord handle is required']);
        exit;
    }

    $stmt = $db->prepare("UPDATE users SET discord_handle = ?, playstyle = ?, languages = ?, age = ?, country = ?, setup_complete = 1 WHERE steam_id = ?");
    $stmt->execute([$discord, $playstyle, $languages, $age, $country, $steamId]);

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Invalid method']);
