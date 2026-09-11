# Filament and Livewire

Resolve the installed Filament and Livewire versions from Composer before using
examples. The old generated instructions contained Filament 3 APIs; they are
not a reliable template for the current application.

Inspect sibling resources under `locker-backend/app/Filament/Resources`, their
pages and relation managers, and panel providers before adding UI. Follow their
schema signatures, component namespaces, and action placement. Use the installed
Filament generators when helpful, checking available arguments first.

- Build components with the framework's `make()` methods; use relationship-backed
  fields when they express the actual model relationship.
- Reuse existing services for domain mutations. Keep authorization enforced for
  both resource access and action execution, including record-level restrictions.
- Route user-facing text through the existing translation conventions.
- Prefer the existing resource/page organization over moving files to match a
  newer documentation example.

For UI behavior, follow existing PHPUnit classes using `Livewire::test()`;
authenticate the appropriate actor and select the panel when the test requires
it. Cover denied access, validation, action effects, and relevant table/form
behavior. Assert persisted state as well as visible notifications or redirects.
See [verification](verification.md) for the execution environment and checks.
