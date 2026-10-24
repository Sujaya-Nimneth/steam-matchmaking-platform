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
    // Assertion validation plus a full library sync is the most expensive
    // write path in the app.
    rate_limit('login', 10, 60);

    $steamId = $steamApi->validateLogin($_GET);

    if ($steamId === null) {
        json_response(['error' => 'Steam authentication failed. Please try again.'], 401);
    }

    // Summary, bans, owned games and recent games in one concurrent round trip.
    $bundle = $steamApi->getProfileBundle($steamId);
    $summary = $bundle['summary'];

    if ($summary === null) {
        json_response(['error' => 'Could not load your Steam profile. Please try again.'], 502);
    }

    $bans = $bundle['bans'];
    $ownedGames = $bundle['owned_games'];
    $recentGames = $bundle['recent_games'];

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
        $rows = [];

        foreach ($ownedGames as $game) {
            $playtimeForever = (int) ($game['playtime_forever'] ?? 0);

            // Games never played add noise and storage without improving matches.
            if ($playtimeForever <= 0) {
                continue;
            }

            $rows[] = [
                $steamId,
                (int) $game['appid'],
                mb_substr((string) ($game['name'] ?? ''), 0, 200),
                $playtimeForever,
                (int) ($recentMap[$game['appid']] ?? 0),
            ];
        }

        // One multi-row statement per 150 games instead of one execute per
        // game: a 3,000-title library drops from ~3,000 round trips to ~20.
        // 750 bound parameters stays under SQLite's 999-variable default.
        foreach (array_chunk($rows, 150) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'));

            $stmt = $db->prepare("INSERT OR REPLACE INTO user_games (steam_id, app_id, name, playtime_forever, playtime_2weeks) VALUES {$placeholders}");
            $stmt->execute(array_merge(...$chunk));
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Failed to sync Steam library: ' . $e->getMessage());
        json_response(['error' => 'Could not save your Steam library. Please try again.'], 500);
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
