window.provisioningTokenCopy = (token, messages) => ({
    token,
    copied: false,

    async copyToken() {
        try {
            await navigator.clipboard.writeText(this.token);
            this.copied = true;
        } catch {
            this.copied = false;
            new FilamentNotification()
                .title(messages.errorTitle)
                .body(messages.errorBody)
                .danger()
                .send();
        }
    },

    resetCopyState() {
        this.copied = false;
    },
});
