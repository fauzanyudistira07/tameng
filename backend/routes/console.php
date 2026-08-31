<?php

use App\Models\ScanJob;
use App\Services\Engines\EngineRegistry;
use App\Services\Engines\EngineRunner;
use App\Services\RepositoryWorkspaceManager;
use App\Services\SimulatedWorker;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('secsys:worker-once', function (SimulatedWorker $worker) {
    $scanJob = ScanJob::query()->where('status', 'queued')->oldest('queued_at')->first();

    if (! $scanJob) {
        $this->info('No queued scan jobs.');

        return self::SUCCESS;
    }

    $processed = $worker->process($scanJob);

    $this->info("Processed {$processed->code} with status {$processed->status}.");

    return self::SUCCESS;
})->purpose('Process one queued scan job with the safe simulated worker.');

Artisan::command('secsys:engines', function (EngineRegistry $registry) {
    $this->table(
        ['Key', 'Name', 'Category', 'Risk', 'Runtime', 'Execution'],
        $registry->all()->map(fn (array $engine): array => [
            $engine['key'],
            $engine['name'],
            $engine['category'],
            $engine['risk_level'],
            $engine['runtime'],
            $engine['execution_mode'],
        ])->all()
    );

    return self::SUCCESS;
})->purpose('List registered SecSys engine adapter skeletons.');

Artisan::command('secsys:engine-once {scan_job_code} {engine_key}', function (EngineRunner $runner) {
    $scanJobCode = $this->argument('scan_job_code');
    $engineKey = $this->argument('engine_key');
    $scanJob = ScanJob::query()->where('code', $scanJobCode)->first();

    if (! $scanJob) {
        $this->error("Scan job {$scanJobCode} not found.");

        return self::FAILURE;
    }

    $scanRun = $runner->execute($scanJob, $engineKey);

    $this->info("Engine {$engineKey} finished with scan run status {$scanRun->status}.");

    if ($scanRun->failure_reason) {
        $this->line("Reason: {$scanRun->failure_reason}");
    }

    return self::SUCCESS;
})->purpose('Run one guarded real engine adapter for a scan job.');

Artisan::command('secsys:workspace-root', function (RepositoryWorkspaceManager $workspaceManager) {
    $this->info($workspaceManager->root());

    return self::SUCCESS;
})->purpose('Show the configured SecSys repository workspace root.');
