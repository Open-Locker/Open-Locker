# Example descriptions

These are fictional formatting examples, not claims about existing changes or
checks. Use only evidence from the actual PR. Match a repository template first.

## Small documentation change

Title: `docs: clarify where shared agent instructions live`

```markdown
## Summary

Document `.agents/skills/` as the shared instruction directory so contributors
edit the canonical files when using Claude or Cursor.

## Testing

Checked the relative documentation links and confirmed both skill symlinks resolve.
```

## Behavior fix with incomplete verification

Title: `fix(mobile): retain the selected locker when retrying a request`

```markdown
## Summary

Keep the selected locker after a failed request so Retry submits the same choice.
Previously, retrying reset the selection and required the user to choose again.

## Testing

- Added and passed a regression test covering failure followed by retry.
- Device interaction was not checked because no emulator or device was available.
- CI is pending.
```

## Contract change

Title: `feat(api)!: paginate locker listings`

```markdown
## Summary

Return locker listings in bounded pages to keep response sizes predictable as
the number of lockers grows.

## Compatibility and rollout

The endpoint now returns a `data` array with pagination metadata instead of a
bare array. Clients must read `data` and follow the next-page link to retrieve
all lockers. Deploy the client that supports both shapes before switching the
endpoint; older clients cannot consume the new response. That client remains
compatible if the endpoint deployment is rolled back.

## Testing

- API tests passed for the first page, final page, and an empty listing.
- Client tests passed for both response shapes and loading the next page.
- Deployment ordering has not been exercised in staging.
```

For partial issue work, add a verified `Refs #N` footer. For a repository-required
checklist, retain unfinished items as unchecked rather than claiming completion.
