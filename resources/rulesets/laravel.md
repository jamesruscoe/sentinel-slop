# Laravel best practices

Read together with php.md.

## Architecture
- Controllers are thin: validate (Form Request), call a service or action, return a response. No queries or business rules in controllers, routes, or Blade.
- Business logic lives in dedicated classes (services/actions) that are unit-testable without HTTP.
- Eloquent models hold relationships, casts, scopes and small accessors only. Use `$fillable` or `#[Fillable]`; never `$guarded = []`.
- Use Form Requests for validation and Policies for authorisation. Never authorise inline with role string comparisons scattered around.
- Use enums for status columns (`casts()`), events for state transitions, jobs for anything slow, and the scheduler for periodic work.

## Database
- Always eager-load relationships you iterate over (`with`, `load`). Turn on `Model::preventLazyLoading()` outside production.
- Use query builder bindings; never interpolate values into `DB::raw`, `whereRaw` or `selectRaw`.
- Migrations are append-only once deployed. Add indexes for every foreign key and every column you filter or sort on.
- Use `chunkById`/`lazy()` for large result sets, and transactions around multi-table writes.

## HTTP and config
- Read configuration through `config()`, never `env()` outside config files.
- Return typed responses (`RedirectResponse`, `JsonResponse`, `View`). Use API Resources for JSON shaping.
- Rate-limit public endpoints and anything that triggers work. Verify webhook signatures before doing anything with the payload.
- Keep `.env.example` complete and commented; never commit `.env`.

## Queues and errors
- Jobs must be idempotent and small. Set `$tries`, `$timeout` and a `failed()` handler or a chain `catch`.
- Report exceptions with `report()` only when execution can genuinely continue; otherwise throw and let the handler render it.
- Log with context arrays, not interpolated strings. Never log tokens, passwords or full request bodies.

## Frontend glue
- Blade views contain no logic beyond conditionals and loops. Move formatting into accessors, view models or components.
- Escape output with `{{ }}`; use `{!! !!}` only for content you sanitised yourself.

## Testing
- Feature tests for every route (happy path plus authorisation failure). Use factories, `RefreshDatabase`, and fake queues, mail, events and HTTP.
- Run `php artisan test` (or `vendor/bin/pest`), Pint and PHPStan/Larastan before finishing any change.
