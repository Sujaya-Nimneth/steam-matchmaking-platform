# Deployment guide

This guide walks through running the Steam Matchmaking Platform on a generic
VPS (Ubuntu/Debian) with Docker, TLS via a reverse proxy, and a backed-up
SQLite volume. The same steps apply to Hetzner, DigitalOcean, Lightsail, EC2
or any provider that gives you a Linux box and a public IP.

## 1. Prepare the server

```bash
ssh root@your-server

apt update && apt -y upgrade
apt -y install docker.io docker-compose-plugin git ufw
systemctl enable --now docker

ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw enable
```

Point your DNS `A`/`AAAA` record (e.g. `lfg.example.com`) at the server IP
before continuing — Let's Encrypt needs it.

## 2. Get the code and configure

```bash
mkdir -p /opt/lfg && cd /opt/lfg
git clone https://github.com/Sujaya-Nimneth/steam-matchmaking-platform.git app
cd app
```

Create `/opt/lfg/.env` (keep it out of the repo, `chmod 600`):

```bash
STEAM_API_KEY=your-real-key          # https://steamcommunity.com/dev/apikey
APP_BASE_URL=https://lfg.example.com
DEMO_MODE=0                          # NEVER 1 in production
LFG_PORT=8080                        # internal port the container listens on
```

## 3. Start the container

```bash
docker compose --env-file /opt/lfg/.env up -d --build
curl -I http://127.0.0.1:8080/       # expect 200
```

What the container does for you:

- runs `php:8.2-apache` with the document root at `public/` and every
  non-file request routed through `router.php` (so `/api/*` works exactly as
  documented);
- initializes the SQLite schema on first boot into the `lfg-data` volume
  (`/var/www/data/database.sqlite`);
- keeps the database across rebuilds — `docker compose build` never touches
  the volume.

## 4. TLS with Caddy (recommended, automatic certificates)

```bash
apt -y install debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt | tee /etc/apt/sources.list.d/caddy-stable.list
apt update && apt -y install caddy
```

`/etc/caddy/Caddyfile`:

```caddy
lfg.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

```bash
systemctl reload caddy
```

Caddy obtains and renews the certificate automatically. The app detects
HTTPS and sends HSTS + secure cookies by itself.

<details>
<summary>Alternative: Nginx + certbot</summary>

Use `deploy/nginx.conf` as the basis, but terminate TLS in front and proxy
to the container:

```nginx
server {
    listen 443 ssl http2;
    server_name lfg.example.com;
    ssl_certificate     /etc/letsencrypt/live/lfg.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/lfg.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Note: the PHP container trusts `X-Forwarded-Proto` only indirectly — the app
checks `$_SERVER['HTTPS']`, so if you use this path, pass
`APACHE_HTTPS=on` or strip the TLS proxy and use Caddy. Caddy is the tested
path.
</details>

## 5. Verify

- `https://lfg.example.com/` shows the login hero.
- "Login with Steam" redirects to steamcommunity.com and back — this proves
  `APP_BASE_URL` matches the public URL.
- `https://lfg.example.com/config/config.php` returns 404.

## 6. Backups

Everything user-generated lives in the SQLite volume. A nightly copy (the
image has no `sqlite3` CLI, so use PDO's `VACUUM INTO` and stream the file
out of the container):

```bash
cat > /usr/local/bin/lfg-backup <<'EOF'
#!/bin/sh
set -e
cd /opt/lfg/app
docker compose exec -T lfg php -r "\$db = new PDO('sqlite:/var/www/data/database.sqlite'); \$db->exec(\"VACUUM INTO '/var/www/data/backup.sqlite'\");"
docker compose exec -T lfg cat /var/www/data/backup.sqlite > "/root/backups/lfg-$(date +%F).sqlite"
EOF
chmod +x /usr/local/bin/lfg-backup && mkdir -p /root/backups
```

Add a cron entry: `17 4 * * * /usr/local/bin/lfg-backup`.

Add a cron entry: `17 4 * * * /usr/local/bin/lfg-backup`.

## 7. Updating

```bash
cd /opt/lfg/app
git pull
docker compose --env-file /opt/lfg/.env up -d --build
```

The service worker revalidates assets on deploy via its versioned cache, so
clients pick up the new build on their next visit.

## Hardening checklist

- [ ] `DEMO_MODE=0` (or unset) — the demo login route 404s without it.
- [ ] `STEAM_API_KEY` set; the app refuses Steam calls with the placeholder.
- [ ] Firewall allows only 22/80/443.
- [ ] `.env` permissions 600, never committed.
- [ ] Backups scheduled and **restored once as a drill**.
- [ ] `APP_BASE_URL` uses `https://` and the exact public hostname.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Steam login loops back with an error | `APP_BASE_URL` doesn't match the browser URL (scheme/host/port). |
| 404 on every API call behind your own Apache/Nginx | Your server must route non-file requests through `router.php` — see `deploy/apache.conf` / `deploy/nginx.conf`. |
| "Database unavailable" | Check the volume: `docker compose exec lfg ls -l /var/www/data`. |
| Login works locally, not in production | HTTPS mismatch: the app sends `secure` cookies when it sees HTTPS; make sure the proxy forwards the scheme. |
