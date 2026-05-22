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
*   **Recent Activity Multiplier:** Actively playing the same game? Our engine applies a **1.5x score multiplier** if both users have played a shared game within the last two weeks.
*   **Playstyle Synergy:** Grants bonus points if both users identify with the same gaming playstyle (e.g., Casual, Competitive, Hardcore).

### 🕹️ Comprehensive User Profiles
Customize your matchmaking presence beyond just Steam stats:
*   **Personal Info:** Age, Country, and Spoken Languages.
*   **Gaming Preferences:** Define your preferred Playstyle.
*   **Social Connections:** Share your Discord handle for seamless communication once a match is made.
*   **Integrity Check:** Displays VAC ban status to ensure fair and trustworthy matchmaking.

### 💖 Swiping & Matching System
*   **Discover:** Browse through potential gaming partners based on your filters.
*   **Swipe:** 'Like' or 'Pass' on profiles based on their games and stats.
*   **Match:** When two users 'Like' each other, a Match is created, unlocking the ability to connect and play together!

## 🛠️ Tech Stack & Architecture

*   **Backend API:** PHP (handling authentication, matching logic, and API endpoints).
*   **Database:** SQLite / Relational Database (managed via standard SQL schemas).
*   **External APIs:** Steam Web API & Steam OpenID.

### Directory Structure

```text
├── api/             # Core API endpoints (auth, swipes, matches, profile updates)
├── config/          # Configuration files (API keys, database config)
├── db/              # Database initialization and schema definitions
├── public/          # Public-facing assets (frontend HTML/JS/CSS)
└── services/        # Business logic (SteamAPI client, MatchEngine algorithm)
```

## ⚙️ Setup & Installation

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/yourusername/steam-matchmaking-platform.git
    cd steam-matchmaking-platform
    ```

2.  **Configuration:**
    *   Navigate to the `config/` directory.
    *   Create or update the configuration file with your **Steam API Key** and base URL. You can get a Steam API key from the [Steam Community Developer page](https://steamcommunity.com/dev/apikey).

3.  **Database Initialization:**
    *   Execute the `db/schema.sql` to generate the necessary tables (`users`, `user_games`, `swipes`, `matches`).

4.  **Run the Application:**
    *   Host the project on a PHP-enabled web server (like Apache or Nginx).
    *   Alternatively, use the PHP built-in server for local development:
        ```bash
        php -S localhost:8000
        ```

## 🤝 Contributing
Contributions, issues, and feature requests are welcome! Feel free to check the issues page.

---
*Built for gamers, by gamers.*
