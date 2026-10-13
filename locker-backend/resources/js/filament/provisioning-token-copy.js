window.provisioningTokenCopy = (token, messages) => ({
    token,
    copyLabel: messages.copyLabel,
    copiedLabel: messages.copiedLabel,
    copied: false,
    copiedTimeout: null,
    canCopy: Boolean(
        window.isSecureContext
        && navigator.clipboard
        && typeof navigator.clipboard.writeText === 'function',
    ),

    async copyToken() {
        if (!this.canCopy) {
            return;
        }

        try {
            await navigator.clipboard.writeText(this.token);
            this.copied = true;
            clearTimeout(this.copiedTimeout);
            this.copiedTimeout = setTimeout(() => {
                this.copied = false;
            }, 1500);
        } catch {
            this.copied = false;
            new window.FilamentNotification()
                .title(messages.errorTitle)
                .body(messages.errorBody)
                .danger()
                .send();
        }
    },

    destroy() {
        clearTimeout(this.copiedTimeout);
    },
});
