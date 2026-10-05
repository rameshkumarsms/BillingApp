<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedUsageLoad extends Command
{
    protected $signature = 'usage:seed-load {rows=5000000} {--days=90}';
    protected $description = 'Bulk-insert synthetic usage_events to validate schema/index behaviour at scale (default 50 lakh rows)';

    public function handle(): int
    {
        $total = (int) $this->argument('rows');
        $days = (int) $this->option('days');
        $customers = Customer::query()->get(['id', 'merchant_id']);
        if ($customers->isEmpty()) {
            $this->error('Seed customers first (php artisan db:seed).');

            return self::FAILURE;
        }

        $today = CarbonImmutable::today();
        $batch = [];
        $run = bin2hex(random_bytes(4));
        for ($i = 0; $i < $total; $i++) {
            $c = $customers[$i % $customers->count()];
            $batch[] = [
                'merchant_id' => $c->merchant_id, 'customer_id' => $c->id,
                'idempotency_key' => "load-{$run}-{$i}", 'quantity' => random_int(1, 20),
                'usage_date' => $today->subDays($i % $days)->toDateString(), 'created_at' => now(),
            ];
            if (count($batch) === 5000) {
                DB::table('usage_events')->insertOrIgnore($batch);
                $batch = [];
                if ($i % 500000 < 5000) {
                    $this->line("inserted ~{$i}");
                }
            }
        }
        if ($batch) {
            DB::table('usage_events')->insertOrIgnore($batch);
        }
        $this->info("Inserted {$total} rows. Now run: php artisan usage:aggregate --from=".$today->subDays($days)->toDateString());

        return self::SUCCESS;
    }
}
