import { TERMS_ACCEPTANCE_EVENT, accountChannelName } from './useCompartmentStatusRealtime';

describe('account channel contract', () => {
  // Both halves are string literals in two repositories' worth of code. Nothing
  // fails loudly when they drift — the app simply stops hearing the event — so
  // they are pinned here against the backend's channel route and broadcastAs().
  it('subscribes to the channel the backend authorises', () => {
    expect(accountChannelName(42)).toBe('users.42.account');
  });

  it('listens for the name the backend broadcasts as', () => {
    expect(TERMS_ACCEPTANCE_EVENT).toBe('.terms.acceptance-required');
  });

  it('keeps the leading dot that stops Echo namespacing the event', () => {
    expect(TERMS_ACCEPTANCE_EVENT.startsWith('.')).toBe(true);
  });
});
