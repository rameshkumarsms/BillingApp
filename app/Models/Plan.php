<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function segments(): HasMany
    {
        return $this->hasMany(SubscriptionSegment::class);
    }
}
