Repository: {{ $repository }}
Slop score: {{ $score->score }}/100 (100 = clean). Lines of code: {{ $score->linesOfCode }}. Penalty density: {{ $score->density }} points per 1k lines.
@if ($score->criticalCapApplied)
The score is capped because malware-like patterns or committed secrets were found. Phase 2 is the priority.
@endif

Detected stack:
- Languages: {{ implode(', ', array_map(fn ($lang, $pct) => "$lang ($pct%)", array_keys($stack->languagePercentages()), $stack->languagePercentages())) ?: 'unknown' }}
- Frameworks, with the major version declared in the manifest where known: {{ $stack->describeFrameworks() ?: 'none detected' }}
- Runtimes declared: {{ $stack->describeRuntimes() ?: 'not declared' }}
- Tooling the project claims to use: {{ implode(', ', $stack->tooling) ?: 'none detected' }}
- Package managers: {{ implode(', ', $stack->packageManagers) ?: 'none detected' }}

Findings by category ({{ $included }} shown, {{ $omitted }} omitted for space, lowest severity first omitted):
@foreach ($byCategory as $category => $count)
- {{ $category }}: {{ $count }}
@endforeach

Inline suppression comments: {{ $suppressions['count'] }} ({{ $suppressions['density'] }} per 1k lines){{ $suppressions['count'] > 0 ? ' by kind: '.implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($suppressions['by_kind']), $suppressions['by_kind'])) : '' }}. Treat a high suppression density as slop: suppressions should be removed by fixing the underlying issue (phase 5) unless a comment justifies them.

Findings (most severe first):
{!! $findings !!}
