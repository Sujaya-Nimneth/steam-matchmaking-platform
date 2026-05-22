-- db/schema.sql
CREATE TABLE IF NOT EXISTS users (
    steam_id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    avatar TEXT,
    discord_handle TEXT,
    playstyle TEXT,
    languages TEXT,
    age INTEGER,
    country TEXT,
    is_vac_banned INTEGER DEFAULT 0,
    setup_complete INTEGER DEFAULT 0,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_games (
    steam_id TEXT,
    app_id INTEGER,
    playtime_forever INTEGER,
    playtime_2weeks INTEGER DEFAULT 0,
    PRIMARY KEY (steam_id, app_id),
    FOREIGN KEY (steam_id) REFERENCES users(steam_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS swipes (
    swiper_id TEXT,
    swiped_id TEXT,
    action TEXT CHECK( action IN ('like', 'pass') ),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (swiper_id, swiped_id),
    FOREIGN KEY (swiper_id) REFERENCES users(steam_id) ON DELETE CASCADE,
    FOREIGN KEY (swiped_id) REFERENCES users(steam_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS matches (
    user1_id TEXT,
    user2_id TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user1_id, user2_id),
    FOREIGN KEY (user1_id) REFERENCES users(steam_id) ON DELETE CASCADE,
    FOREIGN KEY (user2_id) REFERENCES users(steam_id) ON DELETE CASCADE
);
