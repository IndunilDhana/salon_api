<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Access\PlatformRoleCatalog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $connection = 'central';

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'memberships')->withPivot('id', 'role', 'role_id');
    }

    public function platformRole()
    {
        return $this->belongsTo(PlatformRole::class, 'platform_role_id');
    }

    public function hasPlatformPermission(string $permission): bool
    {
        if (! $this->is_platform_admin) {
            return false;
        }

        if (! $this->platform_role_id || $this->platformRole?->is_super_admin) {
            return true;
        }

        return in_array($permission, $this->platformRole?->permissions ?? [], true);
    }

    public function getPlatformPermissions(): array
    {
        if (! $this->is_platform_admin) {
            return [];
        }

        if (! $this->platform_role_id || $this->platformRole?->is_super_admin) {
            return array_keys(PlatformRoleCatalog::permissions());
        }

        return $this->platformRole?->permissions ?? [];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_platform_admin',
        'platform_role_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }
}
