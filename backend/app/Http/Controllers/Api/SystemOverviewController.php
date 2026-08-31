<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SystemOverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $scanProfiles = DB::table('scan_profiles')
            ->select(['key', 'name', 'description', 'engine_keys', 'active_testing'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (object $profile): array => [
                'key' => $profile->key,
                'name' => $profile->name,
                'description' => $profile->description,
                'engine_keys' => json_decode($profile->engine_keys, true) ?: [],
                'active_testing' => (bool) $profile->active_testing,
            ]);

        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        $activeScans = DB::table('scan_jobs')->whereIn('status', ['queued', 'running'])->count();

        $queueStatus = 'ready';
        $queueLabel = 'Queue Worker Siap';
        if ($failedJobs > 0) {
            $queueStatus = 'warning';
            $queueLabel = "{$failedJobs} Job Gagal";
        } elseif ($pendingJobs > 0 || $activeScans > 0) {
            $queueStatus = 'busy';
            $queueLabel = "Memproses ({$activeScans} scan aktif)";
        }

        return response()->json([
            'product' => 'SecSys Security Scan System',
            'mode' => 'mvp_internal',
            'principles' => [
                'NO VERIFIED TARGET = NO EXECUTION',
                'OUTSIDE SCOPE = DENY',
                'AI IS ADVISORY ONLY',
            ],
            'counts' => [
                'projects' => DB::table('projects')->count(),
                'repositories' => DB::table('repositories')->count(),
                'targets' => DB::table('targets')->count(),
                'scan_jobs' => DB::table('scan_jobs')->count(),
                'active_scans' => $activeScans,
                'findings' => DB::table('findings')->count(),
                'critical_findings' => DB::table('findings')->where('severity', 'critical')->count(),
                'high_findings' => DB::table('findings')->where('severity', 'high')->count(),
                'medium_findings' => DB::table('findings')->where('severity', 'medium')->count(),
                'low_findings' => DB::table('findings')->where('severity', 'low')->count(),
                'workers' => DB::table('workers')->count(),
            ],
            'queue_telemetry' => [
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'active_scans' => $activeScans,
                'status' => $queueStatus,
                'label' => $queueLabel,
            ],
            'scan_profiles' => $scanProfiles,
        ]);
    }
}
