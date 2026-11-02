<?php

namespace App\Models;

class CatalogItem extends TenantRecord
{
    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'active' => 'boolean'];
    }
}
