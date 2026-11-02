<?php

namespace App\Http\Controllers;

use App\Access\RoleCatalog;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('central.users', 'email')],
            'password' => ['required', 'confirmed', 'max:72', Password::min(8)],
            'is_platform_admin' => ['prohibited'],
            'salon_name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{1,63}$/', Rule::unique('central.tenants', 'slug')],
            'currency' => ['sometimes', Rule::in(['LKR', 'USD', 'GBP', 'EUR', 'INR', 'AUD'])],
            'timezone' => ['sometimes', 'timezone'],
        ]);
        $user = DB::connection('central')->transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $tenant = Tenant::create(['name' => $data['salon_name'], 'slug' => $data['slug'], 'currency' => $data['currency'] ?? 'LKR', 'timezone' => $data['timezone'] ?? 'Asia/Colombo', 'storage_mode' => 'shared']);
            $roles = RoleCatalog::seed($tenant);
            $owner = $roles['owner'];
            $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $owner->name, 'role_id' => $owner->id]);
            app(SubscriptionService::class)->beginTrial($tenant);

            return $user;
        });

        return response()->json($this->session($user, true), 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The email or password is incorrect.']]);
        }

        return $this->session($user, true);
    }

    public function me(Request $request)
    {
        return $this->session($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function session(User $user, bool $issueToken = false): array
    {
        $userData = $user->only(['id', 'name', 'email', 'is_platform_admin']);
        if ($user->is_platform_admin) {
            $userData['platform_role'] = $user->platformRole?->name ?? 'Super Administrator';
            $userData['platform_role_id'] = $user->platform_role_id;
            $userData['platform_permissions'] = $user->getPlatformPermissions();
        }

        $data = ['user' => $userData, 'tenants' => $user->tenants->map(function ($tenant) {
            $role = TenantRole::where('tenant_id', $tenant->id)->find($tenant->pivot->role_id);

            return [...$tenant->only(['id', 'name', 'slug', 'currency', 'timezone', 'storage_mode', 'status']), 'role' => $role?->name ?? '', 'permissions' => $role?->is_owner ? array_keys(\App\Access\RoleCatalog::permissions()) : ($role?->permissions ?? []), 'subscription' => app(SubscriptionService::class)->summary($tenant->subscription)];
        })->values()];
        $expirationMinutes = (int) (config('sanctum.expiration') ?? 480);
        $expiresAt = now()->addMinutes($expirationMinutes);

        if ($issueToken) {
            $data['token'] = $user->createToken('salon-web', ['*'], $expiresAt)->plainTextToken;
            $data['expires_at'] = $expiresAt->toIso8601String();
        } else {
            $token = $user->currentAccessToken();
            if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->expires_at) {
                $data['expires_at'] = $token->expires_at->toIso8601String();
            } elseif ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->created_at) {
                $data['expires_at'] = $token->created_at->addMinutes($expirationMinutes)->toIso8601String();
            }
        }

        return $data;
    }
}
