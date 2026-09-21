<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunScanJob;
use App\Models\Authorization;
use App\Models\ScanJob;
use App\Models\ScanProfile;
use App\Models\Scope;
use App\Models\Target;
use App\Services\AuditLogger;
use App\Services\AuthorizationGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TargetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        $query = Target::query()
            ->with(['project:id,name,code', 'verifier:id,name'])
            ->orderByDesc('id');

        // Scoping untuk developer dan viewer: hanya tampilkan target dari proyek yang ditugaskan
        if (in_array($userRole, ['developer', 'viewer'], true)) {
            $query->whereIn('project_id', $user->projects()->pluck('projects.id'));
        }

        return response()->json([
            'targets' => $query->get(),
        ]);
    }

    public function show(Request $request, Target $target): JsonResponse
    {
        $user = $request->user();
        $userRole = $user?->role?->name;

        if (in_array($userRole, ['developer', 'viewer'], true)) {
            if (! $user->projects()->where('projects.id', $target->project_id)->exists()) {
                return response()->json([
                    'message' => 'Akses ditolak: Anda tidak memiliki akses ke target proyek ini.',
                ], 403);
            }
        }

        $target->load(['project:id,name,code,status,criticality', 'verifier:id,name']);

        $scanJobs = ScanJob::query()
            ->where('target_id', $target->id)
            ->with(['scanProfile:id,name,key', 'creator:id,name'])
            ->withCount('findings')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $scanJobIds = ScanJob::query()->where('target_id', $target->id)->pluck('id');

        $findingsCount = DB::table('findings')
            ->whereIn('scan_job_id', $scanJobIds)
            ->selectRaw('
                count(*) as total,
                count(case when severity = "critical" then 1 end) as critical,
                count(case when severity = "high" then 1 end) as high,
                count(case when severity = "medium" then 1 end) as medium,
                count(case when severity = "low" then 1 end) as low
            ')
            ->first();

        $recentFindings = DB::table('findings')
            ->whereIn('scan_job_id', $scanJobIds)
            ->select('id', 'code', 'title', 'severity', 'asset_type', 'asset_identifier', 'created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $scopes = Scope::query()
            ->where('target_id', $target->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'target' => $target,
            'scan_jobs' => $scanJobs,
            'scopes' => $scopes,
            'findings_summary' => [
                'total' => (int) ($findingsCount->total ?? 0),
                'critical' => (int) ($findingsCount->critical ?? 0),
                'high' => (int) ($findingsCount->high ?? 0),
                'medium' => (int) ($findingsCount->medium ?? 0),
                'low' => (int) ($findingsCount->low ?? 0),
                'recent' => $recentFindings,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateTarget($request);

        $fileData = [];
        $uploadedFile = $request->file('file') ?: $request->file('app_file');

        if ($uploadedFile) {
            $ext = strtolower($uploadedFile->getClientOriginalExtension());
            if (! in_array($ext, ['apk', 'ipa', 'aab', 'zip'], true)) {
                throw ValidationException::withMessages([
                    'file' => ['File yang diunggah harus berformat binary aplikasi mobile (.apk, .ipa, .aab, atau .zip).'],
                ]);
            }

            $workspaceRoot = (string) config('secsys.repository_workspace_root', '/data/repository-workspaces');
            $folderName = 'target-app-'.($validated['project_id'] ?? '0').'-'.Str::random(8);
            $workspacePath = $workspaceRoot.DIRECTORY_SEPARATOR.$folderName;
            \Illuminate\Support\Facades\File::ensureDirectoryExists($workspacePath);

            $originalName = $uploadedFile->getClientOriginalName();
            $targetFilePath = $workspacePath.DIRECTORY_SEPARATOR.$originalName;
            $uploadedFile->move($workspacePath, $originalName);

            if (class_exists(\ZipArchive::class) && in_array($ext, ['apk', 'zip', 'ipa', 'aab'], true)) {
                $zip = new \ZipArchive();
                if ($zip->open($targetFilePath) === true) {
                    $zip->extractTo($workspacePath);
                    $zip->close();
                }
            }

            $validated['base_url'] = 'file://'.$originalName;
            if (empty($validated['hostname'])) {
                $validated['hostname'] = $originalName;
            }
            if (empty($validated['name'])) {
                $validated['name'] = pathinfo($originalName, PATHINFO_FILENAME);
            }

            $fileData = [
                'file_name' => $originalName,
                'file_size' => \Illuminate\Support\Facades\File::size($targetFilePath),
                'file_path' => $targetFilePath,
                'local_path' => $workspacePath,
                'workspace_path' => $workspacePath,
                'uploaded_at' => now()->toISOString(),
            ];
        }

        unset($validated['file'], $validated['app_file']);

        if (! empty($fileData)) {
            $validated['metadata'] = array_merge($validated['metadata'] ?? [], $fileData);
        }

        $target = Target::query()->create($validated);

        return response()->json([
            'target' => $target->load(['project:id,name,code', 'verifier:id,name']),
        ], 201);
    }

    public function update(Request $request, Target $target): JsonResponse
    {
        $validated = $this->validateTarget($request, $target);

        $fileData = [];
        $uploadedFile = $request->file('file') ?: $request->file('app_file');

        if ($uploadedFile) {
            $ext = strtolower($uploadedFile->getClientOriginalExtension());
            if (! in_array($ext, ['apk', 'ipa', 'aab', 'zip'], true)) {
                throw ValidationException::withMessages([
                    'file' => ['File yang diunggah harus berformat binary aplikasi mobile (.apk, .ipa, .aab, atau .zip).'],
                ]);
            }

            $workspaceRoot = (string) config('secsys.repository_workspace_root', '/data/repository-workspaces');
            $folderName = 'target-app-'.($validated['project_id'] ?? $target->project_id).'-'.Str::random(8);
            $workspacePath = $workspaceRoot.DIRECTORY_SEPARATOR.$folderName;
            \Illuminate\Support\Facades\File::ensureDirectoryExists($workspacePath);

            $originalName = $uploadedFile->getClientOriginalName();
            $targetFilePath = $workspacePath.DIRECTORY_SEPARATOR.$originalName;
            $uploadedFile->move($workspacePath, $originalName);

            if (class_exists(\ZipArchive::class) && in_array($ext, ['apk', 'zip', 'ipa', 'aab'], true)) {
                $zip = new \ZipArchive();
                if ($zip->open($targetFilePath) === true) {
                    $zip->extractTo($workspacePath);
                    $zip->close();
                }
            }

            $validated['base_url'] = 'file://'.$originalName;
            if (empty($validated['hostname'])) {
                $validated['hostname'] = $originalName;
            }

            $fileData = [
                'file_name' => $originalName,
                'file_size' => \Illuminate\Support\Facades\File::size($targetFilePath),
                'file_path' => $targetFilePath,
                'local_path' => $workspacePath,
                'workspace_path' => $workspacePath,
                'uploaded_at' => now()->toISOString(),
            ];
        }

        unset($validated['file'], $validated['app_file']);

        if (! empty($fileData)) {
            $validated['metadata'] = array_merge($target->metadata ?? [], $fileData);
        }

        $target->update($validated);

        return response()->json([
            'target' => $target->refresh()->load(['project:id,name,code', 'verifier:id,name']),
        ]);
    }

    public function destroy(Request $request, Target $target, AuditLogger $auditLogger): JsonResponse
    {
        $targetName = $target->name;
        $targetId = $target->id;
        $projectId = $target->project_id;
        $targetType = $target->type;

        $target->delete();

        $auditLogger->recordSystem('target.deleted', 'success', [
            'user_id' => $request->user()->id,
            'target_id' => $targetId,
            'target_name' => $targetName,
            'target_type' => $targetType,
            'project_id' => $projectId,
        ]);

        return response()->json([
            'message' => "Target '{$targetName}' berhasil dihapus.",
        ]);
    }

    public function verify(Request $request, Target $target): JsonResponse
    {
        $data = $request->validate([
            'verification_status' => ['required', Rule::in(['verified', 'rejected', 'pending'])],
        ]);

        $target->forceFill([
            'verification_status' => $data['verification_status'],
            'verified_at' => $data['verification_status'] === 'verified' ? now() : null,
            'verified_by' => $data['verification_status'] === 'verified' ? $request->user()->id : null,
        ])->save();

        return response()->json([
            'target' => $target->refresh()->load(['project:id,name,code', 'verifier:id,name']),
        ]);
    }

    public function scan(
        Request $request,
        Target $target,
        AuthorizationGateway $gateway,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $user = $request->user();

        // 1. Pastikan target terverifikasi
        if ($target->verification_status !== 'verified') {
            $target->forceFill([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $user->id,
            ])->save();
        }

        // 2. Tentukan Scan Profile (container_security_scan untuk Container, mobile_app_scan untuk Mobile App, api_safe_scan untuk API, web_safe_scan untuk Web)
        $profileKey = match ($target->type) {
            'container' => 'container_security_scan',
            'mobile', 'app' => 'mobile_app_scan',
            'api' => 'api_safe_scan',
            default => 'web_safe_scan',
        };
        $profile = ScanProfile::query()
            ->where('key', $profileKey)
            ->where('is_active', true)
            ->first() ?? ScanProfile::query()->where('is_active', true)->firstOrFail();

        // 3. Buat authorization dan jadwalkan scan job dalam DB transaction
        $scanJob = DB::transaction(function () use ($target, $profile, $user, $gateway, $auditLogger): ScanJob {
            $project = $target->project;

            $scopeType = match ($target->type) {
                'container' => 'container_image',
                'mobile', 'app' => 'mobile_app',
                'api' => 'api_route',
                default => 'url',
            };

            $scope = Scope::query()->firstOrCreate([
                'project_id' => $target->project_id,
                'target_id' => $target->id,
            ], [
                'type' => $scopeType,
                'pattern' => $target->base_url ?: ($target->hostname ?: '*'),
                'effect' => 'allow',
                'status' => 'active',
                'reason' => 'Scope dibuat otomatis untuk pemindaian instan target.',
                'created_by' => $user->id,
            ]);

            $authorization = Authorization::query()->create([
                'code' => 'AUTH-'.now()->format('YmdHis').'-TGT-'.Str::upper(Str::random(4)),
                'project_id' => $target->project_id,
                'target_id' => $target->id,
                'scan_profile_id' => $profile->id,
                'requested_by' => $user->id,
                'approved_by' => $user->id,
                'status' => 'active',
                'valid_from' => now()->subMinute(),
                'valid_until' => now()->addDays(7),
                'max_concurrency' => 1,
                'rate_limit_per_minute' => 60,
                'allowed_engines' => $profile->engine_keys,
                'allowed_scope_snapshot' => [$scope->only(['id', 'type', 'pattern', 'effect', 'target_id'])],
                'denied_scope_snapshot' => [],
                'policy_snapshot' => [
                    'scan_profile' => $profile->only(['id', 'key', 'name', 'active_testing']),
                    'profile_policy' => $profile->policy,
                    'project' => $project ? $project->only(['id', 'code', 'criticality', 'status']) : ['id' => $target->project_id],
                    'target_verified' => true,
                    'source' => 'instant_target_scan',
                ],
                'notes' => 'Pemindaian instan dari Manajemen Target.',
            ]);

            $decision = $gateway->decide([
                'project_id' => $target->project_id,
                'target_id' => $target->id,
                'scan_profile_id' => $profile->id,
                'authorization_id' => $authorization->id,
                'requested_by' => $user->id,
                'requested_at' => now()->toISOString(),
                'source' => 'instant_target_scan',
            ]);

            if ($decision['decision'] !== 'allow') {
                throw ValidationException::withMessages([
                    'target' => ["Authorization Gateway menolak pemindaian: {$decision['reason_code']}"],
                ]);
            }

            $scanJob = ScanJob::query()->create([
                'code' => 'SCAN-'.now()->format('YmdHis').'-TGT-'.Str::upper(Str::random(4)),
                'project_id' => $target->project_id,
                'target_id' => $target->id,
                'scan_profile_id' => $profile->id,
                'authorization_id' => $authorization->id,
                'created_by' => $user->id,
                'status' => 'queued',
                'progress' => 0,
                'attempt' => 0,
                'queued_at' => now(),
                'engine_plan' => $decision['engine_plan'],
                'execution_policy_snapshot' => [
                    'authorization_decision_id' => $decision['policy_decision']->id,
                    'authorization_reason_code' => $decision['reason_code'],
                    'authorization_policy_snapshot' => $decision['policy_snapshot'],
                ],
            ]);

            $decision['policy_decision']->forceFill(['scan_job_id' => $scanJob->id])->save();

            $auditLogger->recordSystem('scan_job.instant_target', 'success', [
                'user_id' => $user->id,
                'project_id' => $target->project_id,
                'target_id' => $target->id,
                'scan_job_id' => $scanJob->id,
                'target_type' => 'scan_job',
                'metadata' => [
                    'code' => $scanJob->code,
                    'target_name' => $target->name,
                    'target_type' => $target->type,
                    'base_url' => $target->base_url,
                ],
            ]);

            RunScanJob::dispatch($scanJob->id, $user->id)->afterCommit();

            return $scanJob;
        });

        return response()->json([
            'message' => "Pemindaian untuk target {$target->name} berhasil dimulai di latar belakang!",
            'scan_job' => $scanJob->load([
                'project:id,name,code',
                'target:id,name,type,base_url,hostname',
                'scanProfile:id,key,name',
            ]),
        ], 201);
    }

    private function validateTarget(Request $request, ?Target $target = null): array
    {
        $hasFile = $request->hasFile('file') || $request->hasFile('app_file');

        return $request->validate([
            'project_id' => [$target ? 'sometimes' : 'required', 'exists:projects,id'],
            'type' => [$target ? 'sometimes' : 'required', Rule::in(['web', 'api', 'container', 'mobile'])],
            'name' => [$hasFile ? 'nullable' : ($target ? 'sometimes' : 'required'), 'string', 'max:255'],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'max:204800'],
            'app_file' => ['nullable', 'file', 'max:204800'],
        ]);
    }
}
