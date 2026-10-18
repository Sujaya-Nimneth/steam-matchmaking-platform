<?php
declare(strict_types=1);

/**
 * Dependency-free regression tests. Run with: php tests/run.php
 */

$root = dirname(__DIR__);
require $root . '/config/db.php';
require $root . '/services/SteamAPI.php';
require $root . '/services/MatchEngine.php';

$failures = 0;
$passed = 0;

function test(string $name, callable $fn): void
{
    global $failures, $passed;

    try {
        $fn();
        $passed++;
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  FAIL {$name}: {$e->getMessage()}\n";
    }
}

function assert_true(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

echo "SteamAPI::validateLogin\n";

$api = new SteamAPI();

test('rejects a non-id_res assertion', function () use ($api) {
    assert_same(null, $api->validateLogin(['openid_mode' => 'cancel']));
});

test('rejects a missing mode', function () use ($api) {
    assert_same(null, $api->validateLogin([]));
});

test('rejects an assertion without claimed_id', function () use ($api) {
    assert_same(null, $api->validateLogin([
        'openid_mode' => 'id_res',
        'openid_assoc_handle' => 'h',
        'openid_signed' => 'signed,op_endpoint',
        'openid_sig' => 'sig',
    ]));
});

test('rejects an assertion where claimed_id is not signed', function () use ($api) {
    // An attacker can control claimed_id while Steam only signs other fields.
    assert_same(null, $api->validateLogin([
        'openid_mode' => 'id_res',
        'openid_assoc_handle' => 'h',
        'openid_signed' => 'signed,op_endpoint,return_to',
        'openid_sig' => 'sig',
        'openid_claimed_id' => 'https://steamcommunity.com/openid/id/76561198000000001',
    ]));
});

test('rejects empty string fields', function () use ($api) {
    assert_same(null, $api->validateLogin([
        'openid_mode' => 'id_res',
        'openid_assoc_handle' => '',
        'openid_signed' => 'claimed_id',
        'openid_sig' => 'sig',
        'openid_claimed_id' => 'https://steamcommunity.com/openid/id/76561198000000001',
    ]));
});

echo "\nDB\n";

test('enables foreign keys on every connection', function () use ($root) {
    $db = DB::getConnection();
    assert_same(1, (int) $db->query('PRAGMA foreign_keys')->fetchColumn());
});

test('enables WAL journaling', function () use ($root) {
    $db = DB::getConnection();
    assert_same('wal', strtolower((string) $db->query('PRAGMA journal_mode')->fetchColumn()));
});

echo "\nMatchEngine\n";

$gamesA = [
    ['app_id' => 570, 'playtime_forever' => 6000, 'playtime_2weeks' => 120],
    ['app_id' => 730, 'playtime_forever' => 3000, 'playtime_2weeks' => 0],
];

test('returns zero when there are no shared games', function () use ($gamesA) {
    $result = MatchEngine::calculateScore(['playstyle' => 'Chill'], ['playstyle' => 'Chill'], $gamesA, [
        ['app_id' => 10, 'playtime_forever' => 100, 'playtime_2weeks' => 0],
    ]);
    assert_same(0, $result['score']);
    assert_same([], $result['shared_games']);
});

test('scores similar playtime higher than mismatched playtime', function () use ($gamesA) {
    $close = MatchEngine::calculateScore([], [], $gamesA, [
        ['app_id' => 570, 'playtime_forever' => 5800, 'playtime_2weeks' => 60],
        ['app_id' => 730, 'playtime_forever' => 2900, 'playtime_2weeks' => 0],
    ]);
    $far = MatchEngine::calculateScore([], [], $gamesA, [
        ['app_id' => 570, 'playtime_forever' => 60, 'playtime_2weeks' => 0],
        ['app_id' => 730, 'playtime_forever' => 30, 'playtime_2weeks' => 0],
    ]);
    assert_true($close['score'] > $far['score'], 'close playtime should score higher');
});

test('never exceeds 100', function () use ($gamesA) {
    $many = [];
    for ($i = 1; $i <= 60; $i++) {
        $many[] = ['app_id' => $i, 'playtime_forever' => 6000, 'playtime_2weeks' => 100];
    }
    $result = MatchEngine::calculateScore(['playstyle' => 'Chill'], ['playstyle' => 'Chill'], $many, $many);
    assert_true($result['score'] <= 100, 'score must be capped at 100');
});

test('limits shared game details to three', function () use ($gamesA) {
    $result = MatchEngine::calculateScore([], [], $gamesA, [
        ['app_id' => 570, 'playtime_forever' => 100, 'playtime_2weeks' => 0],
        ['app_id' => 730, 'playtime_forever' => 100, 'playtime_2weeks' => 0],
        ['app_id' => 440, 'playtime_forever' => 100, 'playtime_2weeks' => 0],
    ]);
    assert_same(2, count($result['shared_games']));
});

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
