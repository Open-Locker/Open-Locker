import React from 'react';

const subscribe = () => () => {};

// The server snapshot is used during server rendering and hydration, so the
// first client render matches the server HTML before switching to `client`.
export function useClientOnlyValue<S, C>(server: S, client: C): S | C {
  const isClient = React.useSyncExternalStore(
    subscribe,
    () => true,
    () => false,
  );
  return isClient ? client : server;
}
