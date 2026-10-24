<?php
declare(strict_types=1);

/**
 * Seed a realistic demo database: 30 players with overlapping game
 * libraries, profiles and locally generated avatars, plus a few pre-seeded
 * likes so the demo user gets instant matches. Pairs with DEMO_MODE=1.
 *
 *   php db/seed.php             wipe and reseed (refuses if users exist)
 *   php db/seed.php --force     wipe and reseed unconditionally
 *   php db/seed.php --if-empty  only seed an empty database
 *
 * CLI only; the seeded data is deterministic (fixed RNG seed).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/config/db.php';
require $root . '/config/domain.php';

$force = in_array('--force', $argv, true);
$ifEmpty = in_array('--if-empty', $argv, true);

$db = DB::getConnection();
$schema = file_get_contents($root . '/db/schema.sql');

if (is_string($schema) && $schema !== '') {
    $db->exec($schema);
}

$existing = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($existing > 0 && $ifEmpty) {
    echo "Database already has {$existing} users; leaving it alone (--if-empty).\n";
    exit(0);
}

if ($existing > 0 && !$force) {
    fwrite(STDERR, "Database already has {$existing} users. Re-run with --force to wipe and reseed.\n");
    exit(1);
}

// Deterministic demo data.
mt_srand(42);

/** @return int minutes of playtime, roughly log-distributed */
$minutes = static fn (): int => (int) round(60 * exp(mt_rand(0, 100) / 100 * 5.5) / 100);

$games = [
    730 => ['Counter-Strike 2', 10],
    570 => ['Dota 2', 8],
    1172470 => ['Apex Legends', 7],
    252490 => ['Rust', 6],
    1086940 => ['Baldur\'s Gate 3', 6],
    1245620 => ['Elden Ring', 6],
    271590 => ['Grand Theft Auto V', 6],
    440 => ['Team Fortress 2', 5],
    105600 => ['Terraria', 5],
    252950 => ['Rocket League', 5],
    359550 => ['Rainbow Six Siege', 5],
    1966720 => ['Lethal Company', 4],
    230410 => ['Warframe', 4],
    381210 => ['Dead by Daylight', 4],
    413150 => ['Stardew Valley', 4],
    1145360 => ['Hades', 3],
    367520 => ['Hollow Knight', 3],
    289070 => ['Sid Meier\'s Civilization VI', 3],
    294100 => ['RimWorld', 3],
    427520 => ['Factorio', 3],
];

/** Popularity-weighted game bag. */
$bag = [];
foreach ($games as $appId => [, $weight]) {
    $bag = array_merge($bag, array_fill(0, $weight, $appId));
}

$countryLanguages = [
    'US' => ['English'],
    'GB' => ['English'],
    'CA' => ['English'],
    'AU' => ['English'],
    'DE' => ['German', 'English'],
    'FR' => ['French', 'English'],
    'PL' => ['Polish', 'English'],
    'BR' => ['Portuguese', 'English'],
    'SE' => ['Swedish', 'English'],
    'NL' => ['Dutch', 'English'],
    'JP' => ['Japanese', 'English'],
    'KR' => ['Korean', 'English'],
];
$countries = array_keys($countryLanguages);

$names = [
    'NovaKnight', 'PixelWarden', 'CtrlAltElite', 'FragMagnet', 'LumaTank',
    'NoScopeNina', 'ByteBandit', 'QuartzFox', 'EchoViolet', 'MellowMint',
    'RushBRat', 'AstroPuff', 'VortexVera', 'MidnightMage', 'TurboToast',
    'LunarLarry', 'CopperCactus', 'ZenZebra', 'HexHunter', 'DaisyDoom',
    'FrostyFingers', 'RiotRabbit', 'SonicSherlock', 'GrumpyGoblin',
    'VoltageVixen', 'MooMooMike', 'PandaExpresso', 'SaltShaker', 'WaffleWizard',
    'GigaGus',
];

$palette = ['#66c0f4', '#a4d007', '#ff4c4c', '#e0a03a', '#9b6df3', '#3fd2a5', '#f06292', '#7986cb'];

$avatarDir = $root . '/public/avatars';
if (!is_dir($avatarDir)) {
    mkdir($avatarDir, 0755, true);
}

/** Write a gradient avatar with the player's initials and return its web path. */
$makeAvatar = static function (string $steamId, string $name) use ($avatarDir, $palette): string {
    $color = $palette[crc32($steamId) % count($palette)];
    $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'P', 0, 2));

    $svg = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="%s"/><stop offset="1" stop-color="#171a21"/>'
        . '</linearGradient></defs>'
        . '<rect width="200" height="200" fill="url(#g)"/>'
        . '<text x="100" y="128" font-family="Arial, Helvetica, sans-serif" font-size="72" font-weight="800" fill="#ffffff" text-anchor="middle">%s</text>'
        . '</svg>',
        $color,
        htmlspecialchars($initials, ENT_XML1)
    );

    $path = $avatarDir . '/' . $steamId . '.svg';
    file_put_contents($path, $svg);

    return '/avatars/' . $steamId . '.svg';
};

// Wipe in FK-safe order.
$db->exec('DELETE FROM matches');
$db->exec('DELETE FROM swipes');
$db->exec('DELETE FROM user_games');
$db->exec('DELETE FROM users');

$demoId = '76561198000000001';
$demoGames = [
    730 => ['Counter-Strike 2', 96000, 480],
    570 => ['Dota 2', 42000, 0],
    252490 => ['Rust', 21000, 120],
    1172470 => ['Apex Legends', 15000, 300],
    1086940 => ['Baldur\'s Gate 3', 9000, 0],
];

try {
    $db->beginTransaction();

    $userStmt = $db->prepare(
        'INSERT INTO users (steam_id, name, avatar, discord_handle, playstyle, languages, age, country, is_vac_banned, setup_complete)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $gameStmt = $db->prepare(
        'INSERT INTO user_games (steam_id, app_id, name, playtime_forever, playtime_2weeks)
         VALUES (?, ?, ?, ?, ?)'
    );

    // The demo player: rich profile, high overlap with several others.
    $userStmt->execute([
        $demoId,
        'DemoPlayer',
        $makeAvatar($demoId, 'DemoPlayer'),
        'demo_player',
        'Chill',
        json_encode(['English']),
        23,
        'US',
        0,
    ]);

    foreach ($demoGames as $appId => [$name, $playtime, $recent]) {
        $gameStmt->execute([$demoId, $appId, $name, $playtime, $recent]);
    }

    $seededUsers = [];

    foreach ($names as $index => $name) {
        $steamId = '7656119800000' . str_pad((string) (100 + $index), 5, '0', STR_PAD_LEFT);
        $country = $countries[$index % count($countries)];
        $librarySize = mt_rand(6, 14);
        $picked = array_unique(array_map(static fn () => $bag[mt_rand(0, count($bag) - 1)], range(1, $librarySize + 4)));

        $userStmt->execute([
            $steamId,
            $name,
            $makeAvatar($steamId, $name),
            strtolower($name) . mt_rand(10, 99),
            PLAYSTYLES[$index % count(PLAYSTYLES)],
            json_encode($countryLanguages[$country]),
            mt_rand(17, 42),
            $country,
            // Two banned players so the "no VAC" filter has something to hide.
            $index === 6 || $index === 19 ? 1 : 0,
        ]);

        foreach (array_slice($picked, 0, $librarySize) as $appId) {
            $gameStmt->execute([
                $steamId,
                $appId,
                $games[$appId][0],
                $minutes(),
                mt_rand(1, 100) <= 30 ? mt_rand(30, 600) : 0,
            ]);
        }

        $seededUsers[$name] = $steamId;
    }

    // Pre-seeded engagement: five players already liked the demo player, so
    // the first right swipe triggers the match celebration, and one pair is
    // already mutual so the matches drawer is not empty.
    $swipeStmt = $db->prepare('INSERT OR IGNORE INTO swipes (swiper_id, swiped_id, action) VALUES (?, ?, ?)');
    $matchStmt = $db->prepare('INSERT OR IGNORE INTO matches (user1_id, user2_id) VALUES (?, ?)');

    $admirers = array_slice($names, 0, 5);
    foreach ($admirers as $name) {
        $swipeStmt->execute([$seededUsers[$name], $demoId, 'like']);
    }

    $mutual = $seededUsers[$names[0]];
    $swipeStmt->execute([$demoId, $mutual, 'like']);
    $matchStmt->execute([min($demoId, $mutual), max($demoId, $mutual)]);

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Seeding failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$gameCount = (int) $db->query('SELECT COUNT(*) FROM user_games')->fetchColumn();

echo "Seeded {$userCount} players and {$gameCount} library entries.\n";
echo "Demo login (requires DEMO_MODE=1):\n";
echo "  http://localhost:8000/api/auth.php?demo_login={$demoId}\n";
