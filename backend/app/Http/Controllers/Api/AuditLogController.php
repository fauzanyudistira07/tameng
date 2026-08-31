<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'audit_logs' => AuditLog::query()
                ->with([
                    'user:id,name,email',
                    'project:id,name,code',
                    'authorization:id,code',
                    'scanJob:id,code,status',
                ])
                ->orderByDesc('id')
                ->limit(250)
                ->get(),
        ]);
    }
}
