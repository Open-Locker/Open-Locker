@php
    $filamentUser = \Filament\Facades\Filament::auth()->user();
    $userId = $filamentUser instanceof \App\Models\User ? $filamentUser->id : null;
@endphp

@if ($userId)
    <script>
        (() => {
            const userId = @js($userId);

            if (! window.Echo || ! window.FilamentNotification || ! userId) {
                return;
            }

            const registrationKey = `openLockerCompartmentStatusListenerRegistered:${userId}`;
            if (window[registrationKey]) {
                return;
            }

            window[registrationKey] = true;

            const statusTitles = {
                accepted: 'Open request accepted',
                sent: 'Open command sent',
                opened: 'Compartment opened',
                denied: 'Open request denied',
                failed: 'Open request failed',
            };

            const statusLevel = {
                accepted: 'success',
                sent: 'info',
                opened: 'success',
                denied: 'danger',
                failed: 'danger',
            };

            window.Echo.private(`users.${userId}.compartment-open`)
                .listen('.compartment.open.status.updated', (payload) => {
                    const status = payload?.status ?? 'sent';
                    const commandId = payload?.command_id ?? 'n/a';
                    const messageParts = [`Command: ${commandId}`];

                    if (payload?.compartment_id) {
                        messageParts.push(`Compartment: ${payload.compartment_id}`);
                    }

                    if (payload?.message) {
                        messageParts.push(`Message: ${payload.message}`);
                    }

                    if (payload?.error_code) {
                        messageParts.push(`Error: ${payload.error_code}`);
                    }

                    const notification = new window.FilamentNotification()
                        .title(statusTitles[status] ?? 'Compartment status updated')
                        .body(messageParts.join(' | '));

                    const level = statusLevel[status] ?? 'info';
                    if (typeof notification[level] === 'function') {
                        notification[level]();
                    }

                    notification.send();
                });
        })();
    </script>
@endif
