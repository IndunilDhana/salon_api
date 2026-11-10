<?php

namespace App\Modules\Platform\Businesses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformBusinessController extends Controller
{
    public function updateTenant(Request $request, string $id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update($request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]));

        return ['data' => $tenant];
    }
}
