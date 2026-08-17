# Production deployment

The app runs on the host with **docker compose**. Anything can sit in front to
terminate TLS — a reverse proxy (Caddy, nginx, Traefik), Cloudflare, or a
k3s/Traefik ingress (a ready-made manifest is in `deploy/k8s/signal.yaml`).

```
internet :80/:443 → your TLS proxy → HTTP_BIND (host) → signal_nginx → php-fpm
```

Only expose HTTPS publicly; bind nginx to a private address via `HTTP_BIND`.

## First install

```bash
git clone https://github.com/signalapi/signal.git /opt/signal
cd /opt/signal

cp deploy/prod.env.example deploy/prod.env
# Fill in APP_SECRET / APP_SECRET_KEY / POSTGRES_PASSWORD / MONGO_PASSWORD:
#   php -r "echo bin2hex(random_bytes(16));"   # APP_SECRET
#   php -r "echo bin2hex(random_bytes(32));"   # APP_SECRET_KEY
chmod 600 deploy/prod.env

docker compose --env-file deploy/prod.env \
    -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

The `php` container installs composer dependencies, runs migrations and seeds
the initial platform admin on first boot. Replace the default
`admin@signal.local` account right away:

```bash
docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml \
    exec php php bin/console app:create-superadmin you@example.com 'strong-password' 'Your Name'
```

## Updating

```bash
cd /opt/signal && git pull
docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml \
    up -d --build
docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml \
    exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml \
    exec php php bin/console cache:clear
```

Convenience: `alias dc='docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml'`

## Notes

- `deploy/prod.env` is gitignored; it lives on the server. **If `APP_SECRET_KEY`
  changes**, every stored DB-connection password becomes undecryptable.
- Postgres/Redis/Mongo ports are not published in prod; access is compose-network only.
- Symfony trusts the proxy's `X-Forwarded-*` headers (`TRUSTED_PROXIES=private_ranges`).
- The `.dev` TLD is HSTS-preloaded: if you host on one, HTTPS is mandatory from
  the first request.
