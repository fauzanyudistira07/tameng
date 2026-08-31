<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'storage' => $this->storageCheck(),
            'queue' => $this->queueCheck(),
            'repository_workspace' => $this->repositoryWorkspaceCheck(),
            'engine_runtime' => $this->engineRuntimeCheck(),
        ];

        $healthy = collect($checks)->every(fn (array $check): bool => $check['ok']);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checked_at' => now()->toISOString(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function databaseCheck(): array
    {
        try {
            DB::select('select 1');

            return [
                'ok' => true,
                'connection' => config('database.default'),
                'migrations_table' => Schema::hasTable('migrations'),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'connection' => config('database.default'),
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function storageCheck(): array
    {
        $paths = [
            storage_path('app/private'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];

        return [
            'ok' => collect($paths)->every(fn (string $path): bool => File::isDirectory($path) && File::isWritable($path)),
            'paths' => collect($paths)->map(fn (string $path): array => [
                'path' => $path,
                'exists' => File::isDirectory($path),
                'writable' => File::isWritable($path),
            ])->values(),
        ];
    }

    private function queueCheck(): array
    {
        return [
            'ok' => config('queue.default') !== null,
            'connection' => config('queue.default'),
            'jobs_table' => Schema::hasTable('jobs'),
            'failed_jobs_table' => Schema::hasTable('failed_jobs'),
        ];
    }

    private function repositoryWorkspaceCheck(): array
    {
        $root = config('secsys.repository_workspace_root');

        if (! is_string($root) || $root === '') {
            return [
                'ok' => false,
                'root' => null,
            ];
        }

        File::ensureDirectoryExists($root);

        return [
            'ok' => File::isDirectory($root) && File::isWritable($root),
            'root' => $root,
            'exists' => File::isDirectory($root),
            'writable' => File::isWritable($root),
        ];
    }

    private function engineRuntimeCheck(): array
    {
        return [
            'ok' => in_array(config('secsys.engine_runtime'), ['docker', 'local_binary'], true),
            'real_engines_enabled' => (bool) config('secsys.real_engines_enabled'),
            'runtime' => config('secsys.engine_runtime'),
            'gitleaks_image' => config('secsys.gitleaks_image'),
        ];
    }
}
