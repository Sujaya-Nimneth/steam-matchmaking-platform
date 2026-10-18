<?php
// config/config.php
return [
    'steam_api_key' => getenv('STEAM_API_KEY') ?: 'YOUR_STEAM_API_KEY_HERE',
    'base_url' => rtrim(getenv('APP_BASE_URL') ?: 'http://localhost:8000', '/'),
    'db_path' => getenv('DB_PATH') ?: __DIR__ . '/../db/database.sqlite',
];
