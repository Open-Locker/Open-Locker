import { BANK_CONNECTION_EVENT, lockerBankChannelName } from './useCompartmentStatusRealtime';

describe('locker bank channel contract', () => {
  // Same reasoning as the account channel: these strings are duplicated across
  // two repositories and nothing fails loudly when they drift — the app just
  // stops hearing the event — so they are pinned against routes/channels.php
  // and LockerBankConnectionUpdated::broadcastAs().
  it('subscribes to the channel the backend authorises', () => {
    expect(lockerBankChannelName(42)).toBe('users.42.locker-banks');
  });

  it('listens for the name the backend broadcasts as', () => {
    expect(BANK_CONNECTION_EVENT).toBe('.locker_bank.connection.updated');
  });

  it('keeps the leading dot that stops Echo namespacing the event', () => {
    expect(BANK_CONNECTION_EVENT.startsWith('.')).toBe(true);
  });
});
