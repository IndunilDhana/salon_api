<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BusinessSubscription extends Model
{
    use HasUuids;

    protected $connection = 'central';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'features' => 'array', 'trial_starts_at' => 'immutable_datetime', 'trial_ends_at' => 'immutable_datetime', 'current_period_start' => 'immutable_datetime', 'current_period_end' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }
}
