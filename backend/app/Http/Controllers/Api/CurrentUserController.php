<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CurrentUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->formatUserResponse($request->user());
    }

    public function __invoke(Request $request): JsonResponse
    {
        return $this->show($request);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:25'],
            'department' => ['nullable', 'string', 'max:150'],
            'password' => ['nullable', 'string', 'min:8'],
            'password_confirmation' => ['nullable', 'same:password'],
        ]);

        $updateData = [
            'name' => trim($data['name']),
            'username' => ! empty($data['username']) ? trim($data['username']) : null,
            'email' => trim($data['email']),
            'phone' => ! empty($data['phone']) ? trim($data['phone']) : null,
            'department' => ! empty($data['department']) ? trim($data['department']) : null,
        ];

        if (! empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
            $updateData['password_changed_at'] = now();
        }

        $user->update($updateData);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => $this->formatUserData($user->fresh(['role', 'projects:id,name,code', 'preference'])),
        ]);
    }

    private function formatUserData($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'department' => $user->department,
            'avatar_path' => $user->avatar_path,
            'auth_provider' => $user->auth_provider ?? 'local',
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'display_name' => $user->role->display_name,
            ] : null,
            'projects' => $user->projects->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'access_level' => $p->pivot?->access_level ?? 'analyst',
            ]),
            'preference' => $user->preference ? [
                'alert_email' => (bool) $user->preference->alert_email,
                'alert_telegram' => (bool) $user->preference->alert_telegram,
                'telegram_chat_id' => $user->preference->telegram_chat_id,
                'alert_min_severity' => $user->preference->alert_min_severity,
            ] : null,
        ];
    }

    private function formatUserResponse($user): JsonResponse
    {
        $user->load(['role', 'projects:id,name,code', 'preference']);
        return response()->json([
            'user' => $this->formatUserData($user),
        ]);
    }
}
