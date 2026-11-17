# Pixelforge Studio

A Laravel + Vue + Inertia app where users generate images through the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API and pay Pixelforge's
cost plus a 25% markup, recorded exactly.

## What it does

- **Generate**: prompt, size, quality and up to 4 reference image URLs. A live
  estimate shows the *expected* price and the *most it can cost* before you
  submit, with a token-by-token breakdown.
- **History**: every generation with live status (submitting, queued,
  processing, succeeded, failed), the estimated and the actual cost, and the image.
- **Credits** (stretch): balance, a fake "Buy credits" button, and a full
  ledger. Generating is blocked if the balance can't cover the worst case.
- **Operator report** (stretch, `/admin/report`): credit spend, provider cost
  and profit per UTC day and per user, with Pixelforge's own `GET /v1/usage`
  figure next to ours for every day, plus a ✓/✗ match indicator.

## Key decisions

### Money is integer nanodollars
Pixelforge reports costs as exact decimals with 9 places, so every amount is
stored as a `BIGINT` of nanodollars (1e-9 USD) and computed with integer maths
(`App\Support\Money`, `App\Pixelforge\CostCalculator`). There are no floats
anywhere in the money path, including the frontend estimate, which uses `BigInt`.

- Provider cost = Σ tokens × rate, using the `rates` **fixed on the generation**
  (from the create response or webhook), not today's prices.
- User price = provider cost × 1.25, computed from the unrounded sum and
  **rounded up to the next nanodollar** (at most $0.000000001 per generation).
  At current rates the result is always exact anyway.
- Display rounds to 4–6 decimals. Hovering shows the exact 9-decimal value.

### Estimate vs. actual, and reservations
Reasoning tokens and the exact text-token count are only known afterwards, so:

- *expected* = `ceil(chars/4)` text tokens + the midpoint of the reasoning range;
- *max* = text tokens +15% (rounded up) + the maximum reasoning tokens.

On submit we **reserve (hold) the max** from the balance. When the generation
is final, the hold is released and a succeeded one is charged its actual price.
Failed generations cost nothing. Reserving the worst case means a user can never
overspend, even with several generations running at once.

### Never charged twice
- Each webhook's `evt_` id is stored with a unique index, in the **same DB
  transaction** that applies it. A redelivered event is acknowledged and ignored,
  and if applying fails, the event isn't marked seen, so Pixelforge's retry tries again.
- All remote updates (create response, webhook, poll) go through
  `GenerationService::applyRemote()`, which locks the row, **only moves status
  forward** (`pending → queued → processing → succeeded|failed`; final is final),
  and settles exactly once (`settled_at`).
- The ledger has a unique `(generation_id, type)` index as a last line of defence.
- `users.balance_nanos` always equals the sum of the user's ledger rows.

### Robustness
- **Idempotency**: our generation's UUID is the `Idempotency-Key`, so retrying
  after a timeout can never create a second generation.
- **Webhook before the POST returns**: the UUID is also sent in `metadata`, so
  webhooks are matched to our row even before we know Pixelforge's `gen_` id.
- **503 / 429 / network errors**: the first attempt runs inline (so `[blocked]`
  and validation errors show straight on the form). Retryable errors go to the
  `SubmitGeneration` queued job, which waits at least `Retry-After` and backs off
  exponentially. It gives up after 6 attempts, but keeps waiting for a free
  concurrency slot for up to 15 minutes, which is expected when a user queues
  several images. Giving up releases the hold.
- **Abandoned but actually created**: if we gave up but an earlier timed-out
  request did go through, the later `succeeded` event records the provider cost
  (so the admin report still matches Pixelforge) and charges the user $0.
- **Missed webhooks**: `generations:sync` runs every 30 seconds and polls
  generations that have been quiet for 30s or more.
- **Daily limit / content policy**: these fail immediately with a clear message,
  and nothing is charged.
- Validation mirrors Pixelforge's (sizes, qualities, 4,000 chars, ≤ 4 http(s) URLs).

### Report days
Like Pixelforge, a generation counts on the **UTC day it completed**, using
Pixelforge's `completed_at`. Provider costs are per API key, so the deployed app
uses its own key and its numbers match `/v1/usage` for that key.

## Running locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed      # test@example.com / password, with $5 credits
composer dev                    # server, queue worker, logs, vite
php artisan schedule:work       # polling fallback (separate terminal)
```

Set in `.env`:

```
PIXELFORGE_API_KEY=pf_...          # one key per environment
PIXELFORGE_WEBHOOK_SECRET=whsec_...
ADMIN_EMAILS=you@example.com       # who can open /admin/report
```

Webhooks need a public URL. Use a tunnel (`expose share`, `ngrok http 8000`,
`cloudflared tunnel`) and set `https://<tunnel>/webhooks/pixelforge` as the
key's webhook URL in the Pixelforge dashboard. Without one, the polling
fallback still brings generations up to date.

## Deploying (Laravel Cloud)

1. Create an app from this GitHub repo and attach a database (Postgres or MySQL).
2. Environment variables: `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET` for a
   **separate production key**, `ADMIN_EMAILS`, `APP_NAME="Pixelforge Studio"`.
3. Build command: `composer install --no-dev && npm ci && npm run build`.
   Deploy command: `php artisan migrate --force`.
4. Enable a **queue worker** (`php artisan queue:work`) and the **scheduler**.
5. In Pixelforge, set the production key's webhook URL to
   `https://<your-app>/webhooks/pixelforge`, then press "Send test event".

## Tests

```bash
./vendor/bin/pest
```

The tests cover the docs' worked pricing examples, rounding, signature
verification, duplicate, out-of-order and early webhooks, failed generations,
content-policy rejections, overload retries and giving up, the polling fallback,
buying credits, and the admin report reconciling with `/v1/usage`.
