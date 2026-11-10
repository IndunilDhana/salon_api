<?php

namespace App\Modules\Shared\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Modules\Business\Access\RoleCatalog;
use App\Modules\Shared\Subscriptions\Contracts\SubscriptionReaderInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $status = PasswordBroker::broker()->sendResetLink(['email' => strtolower(trim($data['email']))]);

        if ($status === PasswordBroker::RESET_THROTTLED) {
            return response()->json(['message' => __($status)], 429);
        }

        // Do not reveal whether the address belongs to an account.
        return response()->json(['message' => 'If that email is registered, a reset link has been sent.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', 'max:72', Password::min(8)],
        ]);

        $status = PasswordBroker::broker()->reset(
            [...$data, 'email' => strtolower(trim($data['email']))],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => __($status)]);
    }

    public function register(Request $request, SubscriptionReaderInterface $subscriptionService)
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
        $user = DB::connection('central')->transaction(function () use ($data, $subscriptionService) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $tenant = Tenant::create(['name' => $data['salon_name'], 'slug' => $data['slug'], 'currency' => $data['currency'] ?? 'LKR', 'timezone' => $data['timezone'] ?? 'Asia/Colombo', 'storage_mode' => 'shared']);
            $roles = RoleCatalog::seed($tenant);
            $owner = $roles['owner'];
            $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $owner->name, 'role_id' => $owner->id]);
            $subscriptionService->beginTrial($tenant);

            return $user;
        });

        return response()->json($this->session($user, true, $subscriptionService), 201);
    }

    public function login(Request $request, SubscriptionReaderInterface $subscriptionService)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The email or password is incorrect.']]);
        }

        return $this->session($user, true, $subscriptionService);
    }

    public function me(Request $request, SubscriptionReaderInterface $subscriptionService)
    {
        return $this->session($request->user(), false, $subscriptionService);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function session(User $user, bool $issueToken = false, ?SubscriptionReaderInterface $subscriptionService = null): array
    {
        $subscriptionService ??= app(SubscriptionReaderInterface::class);

        $userData = $user->only(['id', 'name', 'email', 'is_platform_admin']);
        if ($user->is_platform_admin) {
            $userData['platform_role'] = $user->platformRole?->name ?? 'Super Administrator';
            $userData['platform_role_id'] = $user->platform_role_id;
            $userData['platform_permissions'] = $user->getPlatformPermissions();
        }

        $data = ['user' => $userData, 'tenants' => $user->tenants->map(function ($tenant) use ($subscriptionService) {
            $role = TenantRole::where('tenant_id', $tenant->id)->find($tenant->pivot->role_id);
            $hasAllBranches = $role?->is_owner || ! DB::connection('central')->table('branch_users')->where('tenant_id', $tenant->id)->where('user_id', $tenant->pivot->user_id)->exists();

            return [
                ...$tenant->only(['id', 'name', 'slug', 'currency', 'timezone', 'storage_mode', 'status']),
                'needs_branch_setup' => $tenant->storage_mode === 'shared' && ! $tenant->hasActiveBranch(),
                'has_all_branches_access' => (bool) $hasAllBranches,
                'role' => $role?->name ?? '',
                'permissions' => $role?->is_owner ? array_keys(RoleCatalog::permissions()) : ($role?->permissions ?? []),
                'subscription' => $subscriptionService->summary($tenant->subscription),
            ];
        })->values()];
        $expirationMinutes = (int) (config('sanctum.expiration') ?? 480);
        $expiresAt = now()->addMinutes($expirationMinutes);

        if ($issueToken) {
            $data['token'] = $user->createToken('salon-web', ['*'], $expiresAt)->plainTextToken;
            $data['expires_at'] = $expiresAt->toIso8601String();
        } else {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken && $token->expires_at) {
                $data['expires_at'] = $token->expires_at->toIso8601String();
            } elseif ($token instanceof PersonalAccessToken && $token->created_at) {
                $data['expires_at'] = $token->created_at->addMinutes($expirationMinutes)->toIso8601String();
            }
        }

        return $data;
    }
}
