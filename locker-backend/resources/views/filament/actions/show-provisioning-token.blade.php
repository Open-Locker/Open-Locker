<div class="space-y-3">
    <code
        class="block break-all rounded-lg bg-gray-100 p-4 text-sm dark:bg-gray-800"
        data-testid="one-time-provisioning-token"
    >{{ $token }}</code>

    <x-filament::button
        type="button"
        icon="heroicon-m-clipboard"
        x-on:click="navigator.clipboard.writeText(@js($token))"
    >
        {{ __('Copy token') }}
    </x-filament::button>
</div>
