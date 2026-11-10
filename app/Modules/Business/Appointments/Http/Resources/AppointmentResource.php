<?php

namespace App\Modules\Business\Appointments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->only(['id', 'staff_id', 'customer_name', 'service_name', 'price_minor', 'status', 'branch_id']),
            'starts_at' => $this->starts_at->toISOString(),
            'ends_at' => $this->ends_at->toISOString(),
            'staff_name' => $this->staff->name,
            'branch_name' => $this->branch?->name,
        ];
    }
}
