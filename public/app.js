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
        if (typeof url !== 'string') return FALLBACK_AVATAR;
        try {
            const parsed = new URL(url, window.location.origin);
            return parsed.protocol === 'https:' ? parsed.href : FALLBACK_AVATAR;
        } catch {
            return FALLBACK_AVATAR;
        }
    };

    const toast = (message) => {
        let node = document.getElementById('toast');
        if (!node) {
            node = document.createElement('div');
            node.id = 'toast';
            node.className = 'toast';
            node.setAttribute('role', 'status');
            node.setAttribute('aria-live', 'polite');
            document.body.appendChild(node);
        }
        node.textContent = message;
        node.classList.add('visible');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => node.classList.remove('visible'), 3500);
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

    // Initialize App
    const initApp = async () => {
        try {
            currentUser = await api('/api/profile.php');

            if (Number(currentUser.setup_complete) !== 1) {
                showView('setup');
            } else {
                showView('swipe');
                loadMatches();
            }
        } catch (e) {
            console.error('Init error', e);
            showView('setup');
        }
    };

    // Profile Setup Logic
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
            } catch (error) {
                toast(error.message);
            } finally {
                submit.disabled = false;
            }
        });
    }

    // Swiping Engine
    const cardStack = document.getElementById('card-stack');

    const loadMatches = async (filters = {}) => {
        cardStack.innerHTML = '<div class="spinner stack-spinner"></div>';

        try {
            const qs = new URLSearchParams(filters).toString();
            const data = await api('/api/matches.php' + (qs ? '?' + qs : ''));
            matchQueue = Array.isArray(data.matches) ? data.matches : [];
            renderCards();
        } catch (error) {
            console.error('Failed to load matches', error);
            cardStack.innerHTML = '';
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.textContent = 'Could not load players. Please try again.';
            cardStack.appendChild(empty);
        }
    };

    const renderCards = () => {
        cardStack.innerHTML = '';

        if (matchQueue.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.textContent = 'No more players found. Try adjusting filters or check back later!';
            cardStack.appendChild(empty);
            return;
        }

        // Render from end to start to stack them correctly (last in DOM is on top)
        for (let i = matchQueue.length - 1; i >= 0; i--) {
            const match = matchQueue[i];
            const card = document.createElement('div');
            card.className = 'player-card';
            card.dataset.id = String(match.steam_id);

            const sharedGames = Array.isArray(match.shared_games) ? match.shared_games : [];
            const gamesHtml = sharedGames.map((game) => `
                <div class="game-item">
                    <span>AppID: ${escapeHtml(game.app_id)}</span>
                    <span class="hours">${escapeHtml(game.hours_b)} hrs</span>
                </div>
            `).join('');

            card.innerHTML = `
                <div class="stamp nope">PASS</div>
                <div class="stamp like">LIKE</div>
                <div class="card-image-wrap">
                    <img src="${escapeHtml(safeAvatar(match.avatar))}" alt="" draggable="false">
                    <div class="compatibility-badge">${escapeHtml(match.score)}% Match</div>
                </div>
                <div class="card-content">
                    <div class="card-title">${escapeHtml(match.name)}</div>
                    <div class="card-meta">
                        <span class="pill">${escapeHtml(match.playstyle || 'Casual')}</span>
                    </div>
                    <div class="games-list">
                        <h4>Top Shared Games</h4>
                        ${gamesHtml}
                    </div>
                </div>
            `;

            cardStack.appendChild(card);

            // Only add swipe logic to the top card
            if (i === 0) {
                initSwipeLogic(card, match);
            }
        }
    };

    const initSwipeLogic = (card, matchData) => {
        let startX = 0;
        let currentX = 0;
        let isDragging = false;
        let frame = null;
        const nopeStamp = card.querySelector('.stamp.nope');
        const likeStamp = card.querySelector('.stamp.like');

        const onPointerDown = (e) => {
            isDragging = true;
            startX = e.clientX ?? e.touches?.[0]?.clientX ?? 0;
            card.style.transition = 'none';
        };

        const paint = () => {
            frame = null;
            const rotate = currentX * 0.05;
            card.style.transform = `translateX(${currentX}px) rotate(${rotate}deg)`;

            if (currentX > 0) {
                likeStamp.style.opacity = Math.min(currentX / 100, 1);
                nopeStamp.style.opacity = 0;
            } else {
                nopeStamp.style.opacity = Math.min(Math.abs(currentX) / 100, 1);
                likeStamp.style.opacity = 0;
            }
        };

        const onPointerMove = (e) => {
            if (!isDragging) return;
            const x = e.clientX ?? e.touches?.[0]?.clientX ?? 0;
            currentX = x - startX;
            if (frame === null) frame = requestAnimationFrame(paint);
        };

        const onPointerUp = () => {
            if (!isDragging) return;
            isDragging = false;

            if (frame !== null) {
                cancelAnimationFrame(frame);
                paint();
            }

            card.style.transition = 'transform 0.3s ease, opacity 0.3s ease';

            if (currentX > 100) {
                card.style.transform = `translateX(${window.innerWidth}px) rotate(30deg)`;
                card.style.opacity = 0;
                handleSwipe(matchData.steam_id, 'like');
            } else if (currentX < -100) {
                card.style.transform = `translateX(-${window.innerWidth}px) rotate(-30deg)`;
                card.style.opacity = 0;
                handleSwipe(matchData.steam_id, 'pass');
            } else {
                card.style.transform = 'translateX(0) rotate(0)';
                likeStamp.style.opacity = 0;
                nopeStamp.style.opacity = 0;
            }
        };

        card.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);

        // Touch fallbacks
        card.addEventListener('touchstart', onPointerDown, { passive: true });
        window.addEventListener('touchmove', onPointerMove, { passive: true });
        window.addEventListener('touchend', onPointerUp);
    };

    const handleSwipe = async (swipedId, action) => {
        if (isSwiping) return;
        isSwiping = true;

        // Remove from queue
        matchQueue.shift();

        try {
            const data = await api('/api/swipe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ swiped_id: swipedId, action })
            });

            if (data.is_match && data.match_data) {
                showMatchOverlay(data.match_data);
            } else {
                setTimeout(renderCards, 300); // Wait for animation
            }
        } catch (error) {
            console.error('Swipe API failed', error);
            toast(error.message);
            setTimeout(renderCards, 300);
        } finally {
            isSwiping = false;
        }
    };

    // Buttons fallback
    document.getElementById('btn-pass')?.addEventListener('click', () => {
        if (matchQueue.length > 0 && !isSwiping) {
            const card = cardStack.lastElementChild;
            card.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
            card.style.transform = `translateX(-${window.innerWidth}px) rotate(-30deg)`;
            card.style.opacity = 0;
            handleSwipe(matchQueue[0].steam_id, 'pass');
        }
    });

    document.getElementById('btn-like')?.addEventListener('click', () => {
        if (matchQueue.length > 0 && !isSwiping) {
            const card = cardStack.lastElementChild;
            card.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
            card.style.transform = `translateX(${window.innerWidth}px) rotate(30deg)`;
            card.style.opacity = 0;
            handleSwipe(matchQueue[0].steam_id, 'like');
        }
    });

    // Match Overlay Logic
    const matchOverlay = document.getElementById('match-overlay');
    const showMatchOverlay = (data) => {
        document.getElementById('match-name').textContent = data.name;
        document.getElementById('match-avatar').src = safeAvatar(data.avatar);
        document.getElementById('my-avatar').src = safeAvatar(currentUser.avatar);
        document.getElementById('match-discord').textContent = data.discord_handle || '—';
        document.getElementById('match-steam-link').href = data.steam_profile_url;
        matchOverlay.classList.remove('hidden');
    };

    document.getElementById('btn-keep-swiping')?.addEventListener('click', () => {
        matchOverlay.classList.add('hidden');
        renderCards();
    });

    // Filters Logic
    const filterModal = document.getElementById('filter-modal');
    document.getElementById('btn-filters')?.addEventListener('click', () => {
        filterModal.classList.remove('hidden');
    });
    document.getElementById('btn-close-filters')?.addEventListener('click', () => {
        filterModal.classList.add('hidden');
    });
    document.getElementById('btn-apply-filters')?.addEventListener('click', () => {
        const playstyle = document.getElementById('filter-playstyle').value;
        const noVac = document.getElementById('filter-novac').checked ? '1' : '0';

        filterModal.classList.add('hidden');
        loadMatches({ playstyle, no_vac: noVac });
    });

    // Start
    initApp();
});
