---
name: mobile-app
description: Use when working under mobile-app/ on Expo routes, React Native UI, feature structure, i18n, Redux Toolkit, or mobile tests and checks.
---

# Mobile app workflow

Keep `app/` limited to thin Expo Router routes and layouts. Put reusable UI in
`src/ui`, feature logic in `src/features`, and shared infrastructure in the
existing `src` folders. Reuse existing components and theme tokens before
adding variants. New user-facing text goes through `react-i18next` and must be
added to both locale resources.

Keep RTK Query setup centralized and selectors near their owning slice. Never
hand-edit generated API output; use the repository’s generation script after
the backend contract is available. Validate focused changes with the relevant
Jest test, typecheck, lint, and format check; run the broader `check` script
when the change spans the app.
