<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthenticationLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        $remember = $request->boolean('remember');

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

        // 2. Attempt authentication (clear prior session if any)
        if (Auth::check()) {
            Auth::guard('web')->logout();
        }

        if (! Auth::attempt($credentials, $remember)) {
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

    public function forgotPassword(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Alamat email tidak ditemukan dalam sistem.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Akun ini berstatus tidak aktif. Hubungi Administrator SOC.'],
            ]);
        }

        // Generate a secure 6-digit verification code
        $resetCode = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($resetCode),
                'created_at' => now(),
            ]
        );

        $auditLogger->record($request, 'auth.password_reset_requested', 'success', [
            'user_id' => $user->id,
            'target_type' => 'user',
            'target_id' => $user->id,
            'metadata' => [
                'email' => $user->email,
                'ip' => $request->ip(),
            ],
        ]);

        return response()->json([
            'message' => 'Kode verifikasi reset password berhasil dibuat.',
            'email' => $user->email,
            'reset_code' => $resetCode,
        ]);
    }

    public function resetPassword(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $validated['email'])->first();

        if (! $record || ! Hash::check($validated['token'], $record->token)) {
            throw ValidationException::withMessages([
                'token' => ['Kode verifikasi tidak valid atau telah kadaluarsa.'],
            ]);
        }

        if (Carbon::parse($record->created_at)->addMinutes(30)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();
            throw ValidationException::withMessages([
                'token' => ['Kode verifikasi telah kadaluarsa (melebihi 30 menit). Silakan minta kode baru.'],
            ]);
        }

        $user = User::where('email', $validated['email'])->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Pengguna tidak ditemukan.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        $auditLogger->record($request, 'auth.password_reset_completed', 'success', [
            'user_id' => $user->id,
            'target_type' => 'user',
            'target_id' => $user->id,
            'metadata' => [
                'email' => $user->email,
                'ip' => $request->ip(),
            ],
        ]);

        return response()->json([
            'message' => 'Password berhasil diperbarui. Silakan login dengan password baru Anda.',
        ]);
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
