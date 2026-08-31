<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScanProfile;
use Illuminate\Http\JsonResponse;

class ScanProfileController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'scan_profiles' => ScanProfile::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->get(),
        ]);
    }
}
