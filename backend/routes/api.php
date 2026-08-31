<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthorizationController;
use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Api\EngineController;
use App\Http\Controllers\Api\FindingController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MyScanRequestController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RepositoryController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ScanJobController;
use App\Http\Controllers\Api\ScanProfileController;
use App\Http\Controllers\Api\ScopeController;
use App\Http\Controllers\Api\SystemOverviewController;
use App\Http\Controllers\Api\TargetController;
use App\Http\Controllers\Api\GitWebhookController;
use App\Http\Controllers\Api\SecurityEngineController;
use App\Http\Controllers\Api\UserManagementController;

Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
Route::get('/health', HealthController::class);
Route::post('/webhooks/git', [GitWebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', CurrentUserController::class);
    Route::get('/overview', SystemOverviewController::class);
    Route::get('/my/scan-requests', [MyScanRequestController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer');
    Route::post('/my/scan-requests', [MyScanRequestController::class, 'store'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer');
    Route::post('/my/scan-requests/{scanJob}/rerun', [MyScanRequestController::class, 'rerun'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer');

    // Dynamic 20 Security Engines Registry & Profiles
    Route::get('/security/engines', [SecurityEngineController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
    Route::get('/security/engines/{engine}', [SecurityEngineController::class, 'show'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
    Route::post('/security/engines/{engine}/toggle', [SecurityEngineController::class, 'toggle'])
        ->middleware('role:super_admin,security_admin');
    Route::post('/security/engines/{engine}/health-check', [SecurityEngineController::class, 'healthCheck'])
        ->middleware('role:super_admin,security_admin,security_analyst');
    Route::get('/security/scan-profiles', [SecurityEngineController::class, 'scanProfiles'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');

    Route::get('/engines', [SecurityEngineController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,auditor,viewer');
    Route::get('/engines/{engineKey}', [EngineController::class, 'show'])
        ->middleware('role:super_admin,security_admin,security_analyst,auditor,viewer');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('role:super_admin,security_admin,auditor');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('role:super_admin,security_admin');

    Route::get('/users', [UserManagementController::class, 'index'])->middleware('role:super_admin,security_admin');
    Route::post('/users', [UserManagementController::class, 'store'])->middleware('role:super_admin');
    Route::put('/users/{user}', [UserManagementController::class, 'update'])->middleware('role:super_admin');

    Route::get('/projects', [ProjectController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
    Route::post('/projects', [ProjectController::class, 'store'])
        ->middleware('role:super_admin,security_admin');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])
        ->middleware('role:super_admin,security_admin');

    Route::get('/repositories', [RepositoryController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
    Route::post('/repositories', [RepositoryController::class, 'store'])
        ->middleware('role:super_admin,security_admin');
    Route::put('/repositories/{repository}', [RepositoryController::class, 'update'])
        ->middleware('role:super_admin,security_admin');
    Route::post('/repositories/{repository}/verify', [RepositoryController::class, 'verify'])
        ->middleware('role:super_admin,security_admin');
    Route::post('/repositories/{repository}/workspace', [RepositoryController::class, 'attachWorkspace'])
        ->middleware('role:super_admin,security_admin');
    Route::post('/repositories/{repository}/clone-workspace', [RepositoryController::class, 'cloneWorkspace'])
        ->middleware('role:super_admin,security_admin');
    Route::delete('/repositories/{repository}/workspace', [RepositoryController::class, 'clearWorkspace'])
        ->middleware('role:super_admin,security_admin');

    Route::get('/targets', [TargetController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
    Route::post('/targets', [TargetController::class, 'store'])
        ->middleware('role:super_admin,security_admin');
    Route::put('/targets/{target}', [TargetController::class, 'update'])
        ->middleware('role:super_admin,security_admin');
    Route::post('/targets/{target}/verify', [TargetController::class, 'verify'])
        ->middleware('role:super_admin,security_admin');

    Route::get('/scopes', [ScopeController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,auditor,viewer');
    Route::post('/scopes', [ScopeController::class, 'store'])
        ->middleware('role:super_admin,security_admin');
    Route::put('/scopes/{scope}', [ScopeController::class, 'update'])
        ->middleware('role:super_admin,security_admin');

    Route::get('/scan-profiles', [ScanProfileController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,auditor,viewer');

    Route::get('/authorizations', [AuthorizationController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,auditor,viewer');
    Route::post('/authorizations', [AuthorizationController::class, 'store'])
        ->middleware('role:super_admin,security_admin');

    Route::get('/scan-jobs', [ScanJobController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,auditor,viewer');
    Route::post('/scan-jobs', [ScanJobController::class, 'store'])
        ->middleware('role:super_admin,security_admin');
    Route::post('/scan-jobs/{scanJob}/rerun', [ScanJobController::class, 'rerun'])
        ->middleware('role:super_admin,security_admin');
    Route::post('/scan-jobs/{scanJob}/process-simulated', [ScanJobController::class, 'process'])
        ->middleware('role:super_admin,security_admin');
    Route::post('/scan-jobs/{scanJob}/engines/{engineKey}/run-guarded', [ScanJobController::class, 'runGuardedEngine'])
        ->middleware('role:super_admin,security_admin');

    Route::get('/findings', [FindingController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
    Route::get('/findings/{finding}', [FindingController::class, 'show'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
    Route::get('/findings/{finding}/ai-remediation', [FindingController::class, 'aiRemediation'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
    Route::put('/findings/{finding}', [FindingController::class, 'update'])
        ->middleware('role:super_admin,security_admin,security_analyst');

    Route::get('/reports', [ReportController::class, 'index'])
        ->middleware('role:super_admin,security_admin,security_analyst,auditor,viewer');
    Route::post('/reports', [ReportController::class, 'store'])
        ->middleware('role:super_admin,security_admin,security_analyst');
    Route::get('/reports/{report}', [ReportController::class, 'show'])
        ->middleware('role:super_admin,security_admin,security_analyst,auditor,viewer');
    Route::get('/reports/{report}/download-pdf', [ReportController::class, 'downloadPdf'])
        ->middleware('role:super_admin,security_admin,security_analyst,developer,auditor,viewer');
});
