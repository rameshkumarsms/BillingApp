<?php

return [
    'currency' => 'INR',

    // Cache TTLs (seconds). Primary invalidation is event-based (PlanObserver); TTL is the safety net.
    'plan_cache_ttl' => 600,
    'dashboard_cache_ttl' => 60,

    // Requests per minute per API key on POST /usage.
    'usage_rate_limit' => (int) env('USAGE_RATE_LIMIT', 120),

    // Aggregation job sizing.
    'aggregate_chunk' => 500,     // customers per queued job
    'upsert_batch' => 5000,       // rows per upsert statement

    // Invoice for a cycle is generated once today > cycle_end + grace. Events may be back-dated
    // by at most max_backdate_days, which must be <= grace so late events are never silently unbilled.
    'invoice_grace_days' => 1,
    'max_backdate_days' => 1,

    // Churn risk: previous-period usage must be at least this to avoid flagging noise.
    'churn_min_previous_units' => 100,
];
