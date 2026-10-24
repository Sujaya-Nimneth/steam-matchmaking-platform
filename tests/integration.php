<?php
declare(strict_types=1);

/**
 * Dependency-free HTTP integration tests. Included by tests/run.php (which
 * provides the test()/assert_* helpers) after the unit suite. Boots the PHP
 * built-in server against a seeded throwaway database with DEMO_MODE=1 and
 * exercises the real request path: router, session auth, validation,
 * swiping, matching and rate limiting.
 *
 * Guard clauses return early instead of failing when the environment cannot
 * spawn processes (e.g. restricted CI runners).
 */

$root = dirname(__DIR__);

if (!function_exists('proc_open') || !function_exists('curl_init') || PHP_OS_FAMILY === 'Windows') {
    echo "\nHTTP integration: skipped (proc_open/curl unavailable)\n";
    return;
}

echo "\nHTTP integration\n";

// ---- Fixtures ----------------------------------------------------------

$dbFile = sys_get_temp_dir() . '/lfg-http-' . getmypid() . '.sqlite';
$logFile = $dbFile . '.server.log';
$alice = '76561198000000001';
$bob = '76561198000000002';
$carol = '76561198000000003';

$pdo = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec((string) file_get_contents($root . '/db/schema.sql'));

$fixtures = [
    // steam_id, name, discord, country, languages, games (name => playtime minutes)
    [$alice, 'Alice', 'alice_gg', 'US', ['English'], ['Dota 2' => 240000, 'Rust' => 48000]],
    [$bob, 'Bob', 'bob_gg', 'DE', ['German', 'English'], ['Dota 2' => 180000, 'Rust' => 3000]],
    [$carol, 'Carol', 'carol_gg', 'US', ['English'], ['Stardew Valley' => 30000]],
];

$appIds = ['Dota 2' => 570, 'Rust' => 252490, 'Stardew Valley' => 413150];

$userStmt = $pdo->prepare(
    'INSERT INTO users (steam_id, name, avatar, discord_handle, playstyle, languages, age, country, setup_complete)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
);
$gameStmt = $pdo->prepare(
    'INSERT INTO user_games (steam_id, app_id, name, playtime_forever, playtime_2weeks)
     VALUES (?, ?, ?, ?, ?)'
);

foreach ($fixtures as [$steamId, $name, $discord, $country, $languages, $games]) {
    $userStmt->execute([$steamId, $name, '', $discord, 'Chill', json_encode($languages), 24, $country]);

    foreach ($games as $gameName => $playtime) {
        $gameStmt->execute([$steamId, $appIds[$gameName], $gameName, $playtime, 0]);
    }
}

// ---- Server ------------------------------------------------------------

$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock === false) {
    echo "  SKIP could not allocate a port ({$errstr})\n";
    return;
}
$port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
fclose($sock);

$process = proc_open(
    sprintf(
        'php -S 127.0.0.1:%d -t %s %s',
        $port,
        escapeshellarg($root . '/public'),
        escapeshellarg($root . '/public/router.php')
    ),
    [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
    $pipes,
    $root,
    [
        'DB_PATH' => $dbFile,
        'DEMO_MODE' => '1',
        'STEAM_API_KEY' => 'YOUR_STEAM_API_KEY_HERE',
        'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
    ]
);

if (!is_resource($process)) {
    echo "  SKIP could not spawn the built-in server (see {$logFile})\n";
    return;
}

$base = "http://127.0.0.1:{$port}";

// Wait for the server to answer before running assertions.
$ready = false;
$probeContext = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
for ($attempt = 0; $attempt < 50; $attempt++) {
    usleep(100000);
    if (@file_get_contents($base . '/', false, $probeContext) !== false) {
        $ready = true;
        break;
    }
}

if (!$ready) {
    proc_terminate($process);
    proc_close($process);
    echo "  SKIP built-in server did not come up (see {$logFile})\n";
    return;
}

register_shutdown_function(static function () use ($process, $dbFile, $logFile): void {
    proc_terminate($process);
    proc_close($process);
    foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
        @unlink($dbFile . $suffix);
    }
    @unlink($logFile);
});

/**
 * Perform one HTTP request against the test server.
 *
 * @param array<string, string> $headers
 * @return array{0: int, 1: string, 2: string} status, body, header block
 */
$http = static function (string $method, string $path, ?string $body = null, array $headers = [], ?string $cookie = null) use ($base): array {
    $curlHeaders = [];
    foreach ($headers as $name => $value) {
        $curlHeaders[] = $name . ': ' . $value;
    }

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $curlHeaders,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($cookie !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5; the
    // handle is released when $ch goes out of scope.

    if ($raw === false) {
        return [0, '', ''];
    }

    return [$status, substr((string) $raw, $headerSize), substr((string) $raw, 0, $headerSize)];
};

$jsonBody = static fn (array $data): string => (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$origin = ['Origin' => $base, 'Content-Type' => 'application/json'];

/** Log a fixture user in via demo mode and return the session cookie. */
$login = static function (string $steamId) use ($http): string {
    [, , $headers] = $http('GET', '/api/auth.php?demo_login=' . $steamId);
    return preg_match('/Set-Cookie: (\w+=[^;]+)/i', $headers, $m) ? $m[1] : '';
};

$cookieAlice = $login($alice);
$cookieBob = $login($bob);
$cookieCarol = $login($carol);

// ---- Tests -------------------------------------------------------------

test('demo login issues a session cookie', function () use ($cookieAlice) {
    assert_true($cookieAlice !== '', 'demo login should set a session cookie');
});

test('serves the SPA shell', function () use ($http) {
    [$status, $body] = $http('GET', '/');
    assert_same(200, $status);
    assert_true(str_contains($body, 'Steam Matchmaking Platform'), 'shell should carry the app title');
});

test('serves static assets through the router', function () use ($http) {
    [$status] = $http('GET', '/style.css');
    assert_same(200, $status);
});

test('renders unknown pages as the SPA', function () use ($http) {
    [$status, $body] = $http('GET', '/some/unknown/page');
    assert_same(200, $status);
    assert_true(str_contains($body, '<html'), 'unknown paths should fall back to the shell');
});

test('blocks private directories and dotfiles', function () use ($http) {
    foreach (['/config/config.php', '/db/schema.sql', '/services/MatchEngine.php', '/tests/run.php', '/deploy/apache.conf', '/.gitignore'] as $path) {
        [$status] = $http('GET', $path);
        assert_same(404, $status, "{$path} must not be reachable");
    }
});

test('404s unknown API routes as JSON', function () use ($http) {
    [$status, $body] = $http('GET', '/api/nope.php');
    assert_same(404, $status);
    assert_true(str_contains($body, '"error"'), 'unknown API routes should return JSON');
});

test('requires authentication for JSON endpoints', function () use ($http) {
    foreach (['/api/profile.php', '/api/matches.php', '/api/matches.php?list=1', '/api/filters.php'] as $path) {
        [$status] = $http('GET', $path);
        assert_same(401, $status, "{$path} should require a session");
    }
});

test('logout requires POST and a same-origin request', function () use ($http) {
    [$status] = $http('GET', '/api/auth.php?logout=1');
    assert_same(405, $status, 'GET logout should be rejected');

    [$status] = $http('POST', '/api/auth.php?logout=1');
    assert_same(403, $status, 'POST without Origin should be rejected');
});

test('profile GET returns the signed-in player', function () use ($http, $cookieAlice, $alice) {
    [$status, $body] = $http('GET', '/api/profile.php', null, [], $cookieAlice);
    $data = json_decode($body, true);
    assert_same(200, $status);
    assert_same($alice, $data['steam_id'] ?? null);
    assert_same('alice_gg', $data['discord_handle'] ?? null);
});

test('profile POST rejects cross-origin writes', function () use ($http, $cookieAlice) {
    [$status] = $http('POST', '/api/profile.php', '{"discord_handle":"x"}', ['Content-Type' => 'application/json'], $cookieAlice);
    assert_same(403, $status);
});

test('profile POST validates every field', function () use ($http, $cookieCarol, $origin) {
    $cases = [
        'missing discord' => '{"playstyle":"Chill"}',
        'invalid discord characters' => '{"discord_handle":"bad handle!"}',
        'unknown playstyle' => '{"discord_handle":"ok_user","playstyle":"Sweaty"}',
        'bad country' => '{"discord_handle":"ok_user","country":"USA"}',
        'out-of-range age' => '{"discord_handle":"ok_user","age":5}',
    ];

    foreach ($cases as $label => $payload) {
        [$status] = $http('POST', '/api/profile.php', $payload, $origin, $cookieCarol);
        assert_same(422, $status, "{$label} should be rejected with 422");
    }

    [$status] = $http('POST', '/api/profile.php', 'not json at all', $origin, $cookieCarol);
    assert_same(400, $status, 'invalid JSON should be rejected with 400');
});

test('profile POST rejects oversized bodies', function () use ($http, $cookieCarol, $origin) {
    [$status] = $http('POST', '/api/profile.php', '{"discord_handle":"' . str_repeat('a', 20000) . '"}', $origin, $cookieCarol);
    assert_same(413, $status);
});

test('profile POST saves a valid profile', function () use ($http, $cookieCarol, $origin, $jsonBody) {
    $payload = $jsonBody([
        'discord_handle' => 'carol_gg',
        'playstyle' => 'Tryhard',
        'age' => 30,
        'country' => 'US',
        'languages' => ['English', 'French'],
    ]);

    [$status] = $http('POST', '/api/profile.php', $payload, $origin, $cookieCarol);
    assert_same(200, $status);

    [, $body] = $http('GET', '/api/profile.php', null, [], $cookieCarol);
    $data = json_decode($body, true);
    assert_same('Tryhard', $data['playstyle'] ?? null);
    assert_same(['English', 'French'], $data['languages'] ?? null);
});

test('the deck only contains candidates with shared games', function () use ($http, $cookieAlice, $bob, $carol) {
    [$status, $body] = $http('GET', '/api/matches.php', null, [], $cookieAlice);
    $data = json_decode($body, true);
    assert_same(200, $status);

    $ids = array_column($data['matches'] ?? [], 'steam_id');
    assert_true(in_array($bob, $ids, true), 'Bob shares Dota 2 and Rust with Alice');
    assert_true(!in_array($carol, $ids, true), 'Carol shares nothing with Alice');

    foreach ($data['matches'] ?? [] as $match) {
        if ($match['steam_id'] === $bob) {
            assert_true(($match['score'] ?? 0) > 0, 'a shared-library candidate must score above zero');
            assert_same(['German', 'English'], $match['languages'] ?? null, 'languages must be decoded');
        }
    }
});

test('the deck honours the language filter', function () use ($http, $cookieAlice, $bob) {
    [, $body] = $http('GET', '/api/matches.php?language=German', null, [], $cookieAlice);
    $data = json_decode($body, true);
    assert_true(in_array($bob, array_column($data['matches'] ?? [], 'steam_id'), true), 'Bob speaks German');

    [, $body] = $http('GET', '/api/matches.php?language=French', null, [], $cookieAlice);
    $data = json_decode($body, true);
    assert_same([], $data['matches'] ?? [], 'nobody in the deck speaks French');
});

test('the deck honours the country filter', function () use ($http, $cookieAlice, $bob) {
    [, $body] = $http('GET', '/api/matches.php?country=DE', null, [], $cookieAlice);
    $data = json_decode($body, true);
    assert_same([$bob], array_column($data['matches'] ?? [], 'steam_id'));

    [, $body] = $http('GET', '/api/matches.php?country=JP', null, [], $cookieAlice);
    $data = json_decode($body, true);
    assert_same([], $data['matches'] ?? []);
});

test('filters list the populated options', function () use ($http, $cookieAlice) {
    [$status, $body] = $http('GET', '/api/filters.php', null, [], $cookieAlice);
    $data = json_decode($body, true);
    assert_same(200, $status);
    assert_same(['DE', 'US'], $data['countries'] ?? null);
    assert_same(['English', 'French', 'German'], $data['languages'] ?? null);
    assert_same(PLAYSTYLES, $data['playstyles'] ?? null);
});

test('swipes are validated', function () use ($http, $cookieAlice, $alice, $origin) {
    [$status] = $http('POST', '/api/swipe.php', '{"swiped_id":"' . $alice . '","action":"like"}', $origin, $cookieAlice);
    assert_same(422, $status, 'self swipes should be rejected');

    [$status] = $http('POST', '/api/swipe.php', '{"swiped_id":"76561198000000002","action":"maybe"}', $origin, $cookieAlice);
    assert_same(422, $status, 'unknown actions should be rejected');

    [$status] = $http('POST', '/api/swipe.php', '{"swiped_id":"76561199999999999","action":"like"}', $origin, $cookieAlice);
    assert_same(404, $status, 'unknown players should 404');

    [$status] = $http('GET', '/api/swipe.php', null, [], $cookieAlice);
    assert_same(405, $status);
});

test('a mutual like creates exactly one match and reveals the handle', function () use ($http, $pdo, $cookieAlice, $cookieBob, $bob, $origin) {
    [, $body] = $http('POST', '/api/swipe.php', '{"swiped_id":"' . $bob . '","action":"like"}', $origin, $cookieAlice);
    $data = json_decode($body, true);
    assert_same(false, $data['is_match'] ?? null, 'no match before the other side likes');

    [, $body] = $http('POST', '/api/swipe.php', '{"swiped_id":"' . '76561198000000001' . '","action":"like"}', $origin, $cookieBob);
    $data = json_decode($body, true);
    assert_same(true, $data['is_match'] ?? null);
    assert_same('alice_gg', $data['match_data']['discord_handle'] ?? null, 'the handle is revealed on match');

    $rows = $pdo->query('SELECT user1_id, user2_id FROM matches')->fetchAll();
    assert_same(1, count($rows), 'exactly one match row should exist');
});

test('repeated and conflicting swipes stay idempotent', function () use ($http, $pdo, $cookieAlice, $bob, $origin) {
    // A stale re-sent like, then a late pass: the stored first swipe is final.
    [, $body] = $http('POST', '/api/swipe.php', '{"swiped_id":"' . $bob . '","action":"like"}', $origin, $cookieAlice);
    assert_same(true, json_decode($body, true)['is_match'] ?? null);

    [, $body] = $http('POST', '/api/swipe.php', '{"swiped_id":"' . $bob . '","action":"pass"}', $origin, $cookieAlice);
    assert_same(true, json_decode($body, true)['is_match'] ?? null, 'a late pass cannot undo a like');

    $stmt = $pdo->prepare('SELECT action FROM swipes WHERE swiper_id = ? AND swiped_id = ?');
    $stmt->execute(['76561198000000001', $bob]);
    assert_same('like', $stmt->fetchColumn());
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM matches')->fetchColumn());
});

test('the match list shows confirmed matches', function () use ($http, $cookieAlice, $bob) {
    [, $body] = $http('GET', '/api/matches.php?list=1', null, [], $cookieAlice);
    $data = json_decode($body, true);
    $ids = array_column($data['matches'] ?? [], 'steam_id');
    assert_same([$bob], $ids);
    assert_same('bob_gg', $data['matches'][0]['discord_handle'] ?? null);
});

test('swiped players leave the deck', function () use ($http, $cookieAlice, $bob) {
    [, $body] = $http('GET', '/api/matches.php', null, [], $cookieAlice);
    $data = json_decode($body, true);
    assert_true(!in_array($bob, array_column($data['matches'] ?? [], 'steam_id'), true), 'already-swiped players are excluded');
});

test('profile writes are rate limited per session', function () use ($http, $login, $carol, $origin) {
    $freshCookie = $login($carol);
    assert_true($freshCookie !== '', 'fresh demo login should work');

    $lastStatus = 0;
    for ($i = 0; $i < 21; $i++) {
        [$lastStatus] = $http('POST', '/api/profile.php', '{"discord_handle":"carol_gg"}', $origin, $freshCookie);
    }
    assert_same(429, $lastStatus, 'the 21st write inside a minute should be throttled');
});

test('logout destroys the session', function () use ($http, $login, $carol, $origin) {
    $freshCookie = $login($carol);

    [$status] = $http('POST', '/api/auth.php?logout=1', '{}', $origin, $freshCookie);
    assert_same(200, $status);

    [$status] = $http('GET', '/api/profile.php', null, [], $freshCookie);
    assert_same(401, $status, 'the session cookie must be dead after logout');
});

test('demo login is refused without DEMO_MODE', function () use ($root, $dbFile) {
    // A second server without DEMO_MODE proves the backdoor is closed.
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
    fclose($sock);

    $strict = proc_open(
        sprintf('php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/router.php')),
        [1 => ['file', $dbFile . '.strict.log', 'w'], 2 => ['file', $dbFile . '.strict.log', 'a']],
        $pipes,
        $root,
        [
            'DB_PATH' => $dbFile,
            'STEAM_API_KEY' => 'YOUR_STEAM_API_KEY_HERE',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ]
    );

    if (!is_resource($strict)) {
        assert_true(true, 'skipped: could not spawn second server');
        return;
    }

    $strictBase = "http://127.0.0.1:{$port}";
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        if (@file_get_contents($strictBase . '/', false, $ctx) !== false) {
            break;
        }
    }

    $ch = curl_init($strictBase . '/api/auth.php?demo_login=76561198000000001');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    proc_terminate($strict);
    proc_close($strict);
    @unlink($dbFile . '.strict.log');

    assert_same(404, $status, 'demo login must 404 unless DEMO_MODE=1');
});
