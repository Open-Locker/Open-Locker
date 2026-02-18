# Compartment Open Realtime Roadmap

## Goal
- Build a robust event-sourced open flow for compartments.
- Return immediate API feedback (`accepted` / `denied`) with a stable correlation id.
- Push final device result in realtime to clients (`opened` / `failed`).
- Keep one consistent flow for regular users and admins.
- Provide clear operator feedback in Filament.

## Done
- Removed borrow/return loan API and switched to compartment-based access.
- Added event-sourced access management (`grant` / `revoke`) and Filament management.
- Added open authorization flow events:
  - `CompartmentOpenRequested`
  - `CompartmentOpenAuthorized`
  - `CompartmentOpenDenied`
- Added admin override through the same event-sourcing path.
- Added realtime broadcasting setup (Reverb scaffolding + private user channel).
- Added broadcast event for open status updates:
  - `CompartmentOpenStatusUpdated`
- Added tests for compartment access flow and broadcast behavior.
- Added Reverb service to dev/prod compose files.
- Unified correlation id naming to `command_id` across API + events + MQTT transaction correlation.
- Added status lifecycle broadcasts:
  - `accepted`, `denied`, `sent`, `opened`, `failed`
- Added read model `CompartmentOpenRequest` to track command lifecycle and support polling fallback.
- Added status endpoint:
  - `GET /api/compartments/open-requests/{commandId}`
- Added Filament feedback improvements:
  - open action shows command id
  - compartment table shows latest open status + latest command id
- Centralized access-management authorization in `CompartmentAccessService`:
  - only admins can grant/revoke (service-level guard)
  - optional actor fallback now resolves via authenticated user context
- Added actor tracking for access events and projection:
  - `CompartmentAccessGranted.actorUserId`
  - `CompartmentAccessRevoked.actorUserId`
  - read model columns `granted_by_user_id` / `revoked_by_user_id`
- Added API endpoint for user-visible access scope:
  - `GET /api/compartments/accessible` (grouped by locker bank)
- Added tests for access management authorization:
  - non-admin cannot grant access
  - non-admin cannot revoke access
- Extended Filament user access table visibility:
  - `granted by` / `revoked by`
  - latest open status and opened timestamp per compartment
- Added dedicated Filament resource for command history:
  - `CompartmentOpenRequestResource`
  - filters for `failed`, `denied`, and explicit `status`
- Exposed command history directly on locker detail page:
  - `LockerBank` relation manager `openRequests`
  - filterable by compartment and status
- Added Docker-first Reverb defaults in compose files:
  - dev/prod queue + broadcast defaults
  - internal Reverb host config for app/workers
  - browser Echo defaults via `VITE_REVERB_*`
- Added Filament Realtime notification config:
  - `config/filament.php` with Echo/Reverb settings
  - test command `reverb:test-filament-notification`
- Added compartment-open live toasts in Filament admin:
  - panel render hook in `AdminPanelProvider`
  - realtime listener view `filament/realtime-compartment-open-notifications`
  - listens to `.compartment.open.status.updated` on `users.{id}.compartment-open`
- Added app-side communication documentation:
  - `docs/app_communication.md` for REST + Realtime + polling fallback contract
  - linked from `docs/mqtt_integration_plan.md`

## Next
- Confirm production routing for Reverb domain/path and CORS/origin config.
- Decide whether to keep `CompartmentOpenRequestResource` navigation hidden long-term
  or expose it in addition to locker-detail relation view.

## Open Questions
- Is a `sent` status required in product UX, or are `accepted` and final status enough?
- Should Filament receive push updates via Echo or use periodic refresh for request table?

## Extra Context
- Filament supports realtime broadcast notifications natively and integrates with Laravel Echo.
- For panel feedback, we can use Filament notifications from async jobs/reactors once a request changes status.
- Setup expectations:
  - Broadcasting must be configured and connected (Reverb/Pusher compatible).
  - Echo must be available in the panel.
  - Filament broadcasting config must be enabled and environment variables present.
  - Clear config/route caches after wiring.
- Reference:
  - https://filamentphp.com/docs/5.x/notifications/broadcast-notifications
