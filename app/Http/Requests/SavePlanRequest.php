<?php

namespace App\Http\Requests;

if (! class_exists(SavePlanRequest::class, false)) {
    class_alias(\App\Modules\Platform\Plans\Http\Requests\SavePlanRequest::class, SavePlanRequest::class);
}
