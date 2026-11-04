<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Tenant extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $fillable = ['name', 'slug', 'currency', 'timezone', 'storage_mode', 'status'];

    public function hasActiveBranch(): bool
    {
        return DB::connection('tenant')->table('branches')->where('tenant_id', $this->id)->where('active', true)->exists();
    }

    public function subscription()
    {
        return $this->hasOne(BusinessSubscription::class);
    }
}
