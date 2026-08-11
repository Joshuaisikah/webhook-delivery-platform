# Webhook Delivery Platform

A REST API where applications register webhook URLs. Creating an event for an
application delivers it to all of that application's webhooks in the background,
with retries.

Laravel 13, PHP 8.4, MySQL 8.4, Docker Compose.

## Requirements

Docker and Docker Compose. Ports 8000 and 3307 need to be free.

Without Docker: PHP 8.3+ with `pdo_mysql`, Composer, and a MySQL 8 server.

## Setup

```bash
cp .env.example .env
docker compose up -d --build
curl http://localhost:8000/api/health
```

Migrations run automatically when the app container starts.

Four containers come up:

- `mysql`: the database
- `app`: the API on port 8000
- `worker`: processes queued jobs
- `scheduler`: runs the claim command every ten seconds

### Without Docker

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

php artisan serve
php artisan queue:work --queue=fanout,webhook-deliveries --tries=1
php artisan schedule:work
```

## Configuration

Database settings live in `.env` and point at the Compose service by default:

```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=webhooks
DB_USERNAME=webhooks
DB_PASSWORD=secret
```

MySQL is exposed on host port 3307, since 3306 is often taken by a local install:

```bash
mysql -h 127.0.0.1 -P 3307 -uwebhooks -psecret webhooks
```

To run artisan from the host rather than inside the container, override the host:

```bash
DB_HOST=127.0.0.1 DB_PORT=3307 php artisan migrate
```

`DB_*` has to be set in `.env`, not only in `docker-compose.yml`. `php artisan
serve` passes just a few environment variables through to the server process it
spawns, so container environment never reaches an HTTP request.

Retry and throughput settings are in `config/webhooks.php`, all overridable by
environment variable: `WEBHOOK_MAX_ATTEMPTS` (5), `WEBHOOK_BACKOFF_BASE` (30s),
`WEBHOOK_BACKOFF_MAX` (1h), `WEBHOOK_CLAIM_BATCH_SIZE` (200),
`WEBHOOK_CLAIM_MAX_BATCH_SIZE` (1000), `WEBHOOK_CLAIM_MAX_RUN_SECONDS` (8),
`WEBHOOK_MAX_QUEUE_DEPTH` (1000), `WEBHOOK_STALE_LOCK_MINUTES` (10),
`WEBHOOK_FANOUT_CHUNK_SIZE` (500), `WEBHOOK_MAX_PAYLOAD_BYTES` (65536),
`WEBHOOK_HTTP_TIMEOUT` (5s).

## Migrations

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:fresh
```

Stop the workers before `migrate:fresh`, as it drops the `cache` and `jobs` tables
while they are using them:

```bash
docker compose stop worker scheduler
docker compose exec app php artisan migrate:fresh
docker compose start worker scheduler
```

Four tables: `applications`, `webhooks`, `events`, `webhook_deliveries`. There is
no seeder; the curl examples below create everything.

## How delivery works

Creating an event inserts one row and queues one job, then returns. It contacts no
webhooks, so the response time is the same whether the application has three
webhooks or fifty thousand.

That job writes one `webhook_deliveries` row per active webhook, in chunks. Those
rows are the work list.

`webhooks:process-deliveries` claims a batch of due deliveries and queues a job
for each. The scheduler runs it every ten seconds, and it can be run by hand:

```bash
docker compose exec app php artisan webhooks:process-deliveries
docker compose exec app php artisan webhooks:process-deliveries --limit=50
```

Several copies can run at once. Claiming uses `SELECT ... FOR UPDATE SKIP LOCKED`
so concurrent runs take disjoint rows, and each claim stamps a token that the
delivery job checks before writing anything.

Throughput comes from the number of workers:

```bash
docker compose up -d --scale worker=5
```

Restart the workers after changing code or config. `queue:work` keeps the
framework in memory and will not pick up changes on its own:

```bash
docker compose restart worker scheduler
```

Every attempt is logged with its status code and duration:

```bash
docker compose exec app tail -f storage/logs/laravel.log
```

### Retries

Five attempts by default, backing off 30s, 60s, 120s, 240s.

Not everything is retried. A 4xx means the request will not be accepted however
many times it is sent, so it fails straight away, except 408 and 429, which mean
try again. 5xx, timeouts and connection errors are retried. Redirects are not
followed and count as permanent.

A worker that dies mid-delivery leaves its row locked. Each run first returns
anything locked for longer than `WEBHOOK_STALE_LOCK_MINUTES` to the pool.

## API

All endpoints take and return JSON. No authentication.

Successful responses have a `data` key, paginated ones also `meta`. Failures have
`message`, and validation failures add `errors`. Registering something that
already exists is always a 422.

### Create an application

```bash
curl -X POST http://localhost:8000/api/applications \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"acme"}'
```

### Register a webhook

```bash
curl -X POST http://localhost:8000/api/applications/1/webhooks \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"url":"https://example.com/hooks/orders"}'
```

`url` must be http or https, at most 500 characters, and unique for the
application. `is_active` is optional and defaults to true.

### Create an event

```bash
curl -X POST http://localhost:8000/api/applications/1/events \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"order.created","payload":{"order_id":42,"total":9.99}}'
```

`payload` must be an array or object, up to 64 KB encoded. Returns 201
immediately; delivery happens in the background.

Each webhook receives:

```http
POST /hooks/orders
Content-Type: application/json
X-Webhook-Delivery-Id: 1234
X-Webhook-Event: order.created
X-Webhook-Event-Id: 7
X-Webhook-Attempt: 1

{
  "id": 7,
  "event": "order.created",
  "created_at": "2026-08-11T07:41:45+00:00",
  "data": { "order_id": 42, "total": 9.99 }
}
```

### Delivery history

```bash
curl 'http://localhost:8000/api/applications/1/deliveries?status=failed' \
  -H 'Accept: application/json'
```

Newest first, paginated. Filters: `status`, `webhook_id`, `event_id`, `per_page`
(max 100).

### Retry a delivery

```bash
curl -X POST http://localhost:8000/api/applications/1/deliveries/62/retry \
  -H 'Accept: application/json'
```

Puts a failed delivery back in the queue with a fresh set of attempts. Anything
other than a failed delivery returns 409.

## Trying it locally

`docker/test-receiver.php` is a small endpoint for watching deliveries arrive.
`/ok*` returns 200, `/fail*` returns 500, `/status-NNN` returns that code, and
`/slow*` sleeps past the timeout.

```bash
docker run -d --name receiver --network webhook-delivery-platform_default \
  -v "$PWD/docker/test-receiver.php:/receiver.php:ro" \
  php:8.4-cli php -S 0.0.0.0:9000 /receiver.php
```

Register `http://receiver:9000/ok-1` and `http://receiver:9000/fail-1`, create an
event, then watch what happens:

```bash
docker exec receiver cat /tmp/received.log
docker compose exec mysql mysql -uwebhooks -psecret -D webhooks \
  -e "SELECT id,status,attempts,last_response_code,next_attempt_at FROM webhook_deliveries"
```

The failing one climbs through its attempts and ends up `failed`. To check that
concurrent claims never deliver twice, run a few commands at once and look for
repeated delivery ids:

```bash
for i in 1 2 3 4; do
  docker compose exec -T app php artisan webhooks:process-deliveries --limit=20 &
done; wait

docker exec receiver sh -c \
  "grep -o 'delivery=[0-9]*' /tmp/received.log | sort | uniq -d | wc -l"
```

Clean up with `docker rm -f receiver`.

## Assumptions and limitations

- Delivery is at-least-once. A worker that dies after a 200 but before writing the
  result will resend once its lock goes stale, so dedupe on
  `X-Webhook-Delivery-Id`. Duplicate delivery rows are impossible.
- A webhook registered while an event is fanning out may miss that event.
- Any http/https URL is accepted, including loopback and private addresses, which
  local testing needs. Redirects are not followed and connection failures are
  reported coarsely, but a production deployment should resolve the host and reject
  internal ranges. URLs with embedded credentials are rejected.
- Payloads are unsigned, so receivers cannot verify the sender.
- No per-endpoint rate limit. Throughput is bounded by worker count and batch size.
- `url` is capped at 500 characters to stay inside InnoDB's index key limit, since
  it is part of a composite unique index.
- Nothing is pruned. Events and deliveries are kept indefinitely.
- No authentication. Every endpoint is open.
- Not implemented: automated tests, webhook signing, seeders, an OpenAPI or Postman
  collection, events and listeners, caching.
