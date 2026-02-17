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

## Next
- Add dedicated Filament page/resource for browsing command history and filtering failed requests.
- Confirm production routing for Reverb domain/path and CORS/origin config.

## Open Questions
- Do we want one id (`command_id`) for all phases, or keep external `request_id` and internal `transaction_id`?
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
