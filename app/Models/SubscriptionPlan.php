<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasUuids;

    protected $connection = 'central';
    protected $fillable = [
        'name',
        'description',
        'price_minor',
        'currency',
        'billing_interval',
        'included_branches',
        'price_per_additional_branch_minor',
        'active',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'included_branches' => 'integer',
            'price_per_additional_branch_minor' => 'integer',
            'active' => 'boolean',
            'features' => 'array',
        ];
    }
}
