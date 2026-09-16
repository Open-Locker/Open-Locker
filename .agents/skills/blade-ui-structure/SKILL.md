---
name: blade-ui-structure
description: Use for Laravel Blade and Alpine.js views, especially when a template contains multiple responsibilities or substantial inline UI logic. Keep views readable by extracting coherent sections into components or partials and keeping Alpine declarations small.
---

# Blade / Alpine.js UI Structure

Keep each Blade view focused on composing one page or UI responsibility. When a
template becomes large, contains repeated markup, or mixes several interactions,
split it into named Blade components or focused partials. Keep business rules
and display-data preparation in PHP classes, Livewire components, or services,
not in the template.

## Rule: Keep Alpine.js declarations small

Blade templates may use Alpine.js for small, local UI state and simple event handling.

Do not place substantial application logic inside `x-data`, `x-init`, `x-on:*`, or other Alpine attributes.

### Allowed inline Alpine

Inline Alpine is appropriate when the behavior is trivial and readable at a glance, for example:

```blade
<div x-data="{ open: false }">
    <button @click="open = !open">Toggle</button>

    <div x-show="open">
        ...
    </div>
</div>
```

When state needs multiple methods, asynchronous work, fallback handling, timers,
or coordination with other elements, move it into a named Alpine component/module
or a Livewire component. Do not turn an attribute into a second controller.

## Laravel Blade rules

### Preserve the component attribute bag

Reusable components should merge caller-provided attributes with defaults so
callers can add classes, IDs, ARIA attributes, and event handlers:

```blade
<div {{ $attributes->merge(['class' => 'alert alert-'.$type]) }}>
    {{ $message }}
</div>
```

### Emit component scripts once

When a component can render more than once, use a consistently named
`@pushOnce` block for its script. A plain `@push` would add duplicate script
tags for every instance.

### Choose explicit component interfaces

Use a Blade component when a reusable UI has explicit props, an attribute bag,
or slots. Use an include for a small partial that intentionally relies on the
current view data; pass an explicit data array when implicit variables would
hide the partial's dependencies.

### Centralize shared view data carefully

Use a view composer when named Blade views consistently need the same data.
Ensure the composer is compatible with every targeted view and avoid broad
wildcards when views have different data shapes. View composers apply to view
rendering only; they do not provide data to JSON or streamed responses.

### Return fragments for partial requests

When a client such as htmx or Turbo requests only part of a page, return a
named Blade fragment when the installed Laravel version supports it:

```php
return view('dashboard', compact('users'))
    ->fragmentIf($request->hasHeader('HX-Request'), 'user-list');
```

### Pass ancestor props with `@aware`

Use `@aware` when a nested component needs a prop explicitly passed to an
ancestor. It does not expose an ancestor's default prop unless that value was
also passed through the attribute bag.

## Component boundaries

- Extract repeated or semantically complete UI into `resources/views/components/`.
- Use a partial for one-off markup that only clarifies a large parent view.
- Give components explicit props and slots; avoid hidden lookups and duplicated state.
- Keep the top-level view responsible for layout, data wiring, and composition.
- Keep loops and conditionals simple by preparing complex display data before rendering.
- Preserve accessibility attributes and existing behavior while extracting markup.

## Refactoring workflow

1. Read the target view and nearby components to follow local conventions.
2. Identify separate visual responsibilities and the state each interaction owns.
3. Extract one coherent section at a time, passing explicit data through props or slots.
4. Move non-trivial Alpine handlers and state out of HTML attributes.
5. Render the affected screen and run the relevant backend or browser checks.
