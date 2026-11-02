<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantRole extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $fillable = ['tenant_id', 'name', 'permissions', 'is_owner'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_owner' => 'boolean'];
    }
}
