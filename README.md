# Subscription Billing & Usage-Metering (Laravel 11)

Multi-tenant backend that meters customer usage against subscription plans and produces prorated invoices
with overage, including mid-cycle upgrades/downgrades.

> **Status note:** written without being able to execute PHP in the authoring environment. The test-suite
> expected values were worked out by hand (see comments in `InvoiceCalculatorTest`). Run `php artisan test`
> first thing after setup; if anything is red it is most likely a typo-level fix, not a design issue.

## Setup

```bash
./install.sh billing-app          # creates a fresh Laravel 11 app and overlays this code
cd billing-app
# .env: MySQL 8 (recommended) or sqlite; for the "real" setup CACHE_STORE=redis QUEUE_CONNECTION=redis
php artisan migrate --seed        # seeds "Acme Corp" (X-Api-Key: demo-api-key) + 7 customers + 70 days of usage
php artisan test
php artisan queue:work --queue=aggregation,billing,default
php artisan schedule:work         # every 15 min: usage:aggregate ; daily 01:00: billing:generate-invoices
php artisan serve
```

Manual overlay instead of `install.sh`: `composer create-project laravel/laravel app "^11.0"`, copy this folder
over it (overwrites `bootstrap/app.php`, `routes/*`, `phpunit.xml`, `AppServiceProvider`), then `php artisan install:api` is **not** required.

### Try it
```bash
H='X-Api-Key: demo-api-key'
curl -X POST localhost:8000/api/usage -H "$H" -H 'Idempotency-Key: abc-1' -H 'Content-Type: application/json' \
     -d '{"customer_id":1,"quantity":25}'                 # 201, then 200 {"duplicate":true} on retry
curl localhost:8000/api/merchants/1/dashboard -H "$H"
curl -X POST localhost:8000/api/subscriptions/1/change-plan -H "$H" -H 'Content-Type: application/json' \
     -d '{"plan_id":2,"effective_on":"2026-10-15"}'
php artisan billing:generate-invoices                    # queued, chunked
curl localhost:8000/api/invoices -H "$H"
```

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/usage` | record usage event (idempotent, rate-limited 120/min/key) |
| GET | `/api/merchants/{id}/dashboard` | top 5, projected overage, churn risk, 30-day trend |
| GET/POST/PUT | `/api/plans` | plan CRUD (cache-invalidating) |
| POST | `/api/customers`, `/api/subscriptions`, `/api/subscriptions/{id}/change-plan` | onboarding & plan change |
| GET | `/api/invoices` | generated invoices with lines |

Auth: `X-Api-Key` header → merchant (tenant). Every query is scoped by the merchant on the key.

## Architecture

```
HTTP (thin controllers + FormRequest)
  └─ Actions      SubscribeCustomer, ChangePlan            (transactional state changes)
  └─ Services     UsageRecorder  UsageAggregator  InvoiceGenerator  DashboardService  PlanCache ...
  └─ Billing/     InvoiceCalculator, Money, BillingCycle, DTOs   (PURE: no DB/clock/cache)
Jobs/Commands     AggregateCustomerUsageChunk, GenerateInvoiceChunk  (queued, chunked)
```
The key decision: **all money maths lives in `InvoiceCalculator`, a pure class fed DTOs.** The real invoice and the
dashboard's overage *projection* both call it, so they cannot disagree, and proration edge cases are plain unit tests.

### Data flow
`POST /usage` → `usage_events` (append-only) → `usage_daily_aggregates` (rollup, by queued job) → invoices & dashboard read the rollup only.

## Schema & scale (requirement 1)

| Table | Notes |
|---|---|
| `merchants`, `plans`, `customers`, `subscriptions` | normalised; `plans` price in integer paise + `overage_rate_micros` (no floats anywhere) |
| `subscription_segments` | plan history per subscription (`plan_id`, `starts_on`, `ends_on` null=current). **Plan change = close segment + append**, never mutate. This is what makes requirement 8 exact |
| `usage_events` | `UNIQUE(customer_id, idempotency_key)` (dedupe), `INDEX(customer_id, usage_date)` (aggregation scan). **No FKs** on the hot path |
| `usage_daily_aggregates` | PK `(customer_id, usage_date)`; `merchant_id` **denormalised** + `INDEX(merchant_id, usage_date, customer_id)` so merchant-wide dashboard queries need no join |
| `invoices` / `invoice_lines` | `UNIQUE(subscription_id, period_start)` = DB-enforced invoice idempotency; lines are an auditable breakdown per segment |

**At 50 lakh (5M) rows:** a row is ~50 B; table ≈ 250 MB, + unique index ≈ 200 MB, + (customer,date) index ≈ 100 MB — fits
in the buffer pool of a small MySQL. Raw events are only ever read by the aggregation (index range scan per customer chunk);
invoices and the dashboard read the rollup (customers × days rows), so their cost is independent of event volume.
`php artisan usage:seed-load 5000000` generates the data to verify this (then `EXPLAIN` the aggregation query).

**If it grows 100×:** (1) `PARTITION BY RANGE (TO_DAYS(usage_date))` monthly; MySQL requires the partition column in every
unique key, so the dedupe key becomes `(customer_id, idempotency_key, usage_date)` and the client must send a stable `usage_date`
with retries; old partitions are dropped/archived after invoicing (O(1) retention). (2) Buffer writes through Redis Streams/Kafka and
batch-insert (the API stays 202-style, DB sees few large inserts). (3) Move dedupe to Redis `SET NX EX` with DB unique as backstop.
(4) Shard aggregation by `merchant_id`.

## Idempotent, high-throughput ingest (req. 2, 6)
* One statement: `INSERT IGNORE` against the unique key. No read-then-write race, no transaction, no locks; two concurrent
  retries cannot both insert. 201 = new, 200 + `duplicate:true` = replay.
* Tenancy check uses a cached `customer→merchant` map (immutable, 1h) so the hot path does no extra DB read.
* Rate limit: Laravel `throttle:usage`, 120/min **per API key**, applied *before* auth (cheap rejection). Use a Redis cache store in
  production so limits are shared across app servers.

## Queues & chunking (req. 3)
* `usage:aggregate` (every 15 min, yesterday+today) dispatches one `AggregateCustomerUsageChunk` per **500 customers**; each job runs one
  grouped query and upserts in **5,000-row** batches. It *recomputes and overwrites* — never increments — so retries, reruns and late events cannot double count.
* `billing:generate-invoices` (daily) dispatches `GenerateInvoiceChunk` per 500 subscriptions. `InvoiceGenerator` locks the subscription row
  (`SELECT … FOR UPDATE`), recomputes "next due period" inside the lock, re-aggregates that customer/period (so invoices never depend on the
  rollup job having run), and relies on the unique index as a final backstop. One failing subscription is logged and skipped, not fatal to its chunk; the next run retries it. Missed cycles are caught up.
* Separate queues (`aggregation`, `billing`) so a big billing run cannot starve rollups.

## Billing maths (req. 3, 8)
Per plan *segment* (days on one plan within the period), with `cycleDays` = length of the full calendar cycle:
```
base      = round(base_price     × segmentDays / cycleDays)
allowance = round(included_units × segmentDays / cycleDays)
overage   = max(0, segmentUsage − allowance) × overage_rate
```
Integer-only, round-half-up (`Money::mulDivRound`). Usage dated before a plan change is billed on the old plan's price/allowance/rate,
after on the new. Worked example (in tests): Jan, Plan A days 1–10 then Plan B days 11–31 ⇒ ₹2,515.94.

## Caching (req. 4)
`PlanCache` (cache-aside, 10 min TTL): `plan:terms:{id}` and `merchant:{id}:plans`.
**Invalidation:** event-driven — `PlanObserver` forgets both keys on every Eloquent save/delete (exact, immediate); TTL is the backstop for writes that bypass Eloquent.
Plan pricing with subscribers is **immutable via the API** (422) — otherwise an edit would silently re-price already-metered usage; create a new plan and `change-plan`.
Dashboard is cached 60 s per merchant/day and explicitly forgotten on subscribe / plan change. Merchant API-key lookup cached 5 min.

## Dashboard (req. 5)
* **Top 5**: month-to-date sum from rollups, `% of allowance` from the plan active today.
* **Projected overage**: per active subscription, run-rate of the *current segment* extrapolated over the rest of the cycle → the same `InvoiceCalculator`. Chunked, one grouped query per chunk (no N+1).
* **Churn risk**: month-to-date vs the **same number of days** last month, `current×2 < previous` (strictly >50% drop), ignoring customers below `billing.churn_min_previous_units` (100). Integer comparison, in SQL `HAVING`.

## Assumptions (ambiguities resolved)
* **A1** Cycles are calendar-aligned (month/year); a mid-cycle start gets a prorated first invoice by days. "Current cycle" = calendar month for monthly plans.
* **A2** One active subscription per customer. Cancellation/refunds/taxes/payment collection are out of scope.
* **A3** All dates are UTC days; a plan change is effective at the *start* of `effective_on` (that day's usage is billed on the new plan). Back-dated changes are allowed only for not-yet-invoiced periods.
* **A4** A plan change must keep the same billing cycle (monthly↔monthly).
* **A5** Allowance is prorated per segment and does **not** roll over between segments of the same cycle.
* **A6** Single currency (INR) per merchant for dashboard totals.
* **A7** Late events: accepted up to `max_backdate_days` (1) in the past; invoices are generated after `cycle_end + invoice_grace_days` (1), so accepted late events are always billed. Anything later is rejected (422) rather than silently lost.
* **A8** Idempotency key is scoped to the customer; replaying a key with a *different* quantity returns the original result (first write wins) — a stricter 409 is a possible extension.
* **A9** The dashboard may lag raw events by ≤15 min (rollup schedule) + 60 s cache.

## Trade-offs under time pressure / what I'd do next
* No Redis Streams buffering, no partitioning migration (documented above, not built).
* Dashboard projection assumes linear usage; no seasonality / weekday weighting.
* `INSERT IGNORE` also swallows non-duplicate errors (e.g. data truncation); validation upstream makes that moot, but `ON DUPLICATE KEY UPDATE id=id` is stricter.
* Invoices are generated as `issued`; no PDF, payment, credit-notes or "late usage adjustment" invoice.
* API keys are single-secret; would add scoped keys/rotation, Sanctum for the admin endpoints.
* Would add Horizon dashboards, `Bus::batch` progress tracking, a k6 load test, and Pest/Larastan in CI.

## Prompt log
Place screenshots of your own AI prompts in `/prompts` (required by the brief; I can't produce those for you).
