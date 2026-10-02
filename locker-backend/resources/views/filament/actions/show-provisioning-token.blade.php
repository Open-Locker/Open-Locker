<div
    class="space-y-2"
    x-data="window.provisioningTokenCopy(@js($token), {
        errorTitle: @js(__('Could not copy provisioning token')),
        errorBody: @js(__('Clipboard access requires HTTPS or localhost. Select the token, then press Ctrl+C (or Cmd+C on macOS) to copy it manually.')),
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
        <div class="fi-input-wrp-suffix">
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
    <p class="fi-sr-only" role="status" aria-live="polite" x-text="copied ? copiedLabel : ''"></p>
</div>
