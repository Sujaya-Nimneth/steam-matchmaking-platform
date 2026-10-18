<?php
declare(strict_types=1);

require __DIR__ . '/../config/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../services/MatchEngine.php';

security_headers();
start_secure_session();

$steamId = require_auth();
$db = DB::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$stmt = $db->prepare('SELECT * FROM users WHERE steam_id = ?');
$stmt->execute([$steamId]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    json_response(['error' => 'Profile not found'], 404);
}

$stmt = $db->prepare('SELECT app_id, playtime_forever, playtime_2weeks FROM user_games WHERE steam_id = ?');
$stmt->execute([$steamId]);
$currentGames = $stmt->fetchAll();

$query = 'SELECT * FROM users WHERE steam_id != ? AND setup_complete = 1 ';
$params = [$steamId];

$query .= ' AND steam_id NOT IN (SELECT swiped_id FROM swipes WHERE swiper_id = ?) ';
$params[] = $steamId;

if (($_GET['no_vac'] ?? '') === '1') {
    $query .= ' AND is_vac_banned = 0 ';
}

$country = strtoupper(trim((string) ($_GET['country'] ?? '')));
if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country)) {
    $query .= ' AND country = ? ';
    $params[] = $country;
}

$playstyle = (string) ($_GET['playstyle'] ?? '');
if ($playstyle !== '' && in_array($playstyle, ['Chill', 'Competitive', 'Tryhard', 'Toxic-Free'], true)) {
    $query .= ' AND playstyle = ? ';
    $params[] = $playstyle;
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$candidates = $stmt->fetchAll();

$matches = [];

foreach ($candidates as $candidate) {
    $stmt = $db->prepare('SELECT app_id, playtime_forever, playtime_2weeks FROM user_games WHERE steam_id = ?');
    $stmt->execute([$candidate['steam_id']]);
    $candidateGames = $stmt->fetchAll();

    $matchData = MatchEngine::calculateScore($currentUser, $candidate, $currentGames, $candidateGames);

    if ($matchData['score'] <= 0) {
        continue;
    }

    // Discord handles stay hidden until both players like each other.
    $matches[] = [
        'steam_id' => $candidate['steam_id'],
        'name' => $candidate['name'],
        'avatar' => $candidate['avatar'],
        'playstyle' => $candidate['playstyle'],
        'languages' => json_decode($candidate['languages'] ?? '[]') ?: [],
        'score' => $matchData['score'],
        'shared_games' => $matchData['shared_games'],
    ];
}

usort($matches, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

json_response(['matches' => array_slice($matches, 0, 20)]);
