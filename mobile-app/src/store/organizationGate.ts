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
