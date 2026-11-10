<?php

namespace App\Access;

if (! class_exists(RoleCatalog::class, false)) {
    class_alias(\App\Modules\Business\Access\RoleCatalog::class, RoleCatalog::class);
}
