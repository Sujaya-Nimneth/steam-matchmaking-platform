<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require __DIR__ . '/../services/SteamAPI.php';

$steamApi = new SteamAPI();

$mode = $_GET['openid_mode'] ?? null;

if (isset($_GET['logout'])) {
    // Logout changes session state, so it is a POST with a same-origin
    // check; a plain GET link could be triggered cross-site.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'Method not allowed'], 405);
    }

    require_same_origin();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    json_response(['success' => true]);
}

if ($mode === 'cancel') {
    header('Location: /?auth=cancelled');
    exit;
}

if ($mode !== null) {
    $steamId = $steamApi->validateLogin($_GET);

    if ($steamId === null) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Authentication failed.';
        exit;
    }

    $summary = $steamApi->getPlayerSummary($steamId);

    if ($summary === null) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Could not load your Steam profile. Please try again.';
        exit;
    }

    $bans = $steamApi->getPlayerBans($steamId);
    $ownedGames = $steamApi->getOwnedGames($steamId);
    $recentGames = $steamApi->getRecentlyPlayedGames($steamId);

    $db = DB::getConnection();

    $isVacBanned = !empty($bans['VACBanned']) ? 1 : 0;
    $name = (string) ($summary['personaname'] ?? 'Unknown');
    $avatar = (string) ($summary['avatarfull'] ?? '');

    $stmt = $db->prepare('SELECT steam_id FROM users WHERE steam_id = ?');
    $stmt->execute([$steamId]);

    if ($stmt->fetch()) {
        $stmt = $db->prepare('UPDATE users SET name = ?, avatar = ?, is_vac_banned = ?, last_updated = CURRENT_TIMESTAMP WHERE steam_id = ?');
        $stmt->execute([$name, $avatar, $isVacBanned, $steamId]);
    } else {
        $stmt = $db->prepare('INSERT INTO users (steam_id, name, avatar, is_vac_banned) VALUES (?, ?, ?, ?)');
        $stmt->execute([$steamId, $name, $avatar, $isVacBanned]);
    }

    $recentMap = [];
    foreach ($recentGames as $recent) {
        $recentMap[$recent['appid']] = $recent['playtime_2weeks'] ?? 0;
    }

    $db->beginTransaction();

    try {
        $stmt = $db->prepare('INSERT OR REPLACE INTO user_games (steam_id, app_id, name, playtime_forever, playtime_2weeks) VALUES (?, ?, ?, ?, ?)');

        foreach ($ownedGames as $game) {
            $playtimeForever = (int) ($game['playtime_forever'] ?? 0);

            // Games never played add noise and storage without improving matches.
            if ($playtimeForever <= 0) {
                continue;
            }

            $stmt->execute([
                $steamId,
                (int) $game['appid'],
                mb_substr((string) ($game['name'] ?? ''), 0, 200),
                $playtimeForever,
                (int) ($recentMap[$game['appid']] ?? 0),
            ]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Failed to sync Steam library: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Could not save your Steam library. Please try again.';
        exit;
    }

    // Rotate the session ID so a pre-login session cannot be reused.
    session_regenerate_id(true);
    $_SESSION['steam_id'] = $steamId;
    $_SESSION['authenticated_at'] = time();

    header('Location: /');
    exit;
}

header('Location: ' . $steamApi->getLoginUrl());
exit;
