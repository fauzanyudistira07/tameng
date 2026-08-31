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
            'details' => [
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

        $process = new Process([$dockerBinary, 'image', 'inspect', $image]);
        $process->setTimeout(10);
        $process->run();

        $isAvailable = $process->isSuccessful();
        $engine->status = $isAvailable ? 'AVAILABLE' : 'DEGRADED';
        $engine->last_health_check = now();
        $engine->save();

        return response()->json([
            'message' => $isAvailable ? "Engine image {$image} terverifikasi tersedia." : "Image {$image} belum di-pull di docker daemon.",
            'data' => [
                'engine' => $engine->code,
                'status' => $engine->status,
                'image_present' => $isAvailable,
                'last_health_check' => $engine->last_health_check,
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
