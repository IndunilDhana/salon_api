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
        static::addGlobalScope('branch', function (Builder $builder) {
            $model = $builder->getModel();
            $context = app(TenantContext::class);
            if (! in_array($model->getTable(), ['staff', 'orders', 'appointments', 'deductions', 'branches'], true)) {
                return;
            }
            $ids = $model->getTable() === 'branches' ? $context->allowedBranchIds : ($context->branchId ? [$context->branchId] : $context->allowedBranchIds);
            if ($ids !== null) {
                $column = $model->qualifyColumn($model->getTable() === 'branches' ? 'id' : 'branch_id');
                $builder->where(function (Builder $query) use ($column, $ids, $model, $context) {
                    $query->whereIn($column, $ids);
                    if ($model->getTable() === 'staff' && $context->hasAllBranchesAccess) {
                        $query->orWhereNull($column);
                    }
                });
            }
        });
        static::creating(function (self $record) {
            $record->tenant_id = app(TenantContext::class)->id();
            if (in_array($record->getTable(), ['staff', 'orders', 'appointments', 'deductions'], true)) {
                $record->branch_id = app(TenantContext::class)->authorizeBranch($record->branch_id);
            }
        });
        static::updating(function (self $record) {
            if ($record->tenant_id !== app(TenantContext::class)->id() || $record->isDirty('tenant_id')) {
                throw new LogicException('A business record cannot move between tenants.');
            }
            if ($record->isDirty('branch_id')) {
                $record->branch_id = app(TenantContext::class)->authorizeBranch($record->branch_id);
            }
        });
        static::deleting(function (self $record) {
            if ($record->tenant_id !== app(TenantContext::class)->id()) {
                throw new LogicException('A business record can only be deleted in its tenant context.');
            }
        });
    }
}
