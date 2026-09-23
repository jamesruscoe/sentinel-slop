<?php

namespace App\Console\Commands;

use App\Services\Scanning\CodeRetention;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'sentinel:prune';

    protected $description = 'Apply the code retention policy in config/sentinel.php to stored scan data';

    public function handle(): int
    {
        $retention = CodeRetention::fromConfig();
        $changed = $retention->apply();

        $this->info(sprintf('Retention "%s" after %d days: %d synthesis payload(s) and %d finding snippet(s) changed.',
            $retention->mode(), $retention->days(), $changed['scans'], $changed['findings']));

        return self::SUCCESS;
    }
}
