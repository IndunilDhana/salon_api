<?php

namespace App\Http\Resources;

if (! class_exists(AppointmentResource::class, false)) {
    class_alias(\App\Modules\Business\Appointments\Http\Resources\AppointmentResource::class, AppointmentResource::class);
}
