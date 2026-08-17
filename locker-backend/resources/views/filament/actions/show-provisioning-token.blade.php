<div
    class="space-y-2"
    x-data="{ copied: false, copiedTimeout: null }"
>
    <div class="flex items-center gap-2">
        <code
            class="min-w-0 flex-1 select-all whitespace-pre-wrap break-all rounded-lg bg-gray-100 p-4 font-mono text-sm dark:bg-gray-800"
            data-testid="one-time-provisioning-token"
        >{{ $token }}</code>

        <x-filament::icon-button
            color="gray"
            icon="heroicon-m-clipboard"
            :label="__('Copy token')"
            :tooltip="__('Copy token')"
            type="button"
            x-on:click="
                navigator.clipboard.writeText(@js($token)).then(() => {
                    copied = true
                    clearTimeout(copiedTimeout)
                    copiedTimeout = setTimeout(() => copied = false, 1500)
                })
            "
        />
    </div>

    <p
        class="text-sm text-gray-600 dark:text-gray-400"
        role="status"
        x-cloak
        x-show="copied"
        x-transition.opacity
    >
        {{ __('Copied') }}
    </p>
</div>
