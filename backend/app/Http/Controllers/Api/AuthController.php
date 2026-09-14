<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthenticationLog;
use App\Models\User;
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

        $targetUser = User::where('email', $credentials['email'])->first();

        // 1. Check if account is temporarily locked
        if ($targetUser && $targetUser->locked_until && $targetUser->locked_until->isFuture()) {
            $diffMinutes = ceil(now()->diffInSeconds($targetUser->locked_until) / 60);

            AuthenticationLog::create([
                'user_id' => $targetUser->id,
                'email' => $targetUser->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => 'blocked',
                'failure_reason' => 'account_locked',
                'created_at' => now(),
            ]);

            $auditLogger->record($request, 'auth.login', 'blocked', [
                'user_id' => $targetUser->id,
                'target_type' => 'user',
                'target_id' => $targetUser->id,
                'metadata' => [
                    'email' => $targetUser->email,
                    'reason' => 'account_locked',
                    'locked_until' => $targetUser->locked_until->toIso8601String(),
                ],
            ]);

            throw ValidationException::withMessages([
                'email' => ["Account is temporarily locked due to too many failed attempts. Please try again in {$diffMinutes} minute(s)."],
            ]);
        }

        // 2. Attempt authentication
        if (! Auth::attempt($credentials)) {
            if ($targetUser) {
                $attempts = ($targetUser->failed_login_attempts ?? 0) + 1;
                $updateData = ['failed_login_attempts' => $attempts];

                // Auto lockout after 5 consecutive failed attempts
                if ($attempts >= 5) {
                    $updateData['locked_until'] = now()->addMinutes(15);
                }

                $targetUser->forceFill($updateData)->save();

                AuthenticationLog::create([
                    'user_id' => $targetUser->id,
                    'email' => $targetUser->email,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'status' => $attempts >= 5 ? 'locked' : 'failed',
                    'failure_reason' => $attempts >= 5 ? 'max_failed_attempts_exceeded' : 'invalid_credentials',
                    'created_at' => now(),
                ]);

                $auditLogger->record($request, 'auth.login', 'failed', [
                    'user_id' => $targetUser->id,
                    'target_type' => 'user',
                    'target_id' => $targetUser->id,
                    'metadata' => [
                        'email' => $targetUser->email,
                        'failed_attempts' => $attempts,
                        'locked' => $attempts >= 5,
                    ],
                ]);

                if ($attempts >= 5) {
                    throw ValidationException::withMessages([
                        'email' => ['Too many failed login attempts. Account has been locked for 15 minutes.'],
                    ]);
                }
            } else {
                AuthenticationLog::create([
                    'user_id' => null,
                    'email' => $credentials['email'],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'status' => 'failed',
                    'failure_reason' => 'user_not_found',
                    'created_at' => now(),
                ]);

                $auditLogger->record($request, 'auth.login', 'failed', [
                    'target_type' => 'user',
                    'metadata' => [
                        'email' => $credentials['email'],
                        'reason' => 'user_not_found',
                    ],
                ]);
            }

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

        // 3. Successful login: reset failed attempts, clear lockout, update last login and IP
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        AuthenticationLog::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => 'success',
            'failure_reason' => null,
            'created_at' => now(),
        ]);

        $auditLogger->record($request, 'auth.login', 'success', [
            'user_id' => $user->id,
            'target_type' => 'user',
            'target_id' => $user->id,
            'metadata' => [
                'email' => $user->email,
                'role' => $user->role?->name,
                'ip' => $request->ip(),
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
            AuthenticationLog::create([
                'user_id' => $user->id,
                'email' => $user->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => 'logout',
                'failure_reason' => null,
                'created_at' => now(),
            ]);

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
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'department' => $user->department,
            'avatar_path' => $user->avatar_path,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'last_login_ip' => $user->last_login_ip,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'display_name' => $user->role->display_name,
            ] : null,
        ];
    }
}
