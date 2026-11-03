<?php

namespace App\Models;

class Branch extends TenantRecord
{
    protected $table = 'branches';

    protected $fillable = [
        'name',
        'code',
        'phone',
        'address',
        'is_main',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function staff()
    {
        return $this->hasMany(Staff::class, 'branch_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'branch_id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'branch_id');
    }

    public function deductions()
    {
        return $this->hasMany(Deduction::class, 'branch_id');
    }
}
