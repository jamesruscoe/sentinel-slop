<?php

use App\Scanning\Analysers\EslintAnalyser;
use App\Scanning\Analysers\GitleaksAnalyser;
use App\Scanning\Analysers\JscpdAnalyser;
use App\Scanning\Analysers\PhpStanAnalyser;
use App\Scanning\Analysers\PintAnalyser;
use App\Scanning\Analysers\ProcessAnalyser;
use App\Scanning\Analysers\SemgrepAnalyser;
use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\AnalyserOptions;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Exceptions\AnalyserFailedException;
use App\Scanning\Fetch\ScanWorkspace;
use App\Scanning\Process\ToolLocator;
use App\Scanning\Support\FileWalker;
use Tests\Support\Workspaces;

/**
 * Real scans live under storage/app/scans, inside Sentinel Slop's own tree,
 * which its .gitignore excludes. Every other test uses a temp directory with
 * no parent ignore file, and jscpd 5 (which walks parent .gitignore files)
 * analysed zero files on every real scan while the suite stayed green. These
 * tests run each analyser exactly where production runs it.
 */
function productionWorkspaceFromFixture(string $fixture): ScanWorkspace
{
    $base = base_path('storage/app/scans');
    if (! is_dir($base)) {
        mkdir($base, 0700, true);
    }
    $workspace = Workspaces::register(ScanWorkspace::create($base, 'test-'.bin2hex(random_bytes(6))));
    foreach (FileWalker::walk(fixturePath($fixture)) as $entry) {
        $target = $workspace->repoPath().'/'.$entry['path'];
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        copy($entry['absolute'], $target);
    }

    return $workspace;
}

function semgrepAnalyser(string $rules, string $name): SemgrepAnalyser
{
    return new SemgrepAnalyser(app(ProcessRunner::class), app(ToolLocator::class), app(AnalyserOptions::class), $rules, $name);
}

test('the production workspace path is excluded by our own .gitignore', function () {
    $workspace = productionWorkspaceFromFixture('sloppy-ts');

    expect(str_starts_with(str_replace(chr(92), '/', $workspace->root), str_replace(chr(92), '/', base_path('storage/app/scans'))))->toBeTrue()
        ->and(trim((string) shell_exec('git -C '.escapeshellarg(base_path()).' check-ignore '.escapeshellarg($workspace->repoPath().'/src/dupA.ts'))))->not->toBe('');
});

test('every PHP analyser sees the files of a workspace on the production path', function () {
    $workspace = productionWorkspaceFromFixture('sloppy-laravel');
    $path = $workspace->repoPath();

    $phpstan = array_map(fn (Finding $f) => (string) $f->ruleId, app(PhpStanAnalyser::class)->run($path)->all());
    $pint = array_map(fn (Finding $f) => $f->filePath, app(PintAnalyser::class)->run($path)->all());
    $gitleaks = array_map(fn (Finding $f) => $f->filePath, app(GitleaksAnalyser::class)->run($path)->all());
    $quality = semgrepAnalyser('quality', 'semgrep')->run($path);

    expect($phpstan)->toContain('return.type')
        ->and($pint)->toContain('app/Models/Order.php')
        ->and($gitleaks)->toContain('app/Services/PaymentClient.php')
        ->and($quality)->toBeInstanceOf(FindingCollection::class);
});

test('every JavaScript analyser sees the files of a workspace on the production path', function () {
    $workspace = productionWorkspaceFromFixture('sloppy-ts');
    $path = $workspace->repoPath();

    $eslint = array_map(fn (Finding $f) => (string) $f->ruleId, app(EslintAnalyser::class)->run($path)->all());
    $jscpd = array_map(fn (Finding $f) => $f->filePath, app(JscpdAnalyser::class)->run($path)->all());
    $slop = semgrepAnalyser('slop', 'semgrep-slop')->run($path);

    expect($eslint)->toContain('eqeqeq')
        ->and($jscpd)->toContain('src/dupB.ts')
        ->and($slop->count())->toBeGreaterThan(0);
});

test('the malware rules see the files of a workspace on the production path', function () {
    $workspace = productionWorkspaceFromFixture('backdoor-samples');
    $rules = array_map(fn (Finding $f) => (string) $f->ruleId, semgrepAnalyser('malware', 'semgrep-malware')->run($workspace->repoPath())->all());

    expect($rules)->toContain('sentinel.malware.php.eval-of-decoded-payload');
});

test('an analyser that reports scanning nothing while matching files exist fails loudly instead of returning no findings', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/a.ts', "export const a = 1\n");

    $analyser = new class(app(ProcessRunner::class), app(ToolLocator::class), app(AnalyserOptions::class)) extends ProcessAnalyser
    {
        public function name(): string
        {
            return 'probe';
        }

        public function supports(Stack $stack): bool
        {
            return true;
        }

        public function run(string $path): FindingCollection
        {
            return new FindingCollection;
        }

        public function probe(string $path, array $extensions, int $scanned): void
        {
            $this->assertCoverage($path, $extensions, $scanned, '0 files scanned');
        }
    };

    $analyser->probe($workspace->repoPath(), ['ts'], 3);
    $analyser->probe($workspace->repoPath(), ['php'], 0);

    expect(fn () => $analyser->probe($workspace->repoPath(), ['ts'], 0))
        ->toThrow(AnalyserFailedException::class, 'probe reported 0 files scanned but the repository has 1 ts file(s)');
});
