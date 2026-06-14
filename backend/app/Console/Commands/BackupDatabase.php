<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Dumps the MySQL database to storage/app/backups and prunes old dumps.
 * mysqldump must be on PATH, or set MYSQLDUMP_PATH in .env (e.g. XAMPP:
 * C:\xampp\mysql\bin\mysqldump.exe). Scheduled daily in Console\Kernel.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep=14 : Number of recent backups to retain}';
    protected $description = 'Create a timestamped SQL dump of the database and prune old backups';

    public function handle(): int
    {
        $conn = config('database.connections.' . config('database.default'));
        if (($conn['driver'] ?? null) !== 'mysql') {
            $this->error('db:backup currently supports MySQL only.');
            return self::FAILURE;
        }

        $dir = storage_path('app/backups');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $file = $dir . DIRECTORY_SEPARATOR . 'panelos-' . now()->format('Ymd-His') . '.sql';
        $dump = env('MYSQLDUMP_PATH', 'mysqldump');

        $args = [
            $dump,
            '--host=' . ($conn['host'] ?? '127.0.0.1'),
            '--port=' . ($conn['port'] ?? '3306'),
            '--user=' . ($conn['username'] ?? 'root'),
            '--single-transaction',
            '--skip-lock-tables',
            '--routines',
            '--result-file=' . $file,
            $conn['database'],
        ];

        $this->info('Backing up "' . $conn['database'] . '" → ' . $file);

        // Pass the password via env so it never appears in the process list.
        $result = Process::timeout(600)
            ->env(['MYSQL_PWD' => (string) ($conn['password'] ?? '')])
            ->run($args);

        if (!$result->successful() || !is_file($file) || filesize($file) === 0) {
            @unlink($file);
            $this->error('Backup failed: ' . trim($result->errorOutput() ?: $result->output() ?: 'unknown error'));
            $this->line('Tip: ensure mysqldump is installed and on PATH, or set MYSQLDUMP_PATH in .env.');
            return self::FAILURE;
        }

        $size = round(filesize($file) / 1024, 1);
        $this->info("Backup complete ({$size} KB).");

        $this->prune((int) $this->option('keep'), $dir);
        return self::SUCCESS;
    }

    /** Keep only the most recent N dumps. */
    private function prune(int $keep, string $dir): void
    {
        if ($keep < 1) return;
        $files = glob($dir . DIRECTORY_SEPARATOR . 'panelos-*.sql') ?: [];
        if (count($files) <= $keep) return;

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a)); // newest first
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            $this->line('Pruned old backup: ' . basename($old));
        }
    }
}
