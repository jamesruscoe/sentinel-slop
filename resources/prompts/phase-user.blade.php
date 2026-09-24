Repository: {{ $repository }}
Slop score: {{ $score->score }}/100 (100 = clean). Lines of code: {{ $score->linesOfCode }}.

Detected stack:
- Languages: {{ implode(', ', array_map(fn ($lang, $pct) => "$lang ($pct%)", array_keys($stack->languagePercentages()), $stack->languagePercentages())) ?: 'unknown' }}
- Frameworks: {{ $stack->describeFrameworks() ?: 'none detected' }}
- Runtimes declared: {{ $stack->describeRuntimes() ?: 'not declared' }}
- Analyser coverage: {{ \App\Scanning\Analysers\LanguageCoverage::sentence($stack, $analyserFailures ?? []) }}

## The review's summary

{!! $assessment['summary'] !!}

## The plan (all phases, in order)

@foreach ($outline as $entry)
{{ $entry['phase'] }}. {{ $entry['title'] }}{{ $entry['goal'] !== '' ? ' — '.$entry['goal'] : '' }}
@endforeach

## This phase: {{ $phase['phase'] }} of {{ count($outline) }}

Title: {{ $phase['title'] }}
Goal: {{ $phase['goal'] !== '' ? $phase['goal'] : 'not stated' }}
Addresses: {{ implode('; ', $phase['addresses']) ?: 'not stated' }}

@if ($profile !== '')
## Repository profile

Structural facts from the file tree (no analyser involved). The OBSERVATIONS at the end are for you to weigh; they are not findings.

{!! $profile !!}

@endif
## Findings assigned to this phase

{{ $included }} finding lines were assigned to this phase by the review{{ $unassigned > 0 ? ', plus '.$unassigned.' that the review left unassigned and that fit its scope' : '' }}. Lines beginning [Fn] are the ids the review used.

{!! $findings !!}
