# ADR-0066: Audit-log classification by event attributes

## Status

Proposed

## Date

2026-09-27

## Context

ADR-0030 shows a curated whitelist of stored events in the admin audit log and
relies on review to keep that whitelist current ("review it when adding
events"). That failed in practice: `GroupArchived` was missing from the log
(#181), and `LockerProvisioningReplyFailed` was recorded "so we have a durable
audit trail" but never shown. Nothing forced a decision when an event was
added (#202).

## Decision

This supersedes ADR-0030's decision 2 (the central whitelist) and its
whitelist-drift mitigation. The rest of ADR-0030 stands.

1. Every class in `app/StorableEvents` carries exactly one attribute:
   `#[Audited(AuditCategory::..., 'Label')]` to appear in the log under that
   category and label, or `#[NotAudited('reason')]` to stay out.
2. `AuditEventClassificationTest` fails when an event has neither attribute,
   has both, has an empty reason, or has an audited label without a German
   translation. It also fails if discovery finds no events.
3. Audited events without a tailored description in
   `AuditEventPresenter::describe()` fall back to their label.
4. The presenter reads the attributes at runtime: it lists `app/StorableEvents`
   once per process and keeps the result, because the audit query needs the
   list of audited classes.

## Rationale

The decision now sits on the event itself, where whoever adds one sees it,
and CI enforces it instead of reviewer memory. Attributes were preferred in
the #202 discussion over a central registry kept in sync by a test.

## Alternatives Considered

### Alternative A: central registry plus a test

- Pros: no runtime discovery; the issue's original proposal
- Cons: the decision lives away from the event; two places to edit
- Why not chosen: the team agreed on attributes in #202

### Alternative B: show everything except a denylist

- Pros: complete coverage without a decision per event
- Cons: new telemetry or sensitive events appear unexpectedly
- Why not chosen: the log is meant to stay a curated record of who did what

## Consequences

### Positive

- a new event cannot be silently absent from the log
- excluded events document why

### Negative

- runtime discovery, where #202 hoped for discovery only in tests; it costs one
  directory listing and reflection per process
- the German-translation check covers labels only, not descriptions

### Risks

- a failing test only warns: `main` has no required status checks, so a red
  PR can still be merged unless the backend suite is made required

## Rollout / Migration

No data migration. Existing events were annotated with the categories and
labels of the former whitelist; `LockerProvisioningReplyFailed` is newly
audited and shows no raw failure reason, which can contain credential data.

## Supersedes / Superseded By

- Supersedes: ADR-0030, decision 2 and the whitelist-drift mitigation
- Superseded by: none

## References

- #202, #181
- ADR-0030 (admin audit log)
- `locker-backend/app/Support/Audit/`
- `locker-backend/tests/Feature/AuditEventClassificationTest.php`
