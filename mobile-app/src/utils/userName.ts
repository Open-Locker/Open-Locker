export function formatUserName(firstName?: string | null, lastName?: string | null): string {
  return [firstName, lastName]
    .map((part) => part?.trim())
    .filter(Boolean)
    .join(' ');
}
