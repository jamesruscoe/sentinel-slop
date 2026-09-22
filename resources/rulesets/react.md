# React best practices

Read together with typescript.md or javascript.md. Applies to React, Next.js and Remix.

## Components
- Function components and hooks only. One component per file, named after the file, props typed with an explicit `Props` type.
- Keep components under ~150 lines. Extract child components or hooks when a component does more than one thing.
- Derive values during render instead of mirroring props into state. State holds only what cannot be derived.
- Lift state only as far as needed; prefer composition over prop drilling, and context only for genuinely global concerns.

## Hooks
- Follow the rules of hooks; every dependency array is complete (fix the lint warning, do not silence it).
- Effects are for synchronising with external systems. Data fetching belongs in a data layer (server components, loaders, a query library), not in ad-hoc `useEffect` calls.
- Custom hooks encapsulate reusable stateful logic and are named `useThing`.

## Rendering and data
- Stable `key` props from data identity, never array indexes for reorderable lists.
- Avoid `dangerouslySetInnerHTML`; when unavoidable, sanitise first.
- Memoise (`useMemo`, `useCallback`, `memo`) only after measuring; premature memoisation is noise.
- In Next.js, prefer server components and server actions for data; keep client components small and leaf-like.

## Errors and loading
- Every async boundary shows loading and error states. Use error boundaries for render errors.
- Never swallow errors in event handlers with an empty `catch` or a lone `console.error`.

## Hygiene
- No inline placeholder data (`John Doe`, `example.com`) in components; use fixtures in tests and stories.
- Accessibility is not optional: semantic elements, labels for inputs, keyboard support, alt text.

## Testing
- Test behaviour with Testing Library (queries by role/label), not implementation details.
- Run type-check, lint and tests before finishing any change.
