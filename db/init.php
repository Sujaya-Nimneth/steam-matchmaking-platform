<?php
// db/init.php
require __DIR__ . '/../config/db.php';

$db = DB::getConnection();
$schema = file_get_contents(__DIR__ . '/schema.sql');

try {
    $db->exec($schema);
    echo "Database initialized successfully.\n";
} catch (PDOException $e) {
    die("Error initializing database: " . $e->getMessage() . "\n");
}
