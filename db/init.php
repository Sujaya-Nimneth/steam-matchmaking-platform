<?php
declare(strict_types=1);

// This script mutates the database and must only run from the command line.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db.php';

$db = DB::getConnection();
$schema = file_get_contents(__DIR__ . '/schema.sql');

if ($schema === false) {
    fwrite(STDERR, "Could not read db/schema.sql\n");
    exit(1);
}

try {
    $db->exec($schema);

    // Databases created before game names were stored need a forward migration.
    $columns = $db->query('PRAGMA table_info(user_games)')->fetchAll(PDO::FETCH_COLUMN, 1);

    if (!in_array('name', $columns, true)) {
        $db->exec('ALTER TABLE user_games ADD COLUMN name TEXT');
        echo "Migrated user_games: added name column\n";
    }

    echo "Database initialized successfully.\n";
} catch (PDOException $e) {
    fwrite(STDERR, 'Error initializing database: ' . $e->getMessage() . "\n");
    exit(1);
}
