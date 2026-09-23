# Sentinel Slop fix-it prompt, phase {{ $phase['phase'] }} of {{ count($phases) }}: {!! $phase['title'] !!}

Repository: {{ $repository }}. Paste this into Claude Code in the repository root. {{ $phase['phase'] < count($phases) ? 'Complete it fully before moving to phase '.($phase['phase'] + 1).'.' : 'This is the final phase.' }}

{!! $phase['body'] !!}

## Before you finish this phase

- Run the full test suite and any linters the project already has. Do not continue while anything fails.
- Make no changes outside the scope above. If you notice something else, note it in your final message instead of fixing it.
- Summarise what changed, file by file, and list anything you deliberately left alone and why.
