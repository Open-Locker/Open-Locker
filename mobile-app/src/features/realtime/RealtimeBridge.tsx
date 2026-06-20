import { useCompartmentStatusRealtime } from './useCompartmentStatusRealtime';

/**
 * Headless bridge that owns the compartment-status realtime subscription for
 * the session. Mounted beneath the Redux provider (after authentication) so it
 * can read the token/user id and dispatch cache updates; renders nothing.
 */
export function RealtimeBridge(): null {
  useCompartmentStatusRealtime();
  return null;
}
