<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'users' => User::query()
                ->with([
                    'role:id,name,display_name',
                    'projects:id,name,code',
                    'preference',
                ])
                ->select([
                    'id',
                    'role_id',
                    'name',
                    'username',
                    'email',
                    'phone',
                    'department',
                    'avatar_path',
                    'auth_provider',
                    'provider_id',
                    'status',
                    'failed_login_attempts',
                    'locked_until',
                    'two_factor_enabled',
                    'last_login_at',
                    'last_login_ip',
                    'created_at',
                ])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:100', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25'],
            'department' => ['nullable', 'string', 'max:150'],
            'auth_provider' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:10'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['exists:projects,id'],
            'project_access_level' => ['nullable', 'string', Rule::in(['lead', 'analyst', 'developer', 'viewer'])],
            'alert_email' => ['nullable', 'boolean'],
            'alert_telegram' => ['nullable', 'boolean'],
            'telegram_chat_id' => ['nullable', 'string', 'max:100'],
            'alert_min_severity' => ['nullable', 'string', Rule::in(['critical', 'high', 'medium', 'all'])],
        ]);

        $hashedPassword = Hash::make($data['password']);

        $user = User::query()->create([
            'role_id' => $data['role_id'],
            'name' => $data['name'],
            'username' => $data['username'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'department' => $data['department'] ?? null,
            'auth_provider' => $data['auth_provider'] ?? 'local',
            'status' => $data['status'],
            'password' => $hashedPassword,
            'password_changed_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        // Record initial password history
        PasswordHistory::query()->create([
            'user_id' => $user->id,
            'password' => $hashedPassword,
            'created_at' => now(),
        ]);

        // Assign project scopes if provided
        if (! empty($data['project_ids'])) {
            $syncData = [];
            $level = $data['project_access_level'] ?? 'analyst';
            foreach ($data['project_ids'] as $pid) {
                $syncData[$pid] = ['access_level' => $level, 'assigned_by' => $request->user()->id];
            }
            $user->projects()->sync($syncData);
        }

        // Initialize user preferences
        $user->preference()->create([
            'alert_email' => $data['alert_email'] ?? true,
            'alert_telegram' => $data['alert_telegram'] ?? false,
            'telegram_chat_id' => $data['telegram_chat_id'] ?? null,
            'alert_min_severity' => $data['alert_min_severity'] ?? 'high',
        ]);

        return response()->json([
            'user' => $user->load(['role:id,name,display_name', 'projects:id,name,code', 'preference']),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:25'],
            'department' => ['nullable', 'string', 'max:150'],
            'auth_provider' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'min:10'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['exists:projects,id'],
            'project_access_level' => ['nullable', 'string', Rule::in(['lead', 'analyst', 'developer', 'viewer'])],
            'alert_email' => ['nullable', 'boolean'],
            'alert_telegram' => ['nullable', 'boolean'],
            'telegram_chat_id' => ['nullable', 'string', 'max:100'],
            'alert_min_severity' => ['nullable', 'string', Rule::in(['critical', 'high', 'medium', 'all'])],
        ]);

        if (! empty($data['password'])) {
            // Check password history (last 3 passwords)
            $recentHistories = $user->passwordHistories()->latest('id')->take(3)->get();
            foreach ($recentHistories as $history) {
                if (Hash::check($data['password'], $history->password)) {
                    throw ValidationException::withMessages([
                        'password' => ['Kata sandi baru tidak boleh sama dengan salah satu dari 3 kata sandi terakhir yang pernah digunakan.'],
                    ]);
                }
            }

            $hashedPassword = Hash::make($data['password']);
            $data['password'] = $hashedPassword;
            $data['password_changed_at'] = now();

            $user->passwordHistories()->create([
                'password' => $hashedPassword,
                'created_at' => now(),
            ]);
        } else {
            unset($data['password']);
        }

        $user->update([
            'role_id' => $data['role_id'],
            'name' => $data['name'],
            'username' => $data['username'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'department' => $data['department'] ?? null,
            'auth_provider' => $data['auth_provider'] ?? $user->auth_provider,
            'status' => $data['status'],
            ...(! empty($data['password']) ? ['password' => $data['password'], 'password_changed_at' => $data['password_changed_at']] : []),
        ]);

        // Sync project scopes if project_ids provided
        if (isset($data['project_ids'])) {
            $syncData = [];
            $level = $data['project_access_level'] ?? 'analyst';
            foreach ($data['project_ids'] as $pid) {
                $syncData[$pid] = ['access_level' => $level, 'assigned_by' => $request->user()->id];
            }
            $user->projects()->sync($syncData);
        }

        // Sync preferences
        if (isset($data['alert_email']) || isset($data['alert_telegram']) || isset($data['alert_min_severity'])) {
            $user->preference()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'alert_email' => $data['alert_email'] ?? true,
                    'alert_telegram' => $data['alert_telegram'] ?? false,
                    'telegram_chat_id' => $data['telegram_chat_id'] ?? null,
                    'alert_min_severity' => $data['alert_min_severity'] ?? 'high',
                ]
            );
        }

        return response()->json([
            'user' => $user->refresh()->load(['role:id,name,display_name', 'projects:id,name,code', 'preference']),
        ]);
    }

    public function unlock(User $user): JsonResponse
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        return response()->json([
            'message' => 'Akun pengguna berhasil dibuka kembali.',
            'user' => $user->load(['role:id,name,display_name', 'projects:id,name,code', 'preference']),
        ]);
    }

    public function assignProjects(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'project_ids' => ['present', 'array'],
            'project_ids.*' => ['exists:projects,id'],
            'access_level' => ['nullable', 'string', Rule::in(['lead', 'analyst', 'developer', 'viewer'])],
        ]);

        $syncData = [];
        $level = $data['access_level'] ?? 'analyst';
        foreach ($data['project_ids'] as $pid) {
            $syncData[$pid] = ['access_level' => $level, 'assigned_by' => $request->user()->id];
        }
        $user->projects()->sync($syncData);

        return response()->json([
            'message' => 'Hak akses proyek berhasil didelegasikan.',
            'user' => $user->load(['role:id,name,display_name', 'projects:id,name,code', 'preference']),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->id === 1) {
            return response()->json(['message' => 'Akun Super Admin utama tidak dapat dinonaktifkan atau dihapus.'], 403);
        }

        $user->update(['status' => 'inactive']);
        $user->delete();

        return response()->json([
            'message' => 'Pengguna berhasil dinonaktifkan.',
        ]);
    }
}
