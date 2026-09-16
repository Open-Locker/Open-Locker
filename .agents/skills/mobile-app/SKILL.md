---
name: mobile-app
description: Use for work under mobile-app/ involving Expo routes, React Native UI, feature structure, i18n, Redux Toolkit, or mobile checks.
---

Keep `app/` limited to thin routes/layouts; put reusable UI in `src/ui` and
feature logic in `src/features`. Reuse theme tokens and existing components.
Route new user-facing text through i18n and keep locales synchronized. Keep
RTK Query centralized and never hand-edit generated API output. Run focused
tests, typecheck, lint, and formatting checks for mobile changes.
