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
    name TEXT,
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

-- Candidate discovery joins user_games by app_id and filters on setup state.
CREATE INDEX IF NOT EXISTS idx_user_games_app ON user_games(app_id);
CREATE INDEX IF NOT EXISTS idx_users_discovery ON users(setup_complete, is_vac_banned, country, playstyle);

-- "Has the other player already liked me?" lookups and cascade deletes.
CREATE INDEX IF NOT EXISTS idx_swipes_swiped ON swipes(swiped_id, swiper_id, action);

-- Listing confirmed matches for either side of the pair.
CREATE INDEX IF NOT EXISTS idx_matches_user2 ON matches(user2_id);
