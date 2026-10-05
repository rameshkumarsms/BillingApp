<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Idempotency key may come from the standard header or the body.
        if (! $this->filled('idempotency_key') && $this->header('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
        if (! $this->filled('usage_date')) {
            $this->merge(['usage_date' => CarbonImmutable::today()->toDateString()]);
        }
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'usage_date' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($v->errors()->has('usage_date')) {
                return;
            }
            $d = CarbonImmutable::parse($this->input('usage_date'));
            $today = CarbonImmutable::today();
            if ($d->gt($today->addDay()) || $d->lt($today->subDays(config('billing.max_backdate_days')))) {
                $v->errors()->add('usage_date', 'usage_date is outside the accepted window (today-'.config('billing.max_backdate_days').'d .. tomorrow).');
            }
        });
    }
}
