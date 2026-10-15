<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\JobStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Membersihkan data pekerjaan audit dan direktori artefak yang telah kedaluwarsa';

    public function handle(JobStore $store): int
    {
        $this->info('Membersihkan pekerjaan audit dan direktori artefak yang kedaluwarsa...');

        $expired = $store->deleteExpired();
        $count = 0;

        foreach ($expired as $item) {
            $dir = $item['artifact_dir'] ?? $item['artifactDir'];
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
            $count++;
        }

        $this->info("Berhasil menghapus {$count} pekerjaan audit kedaluwarsa beserta artefaknya.");

        return 0;
    }
}
