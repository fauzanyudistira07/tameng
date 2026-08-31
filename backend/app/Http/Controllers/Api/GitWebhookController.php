<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunScanJob;
use App\Models\Authorization;
use App\Models\Repository;
use App\Models\ScanJob;
use App\Models\ScanProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GitWebhookController extends Controller
{
    public function handle(
        Request $request,
        AuditLogger $auditLogger,
        AuthorizationGateway $gateway
    ): JsonResponse {
        if (! config('secsys.git_webhook_auto_scan_enabled', false)) {
            return response()->json([
                'status' => 'disabled',
                'message' => 'Otomasi pemindaian otomatis pada setiap git push saat ini sedang dinonaktifkan.',
            ], 200);
        }

        $payload = $request->all();
        
        // 1. Detect Repository URL from GitHub / GitLab / Bitbucket webhook payload
        $repoUrl = $payload['repository']['html_url'] 
            ?? $payload['repository']['url'] 
            ?? $payload['project']['web_url'] 
            ?? $payload['repository']['clone_url'] 
            ?? null;

        if (! $repoUrl) {
            return response()->json([
                'status' => 'ignored',
                'message' => 'Tidak dapat mendeteksi URL repositori dari payload webhook.',
            ], 400);
        }

        // Normalize URL (strip .git suffix)
        $cleanUrl = rtrim($repoUrl, '/');
        $cleanUrlNoGit = Str::endsWith($cleanUrl, '.git') ? substr($cleanUrl, 0, -4) : $cleanUrl;

        // 2. Find matching repository in SecSys database
        $repository = Repository::query()
            ->where(function ($q) use ($cleanUrl, $cleanUrlNoGit) {
                $q->where('url', $cleanUrl)
                  ->orWhere('url', $cleanUrl . '.git')
                  ->orWhere('url', $cleanUrlNoGit)
                  ->orWhere('url', $cleanUrlNoGit . '.git');
            })
            ->with(['project'])
            ->first();

        if (! $repository) {
            return response()->json([
                'status' => 'unmatched',
                'message' => "Repositori '{$cleanUrl}' belum terdaftar di SecSys. Daftarkan terlebih dahulu untuk mengaktifkan audit CI/CD otomatis.",
            ], 404);
        }

        $project = $repository->project;
        $systemUser = User::first(); // System / Service account

        $branch = str_replace('refs/heads/', '', $payload['ref'] ?? $repository->default_branch ?? 'main');
        $commitSha = $payload['after'] ?? $payload['head_commit']['id'] ?? $payload['checkout_sha'] ?? 'HEAD';
        $committer = $payload['head_commit']['author']['name'] ?? $payload['user_name'] ?? 'Git Webhook';

        // 3. Create Scan Job & Dispatch Queue
        $scanJob = DB::transaction(function () use ($project, $repository, $systemUser, $branch, $commitSha, $committer, $gateway, $auditLogger): ScanJob {
            $profile = ScanProfile::query()
                ->where('key', 'source_code_scan')
                ->where('is_active', true)
                ->firstOrFail();

            $authorization = Authorization::query()->firstOrCreate([
                'project_id' => $project->id,
                'repository_id' => $repository->id,
            ], [
                'code' => 'AUTH-'.now()->format('YmdHis').'-HOOK-'.Str::upper(Str::random(4)),
                'scan_profile_id' => $profile->id,
                'requested_by' => $systemUser->id,
                'approved_by' => $systemUser->id,
                'status' => 'active',
                'valid_from' => now(),
                'valid_until' => now()->addDays(30),
                'max_concurrency' => 2,
                'rate_limit_per_minute' => 60,
                'allowed_engines' => $profile->engine_keys,
                'allowed_scope_snapshot' => [],
                'denied_scope_snapshot' => [],
                'policy_snapshot' => [
                    'scan_profile' => $profile->only(['id', 'key', 'name', 'active_testing']),
                    'profile_policy' => $profile->policy,
                    'project' => $project->only(['id', 'code', 'criticality', 'status']),
                    'repository_verified' => true,
                    'source' => 'git_webhook_trigger',
                ],
            ]);

            $decision = $gateway->decide([
                'project_id' => $project->id,
                'repository_id' => $repository->id,
                'target_id' => null,
                'scan_profile_id' => $profile->id,
                'authorization_id' => $authorization->id,
                'requested_by' => $systemUser->id,
                'requested_at' => now()->toISOString(),
                'source' => 'git_webhook_trigger',
            ]);

            $job = ScanJob::query()->create([
                'code' => 'SCAN-'.now()->format('YmdHis').'-HOOK-'.Str::upper(Str::random(4)),
                'project_id' => $project->id,
                'repository_id' => $repository->id,
                'target_id' => null,
                'scan_profile_id' => $profile->id,
                'authorization_id' => $authorization->id,
                'created_by' => $systemUser->id,
                'status' => 'queued',
                'progress' => 0,
                'attempt' => 0,
                'queued_at' => now(),
                'engine_plan' => $decision['engine_plan'],
                'execution_policy_snapshot' => [
                    'authorization_decision_id' => $decision['policy_decision']->id,
                    'authorization_reason_code' => $decision['reason_code'],
                    'authorization_policy_snapshot' => $decision['policy_snapshot'],
                    'webhook_event' => 'push',
                    'branch' => $branch,
                    'commit' => $commitSha,
                    'committer' => $committer,
                ],
            ]);

            $decision['policy_decision']->forceFill(['scan_job_id' => $job->id])->save();

            return $job;
        });

        // 4. Dispatch queue job
        dispatch(new RunScanJob($scanJob->id, $systemUser->id));

        $auditLogger->recordSystem('webhook.git_push', 'success', [
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'scan_job_id' => $scanJob->id,
            'branch' => $branch,
            'commit' => $commitSha,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Pemindaian keamanan otomatis berhasil dipicu untuk repositori '{$repository->name}' pada branch '{$branch}'.",
            'scan_job' => [
                'id' => $scanJob->id,
                'code' => $scanJob->code,
                'status' => $scanJob->status,
                'branch' => $branch,
                'commit' => substr($commitSha, 0, 8),
            ],
        ], 202);
    }
}
