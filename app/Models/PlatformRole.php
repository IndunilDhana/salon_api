<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformRole extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $fillable = ['name', 'description', 'permissions', 'is_super_admin'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_super_admin' => 'boolean',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class, 'platform_role_id');
    }
}
