<?php

namespace App\Console\Commands;

use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\ProcessSpec;
use App\Scanning\Process\ToolLocator;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'sentinel:doctor';

    protected $description = 'Check that every analyser binary is installed and report its version';

    public function handle(ToolLocator $tools, ProcessRunner $runner): int
    {
        $ok = true;
        $rows = [];

        foreach ($tools->report() as $tool => $status) {
            if (! $status['available']) {
                $ok = false;
                $rows[] = [$tool, 'MISSING', $status['error']];

                continue;
            }

            $rows[] = [$tool, 'ok', $this->version($runner, $tool, $status['command'] ?? [])];
        }

        $this->table(['Tool', 'Status', 'Detail'], $rows);
        $this->line('Scan storage: '.config('sentinel.scan_storage_path'));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $command
     */
    private function version(ProcessRunner $runner, string $tool, array $command): string
    {
        $flag = $tool === 'gitleaks' ? 'version' : '--version';
        $result = $runner->run(new ProcessSpec([...$command, $flag], base_path(), 60));
        $line = trim((string) strtok(trim($result->stdout.$result->stderr), "\n"));

        return $line === '' ? "exit {$result->exitCode}" : substr($line, 0, 80);
    }
}
