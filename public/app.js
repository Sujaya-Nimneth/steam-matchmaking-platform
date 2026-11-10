// public/app.js — LFG "Library Ledger"
//
// No framework, no build step, no inline code. All DOM contracts live in
// index.php. Steam-sourced strings are escaped before reaching innerHTML.

document.addEventListener('DOMContentLoaded', () => {

    // ---- Views ---------------------------------------------------------

    const views = {
        loader: document.getElementById('loader'),
        setup: document.getElementById('profile-setup-view'),
        swipe: document.getElementById('swipe-view')
    };

    const showView = (name) => {
        Object.values(views).forEach((v) => v && v.classList.remove('active'));
        if (views[name]) views[name].classList.add('active');
    };

    // ---- Small helpers -------------------------------------------------

    const $ = (id) => document.getElementById(id);

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));

    const FALLBACK_AVATAR = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' +
        '<rect width="100" height="100" fill="#1c1f23"/>' +
        '<circle cx="50" cy="38" r="18" fill="#3a4046"/>' +
        '<path d="M12 100c0-21 17-34 38-34s38 13 38 34z" fill="#3a4046"/></svg>'
    );

    const safeAvatar = (url) => {
        if (typeof url !== 'string' || url === '') return FALLBACK_AVATAR;
        try {
            const parsed = new URL(url, window.location.origin);
            if (parsed.origin === window.location.origin) return parsed.pathname + parsed.search;
            return parsed.protocol === 'https:' ? parsed.href : FALLBACK_AVATAR;
        } catch {
            return FALLBACK_AVATAR;
        }
    };

    const toastNode = $('toast');
    const toast = (message, kind = 'info') => {
        if (!toastNode) return;
        toastNode.textContent = message;
        toastNode.className = 'toast visible' + (kind !== 'info' ? ' ' + kind : '');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => { toastNode.className = 'toast'; }, 3500);
    };

    const formatHours = (hours) => {
        const n = Number(hours);
        if (!Number.isFinite(n)) return '—';
        return (n < 10 ? n.toFixed(1) : Math.round(n).toLocaleString()) + ' h';
    };

    const formatDate = (value) => {
        if (!value) return '';
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return '';
        try {
            return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(d);
        } catch {
            return '';
        }
    };

    // ---- API -----------------------------------------------------------

    const api = async (url, options = {}) => {
        const res = await fetch(url, { credentials: 'same-origin', ...options });
        if (res.status === 401) {
            window.location.href = '/';
            throw new Error('Your session expired. Sign in again.');
        }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const error = new Error(data.error || `Request failed (${res.status})`);
            error.status = res.status;
            error.retryAfter = Number(res.headers.get('Retry-After')) || 0;
            throw error;
        }
        return data;
    };

    // ---- Dialog helpers ------------------------------------------------

    const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea, [tabindex]:not([tabindex="-1"])';
    const dialogStack = [];
    let lastFocused = null;

    const setBackgroundInert = (inert) => {
        const main = $('main');
        if (main) {
            if (inert) main.setAttribute('inert', '');
            else main.removeAttribute('inert');
        }
    };

    const openDialog = (node) => {
        lastFocused = document.activeElement;
        node.classList.remove('hidden');
        if (!dialogStack.includes(node)) dialogStack.push(node);
        setBackgroundInert(true);
        const first = node.querySelector(focusableSelector);
        if (first) first.focus();
        const trigger = node.dataset.trigger;
        if (trigger && $(trigger)) $(trigger).setAttribute('aria-expanded', 'true');
    };

    const closeDialog = (node, { restoreFocus = true } = {}) => {
        node.classList.add('hidden');
        const i = dialogStack.indexOf(node);
        if (i !== -1) dialogStack.splice(i, 1);
        if (dialogStack.length === 0) setBackgroundInert(false);
        const trigger = node.dataset.trigger;
        if (trigger && $(trigger)) $(trigger).setAttribute('aria-expanded', 'false');
        if (restoreFocus && lastFocused && typeof lastFocused.focus === 'function') {
            const target = dialogStack.length ? dialogStack[dialogStack.length - 1] : lastFocused;
            (target.querySelector ? target.querySelector(focusableSelector) : null)?.focus?.();
            if (dialogStack.length === 0) lastFocused.focus();
        }
    };

    document.addEventListener('keydown', (event) => {
        const active = dialogStack[dialogStack.length - 1];
        if (!active) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog(active);
            return;
        }
        if (event.key !== 'Tab') return;
        const items = [...active.querySelectorAll(focusableSelector)].filter((el) => el.offsetParent !== null);
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    // ---- State ---------------------------------------------------------

    let currentUser = null;
    let matchQueue = [];
    let isSwiping = false;
    let activeSwipe = null;
    let lastFilters = {};
    let prefetching = false;
    let filterOptionsLoaded = false;
    const PREFETCH_THRESHOLD = 5;

    const cardStack = $('card-stack');
    const deckStatus = $('deck-status');
    const announce = (message) => { if (deckStatus) deckStatus.textContent = message; };

    // ---- URL / navigation state (Phase 5) ------------------------------

    const FILTER_KEYS = ['playstyle', 'country', 'language', 'no_vac'];

    const filtersFromUrl = () => {
        const params = new URLSearchParams(window.location.search);
        const filters = {};
        FILTER_KEYS.forEach((key) => {
            const value = params.get(key);
            if (value) filters[key] = value;
        });
        return filters;
    };

    const syncFilterControls = (filters) => {
        const set = (id, key) => { const el = $(id); if (el) el.value = filters[key] || ''; };
        set('filter-playstyle', 'playstyle');
        set('filter-country', 'country');
        set('filter-language', 'language');
        const noVac = $('filter-novac');
        if (noVac) noVac.checked = filters.no_vac === '1';
    };

    const activeFilterCount = () => FILTER_KEYS.filter((k) => lastFilters[k] && lastFilters[k] !== '0').length;

    const updateFilterTrigger = () => {
        const btn = $('btn-filters');
        if (!btn) return;
        const n = activeFilterCount();
        btn.setAttribute('aria-label', n ? `Open filters, ${n} active` : 'Open filters');
    };

    const writeUrl = (state, { replace = false } = {}) => {
        const url = new URL(window.location.href);
        FILTER_KEYS.forEach((k) => url.searchParams.delete(k));
        url.searchParams.delete('view');
        url.searchParams.delete('match');
        Object.entries(lastFilters).forEach(([k, v]) => { if (v && v !== '0') url.searchParams.set(k, v); });
        if (state.view) url.searchParams.set('view', state.view);
        if (state.match) url.searchParams.set('match', state.match);
        const qs = url.searchParams.toString();
        const target = url.pathname + (qs ? '?' + qs : '');
        const method = replace ? 'replaceState' : 'pushState';
        if (target !== window.location.pathname + window.location.search) {
            window.history[method]({ view: state.view, match: state.match }, '', target);
        }
    };

    const currentViewParam = () => new URLSearchParams(window.location.search).get('view');

    // ---- Init ----------------------------------------------------------

    const initApp = async () => {
        try {
            currentUser = await api('/api/profile.php');
            if (Number(currentUser.setup_complete) !== 1) {
                showView('setup');
                return;
            }
            showView('swipe');
            lastFilters = filtersFromUrl();
            syncFilterControls(lastFilters);
            updateFilterTrigger();
            loadMatchesCount();
            await loadMatches(lastFilters);
            restoreViewFromUrl();
        } catch (error) {
            console.error('Init error', error);
            showView('swipe');
            renderState('error', {
                title: 'Could not load your profile',
                body: 'Check your connection and try again.',
                action: 'Retry', onAction: () => initApp()
            });
        }
    };

    const restoreViewFromUrl = () => {
        const view = currentViewParam();
        if (view === 'matches') openMatches();
        else if (view === 'filters') openFilters();
    };

    // ---- Profile setup (Phase 3: inline validation) --------------------

    const setupForm = $('setup-form');
    const setupErrors = $('setup-errors');
    const setupErrorsList = $('setup-errors-list');

    const fieldError = (id, message) => {
        const errorNode = $(id + '-error');
        const input = $(id);
        if (errorNode) {
            errorNode.textContent = message || '';
            errorNode.classList.toggle('hidden', !message);
        }
        if (input) {
            if (message) input.setAttribute('aria-invalid', 'true');
            else input.removeAttribute('aria-invalid');
        }
    };

    const validateSetup = () => {
        const errors = [];
        const discord = $('setup-discord').value.trim();
        const ageRaw = $('setup-age').value.trim();
        const country = $('setup-country').value.trim();

        if (!discord) errors.push(['setup-discord', 'Enter your Discord handle.']);
        else if (discord.length > 64 || !/^[A-Za-z0-9._#\-]+$/.test(discord)) {
            errors.push(['setup-discord', 'Use letters, numbers, dots, underscores or hyphens only.']);
        }
        if (ageRaw !== '' && (!/^\d+$/.test(ageRaw) || Number(ageRaw) < 13 || Number(ageRaw) > 99)) {
            errors.push(['setup-age', 'Enter an age between 13 and 99.']);
        }
        if (country !== '' && !/^[A-Za-z]{2}$/.test(country)) {
            errors.push(['setup-country', 'Use a two-letter country code such as US.']);
        }

        ['setup-discord', 'setup-age', 'setup-country'].forEach((id) => fieldError(id, ''));
        errors.forEach(([id, message]) => fieldError(id, message));

        if (setupErrors && setupErrorsList) {
            setupErrorsList.innerHTML = '';
            if (errors.length) {
                errors.forEach(([id, message]) => {
                    const link = document.createElement('a');
                    link.href = '#' + id;
                    link.textContent = message;
                    link.addEventListener('click', (e) => { e.preventDefault(); $(id)?.focus(); });
                    setupErrorsList.appendChild(link);
                });
                setupErrors.classList.remove('hidden');
                setupErrors.focus();
            } else {
                setupErrors.classList.add('hidden');
            }
        }
        return errors.length === 0;
    };

    if (setupForm) {
        ['setup-discord', 'setup-age', 'setup-country'].forEach((id) => {
            const el = $(id);
            if (el) el.addEventListener('blur', () => { if (el.value.trim()) validateSetupField(id); });
        });
        setupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!validateSetup()) return;

            const submit = $('setup-submit');
            const original = submit.innerHTML;
            submit.disabled = true;
            submit.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span> Saving…';

            try {
                await api('/api/profile.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        discord_handle: $('setup-discord').value.trim(),
                        playstyle: $('setup-playstyle').value,
                        age: $('setup-age').value,
                        country: $('setup-country').value,
                        languages: $('setup-languages').value.split(',').map((l) => l.trim()).filter(Boolean)
                    })
                });
                currentUser.setup_complete = 1;
                showView('swipe');
                loadMatchesCount();
                await loadMatches(lastFilters);
                toast('Profile saved', 'success');
            } catch (error) {
                if (error.status === 422) {
                    fieldError('setup-discord', error.message);
                    setupErrors?.classList.remove('hidden');
                    if (setupErrorsList) setupErrorsList.innerHTML = `<a href="#setup-discord">${escapeHtml(error.message)}</a>`;
                    setupErrors?.focus();
                }
                toast(error.message, 'error');
            } finally {
                submit.disabled = false;
                submit.innerHTML = original;
            }
        });
    }

    const validateSetupField = (id) => {
        const value = $(id)?.value.trim() || '';
        let message = '';
        if (id === 'setup-discord' && value && (value.length > 64 || !/^[A-Za-z0-9._#\-]+$/.test(value))) {
            message = 'Use letters, numbers, dots, underscores or hyphens only.';
        }
        if (id === 'setup-age' && value && (!/^\d+$/.test(value) || Number(value) < 13 || Number(value) > 99)) {
            message = 'Enter an age between 13 and 99.';
        }
        if (id === 'setup-country' && value && !/^[A-Za-z]{2}$/.test(value)) {
            message = 'Use a two-letter country code such as US.';
        }
        fieldError(id, message);
    };

    // ---- Card rendering (Phase 4) --------------------------------------

    const showSkeleton = () => {
        cardStack.innerHTML = `
            <div class="skeleton-card" aria-hidden="true">
                <div class="skeleton-hero"></div>
                <div class="skeleton-body">
                    <div class="skeleton-line wide"></div>
                    <div class="skeleton-line short"></div>
                    <div class="skeleton-line"></div>
                    <div class="skeleton-line"></div>
                </div>
            </div>`;
    };

    const renderState = (kind, options) => {
        cardStack.innerHTML = '';
        const wrap = document.createElement('div');
        wrap.className = 'state' + (kind === 'error' ? ' is-error' : ' is-empty');
        const glyph = document.createElement('div');
        glyph.className = 'state-glyph';
        glyph.setAttribute('aria-hidden', 'true');
        glyph.textContent = kind === 'error' ? '!' : '·';
        const heading = document.createElement('h2');
        heading.textContent = options.title;
        const body = document.createElement('p');
        body.textContent = options.body;
        wrap.append(glyph, heading, body);
        if (options.action) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-primary';
            btn.textContent = options.action;
            btn.addEventListener('click', options.onAction);
            wrap.appendChild(btn);
        }
        cardStack.appendChild(wrap);
        announce(options.title);
    };

    const gameRowHtml = (game) => {
        const label = game.name ? escapeHtml(game.name) : `App ${escapeHtml(game.app_id)}`;
        const recent = game.recent ? '<span class="chip recent">Both played recently</span>' : '';
        return `
            <div class="game-item">
                <span class="game-name">${label}</span>
                <span class="pair-hours num">${escapeHtml(formatHours(game.hours_a))} / <b>${escapeHtml(formatHours(game.hours_b))}</b></span>
                ${recent}
            </div>`;
    };

    const createCard = (match, isTopCard = false) => {
        const card = document.createElement('article');
        card.className = 'player-card';
        card.dataset.id = String(match.steam_id);
        card.setAttribute('aria-label', `Player card: ${match.name || 'unknown'}`);

        const sharedGames = Array.isArray(match.shared_games) ? match.shared_games : [];
        const gamesHtml = sharedGames.map(gameRowHtml).join('');
        const sharedCount = Number(match.shared_count || sharedGames.length);
        const languages = (Array.isArray(match.languages) ? match.languages : []).filter(Boolean);
        const meta = [
            `<span class="chip accent">${escapeHtml(match.playstyle || 'Casual')}</span>`,
            sharedCount > 0 ? `<span class="chip">${escapeHtml(sharedCount)} shared game${sharedCount === 1 ? '' : 's'}</span>` : '',
            match.country ? `<span class="chip">${escapeHtml(match.country)}</span>` : '',
            match.age ? `<span class="chip num">${escapeHtml(match.age)} yrs</span>` : '',
            languages.length ? `<span class="chip">Speaks ${escapeHtml(languages.slice(0, 3).join(', '))}${languages.length > 3 ? '…' : ''}</span>` : ''
        ].filter(Boolean).join('');

        const score = Math.max(0, Math.min(100, Number(match.score) || 0));
        const circumference = 2 * Math.PI * 28;
        const offset = circumference * (1 - score / 100);

        card.innerHTML = `
            <div class="stamp nope" aria-hidden="true">Pass</div>
            <div class="stamp like" aria-hidden="true">Like</div>
            <div class="card-image-wrap">
                <img src="${escapeHtml(safeAvatar(match.avatar))}" alt="" draggable="false" loading="eager" decoding="async" fetchpriority="high" width="400" height="400">
                <button class="score-ring" type="button" aria-expanded="false"
                        aria-label="${score}% compatibility. Show why you matched this player.">
                    <svg viewBox="0 0 68 68" aria-hidden="true" focusable="false">
                        <circle class="track" cx="34" cy="34" r="28" fill="none" stroke-width="6"></circle>
                        <circle class="fill" cx="34" cy="34" r="28" fill="none" stroke-width="6"
                                stroke-linecap="round" stroke-dasharray="${circumference.toFixed(2)}"
                                stroke-dashoffset="${offset.toFixed(2)}"></circle>
                    </svg>
                    <span class="value num">${score}%</span>
                </button>
            </div>
            <div class="card-content">
                <h2 class="card-title">${escapeHtml(match.name)}</h2>
                <div class="card-meta">${meta}</div>
                <div class="games-list">
                    <p class="list-caption">Top shared games · your hours / their hours</p>
                    ${gamesHtml || '<p class="field-hint">No shared games to show.</p>'}
                </div>
                <div class="ledger-key num"><span><b>You</b> / <b>Them</b> hours</span></div>
                <p class="card-hint">Tap the score to see the breakdown · drag or use the buttons below</p>
            </div>`;

        const avatar = card.querySelector('.card-image-wrap img');
        if (!isTopCard) {
            avatar?.setAttribute('loading', 'lazy');
            avatar?.removeAttribute('fetchpriority');
        }
        avatar?.addEventListener('error', () => {
            if (avatar.src !== FALLBACK_AVATAR) avatar.src = FALLBACK_AVATAR;
        }, { once: true });

        const ring = card.querySelector('.score-ring');
        ring?.addEventListener('click', (e) => { e.stopPropagation(); toggleLedger(card, match, ring); });

        return card;
    };

    const updateDepth = () => {
        [...cardStack.querySelectorAll('.player-card')].forEach((card, index) => {
            card.classList.remove('depth-1', 'depth-2', 'depth-3');
            if (index > 0 && index <= 3) card.classList.add('depth-' + index);
        });
    };

    const renderDeck = () => {
        detachSwipe();
        cardStack.innerHTML = '';
        if (matchQueue.length === 0) {
            renderState('empty', {
                title: 'You have seen everyone for now',
                body: 'Widen your filters or check back later for new players.',
                action: 'Widen filters', onAction: () => openFilters()
            });
            updateLedger(null);
            return;
        }
        for (let i = matchQueue.length - 1; i >= 0; i--) {
            cardStack.appendChild(createCard(matchQueue[i], i === 0));
        }
        updateDepth();
        attachSwipe();
        updateLedger(matchQueue[0]);
        announce(`Showing ${matchQueue[0].name}, ${matchQueue[0].score}% compatibility.`);
    };

    // ---- "Why you matched" ledger --------------------------------------

    let ledgerOpenFor = null;

    const updateLedger = (match) => {
        const scoreNode = $('ledger-score');
        const caption = $('ledger-caption');
        if (!scoreNode || !caption) return;
        if (!match) {
            scoreNode.textContent = '—';
            caption.textContent = 'Select a card to see the breakdown.';
            return;
        }
        const games = Array.isArray(match.shared_games) ? match.shared_games : [];
        const sharedCount = Number(match.shared_count || games.length);
        scoreNode.textContent = `${match.score}%`;
        caption.textContent = `${sharedCount} shared game${sharedCount === 1 ? '' : 's'} · ` +
            `${games.filter((g) => g.recent).length} played by both in the last two weeks`;
    };

    const toggleLedger = (card, match, ring) => {
        const isOpen = ring.getAttribute('aria-expanded') === 'true';
        cardStack.querySelectorAll('.score-ring').forEach((r) => r.setAttribute('aria-expanded', 'false'));
        cardStack.querySelectorAll('.ledger-detail').forEach((n) => n.remove());
        if (isOpen) { ledgerOpenFor = null; return; }

        ring.setAttribute('aria-expanded', 'true');
        ledgerOpenFor = String(match.steam_id);
        const games = Array.isArray(match.shared_games) ? match.shared_games : [];
        const detail = document.createElement('div');
        detail.className = 'ledger-detail';
        detail.setAttribute('role', 'region');
        detail.setAttribute('aria-label', 'Why you matched');
        detail.innerHTML = `<div class="games-list">${games.map(gameRowHtml).join('') ||
            '<p class="field-hint">No shared games to show.</p>'}</div>` +
            `<p class="field-hint ledger-note">Score: ${escapeHtml(match.score)}%. Weights: library coverage 35%, playtime 30%, overlap 20%, recent activity 15%.</p>`;
        card.querySelector('.card-content')?.appendChild(detail);
        updateLedger(match);
    };

    // ---- Load / prefetch -----------------------------------------------

    const loadMatches = async (filters = {}, { fromUrl = false } = {}) => {
        lastFilters = filters;
        syncFilterControls(filters);
        updateFilterTrigger();
        if (!fromUrl) writeUrl({}, { replace: true });
        showSkeleton();
        try {
            const qs = new URLSearchParams(filters).toString();
            const data = await api('/api/matches.php' + (qs ? '?' + qs : ''));
            matchQueue = Array.isArray(data.matches) ? data.matches : [];
            renderDeck();
        } catch (error) {
            console.error('Failed to load matches', error);
            if (error.status === 429) {
                const seconds = error.retryAfter || 60;
                renderState('error', {
                    title: 'Too many refreshes',
                    body: `Wait ${seconds} seconds, then try again.`,
                    action: 'Try again', onAction: () => loadMatches(lastFilters)
                });
            } else {
                renderState('error', {
                    title: "Couldn't load players",
                    body: 'Check your connection and retry.',
                    action: 'Retry', onAction: () => loadMatches(lastFilters)
                });
            }
        }
    };

    const prefetch = async () => {
        if (prefetching) return;
        prefetching = true;
        try {
            const qs = new URLSearchParams(lastFilters).toString();
            const data = await api('/api/matches.php' + (qs ? '?' + qs : ''));
            const known = new Set(matchQueue.map((m) => String(m.steam_id)));
            const extra = (data.matches || []).filter((m) => !known.has(String(m.steam_id)));
            if (extra.length) {
                matchQueue.push(...extra);
                extra.forEach((m) => cardStack.prepend(createCard(m)));
                updateDepth();
            }
        } catch (error) {
            console.error('Prefetch failed', error);
        } finally {
            prefetching = false;
        }
    };

    // ---- Swipe interaction (Phase 3 fix + Phase 6) ---------------------

    const detachSwipe = () => {
        if (activeSwipe) { activeSwipe.destroy(); activeSwipe = null; }
    };

    const attachSwipe = () => {
        const card = cardStack.lastElementChild;
        if (!card || !matchQueue.length) return;

        const matchData = matchQueue[0];
        let startX = 0, currentX = 0, isDragging = false, frame = null;
        const nopeStamp = card.querySelector('.stamp.nope');
        const likeStamp = card.querySelector('.stamp.like');

        const onPointerDown = (event) => {
            if (isSwiping || event.target.closest('.score-ring')) return;
            isDragging = true;
            startX = event.clientX ?? 0;
            card.classList.add('is-dragging');
        };

        const paint = () => {
            frame = null;
            card.style.transform = `translateX(${currentX}px) rotate(${currentX * 0.04}deg)`;
            if (currentX > 0) {
                likeStamp.style.opacity = Math.min(currentX / 100, 1);
                nopeStamp.style.opacity = 0;
            } else {
                nopeStamp.style.opacity = Math.min(Math.abs(currentX) / 100, 1);
                likeStamp.style.opacity = 0;
            }
        };

        const onPointerMove = (event) => {
            if (!isDragging) return;
            currentX = (event.clientX ?? 0) - startX;
            if (frame === null) frame = requestAnimationFrame(paint);
        };

        const resetDrag = () => {
            isDragging = false;
            card.classList.remove('is-dragging');
            if (frame !== null) { cancelAnimationFrame(frame); frame = null; }
            card.style.transform = '';
            if (likeStamp) likeStamp.style.opacity = 0;
            if (nopeStamp) nopeStamp.style.opacity = 0;
            currentX = 0;
        };

        const onPointerUp = () => {
            if (!isDragging) return;
            const distance = currentX;
            resetDrag();
            if (distance > 100) commitSwipe(card, matchData, 'like');
            else if (distance < -100) commitSwipe(card, matchData, 'pass');
        };

        const onPointerCancel = () => { if (isDragging) resetDrag(); };

        card.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('pointermove', onPointerMove, { passive: true });
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerCancel);

        activeSwipe = {
            destroy() {
                card.removeEventListener('pointerdown', onPointerDown);
                window.removeEventListener('pointermove', onPointerMove);
                window.removeEventListener('pointerup', onPointerUp);
                window.removeEventListener('pointercancel', onPointerCancel);
            }
        };
    };

    const commitSwipe = (card, matchData, action) => {
        if (isSwiping) return;
        isSwiping = true;
        detachSwipe();

        card.classList.add('is-leaving');
        card.style.transform = `translateX(${action === 'like' ? '' : '-'}${window.innerWidth}px) rotate(${action === 'like' ? '' : '-'}24deg)`;
        card.style.opacity = '0';

        matchQueue.shift();

        const finish = () => {
            card.remove();
            updateDepth();
            if (matchQueue.length === 0) {
                renderState('empty', {
                    title: 'You have seen everyone for now',
                    body: 'Widen your filters or check back later for new players.',
                    action: 'Widen filters', onAction: () => openFilters()
                });
                updateLedger(null);
            } else {
                attachSwipe();
                updateLedger(matchQueue[0]);
            }
            if (matchQueue.length <= PREFETCH_THRESHOLD) prefetch();
            isSwiping = false;
        };

        setTimeout(finish, 220);
        sendSwipe(matchData, action);
    };

    // A failed swipe must not silently discard a candidate.
    const sendSwipe = async (matchData, action) => {
        try {
            const data = await api('/api/swipe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ swiped_id: matchData.steam_id, action })
            });
            if (data.is_match && data.match_data) {
                showMatchOverlay(data.match_data);
                loadMatchesCount();
            } else {
                announce(`${action === 'like' ? 'Liked' : 'Passed on'} ${matchData.name}.`);
            }
        } catch (error) {
            console.error('Swipe API failed', error);
            toast(error.message, 'error');
            if (!matchQueue.some((m) => String(m.steam_id) === String(matchData.steam_id))) {
                matchQueue.unshift(matchData);
                const restored = createCard(matchData, true);
                cardStack.appendChild(restored);
                updateDepth();
                attachSwipe();
                updateLedger(matchQueue[0]);
                announce(`Could not save that swipe. ${matchData.name} is back in your deck.`);
            }
        }
    };

    const swipeFromButton = (action) => {
        if (matchQueue.length === 0 || isSwiping) return;
        const card = cardStack.lastElementChild;
        if (card) commitSwipe(card, matchQueue[0], action);
    };

    $('btn-pass')?.addEventListener('click', () => swipeFromButton('pass'));
    $('btn-like')?.addEventListener('click', () => swipeFromButton('like'));

    $('btn-logout')?.addEventListener('click', async () => {
        try {
            await api('/api/auth.php?logout=1', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}'
            });
        } catch { /* Clear locally even when the request fails. */ }
        window.location.href = '/';
    });

    document.addEventListener('keydown', (event) => {
        if (dialogStack.length > 0 || !views.swipe?.classList.contains('active')) return;
        if (event.key === 'ArrowLeft') { event.preventDefault(); swipeFromButton('pass'); }
        else if (event.key === 'ArrowRight') { event.preventDefault(); swipeFromButton('like'); }
    });

    // ---- Match overlay -------------------------------------------------

    const matchOverlay = $('match-overlay');

    const showMatchOverlay = (data) => {
        $('match-name').textContent = data.name;
        $('match-title').textContent = `You matched with ${data.name}`;
        $('match-avatar').src = safeAvatar(data.avatar);
        $('my-avatar').src = safeAvatar(currentUser?.avatar);
        $('match-discord').textContent = data.discord_handle || '—';
        const link = $('match-steam-link');
        if (link) link.href = data.steam_profile_url || '#';
        openDialog(matchOverlay);
        writeUrl({ view: 'match', match: String(data.steam_id) });
    };

    $('btn-keep-swiping')?.addEventListener('click', () => {
        closeDialog(matchOverlay);
        writeUrl({}, { replace: true });
    });

    $('btn-copy-discord')?.addEventListener('click', async () => {
        const handle = $('match-discord').textContent;
        try {
            await navigator.clipboard.writeText(handle);
            toast('Copied', 'success');
        } catch {
            toast('Could not copy. Select the handle manually.', 'error');
        }
    });

    // ---- Matches drawer ------------------------------------------------

    const matchesOverlay = $('matches-overlay');
    const matchesList = $('matches-list');
    const matchesCount = $('matches-count');
    matchesOverlay.dataset.trigger = 'btn-matches';

    const loadMatchesCount = async () => {
        try {
            const data = await api('/api/matches.php?list=1');
            const total = (data.matches || []).length;
            if (matchesCount) {
                matchesCount.textContent = String(total);
                matchesCount.classList.toggle('hidden', total === 0);
            }
            return data.matches || [];
        } catch (error) {
            console.error('Could not load matches', error);
            return [];
        }
    };

    const renderMatches = (matches) => {
        matchesList.innerHTML = '';
        if (matches.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'matches-empty';
            empty.textContent = "No matches yet. Like someone who likes you back and they'll show up here.";
            matchesList.appendChild(empty);
            return;
        }
        matches.forEach((match) => {
            const item = document.createElement('li');
            item.className = 'match-row';

            const avatar = document.createElement('img');
            avatar.src = safeAvatar(match.avatar);
            avatar.alt = ''; avatar.loading = 'lazy'; avatar.width = 44; avatar.height = 44;

            const info = document.createElement('div');
            info.className = 'match-info';
            const name = document.createElement('strong');
            name.textContent = match.name;
            const handle = document.createElement('span');
            handle.className = 'handle';
            handle.textContent = match.discord_handle || 'No Discord handle';
            const langs = (Array.isArray(match.languages) ? match.languages : []).filter(Boolean);
            const speaks = document.createElement('span');
            speaks.textContent = langs.length ? `Speaks ${langs.join(', ')}` : 'Languages not listed';
            const date = document.createElement('span');
            date.className = 'match-date';
            date.textContent = formatDate(match.matched_at) ? `Matched ${formatDate(match.matched_at)}` : '';
            info.append(name, handle, speaks);
            if (date.textContent) info.append(date);

            const link = document.createElement('a');
            link.href = match.steam_profile_url;
            link.target = '_blank'; link.rel = 'noopener noreferrer';
            link.textContent = 'Steam';

            item.append(avatar, info, link);
            matchesList.appendChild(item);
        });
    };

    const openMatches = () => {
        openDialog(matchesOverlay);
        matchesList.innerHTML = '<li class="matches-empty">Loading…</li>';
        writeUrl({ view: 'matches' });
        loadMatchesCount().then(renderMatches);
    };

    $('btn-matches')?.addEventListener('click', openMatches);
    $('btn-close-matches')?.addEventListener('click', () => {
        closeDialog(matchesOverlay);
        writeUrl({}, { replace: true });
    });

    // ---- Filters -------------------------------------------------------

    const filterModal = $('filter-modal');
    filterModal.dataset.trigger = 'btn-filters';
    const countrySelect = $('filter-country');
    const languageSelect = $('filter-language');

    const loadFilterOptions = async () => {
        if (filterOptionsLoaded) return;
        try {
            const data = await api('/api/filters.php');
            (data.countries || []).forEach((country) => {
                const option = document.createElement('option');
                option.value = country; option.textContent = country;
                countrySelect?.appendChild(option);
            });
            (data.languages || []).forEach((language) => {
                const option = document.createElement('option');
                option.value = language; option.textContent = language;
                languageSelect?.appendChild(option);
            });
            filterOptionsLoaded = true;
            syncFilterControls(lastFilters);
        } catch (error) {
            console.error('Could not load filter options', error);
            toast('Could not load filter options. Retry when you are back online.', 'error');
        }
    };

    const openFilters = () => {
        openDialog(filterModal);
        loadFilterOptions();
        writeUrl({ view: 'filters' });
    };

    $('btn-filters')?.addEventListener('click', openFilters);
    $('btn-close-filters')?.addEventListener('click', () => {
        closeDialog(filterModal);
        writeUrl({}, { replace: true });
    });
    $('btn-apply-filters')?.addEventListener('click', () => {
        const filters = {
            playstyle: $('filter-playstyle').value,
            country: countrySelect ? countrySelect.value : '',
            language: languageSelect ? languageSelect.value : '',
            no_vac: $('filter-novac').checked ? '1' : '0'
        };
        closeDialog(filterModal);
        loadMatches(filters);
    });

    // ---- Back / forward ------------------------------------------------

    window.addEventListener('popstate', () => {
        closeDialog(matchesOverlay, { restoreFocus: false });
        closeDialog(filterModal, { restoreFocus: false });
        closeDialog(matchOverlay, { restoreFocus: false });
        const nextFilters = filtersFromUrl();
        const changed = FILTER_KEYS.some((k) => (nextFilters[k] || '') !== (lastFilters[k] || ''));
        if (changed) loadMatches(nextFilters, { fromUrl: true });
        else restoreViewFromUrl();
    });

    // ---- Start ---------------------------------------------------------

    initApp();
});
