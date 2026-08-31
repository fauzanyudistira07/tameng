<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function login(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user = $request->user()->load('role');

        if ($user->status !== 'active') {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw ValidationException::withMessages([
                'email' => ['This account is not active.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $auditLogger->record($request, 'auth.login', 'success', [
            'user_id' => $user->id,
            'target_type' => 'user',
            'target_id' => $user->id,
            'metadata' => [
                'email' => $user->email,
                'role' => $user->role?->name,
            ],
        ]);

        return response()->json([
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $auditLogger->record($request, 'auth.logout', 'success', [
                'user_id' => $user->id,
                'target_type' => 'user',
                'target_id' => $user->id,
            ]);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    private function serializeUser($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'role' => $user->role ? [
                'name' => $user->role->name,
                'display_name' => $user->role->display_name,
            ] : null,
        ];
    }
}
