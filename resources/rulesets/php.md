# PHP best practices

Applies to any PHP 8.1+ codebase. Framework-specific rules live in their own files.

## Types and structure
- `declare(strict_types=1)` in every file. Type every parameter, return and property; use union/nullable types instead of docblock-only types.
- Prefer `readonly` properties and constructor promotion for value objects and services. Prefer enums over string/int constants.
- One class per file, PSR-4 namespaces that match the directory, PSR-12 formatting (Pint or PHP-CS-Fixer, never hand-formatted).
- Keep functions under ~40 lines and files under ~400 lines. Extract when a method needs a comment to separate its sections.

## Errors
- Never swallow exceptions. A `catch` must rethrow, wrap in a domain exception, return an explicit failure value the caller checks, or document why ignoring is safe.
- Do not log-and-continue. If the caller cannot proceed, throw. If it can, return a result the caller must handle.
- Throw specific exceptions (`InvalidArgumentException`, domain exceptions), never bare `\Exception`.
- Do not use `@` error suppression.

## Dependencies
- Every imported vendor namespace must map to a package declared in `composer.json`. Remove imports that do not.
- Never rely on transitive dependencies directly; declare what you use.

## Security
- No `eval`, `create_function`, `preg_replace` with `/e`, `unserialize` on untrusted input, or shell functions with interpolated strings. Use `escapeshellarg` or Symfony Process with an argv array.
- Parameterise every SQL query. Hash passwords with `password_hash`/`Hash::make`, never md5/sha1.
- Secrets come from environment/config, never from source. Rotate anything found committed.

## Code hygiene
- No commented-out code, no `TODO` without an issue reference, no placeholder data (`John Doe`, `example.com`, lorem ipsum) in application code.
- Comments explain *why*, not *what*. Delete comments that restate the next line.
- Avoid near-duplicate functions: if two methods differ only in names and literals, extract one with parameters.
- Do not add `@phpstan-ignore`, `phpcs:ignore` or similar suppressions to make a tool pass; fix the code or narrow the rule in config with a justification.

## Testing
- Every bug fix ships with a test that fails before and passes after. Test behaviour through public interfaces; avoid mocking what you own.
- Run the full test suite before considering any change complete.
