@php
    use Filament\Facades\Filament;

    $user = Filament::auth()->user();
    $name = $user ? Filament::getUserName($user) : null;
    $email = $user?->email;

    // Show the name, plus the email on a second line when it adds information.
    $tooltip = collect([$name, $email])
        ->filter()
        ->unique()
        ->implode("\n");
@endphp

@if (filled($tooltip))
    {{-- The topbar user menu only renders an avatar; expose the user's name and
         email as a hover tooltip on the trigger (like the old dashboard widget). --}}
    <div
        x-data="{}"
        x-init="$nextTick(() => document.querySelector('.fi-user-menu-trigger')?.setAttribute('title', @js($tooltip)))"
        class="hidden"
    ></div>
@endif
