<?php
// api/matches.php
session_start();
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../services/MatchEngine.php';

header('Content-Type: application/json');

if (!isset($_SESSION['steam_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$steamId = $_SESSION['steam_id'];
$db = DB::getConnection();

// Get current user and their games
$stmt = $db->prepare("SELECT * FROM users WHERE steam_id = ?");
$stmt->execute([$steamId]);
$currentUser = $stmt->fetch();

$stmt = $db->prepare("SELECT app_id, playtime_forever, playtime_2weeks FROM user_games WHERE steam_id = ?");
$stmt->execute([$steamId]);
$currentGames = $stmt->fetchAll();

// Get filter params
$filters = $_GET;
$query = "SELECT * FROM users WHERE steam_id != ? AND setup_complete = 1 ";
$params = [$steamId];

// Avoid already swiped users
$query .= " AND steam_id NOT IN (SELECT swiped_id FROM swipes WHERE swiper_id = ?) ";
$params[] = $steamId;

// Apply Filters
if (isset($filters['no_vac']) && $filters['no_vac'] == '1') {
    $query .= " AND is_vac_banned = 0 ";
}
if (!empty($filters['country'])) {
    $query .= " AND country = ? ";
    $params[] = $filters['country'];
}
if (!empty($filters['playstyle'])) {
    $query .= " AND playstyle = ? ";
    $params[] = $filters['playstyle'];
}
// more filters can be added here

$stmt = $db->prepare($query);
$stmt->execute($params);
$candidates = $stmt->fetchAll();

$matches = [];

foreach ($candidates as $cand) {
    // Get candidate's games
    $stmt = $db->prepare("SELECT app_id, playtime_forever, playtime_2weeks FROM user_games WHERE steam_id = ?");
    $stmt->execute([$cand['steam_id']]);
    $candGames = $stmt->fetchAll();

    $matchData = MatchEngine::calculateScore($currentUser, $cand, $currentGames, $candGames);
    
    // Only return if they have at least 1 shared game (score > 0)
    if ($matchData['score'] > 0) {
        // Strip sensitive info (like discord_handle) until match
        $matches[] = [
            'steam_id' => $cand['steam_id'],
            'name' => $cand['name'],
            'avatar' => $cand['avatar'],
            'playstyle' => $cand['playstyle'],
            'languages' => json_decode($cand['languages'] ?? '[]'),
            'score' => $matchData['score'],
            'shared_games' => $matchData['shared_games']
        ];
    }
}

// Sort by highest score
usort($matches, function($a, $b) {
    return $b['score'] <=> $a['score'];
});

echo json_encode(['matches' => array_slice($matches, 0, 20)]); // return top 20 candidates
