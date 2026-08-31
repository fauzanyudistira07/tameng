<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Engines\EngineRegistry;
use Illuminate\Http\JsonResponse;

class EngineController extends Controller
{
    public function index(EngineRegistry $registry): JsonResponse
    {
        return response()->json([
            'engines' => $registry->all()->values(),
        ]);
    }

    public function show(string $engineKey, EngineRegistry $registry): JsonResponse
    {
        return response()->json([
            'engine' => $registry->get($engineKey),
        ]);
    }
}
