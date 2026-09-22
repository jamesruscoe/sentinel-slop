# TypeScript best practices

Read together with javascript.md.

## Types
- `strict: true` in `tsconfig.json`. Never weaken it to make errors go away.
- No `any`. Use `unknown` at boundaries and narrow it; use generics for reusable code. `as any` and double casts (`as unknown as T`) are red flags.
- No `@ts-ignore`; `@ts-expect-error` only with a comment explaining the reason and a link.
- Prefer `type` aliases and discriminated unions for data, `interface` for object contracts that will be implemented.
- Return types on exported functions. Let inference handle locals.

## Data and errors
- Validate external data (API responses, form input, `JSON.parse`) at the boundary with a schema or type guard; never cast it.
- Make impossible states unrepresentable: use unions instead of optional flags that must be kept in sync.
- Throw `Error` subclasses with a `cause`; never throw strings.

## Modules
- Use `import type` for type-only imports. Avoid barrel files that re-export everything; they slow builds and hide cycles.
- Path aliases must be declared in `tsconfig.json` `paths` and the bundler config, and used consistently.

## Hygiene
- Delete unused exports, parameters and variables (prefix intentionally unused parameters with `_`).
- Do not keep both a `.js` and a `.ts` version of the same module.

## Testing
- Type-check (`tsc --noEmit`), lint and run the test suite before finishing any change.
