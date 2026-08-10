# Deploy — signal.yusufdgn.com

Uygulama sunucuda **docker compose** ile çalışır; **k3s/Traefik** yalnızca önüne geçip
TLS'i sonlandırır ve isteği host'a yönlendirir (`wg-easy` ile aynı desen).

```
internet :80/:443 → Traefik (k3s) → 10.42.0.1:8090 → signal_nginx → php-fpm
                     ↑ cert-manager (letsencrypt-prod) + http→https redirect
```

Yalnızca **https** yayında: nginx sadece cluster köprüsüne (`10.42.0.1`) bağlanır,
public arayüzde açık düz-http portu yoktur. `www.` kaydı bilinçli olarak yok.

## İlk kurulum

```bash
git clone git@github.com:yusufdogan/signal.git /opt/signal
cd /opt/signal

cp deploy/prod.env.example deploy/prod.env
# APP_SECRET / APP_SECRET_KEY / POSTGRES_PASSWORD / MONGO_PASSWORD doldurun:
#   php -r "echo bin2hex(random_bytes(16));"   # APP_SECRET
#   php -r "echo bin2hex(random_bytes(32));"   # APP_SECRET_KEY
chmod 600 deploy/prod.env

docker compose --env-file deploy/prod.env \
    -f docker-compose.yml -f docker-compose.prod.yml up -d --build

kubectl apply -f deploy/k8s/signal.yaml
```

`php` konteyneri ilk açılışta composer kurulumunu, migration'ları ve süper admin
oluşturmayı kendisi yapar. Varsayılan `admin@signal.local` hesabını prod'da bırakmayın:

```bash
docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml \
    exec php php bin/console app:create-superadmin you@example.com 'güçlü-parola' 'Ad Soyad'
```

## Güncelleme

```bash
cd /opt/signal && git pull
docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml \
    up -d --build
docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml \
    exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml \
    exec php php bin/console cache:clear
```

Kolaylık için: `alias dc='docker compose --env-file deploy/prod.env -f docker-compose.yml -f docker-compose.prod.yml'`

## Notlar

- `deploy/prod.env` gitignore'lu; sunucuda tutulur. **`APP_SECRET_KEY` değişirse**
  kayıtlı DB bağlantı parolaları çözülemez hale gelir.
- Sertifika cert-manager tarafından `signal-tls` secret'ında yönetilir, otomatik yenilenir.
- Postgres/Redis/Mongo portları prod'da yayınlanmaz; erişim compose ağı içinden.
- Symfony, ingress'in `X-Forwarded-*` başlıklarına güvenir (`TRUSTED_PROXIES=private_ranges`).
