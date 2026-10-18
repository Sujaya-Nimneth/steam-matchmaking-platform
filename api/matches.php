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

if (isset($_GET['list'])) {
    json_response(['matches' => list_matches($db, $steamId)]);
}

$stmt = $db->prepare('SELECT * FROM users WHERE steam_id = ?');
$stmt->execute([$steamId]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    json_response(['error' => 'Profile not found'], 404);
}

$stmt = $db->prepare('SELECT app_id, name, playtime_forever, playtime_2weeks FROM user_games WHERE steam_id = ?');
$stmt->execute([$steamId]);
$currentGames = $stmt->fetchAll();
$currentLibrarySize = count($currentGames);

$filters = [];
$filterSql = '';

if (($_GET['no_vac'] ?? '') === '1') {
    $filterSql .= ' AND u.is_vac_banned = 0 ';
}

$country = strtoupper(trim((string) ($_GET['country'] ?? '')));
if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country)) {
    $filterSql .= ' AND u.country = ? ';
    $filters[] = $country;
}

$playstyle = (string) ($_GET['playstyle'] ?? '');
if ($playstyle !== '' && in_array($playstyle, ['Chill', 'Competitive', 'Tryhard', 'Toxic-Free'], true)) {
    $filterSql .= ' AND u.playstyle = ? ';
    $filters[] = $playstyle;
}

// One query returns every candidate that shares at least one game, together
// with the shared rows, instead of one query per candidate.
$sql = "
    SELECT u.steam_id, u.name, u.avatar, u.playstyle, u.languages,
           g.app_id, g.name AS game_name, g.playtime_forever, g.playtime_2weeks
    FROM users u
    JOIN user_games g ON g.steam_id = u.steam_id
    JOIN user_games mine ON mine.app_id = g.app_id AND mine.steam_id = :me
    WHERE u.steam_id != :me
      AND u.setup_complete = 1
      AND NOT EXISTS (
          SELECT 1 FROM swipes s WHERE s.swiper_id = :me AND s.swiped_id = u.steam_id
      )
      {$filterSql}
    ORDER BY u.steam_id
";

$stmt = $db->prepare($sql);
$stmt->execute(array_merge([':me' => $steamId], $filters));

$candidates = [];
$sharedByCandidate = [];

while ($row = $stmt->fetch()) {
    $candidateId = $row['steam_id'];

    if (!isset($candidates[$candidateId])) {
        $candidates[$candidateId] = [
            'steam_id' => $row['steam_id'],
            'name' => $row['name'],
            'avatar' => $row['avatar'],
            'playstyle' => $row['playstyle'],
            'languages' => $row['languages'],
        ];
    }

    $sharedByCandidate[$candidateId][] = [
        'app_id' => (int) $row['app_id'],
        'name' => $row['game_name'],
        'playtime_forever' => (int) $row['playtime_forever'],
        'playtime_2weeks' => (int) $row['playtime_2weeks'],
    ];
}

$librarySizes = candidate_library_sizes($db, array_keys($candidates));

$matches = [];

foreach ($candidates as $candidateId => $candidate) {
    $matchData = MatchEngine::calculateScore(
        $currentUser,
        $candidate,
        $sharedByCandidate[$candidateId],
        $sharedByCandidate[$candidateId],
        $currentLibrarySize,
        $librarySizes[$candidateId] ?? 0
    );

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
        'shared_count' => $matchData['shared_count'],
        'shared_games' => $matchData['shared_games'],
    ];
}

usort($matches, static function (array $a, array $b) {
    return [$b['score'], $b['shared_count']] <=> [$a['score'], $a['shared_count']];
});

json_response(['matches' => array_slice($matches, 0, 20)]);

/**
 * Total owned games per candidate, used to weight library overlap.
 *
 * @param string[] $steamIds
 * @return array<string, int>
 */
function candidate_library_sizes(PDO $db, array $steamIds): array
{
    if ($steamIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($steamIds), '?'));
    $stmt = $db->prepare("SELECT steam_id, COUNT(*) AS total FROM user_games WHERE steam_id IN ({$placeholders}) GROUP BY steam_id");
    $stmt->execute($steamIds);

    $sizes = [];
    foreach ($stmt->fetchAll() as $row) {
        $sizes[$row['steam_id']] = (int) $row['total'];
    }

    return $sizes;
}

/**
 * Confirmed matches for the current player, including the Discord handle that
 * is only revealed once both sides liked each other.
 */
function list_matches(PDO $db, string $steamId): array
{
    $stmt = $db->prepare("
        SELECT m.created_at,
               u.steam_id, u.name, u.avatar, u.playstyle, u.country, u.discord_handle
        FROM matches m
        JOIN users u
          ON u.steam_id = CASE WHEN m.user1_id = :me THEN m.user2_id ELSE m.user1_id END
        WHERE m.user1_id = :me OR m.user2_id = :me
        ORDER BY m.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([':me' => $steamId]);

    $matches = [];

    foreach ($stmt->fetchAll() as $row) {
        $matches[] = [
            'steam_id' => $row['steam_id'],
            'name' => $row['name'],
            'avatar' => $row['avatar'],
            'playstyle' => $row['playstyle'],
            'country' => $row['country'],
            'discord_handle' => $row['discord_handle'],
            'matched_at' => $row['created_at'],
            'steam_profile_url' => 'https://steamcommunity.com/profiles/' . $row['steam_id'],
        ];
    }

    return $matches;
}
