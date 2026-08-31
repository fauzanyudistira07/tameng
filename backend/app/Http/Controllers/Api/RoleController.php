<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'roles' => Role::query()
                ->select(['id', 'name', 'display_name', 'description'])
                ->orderBy('id')
                ->get(),
        ]);
    }
}
