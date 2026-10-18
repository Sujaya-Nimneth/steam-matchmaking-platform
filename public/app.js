// public/app.js

document.addEventListener('DOMContentLoaded', () => {

    // Views
    const views = {
        loader: document.getElementById('loader'),
        setup: document.getElementById('profile-setup-view'),
        swipe: document.getElementById('swipe-view')
    };

    const showView = (viewName) => {
        Object.values(views).forEach(v => v && v.classList.remove('active'));
        if (views[viewName]) views[viewName].classList.add('active');
    };

    // State
    let currentUser = null;
    let matchQueue = [];
    let isSwiping = false;
    let lastFocused = null;

    const PREFETCH_THRESHOLD = 5;

    const FALLBACK_AVATAR = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' +
        '<rect width="100" height="100" fill="#1b1e24"/>' +
        '<circle cx="50" cy="38" r="18" fill="#3b424c"/>' +
        '<path d="M12 100c0-21 17-34 38-34s38 13 38 34z" fill="#3b424c"/></svg>'
    );

    // Persona names, playstyles and avatars come from Steam, so they must never
    // be interpolated into markup without escaping.
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[char]));

    const safeAvatar = (url) => {
        if (typeof url !== 'string' || url === '') return FALLBACK_AVATAR;
        try {
            const parsed = new URL(url, window.location.origin);
            return parsed.protocol === 'https:' ? parsed.href : FALLBACK_AVATAR;
        } catch {
            return FALLBACK_AVATAR;
        }
    };

    const toastNode = document.getElementById('toast');
    const toast = (message) => {
        if (!toastNode) return;
        toastNode.textContent = message;
        toastNode.classList.add('visible');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => toastNode.classList.remove('visible'), 3500);
    };

    /**
     * Fetch JSON from the API, redirecting to the login screen when the
     * session has expired and surfacing server errors as thrown errors.
     */
    const api = async (url, options = {}) => {
        const res = await fetch(url, {
            credentials: 'same-origin',
            ...options
        });

        if (res.status === 401) {
            window.location.href = '/';
            throw new Error('Not authenticated');
        }

        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            throw new Error(data.error || `Request failed (${res.status})`);
        }

        return data;
    };

    // ---- Dialog helpers -------------------------------------------------

    const dialogs = [];
    const focusableSelector = 'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])';

    const openDialog = (node) => {
        lastFocused = document.activeElement;
        node.classList.remove('hidden');
        if (!dialogs.includes(node)) dialogs.push(node);

        const first = node.querySelector(focusableSelector);
        if (first) first.focus();
    };

    const closeDialog = (node) => {
        node.classList.add('hidden');
        const index = dialogs.indexOf(node);
        if (index !== -1) dialogs.splice(index, 1);

        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    };

    document.addEventListener('keydown', (event) => {
        const active = dialogs[dialogs.length - 1];
        if (!active) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog(active);
            return;
        }

        if (event.key !== 'Tab') return;

        // Trap focus inside the top-most dialog.
        const items = [...active.querySelectorAll(focusableSelector)].filter((el) => el.offsetParent !== null);
        if (items.length === 0) return;

        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    // ---- Init -----------------------------------------------------------

    const initApp = async () => {
        try {
            currentUser = await api('/api/profile.php');

            if (Number(currentUser.setup_complete) !== 1) {
                showView('setup');
            } else {
                showView('swipe');
                loadMatches();
                loadMatchesCount();
            }
        } catch (e) {
            console.error('Init error', e);
            showView('setup');
        }
    };

    // ---- Profile setup --------------------------------------------------

    const setupForm = document.getElementById('setup-form');
    if (setupForm) {
        setupForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const submit = setupForm.querySelector('button[type="submit"]');
            submit.disabled = true;

            try {
                await api('/api/profile.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        discord_handle: document.getElementById('setup-discord').value,
                        playstyle: document.getElementById('setup-playstyle').value,
                        age: document.getElementById('setup-age').value,
                        country: document.getElementById('setup-country').value,
                        languages: []
                    })
                });

                currentUser.setup_complete = 1;
                showView('swipe');
                loadMatches();
                loadMatchesCount();
            } catch (error) {
                toast(error.message);
            } finally {
                submit.disabled = false;
            }
        });
    }

    // ---- Card rendering -------------------------------------------------

    const cardStack = document.getElementById('card-stack');
    let activeSwipe = null;

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

    const renderEmptyState = () => {
        cardStack.innerHTML = '';
        const empty = document.createElement('div');
        empty.className = 'empty-state';

        const text = document.createElement('p');
        text.textContent = 'No more players found. Try adjusting filters or check back later!';
        empty.appendChild(text);

        const refresh = document.createElement('button');
        refresh.type = 'button';
        refresh.className = 'btn-primary';
        refresh.textContent = 'Refresh';
        refresh.addEventListener('click', () => loadMatches(lastFilters));
        empty.appendChild(refresh);

        cardStack.appendChild(empty);
    };

    let lastFilters = {};

    const loadMatches = async (filters = {}) => {
        lastFilters = filters;
        showSkeleton();

        try {
            const qs = new URLSearchParams(filters).toString();
            const data = await api('/api/matches.php' + (qs ? '?' + qs : ''));
            matchQueue = Array.isArray(data.matches) ? data.matches : [];
            renderDeck();
        } catch (error) {
            console.error('Failed to load matches', error);
            cardStack.innerHTML = '';
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            const message = document.createElement('p');
            message.textContent = 'Could not load players. Please try again.';
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn-primary';
            retry.textContent = 'Retry';
            retry.addEventListener('click', () => loadMatches(lastFilters));
            empty.append(message, retry);
            cardStack.appendChild(empty);
        }
    };

    const createCard = (match) => {
        const card = document.createElement('div');
        card.className = 'player-card';
        card.dataset.id = String(match.steam_id);

        const sharedGames = Array.isArray(match.shared_games) ? match.shared_games : [];
        const gamesHtml = sharedGames.map((game) => {
            const label = game.name ? escapeHtml(game.name) : `AppID: ${escapeHtml(game.app_id)}`;
            const recent = game.recent ? '<span class="pill recent">Playing now</span>' : '';
            return `
                <div class="game-item">
                    <span class="game-name">${label}</span>
                    <span class="hours">${escapeHtml(game.hours_b)} hrs</span>
                    ${recent}
                </div>`;
        }).join('');

        const sharedCount = Number(match.shared_count || sharedGames.length);
        const meta = [
            `<span class="pill">${escapeHtml(match.playstyle || 'Casual')}</span>`,
            sharedCount > 0 ? `<span class="pill">${escapeHtml(sharedCount)} shared game${sharedCount === 1 ? '' : 's'}</span>` : ''
        ].join('');

        card.innerHTML = `
            <div class="stamp nope" aria-hidden="true">PASS</div>
            <div class="stamp like" aria-hidden="true">LIKE</div>
            <div class="card-image-wrap">
                <img src="${escapeHtml(safeAvatar(match.avatar))}" alt="" draggable="false" loading="lazy" decoding="async" width="400" height="400">
                <div class="compatibility-badge">${escapeHtml(match.score)}%</div>
            </div>
            <div class="card-content">
                <h2 class="card-title">${escapeHtml(match.name)}</h2>
                <div class="card-meta">${meta}</div>
                <div class="games-list">
                    <h4>Top shared games</h4>
                    ${gamesHtml || '<p class="field-hint">No shared games to show.</p>'}
                </div>
            </div>`;

        const score = Math.max(0, Math.min(100, Number(match.score) || 0));
        card.querySelector('.compatibility-badge')?.style.setProperty('--score', String(score));

        return card;
    };

    /** Apply depth classes so the next cards look stacked behind the top one. */
    const updateDepth = () => {
        const cards = [...cardStack.querySelectorAll('.player-card')];
        cards.forEach((card, index) => {
            card.classList.remove('depth-1', 'depth-2', 'depth-3');
            if (index > 0 && index <= 3) card.classList.add('depth-' + index);
        });
    };

    const renderDeck = () => {
        detachSwipe();
        cardStack.innerHTML = '';

        if (matchQueue.length === 0) {
            renderEmptyState();
            return;
        }

        // Render from the back so the first queue item is the top card.
        for (let i = matchQueue.length - 1; i >= 0; i--) {
            cardStack.appendChild(createCard(matchQueue[i]));
        }

        updateDepth();
        attachSwipe();
    };

    // ---- Swipe interaction ----------------------------------------------

    const detachSwipe = () => {
        if (activeSwipe) {
            activeSwipe.destroy();
            activeSwipe = null;
        }
    };

    const attachSwipe = () => {
        const card = cardStack.lastElementChild;
        if (!card || !matchQueue.length) return;

        const matchData = matchQueue[0];
        let startX = 0;
        let currentX = 0;
        let isDragging = false;
        let frame = null;
        const nopeStamp = card.querySelector('.stamp.nope');
        const likeStamp = card.querySelector('.stamp.like');

        const onPointerDown = (event) => {
            if (isSwiping) return;
            isDragging = true;
            startX = event.clientX ?? event.touches?.[0]?.clientX ?? 0;
            card.classList.add('is-dragging');
        };

        const paint = () => {
            frame = null;
            card.style.transform = `translateX(${currentX}px) rotate(${currentX * 0.05}deg)`;

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
            const x = event.clientX ?? event.touches?.[0]?.clientX ?? 0;
            currentX = x - startX;
            if (frame === null) frame = requestAnimationFrame(paint);
        };

        const onPointerUp = () => {
            if (!isDragging) return;
            isDragging = false;
            card.classList.remove('is-dragging');

            if (frame !== null) {
                cancelAnimationFrame(frame);
                paint();
            }

            if (currentX > 100) {
                commitSwipe(card, matchData, 'like');
            } else if (currentX < -100) {
                commitSwipe(card, matchData, 'pass');
            } else {
                card.style.transform = '';
                likeStamp.style.opacity = 0;
                nopeStamp.style.opacity = 0;
            }
        };

        card.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('pointermove', onPointerMove, { passive: true });
        window.addEventListener('pointerup', onPointerUp);

        activeSwipe = {
            destroy() {
                card.removeEventListener('pointerdown', onPointerDown);
                window.removeEventListener('pointermove', onPointerMove);
                window.removeEventListener('pointerup', onPointerUp);
            }
        };
    };

    /**
     * Animate the top card away, then drop it from the DOM instead of
     * rebuilding the whole deck, so the remaining cards keep their images.
     */
    const commitSwipe = (card, matchData, action) => {
        if (isSwiping) return;
        isSwiping = true;
        detachSwipe();

        card.classList.add('is-leaving');
        card.style.transform = `translateX(${action === 'like' ? '' : '-'}${window.innerWidth}px) rotate(${action === 'like' ? '' : '-'}30deg)`;
        card.style.opacity = '0';

        matchQueue.shift();

        const finish = () => {
            card.remove();
            updateDepth();

            if (matchQueue.length === 0) {
                renderEmptyState();
            } else {
                attachSwipe();
            }

            if (matchQueue.length <= PREFETCH_THRESHOLD) {
                prefetch();
            }

            isSwiping = false;
        };

        // Keep the UI responsive even if the network call is slow.
        setTimeout(finish, 280);

        sendSwipe(matchData.steam_id, action);
    };

    const sendSwipe = async (swipedId, action) => {
        try {
            const data = await api('/api/swipe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ swiped_id: swipedId, action })
            });

            if (data.is_match && data.match_data) {
                showMatchOverlay(data.match_data);
            }
        } catch (error) {
            console.error('Swipe API failed', error);
            toast(error.message);
        }
    };

    let prefetching = false;

    const prefetch = async () => {
        if (prefetching) return;
        prefetching = true;

        try {
            const qs = new URLSearchParams(lastFilters).toString();
            const data = await api('/api/matches.php' + (qs ? '?' + qs : ''));
            const known = new Set(matchQueue.map((match) => String(match.steam_id)));
            const extra = (data.matches || []).filter((match) => !known.has(String(match.steam_id)));

            if (extra.length > 0) {
                matchQueue.push(...extra);
                // Cards are stacked in reverse DOM order, so prepending each
                // new card in queue order keeps the top card correct.
                for (let i = 0; i < extra.length; i++) {
                    cardStack.prepend(createCard(extra[i]));
                }
                updateDepth();
            }
        } catch (error) {
            console.error('Prefetch failed', error);
        } finally {
            prefetching = false;
        }
    };

    // ---- Buttons --------------------------------------------------------

    const swipeFromButton = (action) => {
        if (matchQueue.length === 0 || isSwiping) return;
        const card = cardStack.lastElementChild;
        if (!card) return;
        commitSwipe(card, matchQueue[0], action);
    };

    document.getElementById('btn-pass')?.addEventListener('click', () => swipeFromButton('pass'));
    document.getElementById('btn-like')?.addEventListener('click', () => swipeFromButton('like'));

    document.addEventListener('keydown', (event) => {
        if (dialogs.length > 0) return;
        if (!views.swipe || !views.swipe.classList.contains('active')) return;

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            swipeFromButton('pass');
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            swipeFromButton('like');
        }
    });

    // ---- Match overlay --------------------------------------------------

    const matchOverlay = document.getElementById('match-overlay');

    const showMatchOverlay = (data) => {
        document.getElementById('match-name').textContent = data.name;
        document.getElementById('match-avatar').src = safeAvatar(data.avatar);
        document.getElementById('my-avatar').src = safeAvatar(currentUser.avatar);
        document.getElementById('match-discord').textContent = data.discord_handle || '—';
        document.getElementById('match-steam-link').href = data.steam_profile_url;
        openDialog(matchOverlay);
        loadMatchesCount();
    };

    document.getElementById('btn-keep-swiping')?.addEventListener('click', () => {
        closeDialog(matchOverlay);
    });

    document.getElementById('btn-copy-discord')?.addEventListener('click', async () => {
        const handle = document.getElementById('match-discord').textContent;

        try {
            await navigator.clipboard.writeText(handle);
            toast('Discord handle copied');
        } catch {
            toast('Could not copy — select the handle manually');
        }
    });

    // ---- Matches drawer -------------------------------------------------

    const matchesOverlay = document.getElementById('matches-overlay');
    const matchesList = document.getElementById('matches-list');
    const matchesCount = document.getElementById('matches-count');

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
            empty.textContent = 'No matches yet — keep swiping!';
            matchesList.appendChild(empty);
            return;
        }

        matches.forEach((match) => {
            const item = document.createElement('li');
            item.className = 'match-row';

            const avatar = document.createElement('img');
            avatar.src = safeAvatar(match.avatar);
            avatar.alt = '';
            avatar.loading = 'lazy';
            avatar.width = 44;
            avatar.height = 44;

            const info = document.createElement('div');
            info.className = 'match-info';
            const name = document.createElement('strong');
            name.textContent = match.name;
            const discord = document.createElement('span');
            discord.textContent = match.discord_handle || 'No Discord handle';
            info.append(name, discord);

            const link = document.createElement('a');
            link.href = match.steam_profile_url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = 'Steam';

            item.append(avatar, info, link);
            matchesList.appendChild(item);
        });
    };

    document.getElementById('btn-matches')?.addEventListener('click', async () => {
        openDialog(matchesOverlay);
        matchesList.innerHTML = '<li class="matches-empty">Loading…</li>';
        renderMatches(await loadMatchesCount());
    });

    document.getElementById('btn-close-matches')?.addEventListener('click', () => {
        closeDialog(matchesOverlay);
    });

    // ---- Filters --------------------------------------------------------

    const filterModal = document.getElementById('filter-modal');
    const countrySelect = document.getElementById('filter-country');

    const loadFilterOptions = async () => {
        if (!countrySelect || countrySelect.dataset.loaded) return;

        try {
            const data = await api('/api/filters.php');
            (data.countries || []).forEach((country) => {
                const option = document.createElement('option');
                option.value = country;
                option.textContent = country;
                countrySelect.appendChild(option);
            });
            countrySelect.dataset.loaded = '1';
        } catch (error) {
            console.error('Could not load filter options', error);
        }
    };

    document.getElementById('btn-filters')?.addEventListener('click', () => {
        openDialog(filterModal);
        loadFilterOptions();
    });

    document.getElementById('btn-close-filters')?.addEventListener('click', () => {
        closeDialog(filterModal);
    });

    document.getElementById('btn-apply-filters')?.addEventListener('click', () => {
        const filters = {
            playstyle: document.getElementById('filter-playstyle').value,
            country: countrySelect ? countrySelect.value : '',
            no_vac: document.getElementById('filter-novac').checked ? '1' : '0'
        };

        closeDialog(filterModal);
        loadMatches(filters);
    });

    // Start
    initApp();
});
