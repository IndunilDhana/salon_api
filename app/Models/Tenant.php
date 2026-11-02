<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $fillable = ['name', 'slug', 'currency', 'timezone', 'storage_mode', 'status'];

    public function subscription()
    {
        return $this->hasOne(BusinessSubscription::class);
    }
}
