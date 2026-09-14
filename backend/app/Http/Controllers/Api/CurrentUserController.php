<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'projects:id,name,code', 'preference']);

        return response()->json([
            'user' => [
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
            ],
        ]);
    }
}
