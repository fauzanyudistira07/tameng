<?php

namespace App\Console\Commands;

use App\Models\SecurityEngine;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SyncEngineImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'secsys:sync-engines {--pull : Otomatis tarik image jika belum ada di lokal}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Periksa dan sinkronisasi status ketersediaan container image seluruh mesin pemindai keamanan TAMENG';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dockerBinary = config('secsys.docker_binary', 'docker');
        $autoPull = (bool) $this->option('pull');

        $this->info("Memeriksa status container image 20 Security Engine TAMENG...");

        $engines = SecurityEngine::orderBy('id')->get();
        $total = $engines->count();
        $availableCount = 0;
        $standbyCount = 0;

        foreach ($engines as $engine) {
            $image = $engine->container_image;

            if (! $image) {
                $this->line("  [ID {$engine->id}] {$engine->code}: Non-container binary ({$engine->binary_command})");
                $engine->status = 'AVAILABLE';
                $engine->last_health_check = now();
                $engine->save();
                $availableCount++;
                continue;
            }

            $inspect = new Process([$dockerBinary, 'image', 'inspect', $image]);
            $inspect->setTimeout(10);
            $inspect->run();

            if ($inspect->isSuccessful()) {
                $engine->status = 'AVAILABLE';
                $engine->last_health_check = now();
                $engine->save();
                $availableCount++;
                $this->info("  [OK] {$engine->code} -> {$image} (AVAILABLE)");
            } else {
                if ($autoPull) {
                    $this->warn("  [PULLING] {$engine->code} -> Mengunduh {$image}...");
                    $pull = new Process([$dockerBinary, 'pull', $image]);
                    $pull->setTimeout(600);
                    $pull->run();

                    if ($pull->isSuccessful()) {
                        $engine->status = 'AVAILABLE';
                        $engine->last_health_check = now();
                        $engine->save();
                        $availableCount++;
                        $this->info("  [PULL OK] {$engine->code} -> Berhasil diunduh (AVAILABLE)");
                        continue;
                    } else {
                        $this->error("  [PULL FAILED] {$engine->code} -> Gagal mengunduh: " . trim($pull->getErrorOutput()));
                    }
                }

                $engine->status = 'STANDBY';
                $engine->last_health_check = now();
                $engine->save();
                $standbyCount++;
                $this->warn("  [STANDBY] {$engine->code} -> Image belum di-cache secara lokal (STANDBY)");
            }
        }

        $this->newLine();
        $this->info("=== Hasil Sinkronisasi Security Engines ===");
        $this->line("Total Mesin   : {$total}");
        $this->line("Siap Digunakan: {$availableCount}");
        $this->line("Standby Image : {$standbyCount}");

        return Command::SUCCESS;
    }
}
