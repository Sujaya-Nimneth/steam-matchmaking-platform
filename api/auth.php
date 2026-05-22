<?php
// api/auth.php
session_start();
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../services/SteamAPI.php';

$steamApi = new SteamAPI();

if (isset($_GET['openid_mode'])) {
    if ($_GET['openid_mode'] == 'cancel') {
        die('User canceled authentication');
    }

    $steamId = $steamApi->validateLogin($_GET);
    
    if ($steamId) {
        // Fetch User Data
        $summary = $steamApi->getPlayerSummary($steamId);
        $bans = $steamApi->getPlayerBans($steamId);
        $ownedGames = $steamApi->getOwnedGames($steamId);
        $recentGames = $steamApi->getRecentlyPlayedGames($steamId);

        $db = DB::getConnection();

        // Check if user exists
        $stmt = $db->prepare("SELECT steam_id FROM users WHERE steam_id = ?");
        $stmt->execute([$steamId]);
        $exists = $stmt->fetch();

        $isVacBanned = ($bans && $bans['VACBanned']) ? 1 : 0;
        $name = $summary['personaname'] ?? 'Unknown';
        $avatar = $summary['avatarfull'] ?? '';

        if (!$exists) {
            $stmt = $db->prepare("INSERT INTO users (steam_id, name, avatar, is_vac_banned) VALUES (?, ?, ?, ?)");
            $stmt->execute([$steamId, $name, $avatar, $isVacBanned]);
        } else {
            $stmt = $db->prepare("UPDATE users SET name = ?, avatar = ?, is_vac_banned = ?, last_updated = CURRENT_TIMESTAMP WHERE steam_id = ?");
            $stmt->execute([$name, $avatar, $isVacBanned, $steamId]);
        }

        // Process Games
        $db->beginTransaction();
        try {
            // Build recent games map for quick lookup
            $recentMap = [];
            foreach ($recentGames as $rg) {
                $recentMap[$rg['appid']] = $rg['playtime_2weeks'];
            }

            $stmt = $db->prepare("INSERT OR REPLACE INTO user_games (steam_id, app_id, playtime_forever, playtime_2weeks) VALUES (?, ?, ?, ?)");
            foreach ($ownedGames as $game) {
                $appId = $game['appid'];
                $playtimeForever = $game['playtime_forever'];
                $playtime2weeks = $recentMap[$appId] ?? 0;
                
                // Only store games with > 0 playtime to save DB space and relevance
                if ($playtimeForever > 0) {
                    $stmt->execute([$steamId, $appId, $playtimeForever, $playtime2weeks]);
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            die("Error saving games: " . $e->getMessage());
        }

        // Set Session
        $_SESSION['steam_id'] = $steamId;
        header("Location: /");
        exit;
    } else {
        die("Authentication failed.");
    }
} else if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: /");
    exit;
} else {
    // Redirect to Steam Login
    $url = $steamApi->getLoginUrl();
    header("Location: " . $url);
    exit;
}
