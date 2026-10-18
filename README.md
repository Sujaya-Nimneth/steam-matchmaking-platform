# Steam Matchmaking Platform 🎮

Welcome to the **Steam Matchmaking Platform**! Think of it as Tinder, but for finding your perfect gaming duo. Our platform uses your Steam gaming data to intelligently match you with players who share your interests, playstyles, and dedication to the games you love.

## 🚀 Features & Functionality

### 🔐 Secure Steam Authentication
*   **Steam OpenID Integration:** Users log in securely using their existing Steam accounts. No need to create and remember yet another password.
*   **Data Sync:** Automatically fetches your Steam profile summary, game library, and recent playtimes directly from the official Steam Web API.

### 🧠 Intelligent Matchmaking Engine
Our custom algorithm (`MatchEngine.php`) doesn't just look at whether you own the same games. It goes deeper to calculate a **Compatibility Score (0-100)** based on:
*   **Shared Library:** Identifies the overlapping games in both users' libraries.
*   **Playtime Analysis:** Uses a logarithmic formula to compare total playtime. A match is stronger if both players have similar experience levels (e.g., both are veterans with 1000+ hours, or both are beginners).
*   **Library Coverage:** Rewards genuine overlap — sharing 3 of your 3 games beats sharing 1 of 100.
*   **Recent Activity:** Shared games both players touched in the last two weeks are surfaced as "Playing now" and lift the score.
*   **Playstyle Synergy:** Grants a small bonus when both players picked the same playstyle.

Scores are a weighted blend of library coverage, overlap depth (with diminishing
returns), playtime similarity and recency, so 100 is reserved for an almost
identical library rather than seven lucky shared games.

### 🕹️ Comprehensive User Profiles
Customize your matchmaking presence beyond just Steam stats:
*   **Personal Info:** Age, Country, and Spoken Languages.
*   **Gaming Preferences:** Define your preferred Playstyle.
*   **Social Connections:** Share your Discord handle for seamless communication once a match is made.
*   **Integrity Check:** Displays VAC ban status to ensure fair and trustworthy matchmaking.

### 💖 Swiping & Matching System
*   **Discover:** Browse through potential gaming partners based on your filters.
*   **Swipe:** 'Like' or 'Pass' on profiles based on their games and stats, by dragging, tapping the buttons or pressing the arrow keys.
*   **Match:** When two users 'Like' each other, a Match is created, unlocking the ability to connect and play together.
*   **Matches drawer:** Revisit every confirmed match and copy the Discord handle at any time — it is never shown before a mutual like.
*   **Filters:** Playstyle, country and a "no VAC bans" toggle, applied server-side.

### 🔒 Security & Privacy
*   Steam OpenID assertions are validated with a signed `claimed_id`; the session ID rotates on login.
*   All state-changing endpoints require a same-origin request and are rate limited per session.
*   Strict Content-Security-Policy plus output escaping, so Steam persona names cannot inject markup.
*   Discord handles are only returned once both players have liked each other.

## 🛠️ Tech Stack & Architecture

*   **Backend API:** PHP 8.2+ (authentication, matching logic, JSON endpoints).
*   **Database:** SQLite with foreign keys, WAL journaling and covering indexes.
*   **Frontend:** Vanilla JS, no build step. Installable PWA with an offline fallback.
*   **External APIs:** Steam Web API & Steam OpenID (HTTPS only).

### Directory Structure

```text
├── api/             # Core API endpoints (auth, swipes, matches, profile updates)
├── config/          # Configuration, database connection and security helpers
├── db/              # Schema definition and CLI initializer
├── deploy/          # Apache and Nginx example configurations
├── public/          # Document root: SPA, assets, service worker, router.php
├── services/        # Business logic (SteamAPI client, MatchEngine algorithm)
└── tests/           # Dependency-free regression suite (php tests/run.php)
```

### API endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/auth.php` | Redirect to Steam OpenID (or `?logout=1` to sign out) |
| `GET` | `/api/profile.php` | Current profile |
| `POST` | `/api/profile.php` | Save setup fields (same-origin, validated) |
| `GET` | `/api/matches.php` | Ranked discovery deck (top 20) |
| `GET` | `/api/matches.php?list=1` | Confirmed matches with Discord handles |
| `POST` | `/api/swipe.php` | Record a like/pass, returns match data on a mutual like |
| `GET` | `/api/filters.php` | Available countries and playstyles |

All JSON endpoints return `401` when the session is missing, `403` for
cross-origin writes and `422` for invalid input.

Only `public/` is web-accessible. `config/`, `db/` and `services/` are outside the
document root, and `router.php` returns 404 for them even when it is used.

## ⚙️ Setup & Installation

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/yourusername/steam-matchmaking-platform.git
    cd steam-matchmaking-platform
    ```

2.  **Configuration:**
    *   Export your **Steam API Key** (get one from the [Steam Community Developer page](https://steamcommunity.com/dev/apikey)) and the public base URL:
        ```bash
        export STEAM_API_KEY="your-key-here"
        export APP_BASE_URL="http://localhost:8000"
        ```
    *   The application refuses to call Steam while the placeholder key is still configured.

3.  **Database Initialization:**
    *   Create the SQLite database and tables (`users`, `user_games`, `swipes`, `matches`):
        ```bash
        php db/init.php
        ```

4.  **Run the Application:**
    *   The document root **must** be `public/`. The front controller (`public/router.php`) serves the SPA, streams static assets, and dispatches `/api/*.php` to the `api/` directory that sits outside the document root, so backend code is never directly reachable.
    *   PHP built-in server for local development:
        ```bash
        php -S localhost:8000 -t public public/router.php
        ```
    *   For Apache/Nginx, point the document root at `public/`; ready-made configs live in `deploy/`.
    *   The Steam OpenID return URL is `$APP_BASE_URL/api/auth.php`, so `APP_BASE_URL` must match the URL you open in the browser.

## 🧪 Testing

```bash
php tests/run.php
```

The suite covers OpenID validation, SQLite pragmas and the matchmaking score.
CI (`.github/workflows/ci.yml`) runs `php -l`, `node --check` and these tests.

## 🤝 Contributing
Contributions, issues, and feature requests are welcome! Feel free to check the issues page.

---
*Built for gamers, by gamers.*
