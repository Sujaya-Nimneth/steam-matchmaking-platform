<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>LFG · Steam Matchmaking Platform</title>
    <meta name="description" content="Find people who play what you play. LFG ranks Steam players by shared games and playtime, then you decide.">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/icons/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <meta name="theme-color" content="#0b0d10">
    <meta name="color-scheme" content="dark">
    <link rel="preload" href="/fonts/ibm-plex-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/design-tokens.css">
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <main id="main">
        <?php if (!isset($_SESSION['steam_id'])): ?>
            <!-- Login view -->
            <div id="login-view" class="view active">
                <div class="hero">
                    <div class="hero-copy">
                        <span class="wordmark" translate="no">LFG</span>
                        <h1>Find your next duo</h1>
                        <p>We rank Steam players by the games you both own and how much you have played them. Then you decide who to team up with.</p>
                        <a href="/api/auth.php" class="btn btn-steam">
                            <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true" focusable="false"><path d="M11.967 0C5.358 0 0 5.356 0 11.965c0 5.093 3.167 9.426 7.632 11.135l3.528-5.111c-.046-.179-.071-.365-.071-.555 0-1.222.992-2.215 2.215-2.215.176 0 .346.021.509.059l3.351-4.789c-.067-.229-.105-.473-.105-.724 0-1.504 1.22-2.723 2.724-2.723 1.503 0 2.722 1.219 2.722 2.723 0 1.503-1.219 2.722-2.722 2.722-.559 0-1.077-.168-1.505-.461l-3.328 4.757c.075.25.117.514.117.788 0 1.504-1.219 2.724-2.723 2.724-1.504 0-2.723-1.22-2.723-2.724 0-.199.025-.392.067-.577l-3.488 5.056C17.654 22.844 24 18.146 24 11.966 24 5.356 18.641 0 11.967 0zm7.818 7.042c-.378 0-.684.307-.684.685 0 .378.306.684.684.684.378 0 .685-.306.685-.684 0-.378-.307-.685-.685-.685zm-6.48 8.188c-.628 0-1.138.509-1.138 1.137 0 .627.51 1.137 1.138 1.137.627 0 1.137-.51 1.137-1.137 0-.628-.51-1.137-1.137-1.137zm1.198-2.617l-3.43 4.974c-.217-.038-.442-.058-.67-.058-2.025 0-3.666 1.642-3.666 3.667 0 .356.052.701.149 1.03l-4.52-1.32c-.053-.016-.105-.034-.155-.054 2.825 3.012 7.029 4.397 11.168 3.522l-1.04-3.136c.218.037.441.057.669.057 1.504 0 2.723-1.22 2.723-2.724 0-1.206-.79-2.222-1.89-2.585l.662-1.373zm-6.096 4.908c-.754 0-1.365.611-1.365 1.365 0 .754.611 1.365 1.365 1.365.754 0 1.365-.611 1.365-1.365 0-.754-.611-1.365-1.365-1.365z"/></svg>
                            Continue with Steam
                        </a>
                        <p class="fineprint">We only read your public profile, game library and ban status. No password to remember.</p>
                    </div>
                    <!-- A real, data-filled fragment of the product's own interface. -->
                    <div class="hero-preview" data-ui aria-hidden="true">
                        <div class="preview-frame">
                            <h2>Why a score looks the way it does</h2>
                            <div class="preview-row"><span class="name">Shared games</span><span class="label">library overlap</span><span class="value">5 games</span></div>
                            <div class="preview-row"><span class="name">Counter-Strike 2</span><span class="label">you / them</span><span class="value">312 / 284 h</span></div>
                            <div class="preview-row"><span class="name">Apex Legends</span><span class="label">you / them</span><span class="value">96 / 110 h</span></div>
                            <div class="preview-row"><span class="name">Dota 2</span><span class="label">you / them</span><span class="value">640 / 580 h</span></div>
                            <div class="preview-row"><span class="name">Compatibility</span><span class="label">weighted score</span><span class="value">84%</span></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Loading -->
            <div id="loader" class="view active" role="status" aria-live="polite">
                <div class="spinner" aria-hidden="true"></div>
                <p>Loading your library…</p>
            </div>

            <!-- Profile setup -->
            <div id="profile-setup-view" class="view">
                <div class="setup-container">
                    <h2>Complete your profile</h2>
                    <p class="setup-intro">This is what other players see when you show up in their deck.</p>
                    <div id="setup-errors" class="error-summary hidden" role="alert" tabindex="-1">
                        <h3>There is a problem</h3>
                        <div id="setup-errors-list"></div>
                    </div>
                    <form id="setup-form" novalidate>
                        <div class="input-group">
                            <label for="setup-discord">Discord handle <span class="required" aria-hidden="true">*</span></label>
                            <input type="text" id="setup-discord" name="discord" placeholder="e.g. username…" autocomplete="off" spellcheck="false" required aria-describedby="setup-discord-hint">
                            <small id="setup-discord-hint" class="field-hint">Shared only after you both like each other.</small>
                            <small id="setup-discord-error" class="field-error hidden"></small>
                        </div>
                        <div class="input-group">
                            <label for="setup-playstyle">Playstyle</label>
                            <select id="setup-playstyle" name="playstyle" autocomplete="off">
                                <?php foreach (PLAYSTYLE_LABELS as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="setup-age">Age <span class="optional">(optional)</span></label>
                            <input type="number" id="setup-age" name="age" min="13" max="99" inputmode="numeric" placeholder="e.g. 21…" autocomplete="off">
                            <small id="setup-age-error" class="field-error hidden"></small>
                        </div>
                        <div class="input-group">
                            <label for="setup-country">Country code <span class="optional">(optional)</span></label>
                            <input type="text" id="setup-country" name="country" maxlength="2" placeholder="e.g. US…" autocapitalize="characters" autocomplete="country" aria-describedby="setup-country-hint">
                            <small id="setup-country-hint" class="field-hint">Two letters, such as US or DE.</small>
                            <small id="setup-country-error" class="field-error hidden"></small>
                        </div>
                        <div class="input-group">
                            <label for="setup-languages">Spoken languages <span class="optional">(optional)</span></label>
                            <input type="text" id="setup-languages" name="languages" placeholder="e.g. English, German…" autocomplete="off" aria-describedby="setup-languages-hint">
                            <small id="setup-languages-hint" class="field-hint">Comma separated. Other players can filter the deck by language.</small>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="setup-submit">Save and find players</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Discovery deck -->
            <div id="swipe-view" class="view">
                <header class="app-header">
                    <button id="btn-filters" class="icon-btn" type="button" aria-label="Open filters" aria-haspopup="dialog" aria-expanded="false">
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                    </button>
                    <div class="logo" translate="no">LFG</div>
                    <div class="header-actions">
                        <button id="btn-matches" class="icon-btn" type="button" aria-label="View your matches" aria-haspopup="dialog" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-4.35-7-9a4 4 0 017-2.65A4 4 0 0119 12c0 4.65-7 9-7 9z"/></svg>
                            <span id="matches-count" class="badge hidden">0</span>
                        </button>
                        <button id="btn-logout" class="icon-btn" type="button" aria-label="Log out">
                            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        </button>
                    </div>
                </header>

                <div class="deck-layout">
                    <div class="card-container" id="card-stack" aria-label="Player cards">
                        <!-- Cards injected by app.js -->
                    </div>
                    <aside class="deck-ledger" id="ledger-rail" aria-label="How compatibility is scored">
                        <div class="ledger-panel">
                            <h2>Why this score</h2>
                            <p>Compatibility comes from your two Steam libraries. Nothing is invented.</p>
                            <div class="ledger-score num" id="ledger-score">—</div>
                            <p class="field-hint" id="ledger-caption">Select a card to see the breakdown.</p>
                        </div>
                        <div class="ledger-panel">
                            <h2>How it is weighted</h2>
                            <div class="ledger-formula">
                                <div>
                                    <div class="formula-row"><span class="label">Library coverage</span><span class="weight num">35%</span></div>
                                    <div class="formula-bar"><span class="w-35"></span></div>
                                </div>
                                <div>
                                    <div class="formula-row"><span class="label">Playtime similarity</span><span class="weight num">30%</span></div>
                                    <div class="formula-bar"><span class="w-30"></span></div>
                                </div>
                                <div>
                                    <div class="formula-row"><span class="label">Overlap depth</span><span class="weight num">20%</span></div>
                                    <div class="formula-bar"><span class="w-20"></span></div>
                                </div>
                                <div>
                                    <div class="formula-row"><span class="label">Recent activity</span><span class="weight num">15%</span></div>
                                    <div class="formula-bar"><span class="w-15"></span></div>
                                </div>
                            </div>
                            <p class="field-hint ledger-note">A matching playstyle adds a small bonus.</p>
                        </div>
                    </aside>
                </div>

                <p id="deck-status" class="sr-only" role="status" aria-live="polite"></p>

                <div class="action-buttons">
                    <button id="btn-pass" class="btn-action pass" type="button" aria-label="Pass on this player">
                        <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                    <button id="btn-like" class="btn-action like" type="button" aria-label="Like this player">
                        <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12 21s-7-4.35-7-9a4 4 0 017-2.65A4 4 0 0119 12c0 4.65-7 9-7 9z"/></svg>
                    </button>
                </div>
                <p class="action-hint">Drag the card, use the buttons, or press <kbd>←</kbd> / <kbd>→</kbd></p>
            </div>

        <?php endif; ?>
    </main>

        <?php if (isset($_SESSION['steam_id'])): ?>
            <!-- Match celebration -->
            <div id="match-overlay" class="overlay hidden" role="dialog" aria-modal="true" aria-labelledby="match-title">
                <div class="match-content">
                    <h1 id="match-title">You matched</h1>
                    <p>You and <span id="match-name"></span> both liked each other.</p>
                    <div class="match-avatars">
                        <img id="my-avatar" src="" alt="Your avatar" width="72" height="72">
                        <div class="match-link" aria-hidden="true"></div>
                        <img id="match-avatar" src="" alt="Their avatar" width="72" height="72">
                    </div>
                    <div class="match-contact">
                        <h2>Connect on Discord</h2>
                        <div class="discord-box" id="match-discord"></div>
                        <button id="btn-copy-discord" class="btn btn-secondary" type="button">Copy Discord handle</button>
                        <a id="match-steam-link" class="btn btn-secondary" target="_blank" rel="noopener noreferrer">View Steam profile</a>
                    </div>
                    <button id="btn-keep-swiping" class="btn btn-primary" type="button">Keep swiping</button>
                </div>
            </div>

            <!-- Matches -->
            <div id="matches-overlay" class="overlay hidden" role="dialog" aria-modal="true" aria-labelledby="matches-title">
                <div class="modal-content">
                    <h2 id="matches-title">Your matches</h2>
                    <ul id="matches-list" class="matches-list"></ul>
                    <div class="modal-actions">
                        <button id="btn-close-matches" class="btn btn-primary" type="button">Close</button>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div id="filter-modal" class="overlay hidden" role="dialog" aria-modal="true" aria-labelledby="filters-title">
                <div class="modal-content">
                    <h2 id="filters-title">Filters</h2>
                    <p class="modal-intro">Applied on the server before players are ranked.</p>
                    <div class="input-group">
                        <label for="filter-playstyle">Target playstyle</label>
                        <select id="filter-playstyle" name="playstyle" autocomplete="off">
                            <option value="">Any</option>
                            <?php foreach (PLAYSTYLE_LABELS as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="filter-country">Country</label>
                        <select id="filter-country" name="country" autocomplete="off"><option value="">Any</option></select>
                    </div>
                    <div class="input-group">
                        <label for="filter-language">Language</label>
                        <select id="filter-language" name="language" autocomplete="off"><option value="">Any</option></select>
                    </div>
                    <div class="input-group check-group">
                        <input type="checkbox" id="filter-novac" name="no_vac" value="1">
                        <label for="filter-novac">No VAC banned players</label>
                    </div>
                    <div class="modal-actions">
                        <button id="btn-apply-filters" class="btn btn-primary" type="button">Apply filters</button>
                        <button id="btn-close-filters" class="btn btn-secondary" type="button">Cancel</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <?php if (isset($_SESSION['steam_id'])): ?>
        <script src="/app.js" defer></script>
    <?php endif; ?>
    <script src="/sw-register.js" defer></script>
</body>
</html>
