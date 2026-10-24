<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

require_auth();
$db = DB::getConnection();

$stmt = $db->query("SELECT DISTINCT country FROM users WHERE country IS NOT NULL AND country != '' ORDER BY country");
$countries = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Languages are stored as JSON arrays per user; aggregate the distinct
// values in PHP since JSON queries are not portable across SQLite builds.
$stmt = $db->query("SELECT languages FROM users WHERE setup_complete = 1 AND languages IS NOT NULL AND languages NOT IN ('', '[]')");
$languageRows = $stmt->fetchAll(PDO::FETCH_COLUMN);

$seenLanguages = [];

foreach ($languageRows as $json) {
    $list = json_decode((string) $json, true);

    if (is_array($list)) {
        foreach ($list as $language) {
            if (is_string($language) && $language !== '') {
                $seenLanguages[$language] = true;
            }
        }
    }
}

$languages = array_keys($seenLanguages);
sort($languages);

json_response([
    'countries' => $countries,
    'playstyles' => PLAYSTYLES,
    'languages' => $languages,
]);
