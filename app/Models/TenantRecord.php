<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class TenantRecord extends Model
{
    use HasUuids;

    protected $connection = 'tenant';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $builder->where($builder->getModel()->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
        });
        static::creating(function (self $record) {
            $record->tenant_id = app(TenantContext::class)->id();
        });
        static::updating(function (self $record) {
            if ($record->tenant_id !== app(TenantContext::class)->id() || $record->isDirty('tenant_id')) {
                throw new LogicException('A business record cannot move between tenants.');
            }
        });
        static::deleting(function (self $record) {
            if ($record->tenant_id !== app(TenantContext::class)->id()) {
                throw new LogicException('A business record can only be deleted in its tenant context.');
            }
        });
    }
}
