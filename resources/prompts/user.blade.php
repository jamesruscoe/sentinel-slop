Repository: {{ $repository }}
Slop score: {{ $score->score }}/100 (100 = clean). Lines of code: {{ $score->linesOfCode }}. Penalty density: {{ $score->density }} points per 1k lines.{{ $score->structurePenalty > 0 ? ' Structure (absence) findings cost '.$score->structurePenalty.' of those points.' : '' }}
@if ($score->criticalCapApplied)
The score is capped because malware-like patterns or committed secrets were found. Those come first.
@endif

Detected stack:
- Languages: {{ implode(', ', array_map(fn ($lang, $pct) => "$lang ($pct%)", array_keys($stack->languagePercentages()), $stack->languagePercentages())) ?: 'unknown' }}
- Frameworks, with the major version declared in the manifest where known: {{ $stack->describeFrameworks() ?: 'none detected' }}
- Runtimes declared: {{ $stack->describeRuntimes() ?: 'not declared' }}
- Tooling the project claims to use: {{ implode(', ', $stack->tooling) ?: 'none detected' }}
- Package managers: {{ implode(', ', $stack->packageManagers) ?: 'none detected' }}
- Analyser coverage: {{ \App\Scanning\Analysers\LanguageCoverage::sentence($stack, $analyserFailures ?? []) }} Weight the phases by each language's share of the code, not by how many findings its tools produced; say in the assessment when a language had no line-level analyser.

@if ($profile !== '')
## Repository profile

Structural facts from the file tree (no analyser involved). Counts are per area; "kinds" are inferred from file names and directories. The OBSERVATIONS at the end are for you to weigh; they are not findings.

{!! $profile !!}

@endif
## Findings

Findings by category ({{ $total }} in total, sent as {{ $included }} lines of which {{ $aggregated }} aggregate a repeated rule; {{ $omitted }} omitted for space, lowest severity first):
@foreach ($byCategory as $category => $count)
- {{ $category }}: {{ $count }}
@endforeach

Inline suppression comments: {{ $suppressions['count'] }} ({{ $suppressions['density'] }} per 1k lines){{ $suppressions['count'] > 0 ? ' by kind: '.implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($suppressions['by_kind']), $suppressions['by_kind'])) : '' }}. Treat a high suppression density as slop: suppressions should be removed by fixing the underlying issue unless a comment justifies them.

Findings (most severe first):
{!! $findings !!}
