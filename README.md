# Steam Matchmaking Platform

[![CI](https://github.com/Sujaya-Nimneth/steam-matchmaking-platform/actions/workflows/ci.yml/badge.svg)](https://github.com/Sujaya-Nimneth/steam-matchmaking-platform/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)](https://www.php.net/)
[![Dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen.svg)](#tech-stack)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

Find people who play what you play. Log in with Steam and LFG ranks other
players by a compatibility score computed from your real game libraries and
playtime, then you swipe, match, and take the conversation to Discord. No
passwords, no fake profiles: everything comes from the official Steam Web API.

The interface is built around **"The Library Ledger"**: the score is a flat SVG
ring you can open to see the two Steam libraries that produced it, with your
hours and theirs side by side. See [docs/brand-guidelines.md](docs/brand-guidelines.md)
for the design system.

<p align="center">
  <img src="docs/screenshots/after/mobile-deck.png" alt="Swipe deck: flat card, 84% compatibility ring, you/them playtime ledger" width="240">
  <img src="docs/screenshots/after/mobile-match.png" alt="Match celebration revealing the Discord handle" width="240">
  <img src="docs/screenshots/after/desktop-login.png" alt="Login with a real, data-filled interface fragment beside the headline" width="360">
</p>

<details>
<summary>More screens (tablet, desktop, filters, matches)</summary>

<p align="center">
  <img src="docs/screenshots/after/desktop-deck.png" alt="Desktop deck with the score-breakdown ledger rail" width="440">
  <img src="docs/screenshots/after/mobile-filters.png" alt="Filters dialog" width="240">
  <img src="docs/screenshots/after/mobile-matches.png" alt="Matches list" width="240">
</p>
</details>

Before/after evidence at 390 / 768 / 1440 lives in
[docs/screenshots/before](docs/screenshots/before) and
[docs/screenshots/after](docs/screenshots/after).

## Try it in two commands

No Steam key needed — the repo ships a deterministic demo dataset:

```bash
DEMO_MODE=1 docker compose up -d --build
# open http://localhost:8080/api/auth.php?demo_login=76561198000000001
```

The demo database contains 31 players with overlapping libraries, a few
pre-seeded likes (your first right swipe is an instant match) and one
confirmed match. `DEMO_MODE` only enables a dev-only login route — never set
it in production.

## Features

- **Secure Steam authentication** — Steam OpenID 2.0 with full assertion
  validation (including the "unsigned `claimed_id`" attack), session ID
  rotation on login and a 30-day absolute session expiry.
- **Automatic library sync** — profile, VAC-ban status, owned games and
  recent playtime are fetched concurrently from the Steam Web API and stored
  in one batched transaction.
- **A matchmaking engine that goes deeper than shared games** — see
  [How matching works](#how-matching-works).
- **Swiping with a real card deck** — pointer-drag, tap buttons, keyboard
  arrows and a tap-to-open score disclosure; incremental prefetch; a quiet
  match celebration that reveals the other player's Discord handle only on a
  mutual like. A failed swipe rolls the card back instead of losing it.
- **The Library Ledger** — tapping the compatibility ring shows a "Why you
  matched" breakdown built only from real payload fields: shared games, your
  hours, their hours, and games both played recently.
- **Server-side filters** — playstyle, country, spoken language and a
  "no VAC bans" toggle, applied in SQL before scoring. Filters, the matches
  drawer and the match overlay are deep-linkable and Back works.
- **Distinct states** — loading, exhausted deck, over-narrow filters, network
  error and 429 rate-limited each have their own message and action.
- **Installable PWA** — offline fallback, stale-while-revalidate caching,
  versioned asset list, maskable icons.
- **Accessible UI** — one main landmark with a working skip link,
  focus-trapped dialogs with `inert` backgrounds, inline form validation with
  a focusable error summary, `aria-live` status region, 44px targets,
  `prefers-reduced-motion` support, and full keyboard parity for the swipe
  gesture.

## Design system

Three layers of CSS custom properties live in
[public/design-tokens.css](public/design-tokens.css): primitive values,
semantic aliases, and component tokens. IBM Plex Sans is self-hosted, so the
UI needs no external font or script; the strict CSP (`script-src 'self'`, no
`unsafe-inline`) is preserved. No build step, no framework, no raw hex outside
the token file. See [docs/brand-guidelines.md](docs/brand-guidelines.md).

## How matching works

`services/MatchEngine.php` computes a 0–100 compatibility score per candidate:

| Signal | Weight | Rationale |
| --- | --- | --- |
| Library coverage | 35% | Sharing 3 of your 3 games beats sharing 1 of 100 — overlap relative to the smaller library. |
| Overlap depth | 20% | Diminishing returns after ~10 shared games. |
| Playtime similarity | 30% | Log-scaled comparison per game, so a 1,000-hour veteran pairs with veterans, not first-timers. |
| Recent activity | 15% | Games both players touched in the last two weeks ("Playing now" pills). |
| Playstyle match | +5 | Small bonus when both picked the same playstyle. |

Discovery is bounded and indexed: a candidate ranking pass keeps the 300
deepest-overlap players, only their shared rows are hydrated, and scoring
runs on that bounded set before the top 20 are returned.

## Architecture

```mermaid
flowchart LR
    B["Browser SPA<br>(vanilla JS PWA)"] -->|"JSON over fetch"| R["public/router.php<br>(front controller)"]
    R --> A["api/*.php<br>(outside the doc root)"]
    A --> S["services/<br>SteamAPI · MatchEngine"]
    A --> D[("SQLite<br>WAL + 4 indexes")]
    S -->|"HTTPS + curl_multi"| ST["Steam Web API / OpenID"]
```

Only `public/` is web-accessible. The front controller dispatches `/api/*.php`
to the application directory outside the document root, streams static
assets, and renders the SPA for everything else — `config/`, `db/`,
`services/`, `tests/` and `deploy/` return 404 even when the router is
reachable.

```text
├── api/             # JSON endpoints: auth, profile, swipes, discovery, filters
├── config/          # Bootstrap, security helpers, PDO singleton, domain constants
├── db/              # Schema, CLI initializer, demo data seeder
├── deploy/          # Apache/Nginx configs + Docker vhost and entrypoint
├── docs/            # Brand guidelines, before/after screenshots
├── public/          # Document root: SPA, design tokens, fonts, service worker, PWA assets
├── services/        # Business logic: SteamAPI client, MatchEngine scoring
└── tests/           # Unit + HTTP integration suites (php tests/run.php)
```

## Tech stack

**PHP 8.2+ · SQLite (WAL) · vanilla JS · plain CSS · zero runtime
dependencies.** There is no composer.json, no npm install and no build step —
the whole application is ~3,000 lines you can read in an afternoon, and the
Docker image is plain `php:8.2-apache`. The one bundled font (IBM Plex Sans,
OFL) is served locally, so the app works with external requests blocked.

## API endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/auth.php` | Redirect to Steam OpenID |
| `GET` | `/api/auth.php?demo_login=<id>` | Dev-only login when `DEMO_MODE=1` |
| `POST` | `/api/auth.php?logout=1` | Destroy the session (same-origin) |
| `GET` | `/api/profile.php` | Current profile (languages decoded) |
| `POST` | `/api/profile.php` | Save setup fields (same-origin, validated, 413 above 10 KB) |
| `GET` | `/api/matches.php` | Ranked discovery deck (top 20, filters applied server-side) |
| `GET` | `/api/matches.php?list=1` | Confirmed matches with Discord handles |
| `POST` | `/api/swipe.php` | Record a like/pass (idempotent); returns match data on a mutual like |
| `GET` | `/api/filters.php` | Countries, languages and playstyles present in the data |

All JSON endpoints return `401` without a session, `403` for cross-origin
writes, `405` for wrong methods, `422` for invalid input, `429` when rate
limited and a generic JSON `500` for unexpected errors.

## Security model

- Prepared statements everywhere; `ATTR_EMULATE_PREPARES = false`.
- Strict CSP (`script-src 'self'`, no inline code), Steam CDN hosts
  whitelisted for images only, all Steam-sourced strings escaped client-side.
- Steam OpenID assertions verified server-side, `claimed_id` required inside
  the signed fields, TLS with certificate verification, no redirect following.
- Sessions: HttpOnly + SameSite=Lax cookies, `use_strict_mode`, ID rotation
  on login, 30-day absolute expiry.
- Every state-changing request is same-origin checked and rate limited
  (swipes 60/min, profile writes 20/min, deck 30/min, login 10/min per
  session); uncaught errors become generic JSON 500s with details only in the
  server log.
- Discord handles are revealed only after both players like each other.
- An HTTP integration suite regression-tests all of the above (see below).

## Setup

### Local development

```bash
git clone https://github.com/Sujaya-Nimneth/steam-matchmaking-platform.git
cd steam-matchmaking-platform

export STEAM_API_KEY="your-key"      # steamcommunity.com/dev/apikey
export APP_BASE_URL="http://localhost:8000"

php db/init.php
php -S localhost:8000 -t public public/router.php
```

The document root **must** be `public/`; the Steam OpenID return URL is
`$APP_BASE_URL/api/auth.php`, so `APP_BASE_URL` must match the URL you open
in the browser. The app refuses to call Steam while the placeholder key is
configured.

### Demo mode (no Steam key)

```bash
php db/seed.php                       # deterministic demo dataset
DEMO_MODE=1 php -S localhost:8000 -t public public/router.php
# open http://localhost:8000/api/auth.php?demo_login=76561198000000001
```

### Production

`docker compose up -d --build` with `STEAM_API_KEY` and `APP_BASE_URL` set,
or use the ready-made Apache/Nginx configs in `deploy/` (the document root
must point at `public/`). See [DEPLOYMENT.md](DEPLOYMENT.md) for a full VPS
walkthrough with TLS and backups.

## Testing

```bash
php tests/run.php
```

The suite is dependency-free like the app:

- **Unit tests** — OpenID validation paths (including the forged
  `claimed_id` attack), SQLite pragmas and every scoring rule.
- **HTTP integration tests** — boots the PHP built-in server against a
  seeded throwaway database and exercises the real request path: routing and
  private-directory 404s, authentication, same-origin enforcement, input
  validation, swipe idempotency, mutual-match creation, deck filters, rate
  limiting, logout and the `DEMO_MODE` guard.

CI (`.github/workflows/ci.yml`) lints all PHP and JS and runs the suite on
PHP 8.2 and 8.3.

## Roadmap

- Real-time notifications when someone likes you back
- Block/report flows and moderation tooling
- Postgres adapter for larger deployments
- UI localisation (the profile model already stores languages)

## Contributing & license

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).
Released under the [Apache-2.0 license](LICENSE).

---
*Built for gamers, by gamers.*
