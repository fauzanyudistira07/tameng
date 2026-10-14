<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        $query = AuditLog::query()
            ->with([
                'user:id,name,email',
                'project:id,name,code',
                'authorization:id,code',
                'scanJob:id,code,status',
            ])
            ->orderByDesc('id')
            ->limit(250);

        // Scoping keamanan ketat: Administrator (super_admin, security_admin) memiliki akses sistem global.
        // Role non-admin (security_analyst, developer, viewer) hanya boleh melihat log audit aktivitas miliknya atau proyeknya.
        if (! in_array($userRole, ['super_admin', 'security_admin'], true)) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
            $userId = $user->id;
            $query->where(function ($q) use ($allowedProjectIds, $userId) {
                $q->whereIn('project_id', $allowedProjectIds)
                  ->orWhere('user_id', $userId);
            });
        }

        return response()->json([
            'audit_logs' => $query->get(),
        ]);
    }
}
