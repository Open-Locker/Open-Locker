import { deleteItem, getItem, setItem } from '@/src/auth/storage';

const ACTIVE_ORGANIZATION_KEY = 'open-locker.activeOrganizationId';

/**
 * The chosen organization outlives the process, like the token does.
 *
 * Without this a restart drops the choice, the next request arrives with no
 * header, and a multi-membership user is sent back to the switcher every time
 * they reopen the app — for a decision they already made.
 */
export async function loadPersistedOrganization(): Promise<string | null> {
  return getItem(ACTIVE_ORGANIZATION_KEY);
}

export async function persistActiveOrganization(organizationId: string): Promise<void> {
  await setItem(ACTIVE_ORGANIZATION_KEY, organizationId);
}

export async function clearPersistedOrganization(): Promise<void> {
  await deleteItem(ACTIVE_ORGANIZATION_KEY);
}
