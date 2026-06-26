# Signal: Test Platform

Self-hosted, Postman alternatifi + DB doğrulamalı API entegrasyon/E2E test platformu.
Symfony 7.2 / PHP 8.3 üzerine kuruludur ve tamamen Docker üzerinde çalışır.

## Bu sürümde ne var

### Faz 0 — Temel
- Docker Compose ile tek komutta ayağa kalkan ortam (nginx + php-fpm + PostgreSQL + Redis)
- Rol bazlı kimlik doğrulama (form login)
- **Süper Admin paneli** (`/admin`): merchant oluşturma/aktif-pasif/silme, genel istatistikler
- **Merchant paneli** (`/app`): merchant kendi workspace'lerini **sınırsız** oluşturabilir/silebilir
- Çok kiracılı (multi-tenant) veri modeli: `Merchant → Workspace`, `User → Merchant`

### Faz 1 — Collection, Environment, Request
- **Postman içe aktarma**: Collection v2.1 (folder ağacı + istekler) ve Environment (değişkenler, secret) JSON import
- **Collection ağacı**: workspace içinde collection'lar, klasörler ve istekler
- **İstek editörü**: method, URL, header'lar, query parametreleri, body (none/raw/json/form)
- **Request gönderme**: seçili environment ile `{{değişken}}` interpolasyonu → durum kodu, süre, yanıt body
- **Environment editörü**: değişken ekle/düzenle, secret işaretleme

Veri modeli: `Workspace → ApiCollection → Folder/ApiRequest`, `Workspace → Environment → EnvVariable`

### Faz 2 — Test Akışları (chaining + assertion + run geçmişi)
- **Test akışı**: workspace içinde sıralı adımlardan oluşan akışlar (`TestFlow → FlowStep`)
- **Adım**: collection'daki bir isteği çalıştırır; sürükle-sırala (yukarı/aşağı)
- **Extraction**: yanıt JSON'ından değer çıkarma (`token = data.access_token`) → sonraki adımlarda `{{token}}`
- **Assertion**: `status == 200`, `path exists`, `path == değer`, `path contains değer`, `body contains değer`
- **Zincirleme**: bir adımın çıkardığı değer, sonraki adımın URL/header/body'sinde kullanılır
- **Manuel koşum** + **koşum geçmişi**: her adımın isteği/yanıtı/assertion sonuçları/çıkarılan değişkenleri DB'ye yazılır (`FlowRun → StepResult`)
- `stopOnFailure`: ilk başarısız adımda kalanları atlar (skipped)
- Dahili **`/_echo`** test endpoint'i (query'i JSON döner) — harici API olmadan deneme yapmak için

Veri modeli: `Workspace → TestFlow → FlowStep`, `TestFlow → FlowRun → StepResult`

### Faz 3 — DB Doğrulama Bağlayıcıları (asıl farklılaştırıcı)
- **DB bağlantıları** workspace başına: PostgreSQL, MySQL, Redis, MongoDB
- Parolalar **libsodium ile şifrelenerek** saklanır; arayüze düz metin dönmez (`SecretCipher`)
- "Bağlantıyı test et" butonu (her tip için connectivity probe)
- **DB doğrulama adımı**: bir test akışı adımı HTTP yerine sorgu çalıştırıp sonucu doğrular
  - SQL: `SELECT ...` → `rowCount == 1`, `rows.0.kolon == değer`
  - Redis: `GET key` → `value == ...`, `exists == true`
  - Mongo: `{"collection":"x","filter":{...}}` → `count == N`, `documents.0.alan == ...`
- **HTTP → DB zincirleme**: bir HTTP adımının çıkardığı değer, DB sorgusunda kullanılır
  (`SELECT ... WHERE user_id = {{userId}}`) — "API'yi tetikle, sonucu DB'den doğrula"

Yeni servisler: `SqlConnector`, `RedisConnector`, `MongoConnector`, `DbQueryRunner`, `SecretCipher`.
Docker: `mongo` servisi + php eklentileri `pdo_mysql`, `mongodb`.

### Faz 4 — Tetikleme (API token, CI, zamanlama)
- **API token'ları** workspace başına (SHA-256 hash, plaintext yalnızca bir kez gösterilir, iptal edilebilir)
- **Programatik API** (`/api/v1`, Bearer token ile stateless):
  - `GET /api/v1/flows` — akışları listele
  - `POST /api/v1/flows/{id}/run` — akışı çalıştır → JSON sonuç; **geçerse HTTP 200, başarısızsa 422** (CI exit code)
  - `?format=junit` — JUnit XML raporu (CI entegrasyonu)
  - `GET /api/v1/flows/{id}/runs/{runId}` — koşum detayı
- **Zamanlama**: akış başına cron ifadesi; `app:run-due-flows` komutu due olanları çalıştırır (idempotent)

```bash
# CI örneği (başarısızlıkta non-zero exit):
curl -fsS -X POST -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/api/v1/flows/$FLOW_ID/run

# Zamanlanmış akışlar için sistem cron'una ekleyin (dakikada bir):
* * * * * docker compose exec -T php php bin/console app:run-due-flows
```

Yeni: `ApiToken` entity, `ApiTokenAuthenticator` (stateless api firewall), `ApiController`, `FlowRunReporter` (JSON+JUnit), `RunDueFlowsCommand`.

### Faz 4.5 — MCP Server (Claude doğal dilden test akışı kurar/çalıştırır)
Symfony içinde, **Streamable HTTP (JSON-RPC 2.0)** konuşan bir MCP endpoint: `POST /mcp`.
Aynı Bearer API token ile korunur ve token'ın **workspace'ine scope'lanır** — Claude yalnızca
o workspace'in (ve dolayısıyla o merchant'ın) verisine erişir. `initialize` ve `whoami`, oturumun
hangi merchant/workspace ile sınırlı olduğunu açıkça bildirir; her araç çağrısı kaynakların
o workspace'e ait olduğunu UUID ile doğrular. Ayrı bir Node süreci yoktur; mevcut domain servislerini kullanır.

**Araçlar (19):**
- *Kapsam:* `whoami`
- *Okuma:* `list_collections`, `search_requests`, `list_environments`, `list_db_connections`, `list_flows`, `get_flow`, `list_runs`, `get_run`
- *Kurma:* `create_flow`, `create_flow_from_collection` (bir collection'ın isteklerinden tek seferde sıralı flow), `add_http_step`, `add_db_step`, `add_setvar_step`, `add_delay_step`
- *Çalıştırma:* `run_flow` (senkron), `run_flow_async` (arka planda, runId döner)
- *Silme:* `delete_flow`, `delete_step`

Böylece Claude'a "signup isteğini at, dönen userId ile subscriptions tablosunda active kayıt
var mı doğrula" denildiğinde: isteği bulur → akış kurar → HTTP + DB adımlarını ekler →
çalıştırır → DB doğrulama sonucunu okur → gerekirse düzeltir.

```bash
claude mcp add --transport http api-test http://localhost:8080/mcp \
  --header "Authorization: Bearer <TOKEN>"
```

Yeni: `McpController` (JSON-RPC transport), `McpToolRegistry` (workspace-scoped araçlar).

## Çalıştırma

```bash
docker compose up -d --build
```

İlk açılışta `php` konteyneri otomatik olarak:
1. Composer bağımlılıklarını kurar
2. Veritabanını oluşturur ve migration'ları çalıştırır
3. Varsayılan süper admin'i oluşturur

Ardından uygulamaya gidin: **http://localhost:8080**

### Girişler (ayrı paneller, ayrı login)

Süper admin ve merchant **ayrı login sayfaları ve ayrı firewall'lar** kullanır:

| Panel | Giriş adresi | Varsayılan hesap |
|-------|--------------|------------------|
| Süper Admin | http://localhost:8080/admin/login | `admin@signal.local` / `admin1234` |
| Merchant | http://localhost:8080/login | (admin'in oluşturduğu merchant yetkilisi) |

**Merchant kaydı (self-servis):** Merchant'lar `/register` adresinden kendileri kayıt olabilir
(şirket + ilk admin kullanıcısı oluşturulur, otomatik giriş yapılır). Alternatif olarak süper
admin `/admin/login`'den girip **Merchant'lar → Yeni Merchant** ile elle de oluşturabilir.

İki oturum bağımsızdır (biri diğerine erişemez). Merchant kendi `/app` panelinde istediği kadar
workspace açar.

## Faydalı komutlar

```bash
# Logları izle
docker compose logs -f php

# Konteyner içinde konsol
docker compose exec php php bin/console

# Yeni migration üret
docker compose exec php php bin/console make:migration
docker compose exec php php bin/console doctrine:migrations:migrate

# Ek süper admin oluştur
docker compose exec php php bin/console app:create-superadmin email@x.com sifre "Ad Soyad"
```

## Mimari (özet)

```
nginx (8080) → php-fpm (Symfony) → PostgreSQL (app DB) + Redis (queue/cache)
```

Yol haritası:
- ✅ **Faz 0**: Docker temel + multi-tenant + paneller
- ✅ **Faz 1**: Postman import + collection ağacı + environment editor + request atma
- ✅ **Faz 2**: Test akışı (chaining + extraction + assertion) + manuel koşum + run geçmişi
- ✅ **Faz 3**: DB doğrulama bağlayıcıları (PostgreSQL, MySQL, Redis, MongoDB)
- ✅ **Faz 4**: API token + programatik koşum (CI) + JUnit + cron zamanlama
- ✅ **Faz 4.5**: MCP server — Claude doğal dilden test akışı kurup çalıştırır (workspace-scoped)
- **İleride**: async kuyruk (Messenger), webhook tetikleme, bildirimler, onay kapısı (prod)
