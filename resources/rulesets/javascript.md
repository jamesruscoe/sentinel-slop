# JavaScript best practices

Applies to browser and Node code. TypeScript and React have their own files.

## Language
- ES modules (`import`/`export`), `const` by default, `let` when reassigning, never `var`.
- Strict equality (`===`) everywhere except deliberate `== null` checks.
- `async`/`await` over raw promise chains; every awaited call sits in a `try`/`catch` or the error propagates to a caller that handles it.
- No `eval`, `new Function`, string arguments to `setTimeout`, or assigning untrusted strings to `innerHTML`.

## Errors
- Empty `catch` blocks are bugs. Either handle the error, rethrow it with context, or return a value the caller checks.
- `console.log` is not error handling and not a substitute for a TODO. Remove debugging output before finishing.
- Unhandled promise rejections must not exist: attach `.catch` or `await` inside a handled block.

## Dependencies
- Every bare import must be declared in `package.json`. Remove imports of packages that are not installed; do not guess package names.
- Prefer the platform (`fetch`, `URL`, `structuredClone`) over small utility packages.

## Structure
- Functions under ~50 lines, files under ~400 lines, no more than 4 levels of nesting. Extract early.
- One responsibility per module; name files after what they export.
- Do not duplicate blocks across files; extract a shared helper.

## Hygiene
- No commented-out code, no placeholder data in app code, no `TODO` without an issue reference.
- Comments explain intent and trade-offs, not what the next line does.
- No `eslint-disable` comments to make lint pass; fix the code or change the rule in config with a reason.

## Testing
- Unit tests for pure logic, integration tests for I/O boundaries. Tests live next to the code or in `__tests__`.
- Run the full test suite and the linter before considering any change complete.
