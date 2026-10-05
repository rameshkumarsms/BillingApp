<?php

use Illuminate\Support\Facades\Schedule;

// Rolls up yesterday + today so the dashboard is at most ~15 min behind; also absorbs late events.
Schedule::command('usage:aggregate')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
// Invoices every cycle that finished (plus grace). Idempotent, so reruns are harmless.
Schedule::command('billing:generate-invoices')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
