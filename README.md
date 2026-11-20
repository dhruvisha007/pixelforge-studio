# Pixelforge Studio

Users sign in, write a prompt, pick a size and quality, and get an image from the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API. They see the price before they
generate, pay Pixelforge's cost plus 25% when the image is ready, and pay nothing when it fails.

Built with Laravel 12, Vue 3, Inertia 2 and Tailwind. SQLite locally, Postgres in production.

- **Required part:** generate page with a live price, exact billing, a live history, and
  webhooks that are verified, deduplicated and safe in any order.
- **Stretch goals:** both are done. There is a per-user credit balance with a fake "Buy credits"
  page and a check that blocks images you can't afford. There is also an admin report of spend,
  provider cost and profit per day and per user, checked line by line against Pixelforge's
  `GET /v1/usage`.

---

## Running it locally

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # creates demo@example.com / password (an admin)
composer dev                 # web server, queue worker, scheduler, logs and Vite
```

Then open http://127.0.0.1:8000.

Add your Pixelforge key to `.env`. Use a different key for each environment: each key has its own
webhook URL, signing secret and usage report.

```dotenv
PIXELFORGE_API_KEY=pf_...
PIXELFORGE_WEBHOOK_SECRET=whsec_...
```

**Webhooks locally.** Pixelforge can't reach `localhost`, so either:
- run a tunnel (`cloudflared tunnel --url http://127.0.0.1:8000`) and set the key's webhook URL to
  `https://<tunnel>/webhooks/pixelforge`, or
- skip webhooks entirely. The scheduler polls any generation it hasn't heard about for 45 seconds,
  so everything still finishes, just a little later.

**Tests:** `php artisan test` (69 tests, run on SQLite and Postgres in CI, no network: Pixelforge is faked with the exact shapes from its docs).

**Manual testing:** put Pixelforge's keywords in a prompt.

| Keyword | What happens |
|---|---|
| `[fail]` | Accepted, then fails |
| `[slow]` | Takes 60 to 90 seconds |
| `[duplicate]` | Every webhook is sent twice |
| `[blocked]` | Rejected at once |
| `[overloaded]` | Rejected at once with a 503 |
| `[succeed]` | Never fails at random |

---

## How a generation flows

```
Generate form ──POST /generations──▶ GenerationController@store
   (live price from                   • validates like Pixelforge does
    POST /generations/estimate)       • locks the user row, checks available credit ≥ max price
                                      • saves the generation as "pending", holding the max price
                                      • queues SubmitGeneration
SubmitGeneration (job)
   • POST /v1/generations, Idempotency-Key = our uuid, metadata.generation_uuid = our uuid
   • 202 ──▶ GenerationUpdater (status "queued")
   • 429 / 503 / network errors ──▶ retry later (see below)
   • 400 / 422 / daily limit ──▶ failed, free

Pixelforge ──webhook──▶ POST /webhooks/pixelforge
   • verify HMAC signature on the raw body, reject if older than 5 minutes
   • store the event once (unique event id), reply 204, process it in a queued job
   • ProcessPixelforgeEvent ──▶ GenerationUpdater

PixelforgePoller (the webhook fallback)
   • runs from the scheduler every 15 s (generations:sync), and from the studio page
     every 30 s while someone watches images in progress (CatchUpWithPixelforge)
   • polls generations with no news for 45 s ──▶ GenerationUpdater
   • fails anything stuck in "pending" for 20 minutes (lost job, never sent, free)

GenerationUpdater   ◀── the only code that changes a status or charges money
   • locks the generation row
   • only moves forward: pending → queued → processing → succeeded / failed
   • on "succeeded": cost from usage × the generation's own rates, + 25%, one ledger row
   • on "failed": cost 0
   • after success: StoreGenerationImage copies the PNG (Pixelforge's URL dies after 24 h)
```

---

## Decisions (and why)

### Money

- **Every amount is an integer number of nanodollars** (1 USD = 1,000,000,000). Pixelforge reports
  costs with exactly nine decimal places, so nanodollars hold every provider amount exactly.
  Integers never drift like floats, and they sum exactly in SQL. Decimal maths uses `brick/math`.
  All of it lives in `app/Billing/CostCalculator.php`, unit-tested against every worked example
  in Pixelforge's docs.
- **Provider cost = Σ tokens × rate.** The rates are the ones on the generation itself, which
  Pixelforge fixes at creation. They are not the current price list, so a price change never
  re-prices an image already in flight.
- **User price = provider cost × 1.25, rounded up to the next nanodollar.** That is the sub-cent
  decision: we keep full precision and never round to cents. With today's rates every provider
  cost is a multiple of 500 nanodollars, so the markup always comes out exact. The round-up only
  matters if rates change, and even then it costs a user at most $0.000000001.
- **Display rounding is separate from stored amounts.** The UI shows 4 decimal places. Estimates
  are rounded outward (min down, max up), so the real price always falls inside the range shown.
  Hover a charge to see the exact amount.

### The estimate

The output tokens and the 765 tokens per reference image are known up front. Text tokens are
only known to within ±15% of `ceil(characters / 4)`, counting Unicode characters. Reasoning
tokens are only known within the range for the chosen quality. So the form shows a **min to max
range**, updated live as you type. The server computes it from `GET /v1/pricing`, which is cached
for 10 minutes, and keeps the last good copy if Pixelforge is down. It is never computed in the
browser, so there is one source of truth.

### Balances (stretch)

- Balances are a **ledger** (`credit_transactions`): balance = the sum of the user's rows. Rows
  are never edited. A **unique index on `generation_id`** means the database itself refuses a
  second charge for the same generation.
- When an image starts, we **hold its maximum price**. Available = balance − the sum of max
  prices of in-progress images. When it finishes, the hold disappears and the actual cost is
  charged. When it fails, the hold just disappears. No hold or release rows are needed, so there
  is nothing to get out of sync.
- The affordability check runs inside a transaction with the user row locked, so two quick
  clicks can't spend the same credit twice.
- New accounts get $1.00 so reviewers can try it at once. "Buy credits" adds $5, $10 or $25
  instantly.

### Webhooks

- **Verify first.** The signature is HMAC-SHA256 of `timestamp.rawBody`, compared in constant
  time. Requests older than 5 minutes are rejected as replays. The route sits outside the `web`
  middleware group, so there is no session or CSRF.
- **Store once.** `insertOrIgnore` on a unique `event_id`. A duplicate delivery is a no-op. An
  event that was stored but never processed (lost job) is queued again on its next delivery.
- **Reply fast.** Pixelforge wants a 2xx within 10 seconds, so the real work happens in a job.
- **Go by status, not by event type.** Each status has a stage. A generation only moves to a
  later stage, so a retried `generation.processing` that arrives after `generation.succeeded`
  changes nothing. A succeeded or failed result from Pixelforge never changes.
- **Webhook before the API response.** The webhook can arrive before our `POST` returns, so we
  match events by the uuid we put in `metadata`, not by Pixelforge's id.
- **Charge exactly once.** The update runs with the generation row locked, the charge only
  happens on the move into "succeeded", and the ledger's unique index backs that up.

### Calling Pixelforge

- **Idempotency.** Every attempt uses the generation's uuid as the `Idempotency-Key`, with
  byte-for-byte the same body. A retry after a timeout returns the generation that was already
  created instead of making (and billing) a second one.
- Errors fall into three groups:
  - **Wait your turn:** `concurrency_limit_exceeded` and `rate_limit_exceeded`. Retry after
    `Retry-After`. This doesn't count as a failure (`[slow]` images can hold a slot for 90 s).
  - **Temporary failure:** `overloaded`, 5xx and network errors. Exponential backoff (2, 4, 8,
    16 s, or longer if `Retry-After` says so), then give up after 5.
  - **Final:** content policy, validation, daily limit, bad key. Fail at once, free, with a
    message the user can act on.
- **Local concurrency gate.** Pixelforge allows 3 in flight per account, so the job waits
  locally while 3 are queued or processing. It doesn't spend requests on 429s.
- **When our failure and Pixelforge disagree.** If every create request times out we give up,
  but one may have got through. A failure we recorded *before Pixelforge confirmed the
  generation* gives way to whatever Pixelforge later reports. If the image did succeed, the user
  gets it and is charged, and our books still match Pixelforge's. That one case can push a
  balance slightly negative. I chose that over silently losing money.

### Polling without an always-on scheduler

A scheduler that runs every 15 seconds keeps the app and its database awake around the clock,
which costs money on a host that bills by the second. So the studio page also triggers the
catch-up: while it is showing images in progress it reloads every 2 seconds, and at most once
every 30 seconds (an atomic `Cache::add`) that queues `CatchUpWithPixelforge`. Webhooks still do
the real work; this only rescues the rare one that never arrives.

### Images

Pixelforge's signed URLs expire 24 hours after completion, so `StoreGenerationImage` copies each
PNG to the `GENERATION_IMAGE_DISK` disk. Images are served through
`/generations/{uuid}/image`, which only works for their owner (and admins). If the copy hasn't
finished yet, that route redirects to Pixelforge's URL. If the URL has expired and there is no
copy, the card says so.

### Admin report (stretch)

- Shows per UTC day and per user: images, what users were charged, what Pixelforge charged, and
  profit.
- Days are grouped by **when the image completed**, because that is how `GET /v1/usage` bills
  them. Each day is compared with Pixelforge's figure for this API key, with a ✓ or the
  difference.
- To make someone an admin: `php artisan users:make-admin someone@example.com`.

---

## Deploying (Laravel Cloud)

1. Push this repo to GitHub and create a Laravel Cloud app from it.
2. Add a **Postgres** database. Cloud sets the `DB_*` variables.
3. Add an **object storage bucket** for images, then set `GENERATION_IMAGE_DISK` to its disk
   name (for example `s3`). The app server's own disk is not kept between deploys.
4. Add a **background process** for the queue worker: `php artisan queue:work --tries=3`.
   The **scheduler** is optional. It keeps the app awake around the clock, so the free
   setup leaves it off and keeps **scale to zero** on. Lost webhooks are then caught by
   the studio page instead (see PixelforgePoller).
5. Environment: `APP_URL`, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`. Use a
   *separate* Pixelforge key from your local one.
6. Deploy commands: `php artisan migrate --force`.
7. In the Pixelforge dashboard, set that key's webhook URL to `https://<your-app>/webhooks/pixelforge`
   and press "Send test event". It should show a 204.
8. Make yourself an admin: `php artisan users:make-admin you@example.com`.

---

## What I would do next

- Per-user fairness when the 3 Pixelforge slots are busy. Today it is first come, first served.
- A nightly job that compares every day with `GET /v1/usage` and alerts on any difference,
  instead of only showing it in the report.
- Pruning old `webhook_events` rows after, say, 30 days.
