<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'users' => User::query()
                ->with('role:id,name,display_name')
                ->select(['id', 'role_id', 'name', 'email', 'status', 'last_login_at', 'created_at'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $user = User::query()->create([
            ...$data,
            'password' => Hash::make($data['password']),
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'user' => $user->load('role:id,name,display_name'),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:10'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json([
            'user' => $user->refresh()->load('role:id,name,display_name'),
        ]);
    }
}
