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

            // Only terminal outcomes are shown; intermediate statuses
            // (accepted, sent) and door-state changes stay silent.
            const toasts = @js([
                'opened' => [
                    'title' => __('Compartment opened'),
                    'body' => __('Compartment :number of locker :locker'),
                    'level' => 'success',
                ],
                'denied' => [
                    'title' => __('Open request denied'),
                    'body' => __('Compartment :number of locker :locker: :reason'),
                    'level' => 'danger',
                ],
                'failed' => [
                    'title' => __('Open request failed'),
                    'body' => __('Compartment :number of locker :locker. Details are in the server log.'),
                    'level' => 'danger',
                ],
            ]);

            const compartmentStatus = window.Echo.private(`users.${userId}.compartment-status`);

            compartmentStatus.listen('.compartment.open.status.updated', (payload) => {
                const toast = toasts[payload?.status];

                if (! toast) {
                    return;
                }

                const body = toast.body
                    .replace(':number', payload?.compartment_number ?? payload?.compartment_id ?? '?')
                    .replace(':locker', payload?.locker_name ?? '?')
                    .replace(':reason', payload?.message ?? payload?.error_code ?? '-');

                const notification = new window.FilamentNotification()
                    .title(toast.title)
                    .body(body);

                if (typeof notification[toast.level] === 'function') {
                    notification[toast.level]();
                }

                notification.send();
            });
        })();
    </script>
@endif
