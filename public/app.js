// public/app.js

document.addEventListener('DOMContentLoaded', () => {
    
    // Views
    const views = {
        loader: document.getElementById('loader'),
        setup: document.getElementById('profile-setup-view'),
        swipe: document.getElementById('swipe-view')
    };

    const showView = (viewName) => {
        Object.values(views).forEach(v => v.classList.remove('active'));
        if (views[viewName]) views[viewName].classList.add('active');
    };

    // State
    let currentUser = null;
    let matchQueue = [];
    let isSwiping = false;

    // Initialize App
    const initApp = async () => {
        try {
            const res = await fetch('/api/profile.php');
            const data = await res.json();

            if (data.error) {
                window.location.href = '/'; // fallback
                return;
            }

            currentUser = data;

            if (currentUser.setup_complete === 0 || currentUser.setup_complete === '0') {
                showView('setup');
            } else {
                showView('swipe');
                loadMatches();
            }
        } catch (e) {
            console.error("Init error", e);
        }
    };

    // Profile Setup Logic
    const setupForm = document.getElementById('setup-form');
    if (setupForm) {
        setupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const discord = document.getElementById('setup-discord').value;
            const playstyle = document.getElementById('setup-playstyle').value;
            const age = document.getElementById('setup-age').value;
            const country = document.getElementById('setup-country').value;

            try {
                const res = await fetch('/api/profile.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ discord_handle: discord, playstyle, age, country, languages: [] })
                });
                const result = await res.json();
                if (result.success) {
                    currentUser.setup_complete = 1;
                    showView('swipe');
                    loadMatches();
                } else {
                    alert(result.error);
                }
            } catch (e) {
                console.error(e);
            }
        });
    }

    // Swiping Engine
    const cardStack = document.getElementById('card-stack');

    const loadMatches = async (filters = {}) => {
        cardStack.innerHTML = '<div class="spinner" style="margin:auto"></div>';
        try {
            const qs = new URLSearchParams(filters).toString();
            const res = await fetch('/api/matches.php?' + qs);
            const data = await res.json();
            
            matchQueue = data.matches || [];
            renderCards();
        } catch (e) {
            console.error("Failed to load matches", e);
        }
    };

    const renderCards = () => {
        cardStack.innerHTML = '';
        if (matchQueue.length === 0) {
            cardStack.innerHTML = '<div style="text-align:center; padding-top:50px; color:var(--text-secondary)">No more players found. Try adjusting filters or check back later!</div>';
            return;
        }

        // Render from end to start to stack them correctly (last in DOM is on top)
        for (let i = matchQueue.length - 1; i >= 0; i--) {
            const match = matchQueue[i];
            const card = document.createElement('div');
            card.className = 'player-card';
            card.dataset.id = match.steam_id;
            
            let gamesHtml = match.shared_games.map(g => `
                <div class="game-item">
                    <span>AppID: ${g.app_id}</span>
                    <span class="hours">${g.hours_b} hrs</span>
                </div>
            `).join('');

            card.innerHTML = `
                <div class="stamp nope">PASS</div>
                <div class="stamp like">LIKE</div>
                <div class="card-image-wrap">
                    <img src="${match.avatar}" alt="${match.name}" draggable="false">
                    <div class="compatibility-badge">${match.score}% Match</div>
                </div>
                <div class="card-content">
                    <div class="card-title">${match.name}</div>
                    <div class="card-meta">
                        <span class="pill">${match.playstyle || 'Casual'}</span>
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
        const nopeStamp = card.querySelector('.stamp.nope');
        const likeStamp = card.querySelector('.stamp.like');

        const onPointerDown = (e) => {
            isDragging = true;
            startX = e.clientX || e.touches[0].clientX;
            card.style.transition = 'none';
        };

        const onPointerMove = (e) => {
            if (!isDragging) return;
            const x = e.clientX || (e.touches && e.touches[0].clientX);
            currentX = x - startX;
            
            // rotate slightly as we move
            const rotate = currentX * 0.05;
            card.style.transform = `translateX(${currentX}px) rotate(${rotate}deg)`;

            // Opacity of stamps
            if (currentX > 0) {
                likeStamp.style.opacity = Math.min(currentX / 100, 1);
                nopeStamp.style.opacity = 0;
            } else {
                nopeStamp.style.opacity = Math.min(Math.abs(currentX) / 100, 1);
                likeStamp.style.opacity = 0;
            }
        };

        const onPointerUp = () => {
            if (!isDragging) return;
            isDragging = false;
            card.style.transition = 'transform 0.3s ease, opacity 0.3s ease';

            if (currentX > 100) {
                // Swiped Right (Like)
                card.style.transform = `translateX(${window.innerWidth}px) rotate(30deg)`;
                card.style.opacity = 0;
                handleSwipe(matchData.steam_id, 'like');
            } else if (currentX < -100) {
                // Swiped Left (Pass)
                card.style.transform = `translateX(-${window.innerWidth}px) rotate(-30deg)`;
                card.style.opacity = 0;
                handleSwipe(matchData.steam_id, 'pass');
            } else {
                // Reset
                card.style.transform = 'translateX(0) rotate(0)';
                likeStamp.style.opacity = 0;
                nopeStamp.style.opacity = 0;
            }
        };

        card.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        
        // Touch fallbacks
        card.addEventListener('touchstart', onPointerDown, {passive: true});
        window.addEventListener('touchmove', onPointerMove, {passive: true});
        window.addEventListener('touchend', onPointerUp);
    };

    const handleSwipe = async (swipedId, action) => {
        if (isSwiping) return;
        isSwiping = true;

        // Remove from queue
        matchQueue.shift();
        
        try {
            const res = await fetch('/api/swipe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ swiped_id: swipedId, action })
            });
            const data = await res.json();
            
            if (data.is_match) {
                showMatchOverlay(data.match_data);
            } else {
                setTimeout(renderCards, 300); // Wait for animation
            }
        } catch (e) {
            console.error("Swipe API failed", e);
            setTimeout(renderCards, 300);
        }
        
        isSwiping = false;
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
        document.getElementById('match-name').innerText = data.name;
        document.getElementById('match-avatar').src = data.avatar;
        document.getElementById('my-avatar').src = currentUser.avatar;
        document.getElementById('match-discord').innerText = data.discord_handle;
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
