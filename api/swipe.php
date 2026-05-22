<?php
// api/swipe.php
session_start();
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['steam_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$steamId = $_SESSION['steam_id'];
$db = DB::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $swipedId = $data['swiped_id'] ?? null;
    $action = $data['action'] ?? null; // 'like' or 'pass'

    if (!$swipedId || !in_array($action, ['like', 'pass'])) {
        echo json_encode(['error' => 'Invalid data']);
        exit;
    }

    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO swipes (swiper_id, swiped_id, action) VALUES (?, ?, ?)");
        $stmt->execute([$steamId, $swipedId, $action]);

        $isMatch = false;
        $matchData = null;

        if ($action === 'like') {
            // Check if mutual like exists
            $stmt = $db->prepare("SELECT * FROM swipes WHERE swiper_id = ? AND swiped_id = ? AND action = 'like'");
            $stmt->execute([$swipedId, $steamId]);
            $mutual = $stmt->fetch();

            if ($mutual) {
                $isMatch = true;
                
                // Record Match
                $stmt = $db->prepare("INSERT OR IGNORE INTO matches (user1_id, user2_id) VALUES (?, ?)");
                // sort IDs to prevent duplicates (A,B) vs (B,A)
                $u1 = min($steamId, $swipedId);
                $u2 = max($steamId, $swipedId);
                $stmt->execute([$u1, $u2]);

                // Fetch target user's contact info for the Boom overlay
                $stmt = $db->prepare("SELECT name, discord_handle, avatar FROM users WHERE steam_id = ?");
                $stmt->execute([$swipedId]);
                $target = $stmt->fetch();

                $matchData = [
                    'steam_id' => $swipedId,
                    'name' => $target['name'],
                    'avatar' => $target['avatar'],
                    'discord_handle' => $target['discord_handle'],
                    'steam_profile_url' => "https://steamcommunity.com/profiles/" . $swipedId
                ];
            }
        }

        echo json_encode(['success' => true, 'is_match' => $isMatch, 'match_data' => $matchData]);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}
echo json_encode(['error' => 'Invalid method']);
