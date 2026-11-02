<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function index(): array
    {
        return ['data' => CatalogItem::orderBy('type')->orderBy('name')->get()->map(fn (CatalogItem $item) => $this->itemData($item))];
    }

    public function store(Request $request): JsonResponse
    {
        $item = CatalogItem::create($this->validated($request));

        return response()->json(['data' => $this->itemData($item)], 201);
    }

    public function update(Request $request, string $id): array
    {
        $item = CatalogItem::findOrFail($id);
        $item->update($this->validated($request, $id));

        return ['data' => $this->itemData($item)];
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $update = $id !== null;
        $presence = $update ? 'sometimes' : 'required';
        $data = $request->validate([
            'type' => [$presence, Rule::in(['service', 'product'])],
            'name' => [$presence, 'string', 'max:120'],
            'price_minor' => [$presence, 'integer', 'min:0', 'max:100000000000'],
            'active' => ['sometimes', 'boolean'],
            'tenant_id' => ['prohibited'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if ($data['name'] === '') {
                throw ValidationException::withMessages(['name' => ['Enter a service or product name.']]);
            }

            $tenantId = app(TenantContext::class)->id();
            $type = $data['type'] ?? ($id ? CatalogItem::where('id', $id)->value('type') : null);

            $query = CatalogItem::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(name) = ?', [Str::lower($data['name'])]);
            if ($type) {
                $query->where('type', $type);
            }
            if ($id) {
                $query->where('id', '!=', $id);
            }
            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'name' => ["A {$type} with this name already exists in this salon."],
                ]);
            }
        }

        return $data;
    }

    private function itemData(CatalogItem $item): array
    {
        return $item->only(['id', 'type', 'name', 'price_minor', 'active']);
    }
}
