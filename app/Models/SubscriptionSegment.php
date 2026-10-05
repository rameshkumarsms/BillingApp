<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionSegment extends Model
{
    protected $guarded = [];
    protected $casts = ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
}
