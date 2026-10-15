<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AksaraConfig;
use App\Services\AuditWorkerInterface;
use App\Services\JobStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditWorkCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:work
                            {--timeout= : Batas waktu audit dalam milidetik}
                            {--once : Jalankan hanya satu audit lalu berhenti}
                            {--sleep=1000 : Jeda tidur dalam milidetik saat antrean kosong}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menjalankan worker pemroses antrean audit aksesibilitas AksaraNetra';

    public function handle(
        JobStore $store,
        AuditWorkerInterface $worker,
        AksaraConfig $config
    ): int {
        $this->info('Memulai worker audit AksaraNetra (konkurensi: 1)...');

        // Pemulihan job yang terinterupsi saat restart/crash
        $recovered = $worker->recover();
        if ($recovered > 0) {
            $this->info("Memulihkan {$recovered} pekerjaan audit yang terinterupsi.");
        }

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($worker): void {
                $this->info('Menerima sinyal SIGINT, menghentikan worker...');
                $worker->stop();
            });
            pcntl_signal(SIGTERM, function () use ($worker): void {
                $this->info('Menerima sinyal SIGTERM, menghentikan worker...');
                $worker->stop();
            });
        }

        $lastCleanup = microtime(true);
        $cleanupIntervalSeconds = $config->cleanupIntervalMs / 1000;
        $sleepMicroseconds = max(50_000, ((int) ($this->option('sleep') ?: 1000)) * 1000);

        while (!$worker->isStopped()) {
            if ((microtime(true) - $lastCleanup) >= $cleanupIntervalSeconds) {
                $lastCleanup = microtime(true);
                $this->performCleanup($store);
            }

            $processed = $worker->processNext();

            if ($this->option('once')) {
                break;
            }

            if (!$processed) {
                usleep($sleepMicroseconds);
            }
        }

        $this->info('Worker audit AksaraNetra selesai.');

        return 0;
    }

    private function performCleanup(JobStore $store): void
    {
        $expired = $store->deleteExpired();
        foreach ($expired as $item) {
            $dir = $item['artifact_dir'] ?? $item['artifactDir'];
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }
    }
}
