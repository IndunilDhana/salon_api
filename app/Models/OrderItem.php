<?php

namespace App\Models;

class OrderItem extends TenantRecord
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'total_minor' => 'integer'];
    }
}
