<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasUuids;

    protected $connection = 'central';
    protected $fillable = ['name', 'description', 'price_minor', 'currency', 'billing_interval', 'active', 'features'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'active' => 'boolean', 'features' => 'array'];
    }
}
