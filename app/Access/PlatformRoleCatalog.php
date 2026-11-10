<?php

namespace App\Access;

if (! class_exists(PlatformRoleCatalog::class, false)) {
    class_alias(\App\Modules\Platform\Access\PlatformRoleCatalog::class, PlatformRoleCatalog::class);
}
