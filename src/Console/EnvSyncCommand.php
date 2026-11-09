<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Console;

use Illuminate\Console\Command;

final class EnvSyncCommand extends Command
{
    protected $signature = 'payments:env:sync {--dry-run} {--write}';

    protected $description = 'Append missing safe template keys without replacing existing values';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('write')) {
            $this->error('Choose --write or --dry-run.');

            return self::FAILURE;
        }
        $path = $this->laravel->environmentFilePath();
        $file = $this->option('write') ? fopen($path, 'c+') : null;
        if ($this->option('write') && (! $file || ! flock($file, LOCK_EX))) {
            $this->error('Cannot lock environment file.');

            return self::FAILURE;
        }
        try {
            $existing = $file ? stream_get_contents($file) : (is_file($path) ? file_get_contents($path) : '');
            preg_match_all('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m', $existing, $matches);
            $known = array_flip($matches[1]);
            $missing = [];
            foreach (file(__DIR__.'/../../.env.example', FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', $line, $m) && ! isset($known[$m[1]])) {
                    $missing[] = $line;
                    $this->line($m[1]);
                }
            }
            if ($file && $missing) {
                fseek($file, 0, SEEK_END);
                fwrite($file, ($existing !== '' && ! str_ends_with($existing, "\n") ? "\n" : '')."\n# Provider Payments safe defaults\n".implode("\n", $missing)."\n");
                fflush($file);
            }
            $this->info(count($missing).' missing keys'.($file ? ' appended.' : ' (dry run).'));

            return self::SUCCESS;
        } finally {
            if ($file) {
                flock($file, LOCK_UN);
                fclose($file);
            }
        }
    }
}
