<?php
// config/config.php
return [
    'steam_api_key' => getenv('STEAM_API_KEY') ?: 'YOUR_STEAM_API_KEY_HERE',
    'base_url' => 'http://localhost:8000',
    'db_path' => __DIR__ . '/../db/database.sqlite'
];
