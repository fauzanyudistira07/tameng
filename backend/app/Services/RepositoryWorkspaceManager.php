<?php

namespace App\Services;

use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class RepositoryWorkspaceManager
{
    public function root(): string
    {
        $root = config('secsys.repository_workspace_root');

        if (! is_string($root) || $root === '') {
            $root = storage_path('app/private/repository-workspaces');
        }

        File::ensureDirectoryExists($root);

        return $this->normalizePath($root);
    }

    public function attach(Repository $repository, string $path): Repository
    {
        $normalizedPath = $this->normalizePath($path);
        $root = $this->root();

        if (! File::isDirectory($normalizedPath)) {
            throw ValidationException::withMessages([
                'local_path' => ['Repository workspace path must be an existing directory.'],
            ]);
        }

        if (! $this->isInsideRoot($normalizedPath, $root)) {
            throw ValidationException::withMessages([
                'local_path' => ['Repository workspace path must be inside the configured SecSys workspace root.'],
            ]);
        }

        $metadata = $repository->metadata ?? [];
        $metadata['local_path'] = $normalizedPath;
        $metadata['workspace_status'] = 'attached';
        $metadata['workspace_attached_at'] = now()->toISOString();
        $metadata['workspace_root'] = $root;
        $metadata['scanner_execution_ready'] = true;

        $repository->forceFill(['metadata' => $metadata])->save();

        return $repository->refresh();
    }

    public function clear(Repository $repository): Repository
    {
        $metadata = $repository->metadata ?? [];
        unset($metadata['local_path']);

        $metadata['workspace_status'] = 'detached';
        $metadata['workspace_detached_at'] = now()->toISOString();
        $metadata['scanner_execution_ready'] = false;

        $repository->forceFill(['metadata' => $metadata])->save();

        return $repository->refresh();
    }

    public function summarize(Repository $repository): array
    {
        $localPath = $repository->metadata['local_path'] ?? null;

        return [
            'root' => $this->root(),
            'local_path' => $localPath,
            'is_attached' => is_string($localPath) && $localPath !== '',
            'exists' => is_string($localPath) && File::isDirectory($localPath),
            'scanner_execution_ready' => (bool) ($repository->metadata['scanner_execution_ready'] ?? false),
        ];
    }

    private function isInsideRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    private function normalizePath(string $path): string
    {
        $realPath = realpath($path);

        if ($realPath !== false) {
            return rtrim($realPath, DIRECTORY_SEPARATOR);
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
