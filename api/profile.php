<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$steamId = require_auth();
$db = DB::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT steam_id, name, avatar, discord_handle, playstyle, languages, age, country, setup_complete FROM users WHERE steam_id = ?');
    $stmt->execute([$steamId]);
    $user = $stmt->fetch();

    if (!$user) {
        json_response(['error' => 'Profile not found'], 404);
    }

    json_response($user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_same_origin();
    rate_limit('profile', 20, 60);

    $data = read_json_body();

    $discord = trim((string) ($data['discord_handle'] ?? ''));
    $playstyle = (string) ($data['playstyle'] ?? '');
    $country = strtoupper(trim((string) ($data['country'] ?? '')));
    $age = isset($data['age']) && $data['age'] !== '' ? (int) $data['age'] : null;
    $languages = $data['languages'] ?? [];

    if ($discord === '') {
        json_response(['success' => false, 'error' => 'Discord handle is required'], 422);
    }

    if (mb_strlen($discord) > 64 || !preg_match('/^[A-Za-z0-9._#\-]+$/', $discord)) {
        json_response(['success' => false, 'error' => 'Discord handle contains invalid characters'], 422);
    }

    if ($playstyle !== '' && !in_array($playstyle, PLAYSTYLES, true)) {
        json_response(['success' => false, 'error' => 'Unknown playstyle'], 422);
    }

    if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
        json_response(['success' => false, 'error' => 'Country must be a two letter code'], 422);
    }

    if ($age !== null && ($age < 13 || $age > 99)) {
        json_response(['success' => false, 'error' => 'Age must be between 13 and 99'], 422);
    }

    if (!is_array($languages)) {
        $languages = [];
    }

    $languages = array_values(array_unique(array_filter(
        array_map(static fn ($language) => trim((string) $language), $languages),
        static fn (string $language) => $language !== '' && mb_strlen($language) <= 32
    )));

    $stmt = $db->prepare('UPDATE users SET discord_handle = ?, playstyle = ?, languages = ?, age = ?, country = ?, setup_complete = 1 WHERE steam_id = ?');
    $stmt->execute([
        $discord,
        $playstyle,
        json_encode(array_slice($languages, 0, 10)),
        $age,
        $country,
        $steamId,
    ]);

    json_response(['success' => true]);
}

json_response(['error' => 'Method not allowed'], 405);
