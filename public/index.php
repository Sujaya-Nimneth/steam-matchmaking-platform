<?php
declare(strict_types=1);

require __DIR__ . '/../config/security.php';

security_headers();
start_secure_session();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Steam Friend Finder</title>
    <link rel="stylesheet" href="/style.css">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#171a21">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet">
</head>
<body>
    <div id="app">
        <?php if (!isset($_SESSION['steam_id'])): ?>
            <!-- Login View -->
            <div id="login-view" class="view active">
                <div class="hero">
                    <div class="logo-glitch" data-text="LFG">LFG</div>
                    <h1>Find Your Player 2</h1>
                    <p>Swipe, match, and play. Connect with Steam gamers based on the games you actually play.</p>
                    <a href="/api/auth.php" class="btn-steam">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M11.967 0C5.358 0 0 5.356 0 11.965c0 5.093 3.167 9.426 7.632 11.135l3.528-5.111c-.046-.179-.071-.365-.071-.555 0-1.222.992-2.215 2.215-2.215.176 0 .346.021.509.059l3.351-4.789c-.067-.229-.105-.473-.105-.724 0-1.504 1.22-2.723 2.724-2.723 1.503 0 2.722 1.219 2.722 2.723 0 1.503-1.219 2.722-2.722 2.722-.559 0-1.077-.168-1.505-.461l-3.328 4.757c.075.25.117.514.117.788 0 1.504-1.219 2.724-2.723 2.724-1.504 0-2.723-1.22-2.723-2.724 0-.199.025-.392.067-.577l-3.488 5.056C17.654 22.844 24 18.146 24 11.966 24 5.356 18.641 0 11.967 0zm7.818 7.042c-.378 0-.684.307-.684.685 0 .378.306.684.684.684.378 0 .685-.306.685-.684 0-.378-.307-.685-.685-.685zm-6.48 8.188c-.628 0-1.138.509-1.138 1.137 0 .627.51 1.137 1.138 1.137.627 0 1.137-.51 1.137-1.137 0-.628-.51-1.137-1.137-1.137zm1.198-2.617l-3.43 4.974c-.217-.038-.442-.058-.67-.058-2.025 0-3.666 1.642-3.666 3.667 0 .356.052.701.149 1.03l-4.52-1.32c-.053-.016-.105-.034-.155-.054 2.825 3.012 7.029 4.397 11.168 3.522l-1.04-3.136c.218.037.441.057.669.057 1.504 0 2.723-1.22 2.723-2.724 0-1.206-.79-2.222-1.89-2.585l.662-1.373zm-6.096 4.908c-.754 0-1.365.611-1.365 1.365 0 .754.611 1.365 1.365 1.365.754 0 1.365-.611 1.365-1.365 0-.754-.611-1.365-1.365-1.365z"/></svg>
                        Login with Steam
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Setup / Main App View (Handled by JS based on profile completeness) -->
            <div id="loader" class="view active">
                <div class="spinner"></div>
                <p>Loading your Steam library...</p>
            </div>

            <!-- Profile Setup View -->
            <div id="profile-setup-view" class="view">
                <div class="setup-container">
                    <h2>Complete Your Profile</h2>
                    <form id="setup-form">
                        <div class="input-group">
                            <label>Discord Handle <span class="required">*</span></label>
                            <input type="text" id="setup-discord" placeholder="Username#0000" required>
                        </div>
                        <div class="input-group">
                            <label>Playstyle</label>
                            <select id="setup-playstyle">
                                <option value="Chill">Chill / Casual</option>
                                <option value="Competitive">Competitive / Ranked</option>
                                <option value="Tryhard">Tryhard</option>
                                <option value="Toxic-Free">Toxic-Free</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Age</label>
                            <input type="number" id="setup-age" min="13" max="99" placeholder="e.g. 21">
                        </div>
                        <div class="input-group">
                            <label>Country Code (e.g. US, UK)</label>
                            <input type="text" id="setup-country" maxlength="2" placeholder="US">
                        </div>
                        <button type="submit" class="btn-primary">Save & Start Swiping</button>
                    </form>
                </div>
            </div>

            <!-- Swiping View -->
            <div id="swipe-view" class="view">
                <header class="app-header">
                    <button id="btn-filters" class="icon-btn">⚙️</button>
                    <div class="logo">LFG</div>
                    <a href="/api/auth.php?logout=1" class="icon-btn">🚪</a>
                </header>

                <div class="card-container" id="card-stack">
                    <!-- Cards injected here by JS -->
                </div>

                <div class="action-buttons">
                    <button id="btn-pass" class="btn-action pass">✕</button>
                    <button id="btn-like" class="btn-action like">♥</button>
                </div>
            </div>

            <!-- Match Overlay -->
            <div id="match-overlay" class="overlay hidden">
                <div class="match-content">
                    <h1 class="match-title">BOOM! MATCH</h1>
                    <p>You and <span id="match-name" class="highlight">Player</span> both swiped right!</p>
                    <div class="match-avatars">
                        <img id="my-avatar" src="" alt="Me">
                        <div class="match-vs">⚔️</div>
                        <img id="match-avatar" src="" alt="Them">
                    </div>
                    <div class="match-contact">
                        <h3>Connect on Discord</h3>
                        <div class="discord-box" id="match-discord"></div>
                        <a id="match-steam-link" href="#" target="_blank" class="btn-primary">View Steam Profile</a>
                    </div>
                    <button id="btn-keep-swiping" class="btn-secondary">Keep Swiping</button>
                </div>
            </div>

            <!-- Filter Modal -->
            <div id="filter-modal" class="overlay hidden">
                <div class="modal-content">
                    <h2>Filters</h2>
                    <div class="input-group">
                        <label>Target Playstyle</label>
                        <select id="filter-playstyle">
                            <option value="">Any</option>
                            <option value="Chill">Chill</option>
                            <option value="Competitive">Competitive</option>
                            <option value="Tryhard">Tryhard</option>
                            <option value="Toxic-Free">Toxic-Free</option>
                        </select>
                    </div>
                    <div class="input-group check-group">
                        <input type="checkbox" id="filter-novac" value="1">
                        <label for="filter-novac">No VAC Banned Players</label>
                    </div>
                    <div class="modal-actions">
                        <button id="btn-apply-filters" class="btn-primary">Apply</button>
                        <button id="btn-close-filters" class="btn-secondary">Cancel</button>
                    </div>
                </div>
            </div>
            
        <?php endif; ?>
    </div>

    <?php if (isset($_SESSION['steam_id'])): ?>
        <script src="/app.js" defer></script>
    <?php endif; ?>
    <script src="/sw-register.js" defer></script>
</body>
</html>
