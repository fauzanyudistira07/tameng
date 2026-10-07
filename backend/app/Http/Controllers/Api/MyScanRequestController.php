<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunScanJob;
use App\Models\Authorization;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ScanJob;
use App\Models\ScanProfile;
use App\Models\Scope;
use App\Models\Target;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MyScanRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');
        $isAdminOrAnalyst = in_array($user->role?->name ?? '', ['super_admin', 'security_admin', 'security_analyst'], true);

        $query = ScanJob::query()
            ->with([
                'project:id,name,code',
                'repository:id,name,url,default_branch,metadata',
                'target:id,name,type,base_url,hostname',
                'scanProfile:id,key,name',
                'authorization:id,code,status,valid_from,valid_until',
                'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
                'reports:id,scan_job_id,status,format,generated_at',
            ]);

        if (! $isAdminOrAnalyst) {
            $assignedProjectIds = $user->projects()->pluck('projects.id');
            $userId = $user->id;
            $query->where(function ($q) use ($assignedProjectIds, $userId) {
                $q->where('created_by', $userId)
                  ->orWhereIn('project_id', $assignedProjectIds);
            });
        }

        $jobs = $query->orderByDesc('id')->get();
        $userIds = $jobs->pluck('created_by')->filter()->unique();
        $users = User::query()->whereIn('id', $userIds)->with('role:id,name')->get(['id', 'name', 'email', 'role_id'])->keyBy('id');

        $requests = $jobs->map(function (ScanJob $job) use ($users) {
            $sanitized = $this->sanitizeJob($job);
            if ($job->created_by && isset($users[$job->created_by])) {
                $user = $users[$job->created_by];
                $sanitized->creator_name = $user->name;
                $sanitized->creator_email = $user->email;
                $roleName = $user->role?->name;
                $sanitized->creator_role = $roleName;
                $sanitized->creator_is_admin = in_array($roleName, ['super_admin', 'security_admin'], true);
            }

            return $sanitized;
        });

        return response()->json([
            'scan_requests' => $requests,
        ]);
    }

    public function store(
        Request $request,
        AuthorizationGateway $gateway,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $hasUpload = $request->hasFile('file') || $request->hasFile('mobile_file');
        $uploadedFile = $request->file('file') ?: $request->file('mobile_file');

        $data = $request->validate([
            'scan_type' => ['required', Rule::in(['repository', 'web', 'api', 'container', 'mobile'])],
            'project_name' => ['required', 'string', 'max:255'],
            'asset_url' => [$hasUpload ? 'nullable' : 'required', 'string', 'max:2048'],
            'file' => ['nullable', 'file', 'max:204800'],
            'mobile_file' => ['nullable', 'file', 'max:204800'],
            'default_branch' => ['nullable', 'string', 'max:120'],
            'is_private' => ['nullable', 'boolean'],
            'access_token' => ['nullable', 'string', 'max:500'],
            'auth_type' => ['nullable', Rule::in(['none', 'header', 'cookie', 'basic', 'form_login'])],
            'login_url_path' => ['nullable', 'string', 'max:255'],
            'auth_header_name' => ['nullable', 'string', 'max:100'],
            'auth_header_value' => ['nullable', 'string', 'max:2000'],
            'auth_username' => ['nullable', 'string', 'max:255'],
            'auth_password' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($hasUpload && $uploadedFile) {
            $ext = strtolower($uploadedFile->getClientOriginalExtension());
            if (! in_array($ext, ['apk', 'ipa', 'aab', 'zip'], true)) {
                throw ValidationException::withMessages([
                    'file' => ['File yang diunggah harus berformat binary aplikasi mobile (.apk, .ipa, .aab, atau .zip).'],
                ]);
            }
            $data['asset_url'] = 'file://'.$uploadedFile->getClientOriginalName();
        }

        if (in_array($data['scan_type'], ['web', 'api'], true) && ! filter_var($data['asset_url'] ?? '', FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'asset_url' => ['URL target web/API harus berformat URL valid (contoh: https://app.example.com).'],
            ]);
        }

        if ($data['scan_type'] === 'container' && ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.\-\/:@]*$/', $data['asset_url'] ?? '')) {
            throw ValidationException::withMessages([
                'asset_url' => ['Nama Docker image / tag registry tidak valid (contoh: alpine:latest, nginx:alpine, atau ghcr.io/org/app:latest).'],
            ]);
        }

        if ($data['scan_type'] === 'repository' && ! Str::startsWith($data['asset_url'] ?? '', ['https://github.com/', 'http://github.com/'])) {
            throw ValidationException::withMessages([
                'asset_url' => ['Scan repository source code otomatis saat ini mendukung URL repositori GitHub.'],
            ]);
        }

        if ($data['scan_type'] === 'mobile' && ! $hasUpload) {
            $isGit = Str::startsWith($data['asset_url'] ?? '', ['https://github.com/', 'http://github.com/']);
            $isDirectUrl = filter_var($data['asset_url'] ?? '', FILTER_VALIDATE_URL);
            if (! $isGit && ! $isDirectUrl) {
                throw ValidationException::withMessages([
                    'asset_url' => ['Target mobile harus berupa file upload .apk/.ipa, URL repositori GitHub, atau link unduhan direct .apk.'],
                ]);
            }
        }

        if (in_array($data['scan_type'], ['repository', 'mobile'], true) && Str::startsWith($data['asset_url'] ?? '', ['https://github.com/', 'http://github.com/']) && ! empty($data['is_private']) && empty($data['access_token'])) {
            throw ValidationException::withMessages([
                'access_token' => ['Untuk private repository, Anda wajib menyertakan GitHub Personal Access Token (PAT).'],
            ]);
        }

        $user = $request->user();
        $user->loadMissing('role');
        $isAdmin = in_array($user->role?->name ?? '', ['super_admin', 'security_admin', 'security_analyst'], true);

        if (! $isAdmin) {
            // Validasi: Cegah permohonan scan baru pada repositori/target yang sama jika scan sebelumnya masih menunggu persetujuan Admin SOC
            $rawAssetUrl = trim($data['asset_url'] ?? '');
            $normalizedUrl = rtrim(strtolower($rawAssetUrl), '/');
            $normalizedWithoutGit = Str::endsWith($normalizedUrl, '.git') ? substr($normalizedUrl, 0, -4) : $normalizedUrl;
            $repoIdentifier = Str::contains($rawAssetUrl, 'github.com') ? $this->repositoryName($rawAssetUrl) : null;
            $uploadedFileName = $uploadedFile ? $uploadedFile->getClientOriginalName() : null;

            $existingPendingJob = ScanJob::query()
                ->where('status', 'pending_approval')
                ->where(function ($query) use ($rawAssetUrl, $normalizedUrl, $normalizedWithoutGit, $repoIdentifier, $uploadedFileName) {
                    $query->whereHas('repository', function ($repoQuery) use ($rawAssetUrl, $normalizedUrl, $normalizedWithoutGit, $repoIdentifier, $uploadedFileName) {
                        $repoQuery->where(function ($sub) use ($rawAssetUrl, $normalizedUrl, $normalizedWithoutGit, $repoIdentifier, $uploadedFileName) {
                            if ($rawAssetUrl !== '') {
                                $sub->where('url', $rawAssetUrl)
                                    ->orWhere('url', $normalizedUrl)
                                    ->orWhere('url', $normalizedWithoutGit)
                                    ->orWhere('url', $normalizedWithoutGit . '.git');

                                if ($repoIdentifier) {
                                    $sub->orWhere('name', $repoIdentifier);
                                }
                            }

                            if ($uploadedFileName) {
                                $sub->orWhere('name', $uploadedFileName);
                            }
                        });
                    })->orWhereHas('target', function ($targetQuery) use ($rawAssetUrl, $normalizedUrl) {
                        if ($rawAssetUrl !== '') {
                            $targetQuery->where('base_url', $rawAssetUrl)
                                ->orWhere('base_url', $normalizedUrl);
                        }
                    });
                })
                ->latest('id')
                ->first();

            if ($existingPendingJob) {
                $existingTargetName = $repoIdentifier ?: ($uploadedFileName ?: $rawAssetUrl);
                throw ValidationException::withMessages([
                    'asset_url' => [
                        "Repositori '{$existingTargetName}' masih memiliki permohonan scan ({$existingPendingJob->code}) yang menunggu persetujuan Admin SOC. Harap tunggu hingga permohonan pertama disetujui terlebih dahulu sebelum mengajukan pemindaian ulang."
                    ],
                ]);
            }
        }

        $scanJob = DB::transaction(function () use ($data, $user, $gateway, $auditLogger, $uploadedFile, $isAdmin): ScanJob {
            $profile = ScanProfile::query()
                ->where('key', $this->profileKey($data['scan_type']))
                ->where('is_active', true)
                ->firstOrFail();

            $project = Project::query()->create([
                'name' => $data['project_name'],
                'code' => $this->projectCode($data['project_name'], $user->id),
                'description' => 'Dibuat dari pengajuan scan user (' . $user->name . ').',
                'criticality' => 'medium',
                'status' => 'active',
                'owner_id' => $user->id,
            ]);

            [$repository, $target] = $this->createAsset($data, $project, $user->id, $uploadedFile);
            $scope = $this->createAllowedScope($data, $project, $target, $user->id);

            $authStatus = $isAdmin ? 'active' : 'pending';
            $authNotes = $isAdmin ? ($data['notes'] ?? null) : trim(($data['notes'] ?? '') . "\n[Menunggu Persetujuan Admin SOC]");

            $authorization = Authorization::query()->create([
                'code' => 'AUTH-'.now()->format('YmdHis').'-USER-'.Str::upper(Str::random(4)),
                'project_id' => $project->id,
                'repository_id' => $repository?->id,
                'target_id' => $target?->id,
                'scan_profile_id' => $profile->id,
                'requested_by' => $user->id,
                'approved_by' => $isAdmin ? $user->id : null,
                'status' => $authStatus,
                'valid_from' => now(),
                'valid_until' => $isAdmin ? null : now()->addDays(7),
                'max_concurrency' => 1,
                'rate_limit_per_minute' => 30,
                'allowed_engines' => $profile->engine_keys,
                'allowed_scope_snapshot' => [$scope->only(['id', 'type', 'pattern', 'effect', 'target_id'])],
                'denied_scope_snapshot' => [],
                'policy_snapshot' => [
                    'scan_profile' => $profile->only(['id', 'key', 'name', 'active_testing']),
                    'profile_policy' => $profile->policy,
                    'project' => $project->only(['id', 'code', 'criticality', 'status']),
                    'repository_verified' => $repository?->verification_status === 'verified',
                    'target_verified' => $target?->verification_status === 'verified',
                    'source' => 'user_scan_request',
                    'requires_admin_approval' => ! $isAdmin,
                ],
                'notes' => $authNotes,
            ]);

            $jobStatus = $isAdmin ? 'queued' : 'pending_approval';

            if ($isAdmin) {
                $decision = $gateway->decide([
                    'project_id' => $project->id,
                    'repository_id' => $repository?->id,
                    'target_id' => $target?->id,
                    'scan_profile_id' => $profile->id,
                    'authorization_id' => $authorization->id,
                    'requested_by' => $user->id,
                    'requested_at' => now()->toISOString(),
                    'source' => 'user_scan_request',
                ]);

                if ($decision['decision'] !== 'allow') {
                    throw ValidationException::withMessages([
                        'asset_url' => ["Authorization Gateway menolak permintaan: {$decision['reason_code']}"],
                    ]);
                }

                $enginePlan = $decision['engine_plan'];
                $executionPolicySnapshot = [
                    'authorization_decision_id' => $decision['policy_decision']->id,
                    'authorization_reason_code' => $decision['reason_code'],
                    'authorization_policy_snapshot' => $decision['policy_snapshot'],
                    'requires_admin_approval' => false,
                ];
                $policyDecision = $decision['policy_decision'];
            } else {
                $enginePlan = collect($profile->engine_keys)->map(fn (string $engine): array => [
                    'engine_key' => $engine,
                    'status' => 'planned',
                ])->values()->all();

                $executionPolicySnapshot = [
                    'authorization_decision_id' => null,
                    'authorization_reason_code' => 'PENDING_ADMIN_APPROVAL',
                    'authorization_policy_snapshot' => $authorization->policy_snapshot,
                    'requires_admin_approval' => true,
                ];
                $policyDecision = null;
            }

            $scanJob = ScanJob::query()->create([
                'code' => 'SCAN-'.now()->format('YmdHis').'-USER-'.Str::upper(Str::random(4)),
                'project_id' => $project->id,
                'repository_id' => $repository?->id,
                'target_id' => $target?->id,
                'scan_profile_id' => $profile->id,
                'authorization_id' => $authorization->id,
                'created_by' => $user->id,
                'status' => $jobStatus,
                'progress' => 0,
                'attempt' => 0,
                'queued_at' => $isAdmin ? now() : null,
                'engine_plan' => $enginePlan,
                'execution_policy_snapshot' => $executionPolicySnapshot,
            ]);

            if ($policyDecision) {
                $policyDecision->forceFill(['scan_job_id' => $scanJob->id])->save();
            }

            $auditLogger->recordSystem('scan_request.create', 'success', [
                'user_id' => $user->id,
                'project_id' => $project->id,
                'authorization_id' => $authorization->id,
                'scan_job_id' => $scanJob->id,
                'target_type' => 'scan_job',
                'target_id' => $scanJob->id,
                'metadata' => [
                    'code' => $scanJob->code,
                    'scan_type' => $data['scan_type'],
                    'asset_url' => $data['asset_url'],
                    'status' => $jobStatus,
                    'requires_admin_approval' => ! $isAdmin,
                    'source' => 'user_scan_request',
                ],
            ]);

            if ($isAdmin) {
                RunScanJob::dispatch($scanJob->id, $user->id)->afterCommit();
            }

            return $scanJob;
        });

        $loaded = $scanJob->refresh()->load([
            'project:id,name,code',
            'repository:id,name,url,default_branch,metadata',
            'target:id,name,type,base_url,hostname',
            'scanProfile:id,key,name',
            'authorization:id,code,status,valid_from,valid_until',
            'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            'reports:id,scan_job_id,status,format,generated_at,metadata',
        ]);

        $loaded->creator_name = $user->name;
        $loaded->creator_email = $user->email;

        return response()->json([
            'message' => $isAdmin
                ? 'Permintaan scan berhasil dibuat dan dimasukkan ke antrean runner.'
                : 'Permintaan scan berhasil diajukan dan sedang menunggu persetujuan Administrator Keamanan (SOC).',
            'scan_request' => $this->sanitizeJob($loaded),
        ], 201);
    }

    public function approve(Request $request, ScanJob $scanJob, AuditLogger $auditLogger, AuthorizationGateway $gateway): JsonResponse
    {
        $user = $request->user();

        if ($scanJob->status !== 'pending_approval') {
            return response()->json([
                'message' => 'Hanya permintaan scan dengan status pending_approval yang dapat disetujui.',
            ], 422);
        }

        DB::transaction(function () use ($scanJob, $user, $auditLogger, $request, $gateway): void {
            if ($scanJob->authorization) {
                $scanJob->authorization->update([
                    'status' => 'active',
                    'approved_by' => $user->id,
                    'valid_from' => now(),
                    'valid_until' => now()->addDays(7),
                ]);
            }

            $decision = $gateway->decide([
                'project_id' => $scanJob->project_id,
                'repository_id' => $scanJob->repository_id,
                'target_id' => $scanJob->target_id,
                'scan_profile_id' => $scanJob->scan_profile_id,
                'authorization_id' => $scanJob->authorization_id,
                'requested_by' => $scanJob->created_by,
                'requested_at' => now()->toISOString(),
                'source' => 'user_scan_request_approved',
            ]);

            if ($decision['decision'] !== 'allow') {
                throw ValidationException::withMessages([
                    'scan_job' => ["Authorization Gateway menolak aktivasi scan: {$decision['reason_code']}"],
                ]);
            }

            $scanJob->update([
                'status' => 'queued',
                'queued_at' => now(),
                'engine_plan' => $decision['engine_plan'],
                'execution_policy_snapshot' => [
                    'authorization_decision_id' => $decision['policy_decision']->id,
                    'authorization_reason_code' => $decision['reason_code'],
                    'authorization_policy_snapshot' => $decision['policy_snapshot'],
                    'requires_admin_approval' => false,
                    'approved_by_admin' => $user->name,
                ],
            ]);

            $decision['policy_decision']->forceFill(['scan_job_id' => $scanJob->id])->save();

            $auditLogger->record($request, 'scan_job.approve', 'success', [
                'project_id' => $scanJob->project_id,
                'authorization_id' => $scanJob->authorization_id,
                'scan_job_id' => $scanJob->id,
                'target_type' => 'scan_job',
                'target_id' => $scanJob->id,
                'metadata' => [
                    'code' => $scanJob->code,
                    'approved_by' => $user->name,
                ],
            ]);

            RunScanJob::dispatch($scanJob->id, $user->id)->afterCommit();
        });

        $loaded = $scanJob->refresh()->load([
            'project:id,name,code',
            'repository:id,name,url,default_branch,metadata',
            'target:id,name,type,base_url,hostname',
            'scanProfile:id,key,name',
            'authorization:id,code,status,valid_from,valid_until',
            'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            'reports:id,scan_job_id,status,format,generated_at,metadata',
        ]);

        $creator = User::query()->find($scanJob->created_by);
        if ($creator) {
            $loaded->creator_name = $creator->name;
            $loaded->creator_email = $creator->email;
        }

        return response()->json([
            'message' => 'Permintaan scan berhasil disetujui dan telah dimasukkan ke antrean runner pemindaian.',
            'scan_request' => $this->sanitizeJob($loaded),
        ]);
    }

    public function reject(Request $request, ScanJob $scanJob, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();

        if ($scanJob->status !== 'pending_approval') {
            return response()->json([
                'message' => 'Hanya permintaan scan dengan status pending_approval yang dapat ditolak.',
            ], 422);
        }

        $reason = $request->input('reason', 'Permohonan scan tidak disetujui oleh Administrator SOC.');

        DB::transaction(function () use ($scanJob, $user, $auditLogger, $request, $reason): void {
            if ($scanJob->authorization) {
                $scanJob->authorization->update([
                    'status' => 'rejected',
                    'approved_by' => $user->id,
                    'notes' => trim(($scanJob->authorization->notes ?? '') . "\n[Ditolak Admin: {$reason}]"),
                ]);
            }

            $scanJob->update([
                'status' => 'cancelled',
                'failure_reason' => 'Pengajuan scan ditolak oleh Admin: ' . $reason,
                'cancelled_at' => now(),
            ]);

            $auditLogger->record($request, 'scan_job.reject', 'success', [
                'project_id' => $scanJob->project_id,
                'authorization_id' => $scanJob->authorization_id,
                'scan_job_id' => $scanJob->id,
                'target_type' => 'scan_job',
                'target_id' => $scanJob->id,
                'metadata' => [
                    'code' => $scanJob->code,
                    'rejected_by' => $user->name,
                    'reason' => $reason,
                ],
            ]);
        });

        $loaded = $scanJob->refresh()->load([
            'project:id,name,code',
            'repository:id,name,url,default_branch,metadata',
            'target:id,name,type,base_url,hostname',
            'scanProfile:id,key,name',
            'authorization:id,code,status,valid_from,valid_until',
            'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            'reports:id,scan_job_id,status,format,generated_at,metadata',
        ]);

        $creator = User::query()->find($scanJob->created_by);
        if ($creator) {
            $loaded->creator_name = $creator->name;
            $loaded->creator_email = $creator->email;
        }

        return response()->json([
            'message' => 'Permintaan scan telah ditolak.',
            'scan_request' => $this->sanitizeJob($loaded),
        ]);
    }

    public function rerun(Request $request, ScanJob $scanJob, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');
        $isAdminOrAnalyst = in_array($user->role?->name ?? '', ['super_admin', 'security_admin', 'security_analyst'], true);

        $isRelated = $user->projects()->where('projects.id', $scanJob->project_id)->exists();

        if ($scanJob->created_by !== $user->id && ! $isAdminOrAnalyst && ! $isRelated) {
            abort(403, 'Anda tidak memiliki izin untuk mengulang pemindaian ini.');
        }

        // Kebijakan Masa Berlaku:
        // Jika Admin: hilangkan masa berlaku (valid_until = null / unlimited)
        // Jika Pengguna Biasa: terapkan masa berlaku 7 hari jika sudah kedaluwarsa
        if ($scanJob->authorization) {
            $auth = $scanJob->authorization;
            if ($isAdminOrAnalyst) {
                $auth->update([
                    'status' => 'active',
                    'valid_from' => now()->subMinute(),
                    'valid_until' => null, // Admin = Tanpa Masa Berlaku
                    'notes' => trim(($auth->notes ?? '') . "\n[Diperbarui oleh Administrator: Tanpa Masa Berlaku pada " . now()->format('Y-m-d H:i') . "]"),
                ]);
            } else {
                if ($auth->status !== 'active' || ($auth->valid_until && \Carbon\Carbon::parse($auth->valid_until)->isPast())) {
                    $auth->update([
                        'status' => 'active',
                        'valid_from' => now()->subMinute(),
                        'valid_until' => now()->addDays(7),
                        'notes' => trim(($auth->notes ?? '') . "\n[Diperpanjang untuk Pengguna Biasa (7 hari) pada " . now()->format('Y-m-d H:i') . "]"),
                    ]);
                }
            }
        }

        $newScanJob = ScanJob::query()->create([
            'code' => 'SCAN-'.now()->format('YmdHis').'-USER-'.Str::upper(Str::random(4)),
            'project_id' => $scanJob->project_id,
            'repository_id' => $scanJob->repository_id,
            'target_id' => $scanJob->target_id,
            'scan_profile_id' => $scanJob->scan_profile_id,
            'authorization_id' => $scanJob->authorization_id,
            'created_by' => $request->user()->id,
            'status' => 'queued',
            'progress' => 0,
            'attempt' => 0,
            'queued_at' => now(),
            'engine_plan' => $scanJob->engine_plan,
            'execution_policy_snapshot' => $scanJob->execution_policy_snapshot,
        ]);

        $auditLogger->recordSystem('scan_request.rerun', 'success', [
            'user_id' => $request->user()->id,
            'project_id' => $newScanJob->project_id,
            'authorization_id' => $newScanJob->authorization_id,
            'scan_job_id' => $newScanJob->id,
            'target_type' => 'scan_job',
            'target_id' => $newScanJob->id,
            'metadata' => [
                'code' => $newScanJob->code,
                'original_scan_job_id' => $scanJob->id,
            ],
        ]);

        RunScanJob::dispatch($newScanJob->id, $request->user()->id)->afterCommit();

        $loaded = $newScanJob->refresh()->load([
            'project:id,name,code',
            'repository:id,name,url,default_branch,metadata',
            'target:id,name,type,base_url,hostname',
            'scanProfile:id,key,name',
            'authorization:id,code,status,valid_from,valid_until',
            'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            'reports:id,scan_job_id,status,format,generated_at,metadata',
        ]);

        return response()->json([
            'scan_request' => $this->sanitizeJob($loaded),
        ], 201);
    }

    private function sanitizeJob(ScanJob $job): ScanJob
    {
        if ($job->repository && isset($job->repository->metadata['access_token'])) {
            $meta = $job->repository->metadata;
            $meta['has_access_token'] = ! empty($meta['access_token']);
            unset($meta['access_token']);
            $job->repository->metadata = $meta;
        }

        if ($job->target && isset($job->target->metadata['auth'])) {
            $meta = $job->target->metadata;
            $auth = $meta['auth'] ?? null;
            if ($auth) {
                $meta['has_auth'] = true;
                $meta['auth_type'] = $auth['type'] ?? 'none';
                unset($meta['auth']);
            }
            $job->target->metadata = $meta;
        }

        if ($job->created_by && ! isset($job->creator_name)) {
            $user = User::with('role:id,name')->find($job->created_by);
            if ($user) {
                $job->creator_name = $user->name;
                $job->creator_email = $user->email;
                $roleName = $user->role?->name;
                $job->creator_role = $roleName;
                $job->creator_is_admin = in_array($roleName, ['super_admin', 'security_admin'], true);
            }
        }

        return $job;
    }

    private function profileKey(string $scanType): string
    {
        return match ($scanType) {
            'web' => 'web_safe_scan',
            'api' => 'api_safe_scan',
            'container' => 'container_security_scan',
            'mobile' => 'mobile_app_scan',
            default => 'source_code_scan',
        };
    }

    private function createAsset(array $data, Project $project, int $userId, $uploadedFile = null): array
    {
        // 1. Uploaded File (Binary APK / IPA / AAB / ZIP)
        if ($uploadedFile) {
            $workspaceRoot = app(\App\Services\RepositoryWorkspaceManager::class)->root();
            $workspaceName = Str::slug($project->id.'-'.$project->name);
            if ($workspaceName === '') {
                $workspaceName = 'project-'.$project->id;
            }

            $workspacePath = $workspaceRoot.DIRECTORY_SEPARATOR.$workspaceName;
            \Illuminate\Support\Facades\File::ensureDirectoryExists($workspacePath);

            $originalName = $uploadedFile->getClientOriginalName();
            $targetFilePath = $workspacePath.DIRECTORY_SEPARATOR.$originalName;
            $uploadedFile->move($workspacePath, $originalName);

            // Auto-extract archive contents (ZIP / APK / IPA / AAB)
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (class_exists(\ZipArchive::class) && in_array($ext, ['apk', 'zip', 'ipa', 'aab'], true)) {
                $zip = new \ZipArchive();
                if ($zip->open($targetFilePath) === true) {
                    $zip->extractTo($workspacePath);
                    $zip->close();
                }
            }

            $fileHash = md5_file($targetFilePath) ?: Str::random(32);
            $fileSize = \Illuminate\Support\Facades\File::size($targetFilePath);

            $repository = Repository::query()->create([
                'project_id' => $project->id,
                'provider' => 'file_upload',
                'name' => $originalName,
                'url' => 'file://'.$originalName,
                'default_branch' => 'main',
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $userId,
                'metadata' => [
                    'source' => 'user_scan_request',
                    'scan_type' => $data['scan_type'],
                    'is_direct_file' => true,
                    'file_name' => $originalName,
                    'file_size' => $fileSize,
                    'workspace_commit' => $fileHash,
                    'local_path' => $workspacePath,
                    'workspace_status' => 'attached',
                    'scanner_execution_ready' => true,
                    'workspace_attached_at' => now()->toISOString(),
                ],
            ]);

            return [$repository, null];
        }

        // 2. Direct Mobile Download URL (e.g. https://.../app.apk)
        if ($data['scan_type'] === 'mobile' && ! Str::startsWith($data['asset_url'] ?? '', ['https://github.com/', 'http://github.com/']) && filter_var($data['asset_url'] ?? '', FILTER_VALIDATE_URL)) {
            $parsedPath = parse_url($data['asset_url'], PHP_URL_PATH) ?? '';
            $filename = basename($parsedPath) ?: 'app.apk';

            $repository = Repository::query()->create([
                'project_id' => $project->id,
                'provider' => 'direct_download',
                'name' => $filename,
                'url' => $data['asset_url'],
                'default_branch' => 'main',
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $userId,
                'metadata' => [
                    'source' => 'user_scan_request',
                    'scan_type' => 'mobile',
                    'submitted_url' => $data['asset_url'],
                    'is_direct_file' => true,
                    'file_name' => $filename,
                ],
            ]);

            return [$repository, null];
        }

        if (in_array($data['scan_type'], ['repository', 'mobile'], true) && Str::startsWith($data['asset_url'], ['https://github.com/', 'http://github.com/'])) {
            $isPrivate = (bool) ($data['is_private'] ?? ! empty($data['access_token']));
            $encryptedToken = ! empty($data['access_token']) ? Crypt::encryptString($data['access_token']) : null;

            $repository = Repository::query()->create([
                'project_id' => $project->id,
                'provider' => 'github',
                'name' => $this->repositoryName($data['asset_url']),
                'url' => $data['asset_url'],
                'default_branch' => $data['default_branch'] ?? 'main',
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $userId,
                'metadata' => [
                    'source' => 'user_scan_request',
                    'scan_type' => $data['scan_type'],
                    'submitted_url' => $data['asset_url'],
                    'is_private' => $isPrivate,
                    'access_token' => $encryptedToken,
                ],
            ]);

            return [$repository, null];
        }

        if ($data['scan_type'] === 'container') {
            $imageTag = trim($data['asset_url']);
            $host = parse_url('//'.$imageTag, PHP_URL_HOST);

            $target = Target::query()->create([
                'project_id' => $project->id,
                'type' => 'container',
                'name' => $imageTag,
                'base_url' => $imageTag,
                'hostname' => $host ?: null,
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $userId,
                'metadata' => [
                    'source' => 'user_scan_request',
                    'image_tag' => $imageTag,
                    'submitted_url' => $imageTag,
                ],
            ]);

            return [null, $target];
        }

        $host = parse_url($data['asset_url'], PHP_URL_HOST);
        $authType = $data['auth_type'] ?? 'none';
        $authConfig = null;

        if ($authType === 'header' && ! empty($data['auth_header_value'])) {
            $headerName = ! empty($data['auth_header_name']) ? trim($data['auth_header_name']) : 'Authorization';
            $authConfig = [
                'type' => 'header',
                'header_name' => $headerName,
                'header_value' => Crypt::encryptString($data['auth_header_value']),
            ];
        } elseif ($authType === 'cookie' && ! empty($data['auth_header_value'])) {
            $authConfig = [
                'type' => 'cookie',
                'cookie_value' => Crypt::encryptString($data['auth_header_value']),
            ];
        } elseif ($authType === 'basic' && ! empty($data['auth_username'])) {
            $authConfig = [
                'type' => 'basic',
                'username' => $data['auth_username'],
                'password' => ! empty($data['auth_password']) ? Crypt::encryptString($data['auth_password']) : null,
            ];
        } elseif ($authType === 'form_login' && ! empty($data['auth_username']) && ! empty($data['auth_password'])) {
            $authConfig = [
                'type' => 'form_login',
                'username' => $data['auth_username'],
                'password' => Crypt::encryptString($data['auth_password']),
                'login_path' => ! empty($data['login_url_path']) ? $data['login_url_path'] : '/login',
            ];
        }

        $target = Target::query()->create([
            'project_id' => $project->id,
            'type' => $data['scan_type'],
            'name' => $host ?: $data['asset_url'],
            'base_url' => $data['asset_url'],
            'hostname' => $host,
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $userId,
            'metadata' => [
                'source' => 'user_scan_request',
                'submitted_url' => $data['asset_url'],
                'auth' => $authConfig,
            ],
        ]);

        return [null, $target];
    }

    private function createAllowedScope(array $data, Project $project, ?Target $target, int $userId): Scope
    {
        $type = match ($data['scan_type']) {
            'api' => 'api_route',
            'container' => 'container_image',
            'mobile' => 'mobile_app',
            default => 'url',
        };

        return Scope::query()->create([
            'project_id' => $project->id,
            'target_id' => $target?->id,
            'type' => $type,
            'pattern' => $data['asset_url'],
            'effect' => 'allow',
            'status' => 'active',
            'reason' => 'Scope dibuat otomatis dari permintaan scan user.',
            'created_by' => $userId,
        ]);
    }

    private function projectCode(string $projectName, int $userId): string
    {
        $base = Str::upper(Str::slug($projectName, '-'));
        $base = $base !== '' ? Str::limit($base, 28, '') : 'USER-PROJECT';

        return 'USR-'.$userId.'-'.$base.'-'.Str::upper(Str::random(4));
    }

    private function repositoryName(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return Str::of($path)->replaceEnd('.git', '')->value() ?: $url;
    }
}
