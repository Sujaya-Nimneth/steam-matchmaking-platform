# Contributing

Thanks for your interest in the Steam Matchmaking Platform! This project is
deliberately small and dependency-free — please keep it that way.

## Getting started

```bash
git clone https://github.com/Sujaya-Nimneth/steam-matchmaking-platform.git
cd steam-matchmaking-platform

export STEAM_API_KEY="your-key"     # optional for demo mode
export APP_BASE_URL="http://localhost:8000"

php db/init.php                     # create the SQLite schema
php -S localhost:8000 -t public public/router.php
```

To explore the app without a Steam key, seed demo data and log in as the demo
player:

```bash
php db/seed.php --force
DEMO_MODE=1 php -S localhost:8000 -t public public/router.php
# open http://localhost:8000/api/auth.php?demo_login=76561198000000001
```

## Before you open a pull request

- `php tests/run.php` must pass. The suite includes HTTP integration tests
  that spawn the built-in server, so it needs no external services.
- `php -l` and `node --check public/*.js` must be clean (CI runs both).
- No new runtime dependencies (composer packages, npm packages, CDNs) without
  opening an issue first — zero dependencies is a core property of this
  project.
- Security-sensitive changes must come with tests. The suite has coverage for
  same-origin enforcement, input validation, rate limiting and the router's
  private-directory blocks; extend it rather than trusting manual checks.

## Commit style

This repository uses [Conventional Commits](https://www.conventionalcommits.org/)
(`feat:`, `fix:`, `perf:`, `test:`, `docs:`, `ci:`, `chore:`, `refactor:`),
one concern per commit, with a body explaining *why* when the reason is not
obvious from the diff.

## Reporting security issues

Please do not open public issues for vulnerabilities — see the security notes
in the README and prefer responsible disclosure to the repository owner.
