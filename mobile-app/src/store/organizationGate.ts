/**
 * Whether a refusal means "say which organization you are acting in".
 *
 * Matched on the code the middleware sends, never on the message — the same
 * rule the terms gate follows, and for the same reason: the message is
 * localised and free to be reworded.
 */
export function isOrganizationSelectionRequiredError(data: unknown): boolean {
  return (
    typeof data === 'object' &&
    data !== null &&
    (data as { code?: unknown }).code === 'organization_not_selected'
  );
}

/**
 * The user asked to act somewhere they do not belong. Distinct from selection
 * being required: choosing again cannot fix it, so the app clears its stored
 * choice rather than re-opening the switcher on the same bad value.
 */
export function isOrganizationForbiddenError(data: unknown): boolean {
  return (
    typeof data === 'object' &&
    data !== null &&
    (data as { code?: unknown }).code === 'organization_forbidden'
  );
}
