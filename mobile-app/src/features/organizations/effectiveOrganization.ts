type OrganizationRef = { id: string };

/**
 * The organization the API is acting in for this user.
 *
 * The chosen one if it is still a membership; otherwise the first by name,
 * which is what the backend picks when no `X-Organization` header is sent
 * (`locker-backend/app/Http/Middleware/ResolveOrganization.php`). Keep the two
 * in sync: `organizations` must be ordered by name, as `/organizations` returns
 * them.
 */
export function effectiveOrganizationId(
  activeOrganizationId: string | null,
  organizations: readonly OrganizationRef[],
): string | null {
  if (organizations.some((organization) => organization.id === activeOrganizationId)) {
    return activeOrganizationId;
  }

  return organizations[0]?.id ?? null;
}
