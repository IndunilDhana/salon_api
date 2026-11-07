<?php

namespace App\Modules\Platform\Plans\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Modules\Platform\Plans\Http\Requests\SavePlanRequest;
use App\Modules\Shared\Subscriptions\SubscriptionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanController extends Controller
{
    public function index(): array
    {
        return [
            'data' => SubscriptionPlan::orderByDesc('active')->orderBy('name')->get(),
            'features' => collect(SubscriptionCatalog::FEATURES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ];
    }

    public function store(SavePlanRequest $request)
    {
        $data = $request->validated();
        $this->validateFeatures($data['features']);
        $plan = SubscriptionPlan::create([...$data, 'active' => $data['active'] ?? true]);

        return response()->json(['data' => $plan], 201);
    }

    public function update(SavePlanRequest $request, string $id): array
    {
        return DB::connection('central')->transaction(function () use ($request, $id) {
            $plan = SubscriptionPlan::lockForUpdate()->findOrFail($id);
            $data = $request->validated();
            $this->validateFeatures($data['features'] ?? $plan->features);
            $plan->update($data);

            return ['data' => $plan];
        });
    }

    private function validateFeatures(array $features): void
    {
        if (in_array('orders', $features, true) && ! in_array('catalog', $features, true)) {
            throw ValidationException::withMessages(['features' => ['Customer billing also requires the services and products feature.']]);
        }
    }
}
