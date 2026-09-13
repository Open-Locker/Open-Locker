<div
    class="space-y-2"
    x-data="window.provisioningTokenCopy(@js($token), {
        errorTitle: @js(__('Could not copy provisioning token')),
        errorBody: @js(__('Your browser blocked clipboard access. Select the token, then press Ctrl+C (or Cmd+C on macOS) to copy it manually.')),
    })"
>
    <div class="fi-input-wrp">
        <div
            class="fi-input-wrp-content-ctn"
            style="padding-block: 1rem; padding-inline: 1.25rem;"
        >
            <code
                class="fi-sc-text fi-font-mono"
                data-testid="one-time-provisioning-token"
            >{{ $token }}</code>
        </div>
        <div
            class="fi-input-wrp-suffix"
            x-on:mouseenter="resetCopyState()"
        >
            <x-filament::icon-button
                x-cloak
                color="gray"
                icon="heroicon-m-clipboard"
                :label="__('Copy token')"
                :tooltip="__('Copy token')"
                type="button"
                x-show="!copied"
                x-on:click="copyToken()"
            />
            <x-filament::icon-button
                x-cloak
                color="gray"
                icon="heroicon-m-check"
                :label="__('Copied')"
                :tooltip="__('Copied')"
                type="button"
                x-show="copied"
                x-on:click="copyToken()"
            />
        </div>
    </div>

</div>
