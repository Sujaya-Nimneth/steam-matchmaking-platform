<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>LFG — Steam Matchmaking Platform</title>
    <meta name="description" content="Swipe, match and play. The Steam matchmaking platform finds you gaming partners based on the games you actually play.">
    <link rel="stylesheet" href="/style.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/icons/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <meta name="theme-color" content="#171a21">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet">
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <div id="app">
        <?php if (!isset($_SESSION['steam_id'])): ?>
            <!-- Login View -->
            <div id="login-view" class="view active">
                <main class="hero">
                    <div class="logo-glitch" data-text="LFG">LFG</div>
                    <h1>Find Your Player 2</h1>
                    <p>Swipe, match, and play. Connect with Steam gamers based on the games you actually play.</p>
                    <a href="/api/auth.php" class="btn-steam">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true" focusable="false"><path d="M11.967 0C5.358 0 0 5.356 0 11.965c0 5.093 3.167 9.426 7.632 11.135l3.528-5.111c-.046-.179-.071-.365-.071-.555 0-1.222.992-2.215 2.215-2.215.176 0 .346.021.509.059l3.351-4.789c-.067-.229-.105-.473-.105-.724 0-1.504 1.22-2.723 2.724-2.723 1.503 0 2.722 1.219 2.722 2.723 0 1.503-1.219 2.722-2.722 2.722-.559 0-1.077-.168-1.505-.461l-3.328 4.757c.075.25.117.514.117.788 0 1.504-1.219 2.724-2.723 2.724-1.504 0-2.723-1.22-2.723-2.724 0-.199.025-.392.067-.577l-3.488 5.056C17.654 22.844 24 18.146 24 11.966 24 5.356 18.641 0 11.967 0zm7.818 7.042c-.378 0-.684.307-.684.685 0 .378.306.684.684.684.378 0 .685-.306.685-.684 0-.378-.307-.685-.685-.685zm-6.48 8.188c-.628 0-1.138.509-1.138 1.137 0 .627.51 1.137 1.138 1.137.627 0 1.137-.51 1.137-1.137 0-.628-.51-1.137-1.137-1.137zm1.198-2.617l-3.43 4.974c-.217-.038-.442-.058-.67-.058-2.025 0-3.666 1.642-3.666 3.667 0 .356.052.701.149 1.03l-4.52-1.32c-.053-.016-.105-.034-.155-.054 2.825 3.012 7.029 4.397 11.168 3.522l-1.04-3.136c.218.037.441.057.669.057 1.504 0 2.723-1.22 2.723-2.724 0-1.206-.79-2.222-1.89-2.585l.662-1.373zm-6.096 4.908c-.754 0-1.365.611-1.365 1.365 0 .754.611 1.365 1.365 1.365.754 0 1.365-.611 1.365-1.365 0-.754-.611-1.365-1.365-1.365z"/></svg>
                        Login with Steam
                    </a>
                    <p class="fineprint">We only read your public profile, game library and ban status.</p>
                </main>
            </div>
        <?php else: ?>
            <!-- Setup / Main App View (Handled by JS based on profile completeness) -->
            <div id="loader" class="view active" role="status" aria-live="polite">
                <div class="spinner" aria-hidden="true"></div>
                <p>Loading your Steam library...</p>
            </div>

            <!-- Profile Setup View -->
            <div id="profile-setup-view" class="view">
                <main class="setup-container">
                    <h2>Complete Your Profile</h2>
                    <p class="setup-intro">This is what other players see when you show up in their deck.</p>
                    <form id="setup-form" novalidate>
                        <div class="input-group">
                            <label for="setup-discord">Discord Handle <span class="required" aria-hidden="true">*</span></label>
                            <input type="text" id="setup-discord" name="discord" placeholder="username" autocomplete="off" required aria-describedby="setup-discord-hint">
                            <small id="setup-discord-hint" class="field-hint">Shared only after you both like each other.</small>
                        </div>
                        <div class="input-group">
                            <label for="setup-playstyle">Playstyle</label>
                            <select id="setup-playstyle">
                                <?php foreach (PLAYSTYLE_LABELS as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="setup-age">Age <span class="optional">(optional)</span></label>
                            <input type="number" id="setup-age" min="13" max="99" inputmode="numeric" placeholder="21">
                        </div>
                        <div class="input-group">
                            <label for="setup-country">Country Code <span class="optional">(optional)</span></label>
                            <input type="text" id="setup-country" maxlength="2" placeholder="US" autocapitalize="characters" autocomplete="country">
                        </div>
                        <div class="input-group">
                            <label for="setup-languages">Spoken Languages <span class="optional">(optional)</span></label>
                            <input type="text" id="setup-languages" placeholder="English, German" autocomplete="off" aria-describedby="setup-languages-hint">
                            <small id="setup-languages-hint" class="field-hint">Comma separated. Other players can filter the deck by language.</small>
                        </div>
                        <button type="submit" class="btn-primary">Save &amp; Start Swiping</button>
                    </form>
                </main>
            </div>

            <!-- Swiping View -->
            <div id="swipe-view" class="view">
                <header class="app-header">
                    <button id="btn-filters" class="icon-btn" type="button" aria-label="Open filters" aria-haspopup="dialog">
                        <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                    </button>
                    <div class="logo">LFG</div>
                    <div class="header-actions">
                        <button id="btn-matches" class="icon-btn" type="button" aria-label="View your matches" aria-haspopup="dialog">
                            <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-4.35-7-9a4 4 0 017-2.65A4 4 0 0119 12c0 4.65-7 9-7 9z"/></svg>
                            <span id="matches-count" class="badge hidden">0</span>
                        </button>
                        <button id="btn-logout" class="icon-btn" type="button" aria-label="Log out">
                            <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        </button>
                    </div>
                </header>

                <main class="card-container" id="card-stack" aria-live="polite">
                    <!-- Cards injected here by JS -->
                </main>

                <div class="action-buttons">
                    <button id="btn-pass" class="btn-action pass" type="button" aria-label="Pass">
                        <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                    <button id="btn-like" class="btn-action like" type="button" aria-label="Like">
                        <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12 21s-7-4.35-7-9a4 4 0 017-2.65A4 4 0 0119 12c0 4.65-7 9-7 9z"/></svg>
                    </button>
                </div>
            </div>

            <!-- Match Overlay -->
            <div id="match-overlay" class="overlay hidden" role="dialog" aria-modal="true" aria-labelledby="match-title">
                <div class="match-content">
                    <h1 id="match-title" class="match-title">BOOM! MATCH</h1>
                    <p>You and <span id="match-name" class="highlight">Player</span> both swiped right!</p>
                    <div class="match-avatars">
                        <img id="my-avatar" src="" alt="Your avatar" width="100" height="100">
                        <div class="match-vs" aria-hidden="true">⚔️</div>
                        <img id="match-avatar" src="" alt="Their avatar" width="100" height="100">
                    </div>
                    <div class="match-contact">
                        <h3>Connect on Discord</h3>
                        <div class="discord-box" id="match-discord"></div>
                        <button id="btn-copy-discord" class="btn-secondary btn-copy" type="button">Copy handle</button>
                        <a id="match-steam-link" href="#" target="_blank" rel="noopener noreferrer" class="btn-primary">View Steam Profile</a>
                    </div>
                    <button id="btn-keep-swiping" class="btn-secondary" type="button">Keep Swiping</button>
                </div>
            </div>

            <!-- Matches Drawer -->
            <div id="matches-overlay" class="overlay hidden" role="dialog" aria-modal="true" aria-labelledby="matches-title">
                <div class="modal-content matches-modal">
                    <h2 id="matches-title">Your Matches</h2>
                    <ul id="matches-list" class="matches-list"></ul>
                    <div class="modal-actions">
                        <button id="btn-close-matches" class="btn-secondary" type="button">Close</button>
                    </div>
                </div>
            </div>

            <!-- Filter Modal -->
            <div id="filter-modal" class="overlay hidden" role="dialog" aria-modal="true" aria-labelledby="filters-title">
                <div class="modal-content">
                    <h2 id="filters-title">Filters</h2>
                    <div class="input-group">
                        <label for="filter-playstyle">Target Playstyle</label>
                        <select id="filter-playstyle">
                            <option value="">Any</option>
                            <?php foreach (PLAYSTYLE_LABELS as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="filter-country">Country</label>
                        <select id="filter-country">
                            <option value="">Any</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="filter-language">Language</label>
                        <select id="filter-language">
                            <option value="">Any</option>
                        </select>
                    </div>
                    <div class="input-group check-group">
                        <input type="checkbox" id="filter-novac" value="1">
                        <label for="filter-novac">No VAC banned players</label>
                    </div>
                    <div class="modal-actions">
                        <button id="btn-apply-filters" class="btn-primary" type="button">Apply</button>
                        <button id="btn-close-filters" class="btn-secondary" type="button">Cancel</button>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <?php if (isset($_SESSION['steam_id'])): ?>
        <script src="/app.js" defer></script>
    <?php endif; ?>
    <script src="/sw-register.js" defer></script>
</body>
</html>
