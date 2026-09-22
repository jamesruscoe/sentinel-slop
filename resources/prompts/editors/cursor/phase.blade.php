# Sentinel Slop fix-it prompt, phase {{ $phase['phase'] }} of {{ count($phases) }}: {!! $phase['title'] !!}

Repository: {{ $repository }}. Paste this into Cursor's agent chat with the repository open. Complete it fully before moving to phase {{ $phase['phase'] + 1 }}.

{!! $phase['body'] !!}

## Before you finish this phase

- Run the full test suite and any linters the project already has in the integrated terminal. Do not continue while anything fails.
- Make no changes outside the scope above. If you notice something else, mention it at the end instead of fixing it.
- Summarise what changed, file by file, and list anything you deliberately left alone and why.
