<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Tenant auth: X-Api-Key header -> merchant_id (cached 5 min), exposed as request attribute. */
class AuthenticateMerchant
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-Api-Key');
        if (! $key) {
            return response()->json(['message' => 'Missing X-Api-Key header.'], 401);
        }
        $hash = hash('sha256', $key);
        $id = Cache::remember("merchant:key:{$hash}", 300, fn () => Merchant::query()->where('api_key_hash', $hash)->value('id'));
        if (! $id) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }
        $request->attributes->set('merchant_id', (int) $id);

        return $next($request);
    }
}
