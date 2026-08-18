<p align="center">
  <strong>Signal</strong> — API testing with database verification
</p>

<h1 align="center">A 200 response is not a passing test.</h1>

<p align="center">
Signal fires your API, chains the values it returns, then verifies what actually
landed in <b>PostgreSQL, MySQL, Redis and MongoDB</b> — in the same run.
</p>

<p align="center">
  <a href="https://signalapi.dev">signalapi.dev</a> ·
  <a href="#quick-start">Quick start</a> ·
  <a href="#claude--mcp">Claude / MCP</a> ·
  <a href="#ci">CI</a> ·
  <a href="#license">License</a>
</p>

---

Most tools stop at the response body. That is where the interesting bugs start:
a webhook that never fires, a ledger row that never lands, a cache that never
invalidates — all of it returns 200. Signal chains an HTTP step's extracted
values straight into `WHERE id = {{subId}}` and fails the run when the database
disagrees with the status code.

## What you get

- **Request workbench** — collections, folders, saved examples, environments,
  variable highlighting, code generation. Import a Postman collection
  (v2.1) and keep working.
- **Flows that chain** — extract a value, assert on it, feed it into the next
  step. Conditions, loops, retries, waits and sub-flows called by reference.
- **Database assertions** — `rowCount`, row values, Redis keys, Mongo documents,
  asserted in the same run as the request that caused them. Connection
  passwords are sealed with libsodium and never returned to the browser.
- **Browser step** — a Playwright runner completes hosted 3-D Secure / redirect
  sessions (Checkout.com, Stripe, Adyen and a generic handler) so payment
  flows can run end to end, headless.
- **Suites and schedules** — group flows into suites, run them end to end, or
  let cron do it. History, trends, flakiness and p95 out of the box.
- **Notifications** — run results delivered to a Slack channel (incoming
  webhook) or any HTTP endpoint (n8n, Zapier, your own service), by standing
  rule per workspace/test/suite, per schedule, or ticked for a single run.
- **CI without glue code** — one authenticated `POST` runs a flow and returns
  `422` on failure; ask for JUnit XML and your pipeline renders it.
- **Claude / MCP** — a Streamable HTTP MCP endpoint inside the app, scoped to a
  single workspace by the same Bearer token. Claude builds and runs flows over
  natural language.
- **Built for teams** — companies, workspaces, members, roles and API tokens;
  every resource scoped, including the MCP session.

## Quick start

Requirements: Docker + Docker Compose.

```bash
git clone https://github.com/signalapi/signal.git
cd signal
docker compose up -d --build
```

Open <http://localhost:8080>. The first boot runs migrations and seeds a
platform admin (`admin@signal.local` / `admin1234`) — change it immediately:

```bash
docker compose exec php php bin/console app:create-superadmin you@example.com 'strong-password' 'Your Name'
```

Sign up at `/register` for the app itself; the `/admin` area is a separate
identity set for platform operators.

For a production deployment (TLS, secrets, hardening) see [DEPLOY.md](DEPLOY.md).

## Claude / MCP

Create a token under **API & MCP** in a workspace, then:

```bash
claude mcp add --transport http signal https://your-host/mcp \
  --header "Authorization: Bearer <TOKEN>"
```

The session is scoped to the token's workspace. Tools cover reading
(`list_flows`, `get_run`, `search_requests`), building (`create_flow`,
`add_http_step`, `add_db_step`, `add_browser_step`, …) and running
(`run_flow`, `run_flow_async`, `run_suite`).

## Notifications

**Notifications** in a workspace holds the destinations and the rules.

- A destination is a Slack incoming webhook URL or a plain HTTP endpoint; the
  URL is sealed with libsodium and never rendered back. Give an HTTP endpoint a
  secret and every call carries `X-Signal-Signature: sha256=<hmac of the body>`.
- A rule says who hears about what: every run in the workspace, one test or one
  suite — always, or only on failure.
- Rules cover the runs nobody is watching: scheduled, API/CI and MCP. A run you
  start yourself stays quiet unless you pick a destination in the run bar, and
  `notify: off` mutes one run whatever the rules say. A schedule can carry its
  own destinations on top of the rules.
- Sending happens on the worker, never inside the run, so a slow or broken
  endpoint cannot fail a test. Every attempt is logged with its HTTP status and
  error under **Deliveries**.
- A message carries the run, not just its verdict: what failed with the
  assertion that caught it, then the rundown — every flow of a suite, every row
  of a data-driven run, every step of a single run with its HTTP status and
  duration — and a link to the full report.
- Over MCP: `list_notification_destinations` to see the channels,
  `notify: ["#api-alerts"]` on `run_flow` / `run_flow_async` / `run_suite`,
  `notify: ["none"]` to keep one run silent, and `notify` / `notifyCondition` on
  `update_schedule`. Destinations are created in the UI, because the webhook URL
  is a secret.

## CI

```bash
# non-zero exit when a step fails
curl -fsS -X POST -H "Authorization: Bearer $TOKEN" \
  https://your-host/api/v1/flows/$FLOW/run

# same call, JUnit XML your CI can render
curl -fsS -X POST -H "Authorization: Bearer $TOKEN" \
  "https://your-host/api/v1/flows/$FLOW/run?format=junit"
```

## Stack

Symfony 7.2 · PHP 8.3 · PostgreSQL 16 · Redis · MongoDB (optional, for Mongo
assertions) · Playwright (browser steps) · Docker Compose. No JS build step —
the UI is server-rendered Twig with a single stylesheet.

## Signal Cloud

Prefer not to host it yourself? A managed cloud version is on the way at
[signalapi.dev](https://signalapi.dev) — free while it is young.
Self-hosting stays free and unlimited.

## License

[AGPL-3.0](LICENSE). You can self-host, modify and use Signal freely; if you
offer a modified Signal as a service, you must publish your changes under the
same license.
