<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemOverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;
        // Tim SOC (super_admin, security_admin, security_analyst) memiliki visibilitas telemetri sistem global
        $isSocMember = in_array($userRole, ['super_admin', 'security_admin', 'security_analyst'], true);

        $allowedProjectIds = null;
        if (! $isSocMember && $user) {
            $allowedProjectIds = $user->projects()->pluck('projects.id')
                ->merge(\App\Models\Project::where('owner_id', $user->id)->pluck('id'))
                ->unique();
        }

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

        $projectCountQuery = DB::table('projects');
        $repoCountQuery = DB::table('repositories');
        $targetCountQuery = DB::table('targets');
        $scanJobCountQuery = DB::table('scan_jobs');
        $findingsCountQuery = DB::table('findings');

        if ($allowedProjectIds !== null) {
            $projectCountQuery->whereIn('id', $allowedProjectIds);
            $repoCountQuery->whereIn('project_id', $allowedProjectIds);
            $targetCountQuery->whereIn('project_id', $allowedProjectIds);
            $scanJobCountQuery->whereIn('project_id', $allowedProjectIds);
            $findingsCountQuery->whereIn('project_id', $allowedProjectIds);
        }

        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        $activeScansQuery = DB::table('scan_jobs')->whereIn('status', ['queued', 'running']);
        if ($allowedProjectIds !== null) {
            $activeScansQuery->whereIn('project_id', $allowedProjectIds);
        }
        $activeScans = $activeScansQuery->count();

        $queueStatus = 'ready';
        $queueLabel = 'Queue Worker Siap';
        if ($failedJobs > 0) {
            $queueStatus = 'warning';
            $queueLabel = "{$failedJobs} Job Gagal";
        } elseif ($pendingJobs > 0 || $activeScans > 0) {
            $queueStatus = 'busy';
            $queueLabel = "Memproses ({$activeScans} scan aktif)";
        }

        $findingsList = (clone $findingsCountQuery)->get(['severity']);

        return response()->json([
            'product' => 'SecSys Security Scan System',
            'mode' => 'mvp_internal',
            'principles' => [
                'NO VERIFIED TARGET = NO EXECUTION',
                'OUTSIDE SCOPE = DENY',
                'AI IS ADVISORY ONLY',
            ],
            'counts' => [
                'projects' => $projectCountQuery->count(),
                'repositories' => $repoCountQuery->count(),
                'targets' => $targetCountQuery->count(),
                'scan_jobs' => $scanJobCountQuery->count(),
                'active_scans' => $activeScans,
                'findings' => $findingsList->count(),
                'critical_findings' => $findingsList->where('severity', 'critical')->count(),
                'high_findings' => $findingsList->where('severity', 'high')->count(),
                'medium_findings' => $findingsList->where('severity', 'medium')->count(),
                'low_findings' => $findingsList->where('severity', 'low')->count(),
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
            'findings_timeline' => $this->buildFindingsTimeline($allowedProjectIds),
        ]);
    }

    private function buildFindingsTimeline($allowedProjectIds = null): array
    {
        $rawQuery = DB::table('findings')
            ->selectRaw('DATE(created_at) as date, severity, count(*) as count')
            ->whereIn('severity', ['critical', 'high', 'medium', 'low']);

        if ($allowedProjectIds !== null) {
            $rawQuery->whereIn('project_id', $allowedProjectIds);
        }

        $raw = $rawQuery->groupBy('date', 'severity')
            ->orderBy('date')
            ->get();

        $grouped = [];
        foreach ($raw as $r) {
            $grouped[$r->date][$r->severity] = (int) $r->count;
        }

        $minDateQuery = DB::table('findings');
        if ($allowedProjectIds !== null) {
            $minDateQuery->whereIn('project_id', $allowedProjectIds);
        }
        $minDateStr = $minDateQuery->min('created_at');
        $startDate = $minDateStr
            ? \Carbon\Carbon::parse($minDateStr)->startOfDay()
            : now()->subDays(14)->startOfDay();
        $endDate = now()->endOfDay();

        $dates = [];
        $critical = [];
        $high = [];
        $medium = [];
        $low = [];

        $cumCrit = 0;
        $cumHigh = 0;
        $cumMed = 0;
        $cumLow = 0;

        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $dateStr = $d->toDateString();
            $dates[] = $d->format('d M');

            if (isset($grouped[$dateStr])) {
                $cumCrit += $grouped[$dateStr]['critical'] ?? 0;
                $cumHigh += $grouped[$dateStr]['high'] ?? 0;
                $cumMed += $grouped[$dateStr]['medium'] ?? 0;
                $cumLow += $grouped[$dateStr]['low'] ?? 0;
            }

            $critical[] = $cumCrit;
            $high[] = $cumHigh;
            $medium[] = $cumMed;
            $low[] = $cumLow;
        }

        return [
            'dates' => $dates,
            'critical' => $critical,
            'high' => $high,
            'medium' => $medium,
            'low' => $low,
        ];
    }
}
