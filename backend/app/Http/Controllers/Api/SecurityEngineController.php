<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ScanProfileEngine;
use App\Models\SecurityEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class SecurityEngineController extends Controller
{
    /**
     * List all registered security engines with their domain, resource class, and active status.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SecurityEngine::with(['activeVersion', 'versions'])->orderBy('domain')->orderBy('code');

        if ($request->filled('domain')) {
            $query->where('domain', strtoupper($request->query('domain')));
        }

        if ($request->filled('resource_class')) {
            $query->where('resource_class', strtoupper($request->query('resource_class')));
        }

        if ($request->filled('enabled')) {
            $query->where('enabled', filter_var($request->query('enabled'), FILTER_VALIDATE_BOOLEAN));
        }

        $engines = $query->get();

        $domainCounts = SecurityEngine::selectRaw('domain, count(*) as count')
            ->groupBy('domain')
            ->pluck('count', 'domain');

        $resourceCounts = SecurityEngine::selectRaw('resource_class, count(*) as count')
            ->groupBy('resource_class')
            ->pluck('count', 'resource_class');

        return response()->json([
            'data' => $engines,
            'summary' => [
                'total_engines' => $engines->count(),
                'active_engines' => $engines->where('enabled', true)->count(),
                'domain_breakdown' => $domainCounts,
                'resource_breakdown' => $resourceCounts,
            ],
        ]);
    }

    /**
     * Show detail of a single security engine.
     */
    public function show(SecurityEngine $engine): JsonResponse
    {
        $engine->load(['versions', 'profileEngines']);

        return response()->json([
            'data' => $engine,
        ]);
    }

    /**
     * Toggle enabled status of a security engine.
     */
    public function toggle(Request $request, SecurityEngine $engine): JsonResponse
    {
        $engine->enabled = ! $engine->enabled;
        $engine->status = $engine->enabled ? 'AVAILABLE' : 'DISABLED';
        $engine->save();

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'security_engine.toggle',
            'result' => 'success',
            'actor_ip' => $request->ip(),
            'target_type' => 'security_engine',
            'target_id' => $engine->id,
            'metadata' => [
                'engine_code' => $engine->code,
                'enabled' => $engine->enabled,
                'status' => $engine->status,
            ],
        ]);

        return response()->json([
            'message' => "Security engine {$engine->name} berhasil di-".($engine->enabled ? 'aktifkan' : 'nonaktifkan').'.',
            'data' => $engine,
        ]);
    }

    /**
     * Perform preflight container health check on the specified engine.
     */
    public function healthCheck(Request $request, SecurityEngine $engine): JsonResponse
    {
        $dockerBinary = config('secsys.docker_binary', 'docker');
        $image = $engine->container_image;

        if (! $image) {
            $engine->status = 'AVAILABLE';
            $engine->last_health_check = now();
            $engine->save();

            return response()->json([
                'message' => "Mesin {$engine->name} menggunakan binary host ({$engine->binary_command}) dan siap digunakan.",
                'data' => [
                    'engine' => $engine->code,
                    'status' => $engine->status,
                    'image_present' => true,
                    'last_health_check' => $engine->last_health_check,
                ],
            ]);
        }

        $process = new Process([$dockerBinary, 'image', 'inspect', $image]);
        $process->setTimeout(10);
        $process->run();

        $isAvailable = $process->isSuccessful();

        if ($isAvailable) {
            $engine->status = 'AVAILABLE';
            $engine->last_health_check = now();
            $engine->save();

            return response()->json([
                'message' => "Engine image {$image} terverifikasi siap dan aktif.",
                'data' => [
                    'engine' => $engine->code,
                    'status' => $engine->status,
                    'image_present' => true,
                    'last_health_check' => $engine->last_health_check,
                ],
            ]);
        }

        // Image not present locally: attempt auto_pull if requested
        if ($request->boolean('auto_pull', false)) {
            $pullProcess = new Process([$dockerBinary, 'pull', $image]);
            $pullProcess->setTimeout(300);
            $pullProcess->run();

            if ($pullProcess->isSuccessful()) {
                $engine->status = 'AVAILABLE';
                $engine->last_health_check = now();
                $engine->save();

                return response()->json([
                    'message' => "Image {$image} berhasil diunduh dan mesin siap digunakan.",
                    'data' => [
                        'engine' => $engine->code,
                        'status' => $engine->status,
                        'image_present' => true,
                        'last_health_check' => $engine->last_health_check,
                    ],
                ]);
            }
        }

        // Do not degrade blindly: mark as STANDBY if image is not yet cached locally
        $engine->status = 'STANDBY';
        $engine->last_health_check = now();
        $engine->save();

        return response()->json([
            'message' => "Image {$image} belum di-cache secara lokal. Gunakan tombol 'Tarik Image' untuk mengunduh.",
            'data' => [
                'engine' => $engine->code,
                'status' => $engine->status,
                'image_present' => false,
                'last_health_check' => $engine->last_health_check,
            ],
        ]);
    }

    /**
     * Pull container image for the specified engine on demand.
     */
    public function pullImage(Request $request, SecurityEngine $engine): JsonResponse
    {
        $dockerBinary = config('secsys.docker_binary', 'docker');
        $image = $engine->container_image;

        if (! $image) {
            return response()->json([
                'message' => "Mesin {$engine->name} tidak memerlukan docker image.",
                'data' => $engine,
            ]);
        }

        $engine->status = 'UPDATING';
        $engine->save();

        $process = new Process([$dockerBinary, 'pull', $image]);
        $process->setTimeout(600);
        $process->run();

        if ($process->isSuccessful()) {
            $engine->status = 'AVAILABLE';
            $engine->last_health_check = now();
            $engine->save();

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'security_engine.pull_image',
                'result' => 'success',
                'actor_ip' => $request->ip(),
                'target_type' => 'security_engine',
                'target_id' => $engine->id,
                'metadata' => [
                    'engine_code' => $engine->code,
                    'image' => $image,
                ],
            ]);

            return response()->json([
                'message' => "Image {$image} berhasil diunduh. Mesin {$engine->name} siap digunakan.",
                'data' => [
                    'engine' => $engine->code,
                    'status' => $engine->status,
                    'image_present' => true,
                    'last_health_check' => $engine->last_health_check,
                ],
            ]);
        }

        $engine->status = 'STANDBY';
        $engine->save();

        return response()->json([
            'message' => "Gagal mengunduh image {$image}: " . trim($process->getErrorOutput() ?: $process->getOutput()),
            'data' => [
                'engine' => $engine->code,
                'status' => $engine->status,
                'image_present' => false,
                'last_health_check' => $engine->last_health_check,
            ],
        ], 422);
    }

    /**
     * Batch sync health checks across all security engines.
     */
    public function syncAll(Request $request): JsonResponse
    {
        $dockerBinary = config('secsys.docker_binary', 'docker');
        $engines = SecurityEngine::all();
        $synced = 0;
        $available = 0;

        foreach ($engines as $engine) {
            $image = $engine->container_image;

            if (! $image) {
                $engine->status = 'AVAILABLE';
                $engine->last_health_check = now();
                $engine->save();
                $available++;
                $synced++;
                continue;
            }

            $process = new Process([$dockerBinary, 'image', 'inspect', $image]);
            $process->setTimeout(5);
            $process->run();

            $isOk = $process->isSuccessful();
            $engine->status = $isOk ? 'AVAILABLE' : 'STANDBY';
            $engine->last_health_check = now();
            $engine->save();

            if ($isOk) {
                $available++;
            }
            $synced++;
        }

        return response()->json([
            'message' => "Sinkronisasi selesai: {$available} dari {$synced} mesin terverifikasi aktif.",
            'data' => [
                'total_synced' => $synced,
                'available_count' => $available,
            ],
        ]);
    }

    /**
     * List all scan profiles with their mapped engines.
     */
    public function scanProfiles(): JsonResponse
    {
        $mappings = ScanProfileEngine::with('securityEngine')->orderBy('execution_order')->get();
        $grouped = $mappings->groupBy('profile_code');

        $profiles = [];
        foreach ($grouped as $code => $items) {
            $profiles[] = [
                'code' => $code,
                'name' => str_replace('_', ' ', $code),
                'engine_count' => $items->count(),
                'engines' => $items->map(fn ($item) => [
                    'id' => $item->securityEngine?->id,
                    'code' => $item->securityEngine?->code,
                    'name' => $item->securityEngine?->name,
                    'domain' => $item->securityEngine?->domain,
                    'resource_class' => $item->securityEngine?->resource_class,
                    'is_required' => $item->is_required,
                ]),
            ];
        }

        return response()->json([
            'data' => $profiles,
        ]);
    }
}
