<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class RepositoryWorkspaceSyncer
{
    public function __construct(private readonly RepositoryWorkspaceManager $workspaceManager) {}

    public function sync(Repository $repository, ?User $user = null): array
    {
        if (! Str::startsWith($repository->url, ['https://github.com/', 'http://github.com/'])) {
            if (filter_var($repository->url, FILTER_VALIDATE_URL)) {
                return $this->syncDirectFile($repository, $user);
            }

            throw ValidationException::withMessages([
                'url' => ['Saat ini clone otomatis mendukung URL repositori GitHub atau link unduhan langsung file .apk/.ipa.'],
            ]);
        }

        $workspaceRoot = $this->workspaceManager->root();
        $workspaceName = Str::slug($repository->project_id.'-'.$repository->name);

        if ($workspaceName === '') {
            $workspaceName = 'repository-'.$repository->id;
        }

        $workspacePath = $workspaceRoot.DIRECTORY_SEPARATOR.$workspaceName;
        $gitDirectory = $workspacePath.DIRECTORY_SEPARATOR.'.git';

        $rawToken = $repository->metadata['access_token'] ?? null;
        $token = null;
        if (! empty($rawToken)) {
            try {
                $token = Crypt::decryptString($rawToken);
            } catch (\Throwable) {
                $token = $rawToken;
            }
        }
        $cloneUrl = $this->buildAuthenticatedUrl($repository->url, $token);

        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => 'echo',
        ];

        if (File::isDirectory($gitDirectory)) {
            // Update remote URL with token if provided
            if (! empty($token)) {
                $setRemoteProcess = new Process(
                    ['git', '-C', $workspacePath, 'remote', 'set-url', 'origin', $cloneUrl],
                    null,
                    $env
                );
                $setRemoteProcess->run();
            }

            $process = new Process(
                ['git', '-c', 'core.longpaths=true', '-C', $workspacePath, 'pull', '--ff-only'],
                null,
                $env
            );
        } else {
            File::ensureDirectoryExists($workspaceRoot);
            if (File::isDirectory($workspacePath)) {
                File::deleteDirectory($workspacePath);
            }

            $process = new Process(
                [
                    'git',
                    '-c', 'core.longpaths=true',
                    'clone',
                    '--depth', '1',
                    '--branch', $repository->default_branch,
                    $cloneUrl,
                    $workspacePath,
                ],
                null,
                $env
            );
        }

        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            if (! File::isDirectory($gitDirectory) && File::isDirectory($workspacePath)) {
                @File::deleteDirectory($workspacePath);
            }

            $rawError = trim($process->getErrorOutput() ?: $process->getOutput());
            $sanitizedError = $this->sanitizeOutput($rawError, $token);
            $cleanError = $this->parseGitError($sanitizedError, $repository);

            throw ValidationException::withMessages([
                'repository' => [$cleanError],
            ]);
        }

        $commitProcess = new Process(['git', '-C', $workspacePath, 'rev-parse', 'HEAD'], null, $env);
        $commitProcess->run();

        $repository = $this->workspaceManager->attach($repository, $workspacePath);
        $metadata = $repository->metadata ?? [];
        $metadata['remote_url'] = $repository->url;
        $metadata['is_private'] = ! empty($token) || ($metadata['is_private'] ?? false);
        $metadata['workspace_commit'] = trim($commitProcess->getOutput());
        $metadata['workspace_synced_at'] = now()->toISOString();

        $repository->forceFill([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $user?->id,
            'metadata' => $metadata,
        ])->save();

        return [
            'repository' => $repository->refresh(),
            'workspace' => $this->workspaceManager->summarize($repository),
            'commit' => $metadata['workspace_commit'] ?? null,
        ];
    }

    /**
     * Download direct binary/archive file (.apk, .ipa, .aab, .zip) into workspace.
     */
    private function syncDirectFile(Repository $repository, ?User $user = null): array
    {
        $workspaceRoot = $this->workspaceManager->root();
        $workspaceName = Str::slug($repository->project_id.'-'.$repository->name);

        if ($workspaceName === '') {
            $workspaceName = 'repository-'.$repository->id;
        }

        $workspacePath = $workspaceRoot.DIRECTORY_SEPARATOR.$workspaceName;
        File::ensureDirectoryExists($workspacePath);

        // Determine filename
        $parsedPath = parse_url($repository->url, PHP_URL_PATH) ?? '';
        $filename = basename($parsedPath);
        if ($filename === '' || ! preg_match('/\.(apk|ipa|aab|zip)$/i', $filename)) {
            $filename = 'app.apk';
        }

        $targetFilePath = $workspacePath.DIRECTORY_SEPARATOR.$filename;

        // Download file via curl
        $downloadProcess = new Process([
            'curl', '-sL',
            '--connect-timeout', '15',
            '--max-time', '300',
            $repository->url,
            '-o', $targetFilePath,
        ]);
        $downloadProcess->setTimeout(300);
        $downloadProcess->run();

        if (! $downloadProcess->isSuccessful() || ! File::exists($targetFilePath) || File::size($targetFilePath) === 0) {
            @File::deleteDirectory($workspacePath);
            throw ValidationException::withMessages([
                'repository' => ['Gagal mengunduh file binary APK dari URL yang diberikan. Pastikan link dapat diakses secara publik.'],
            ]);
        }

        // Auto extract APK/ZIP contents so that manifest, assets, and classes are available for SAST engines
        if (class_exists(\ZipArchive::class) && in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['apk', 'zip', 'ipa', 'aab'], true)) {
            $zip = new \ZipArchive();
            if ($zip->open($targetFilePath) === true) {
                $zip->extractTo($workspacePath);
                $zip->close();
            }
        }

        $fileHash = md5_file($targetFilePath);
        $repository = $this->workspaceManager->attach($repository, $workspacePath);
        $metadata = $repository->metadata ?? [];
        $metadata['remote_url'] = $repository->url;
        $metadata['is_direct_file'] = true;
        $metadata['file_name'] = $filename;
        $metadata['file_size'] = File::size($targetFilePath);
        $metadata['workspace_commit'] = $fileHash;
        $metadata['workspace_synced_at'] = now()->toISOString();

        $repository->forceFill([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $user?->id,
            'metadata' => $metadata,
        ])->save();

        return [
            'repository' => $repository->refresh(),
            'workspace' => $this->workspaceManager->summarize($repository),
            'commit' => $fileHash,
        ];
    }

    /**
     * Build authenticated URL using Personal Access Token (PAT).
     */
    private function buildAuthenticatedUrl(string $url, ?string $token): string
    {
        if (empty($token)) {
            return $url;
        }

        if (Str::startsWith($url, 'https://github.com/')) {
            $path = Str::after($url, 'https://github.com/');

            return 'https://x-access-token:'.rawurlencode($token).'@github.com/'.$path;
        }

        return $url;
    }

    /**
     * Mask any access token from error output to prevent credential leaks.
     */
    private function sanitizeOutput(string $output, ?string $token): string
    {
        if (! empty($token)) {
            $output = str_replace($token, '***', $output);
        }

        return preg_replace('/https:\/\/x-access-token:[^@]+@/i', 'https://***@', $output) ?? $output;
    }

    /**
     * Clean and categorize Git error output into concise Indonesian outline.
     */
    private function parseGitError(string $rawError, Repository $repository): string
    {
        if (preg_match('/could not read Username|Authentication failed|Repository not found|Invalid username or password/i', $rawError)) {
            return 'Akses ditolak atau repositori GitHub bersifat privat. Pastikan URL benar dan sertakan GitHub Personal Access Token (PAT) yang valid.';
        }

        if (preg_match('/Remote branch .* not found|did not match any file/i', $rawError)) {
            return "Branch '{$repository->default_branch}' tidak ditemukan pada repositori GitHub ini.";
        }

        if (preg_match('/Filename too long|unable to create file/i', $rawError)) {
            return 'Checkout file gagal: Terdapat struktur nama file/path yang melebihi batas panjang karakter sistem (Windows MAX_PATH).';
        }

        if (preg_match('/timed out|Connection refused|Could not resolve host|Failed to connect/i', $rawError)) {
            return 'Koneksi jaringan ke GitHub gagal atau mengalami timeout saat mengunduh source code.';
        }

        // Strip noise progress
        $lines = explode("\n", $rawError);
        $cleanLines = array_filter($lines, function ($line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                return false;
            }
            if (Str::startsWith($trimmed, ['Updating files:', 'Receiving objects:', 'Resolving deltas:', 'Counting objects:', 'Compressing objects:', 'Cloning into'])) {
                return false;
            }

            return true;
        });

        $filtered = trim(implode(' ', $cleanLines));
        if ($filtered === '') {
            $filtered = 'Proses git clone gagal.';
        }

        return Str::limit($filtered, 250);
    }
}
