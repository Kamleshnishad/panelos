<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Ifsnop\Mysqldump\Mysqldump;

/**
 * Dumps the MySQL database to storage/app/backups and prunes old dumps.
 *
 * Uses a PURE-PHP dumper (ifsnop/mysqldump-php) over PDO — the SAME driver the
 * app already uses to talk to MySQL. This avoids the mysqldump binary entirely,
 * which matters because the container's mariadb-client cannot authenticate to
 * MySQL 8's caching_sha2_password. Scheduled daily in Console\Kernel.
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
        $host = $conn['host'] ?? '127.0.0.1';
        $port = $conn['port'] ?? '3306';
        $dbnm = $conn['database'];
        $dsn  = "mysql:host={$host};port={$port};dbname={$dbnm};charset=" . ($conn['charset'] ?? 'utf8mb4');

        $this->info('Backing up "' . $dbnm . '" → ' . $file);

        try {
            $dump = new Mysqldump($dsn, $conn['username'] ?? 'root', (string) ($conn['password'] ?? ''), [
                'add-drop-table'        => true,
                'single-transaction'    => true,
                'routines'              => true,
                'default-character-set' => Mysqldump::UTF8MB4,
            ]);
            $dump->start($file);
        } catch (\Throwable $e) {
            @unlink($file);
            $this->error('Backup failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!is_file($file) || filesize($file) === 0) {
            @unlink($file);
            $this->error('Backup failed: empty dump produced.');
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
