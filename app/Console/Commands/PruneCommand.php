<?php

namespace App\Console\Commands;

use App\Services\Scanning\SynthesisPayloadRetention;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'sentinel:prune';

    protected $description = 'Apply the retention policy in config/sentinel.php to stored scan data';

    public function handle(): int
    {
        $changed = SynthesisPayloadRetention::fromConfig()->apply();
        $this->info("synthesis_payload: {$changed} scan(s) ".config('sentinel.retention.synthesis_payload').'d after '.config('sentinel.retention.synthesis_payload_days').' days.');

        return self::SUCCESS;
    }
}
