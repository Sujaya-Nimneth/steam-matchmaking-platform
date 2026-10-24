<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$steamId = require_auth();
$db = DB::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_same_origin();
rate_limit('swipe', 60, 60);

$data = read_json_body();

$swipedId = (string) ($data['swiped_id'] ?? '');
$action = (string) ($data['action'] ?? '');

if (!preg_match('/^\d{17,25}$/', $swipedId) || !in_array($action, ['like', 'pass'], true)) {
    json_response(['error' => 'Invalid data'], 422);
}

if ($swipedId === $steamId) {
    json_response(['error' => 'You cannot swipe on yourself'], 422);
}

$stmt = $db->prepare('SELECT setup_complete FROM users WHERE steam_id = ?');
$stmt->execute([$swipedId]);

if (!$stmt->fetch()) {
    json_response(['error' => 'Player not found'], 404);
}

// A swipe is final. INSERT OR IGNORE keeps two concurrent first swipes
// from crashing on the primary key, and the stored action — not whatever
// the client re-sent — decides whether a mutual like happened.
$stmt = $db->prepare('INSERT OR IGNORE INTO swipes (swiper_id, swiped_id, action) VALUES (?, ?, ?)');
$stmt->execute([$steamId, $swipedId, $action]);

$stmt = $db->prepare('SELECT action FROM swipes WHERE swiper_id = ? AND swiped_id = ?');
$stmt->execute([$steamId, $swipedId]);
$effectiveAction = (string) $stmt->fetchColumn();

$isMatch = false;
$matchData = null;

if ($effectiveAction === 'like') {
    $stmt = $db->prepare("SELECT 1 FROM swipes WHERE swiper_id = ? AND swiped_id = ? AND action = 'like'");
    $stmt->execute([$swipedId, $steamId]);

    if ($stmt->fetch()) {
        $isMatch = true;

        // Sort the pair so (A,B) and (B,A) collapse into one row.
        $user1 = min($steamId, $swipedId);
        $user2 = max($steamId, $swipedId);

        $stmt = $db->prepare('INSERT OR IGNORE INTO matches (user1_id, user2_id) VALUES (?, ?)');
        $stmt->execute([$user1, $user2]);

        $stmt = $db->prepare('SELECT name, discord_handle, avatar FROM users WHERE steam_id = ?');
        $stmt->execute([$swipedId]);
        $target = $stmt->fetch();

        if ($target) {
            $matchData = [
                'steam_id' => $swipedId,
                'name' => $target['name'],
                'avatar' => $target['avatar'],
                'discord_handle' => $target['discord_handle'],
                'steam_profile_url' => 'https://steamcommunity.com/profiles/' . $swipedId,
            ];
        }
    }
}

json_response(['success' => true, 'is_match' => $isMatch, 'match_data' => $matchData]);
