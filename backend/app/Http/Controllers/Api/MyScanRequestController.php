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
        $requests = ScanJob::query()
            ->with([
                'project:id,name,code',
                'repository:id,name,url,default_branch,metadata',
                'target:id,name,type,base_url,hostname',
                'scanProfile:id,key,name',
                'authorization:id,code,status',
                'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
                'reports:id,scan_job_id,status,format,generated_at,metadata',
            ])
            ->where('created_by', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ScanJob $job) => $this->sanitizeJob($job));

        return response()->json([
            'scan_requests' => $requests,
        ]);
    }

    public function store(
        Request $request,
        AuthorizationGateway $gateway,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $data = $request->validate([
            'scan_type' => ['required', Rule::in(['repository', 'web', 'api'])],
            'project_name' => ['required', 'string', 'max:255'],
            'asset_url' => ['required', 'url', 'max:2048'],
            'default_branch' => ['nullable', 'string', 'max:120'],
            'is_private' => ['nullable', 'boolean'],
            'access_token' => ['nullable', 'string', 'max:500'],
            'auth_type' => ['nullable', Rule::in(['none', 'header', 'cookie', 'basic', 'form_login'])],
            'login_url_path' => ['nullable', 'string', 'max:255'],
            'auth_header_name' => ['nullable', 'string', 'max:100'],
            'auth_header_value' => ['nullable', 'string', 'max:2000'],
            'auth_username' => ['nullable', 'string', 'max:255'],
            'auth_password' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['scan_type'] === 'repository' && ! Str::startsWith($data['asset_url'], ['https://github.com/', 'http://github.com/'])) {
            throw ValidationException::withMessages([
                'asset_url' => ['Scan repository otomatis saat ini hanya mendukung URL GitHub.'],
            ]);
        }

        if ($data['scan_type'] === 'repository' && ! empty($data['is_private']) && empty($data['access_token'])) {
            throw ValidationException::withMessages([
                'access_token' => ['Untuk private repository, Anda wajib menyertakan GitHub Personal Access Token (PAT).'],
            ]);
        }

        $user = $request->user();
        $scanJob = DB::transaction(function () use ($data, $user, $gateway, $auditLogger): ScanJob {
            $profile = ScanProfile::query()
                ->where('key', $this->profileKey($data['scan_type']))
                ->where('is_active', true)
                ->firstOrFail();

            $project = Project::query()->create([
                'name' => $data['project_name'],
                'code' => $this->projectCode($data['project_name'], $user->id),
                'description' => 'Dibuat otomatis dari permintaan scan user.',
                'criticality' => 'medium',
                'status' => 'active',
                'owner_id' => $user->id,
            ]);

            [$repository, $target] = $this->createAsset($data, $project, $user->id);
            $scope = $this->createAllowedScope($data, $project, $target, $user->id);

            $authorization = Authorization::query()->create([
                'code' => 'AUTH-'.now()->format('YmdHis').'-USER-'.Str::upper(Str::random(4)),
                'project_id' => $project->id,
                'repository_id' => $repository?->id,
                'target_id' => $target?->id,
                'scan_profile_id' => $profile->id,
                'requested_by' => $user->id,
                'approved_by' => $user->id,
                'status' => 'active',
                'valid_from' => now(),
                'valid_until' => now()->addDays(7),
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
                ],
                'notes' => $data['notes'] ?? null,
            ]);

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

            $scanJob = ScanJob::query()->create([
                'code' => 'SCAN-'.now()->format('YmdHis').'-USER-'.Str::upper(Str::random(4)),
                'project_id' => $project->id,
                'repository_id' => $repository?->id,
                'target_id' => $target?->id,
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
                    'source' => 'user_scan_request',
                ],
            ]);

            RunScanJob::dispatch($scanJob->id, $user->id)->afterCommit();

            return $scanJob;
        });

        $loaded = $scanJob->refresh()->load([
            'project:id,name,code',
            'repository:id,name,url,default_branch,metadata',
            'target:id,name,type,base_url,hostname',
            'scanProfile:id,key,name',
            'authorization:id,code,status',
            'scanRuns:id,scan_job_id,engine_key,status,exit_code,command_spec,runtime_metrics,started_at,finished_at,failure_reason',
            'reports:id,scan_job_id,status,format,generated_at,metadata',
        ]);

        return response()->json([
            'scan_request' => $this->sanitizeJob($loaded),
        ], 201);
    }

    public function rerun(Request $request, ScanJob $scanJob, AuditLogger $auditLogger): JsonResponse
    {
        if ($scanJob->created_by !== $request->user()->id && ! in_array($request->user()->role?->name, ['super_admin', 'security_admin'], true)) {
            abort(403, 'Anda tidak memiliki izin untuk mengulang pemindaian ini.');
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
            'authorization:id,code,status',
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

        return $job;
    }

    private function profileKey(string $scanType): string
    {
        return match ($scanType) {
            'web' => 'web_safe_scan',
            'api' => 'api_safe_scan',
            default => 'source_code_scan',
        };
    }

    private function createAsset(array $data, Project $project, int $userId): array
    {
        if ($data['scan_type'] === 'repository') {
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
                    'submitted_url' => $data['asset_url'],
                    'is_private' => $isPrivate,
                    'access_token' => $encryptedToken,
                ],
            ]);

            return [$repository, null];
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
        return Scope::query()->create([
            'project_id' => $project->id,
            'target_id' => $target?->id,
            'type' => $data['scan_type'] === 'api' ? 'api_route' : 'url',
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
