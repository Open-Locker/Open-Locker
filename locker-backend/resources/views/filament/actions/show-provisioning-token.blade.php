<div
    class="space-y-2"
    x-data="window.provisioningTokenCopy(@js($token), {
        errorTitle: @js(__('Could not copy provisioning token')),
        errorBody: @js(__('Copying was blocked. Select the token, then press Ctrl+C (or Cmd+C on macOS) to copy it manually.')),
        copyLabel: @js(__('Copy token')),
        copiedLabel: @js(__('Copied')),
    })"
>
    <div class="fi-input-wrp">
        <div
            class="fi-input-wrp-content-ctn"
            style="padding-block: 1rem; padding-inline: 1.25rem;"
        >
            <code
                class="fi-sc-text fi-font-mono select-all whitespace-pre-wrap break-all"
                data-testid="one-time-provisioning-token"
            >{{ $token }}</code>
        </div>
        <div
            class="fi-input-wrp-suffix"
            x-cloak
            x-show="canCopy"
        >
            <button
                class="fi-icon-btn fi-size-md"
                type="button"
                x-bind:aria-label="copied ? copiedLabel : copyLabel"
                x-bind:title="copied ? copiedLabel : copyLabel"
                x-on:click="copyToken()"
            >
                <x-filament::icon
                    x-cloak
                    icon="heroicon-m-clipboard"
                    x-show="!copied"
                    aria-hidden="true"
                />
                <x-filament::icon
                    x-cloak
                    icon="heroicon-m-check"
                    x-show="copied"
                    aria-hidden="true"
                />
            </button>
        </div>
    </div>
    <p
        class="text-sm text-gray-600 dark:text-gray-400"
        x-cloak
        x-show="!canCopy"
    >
        {{ __('Automatic copying is unavailable. Select the token, then press Ctrl+C (or Cmd+C on macOS) to copy it.') }}
    </p>
    <p class="fi-sr-only" role="status" aria-live="polite" x-text="copied ? copiedLabel : ''"></p>
</div>
