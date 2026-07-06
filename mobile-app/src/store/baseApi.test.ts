import { configureStore } from '@reduxjs/toolkit';

import { authReducer, setCredentials } from '@/src/store/authSlice';
import { clearPersistedAuth } from '@/src/store/authStorage';
import { baseApi } from '@/src/store/baseApi';

jest.mock('@/src/store/authStorage', () => ({
  clearPersistedAuth: jest.fn(() => Promise.resolve()),
}));

const testApi = baseApi.injectEndpoints({
  endpoints: (build) => ({
    sessionProbe: build.query<unknown, string>({
      query: (probeId) => `/session-probe/${probeId}`,
    }),
  }),
});

function createTestStore() {
  return configureStore({
    reducer: {
      auth: authReducer,
      [baseApi.reducerPath]: baseApi.reducer,
    },
    middleware: (getDefaultMiddleware) => getDefaultMiddleware().concat(baseApi.middleware),
    // The default autoBatch enhancer notifies via requestAnimationFrame, which
    // react-native's jest setup turns into a timer that can fire after teardown
    // (and calls jest.now() → "environment has been torn down"). Microtasks
    // settle within each test instead.
    enhancers: (getDefaultEnhancers) => getDefaultEnhancers({ autoBatch: { type: 'tick' } }),
  });
}

function mockFetchWithStatus(status: number): jest.Mock {
  const mock = jest.fn(
    () =>
      new Promise<Response>((resolve) => {
        setTimeout(() => {
          resolve(
            new Response(JSON.stringify({ message: 'Unauthenticated.' }), {
              status,
              headers: { 'Content-Type': 'application/json' },
            }),
          );
        }, 0);
      }),
  );
  globalThis.fetch = mock as unknown as typeof fetch;
  return mock;
}

describe('baseQuery 401 session-expiry handling', () => {
  let store: ReturnType<typeof createTestStore>;

  async function dispatchProbe(probeId: string) {
    const request = store.dispatch(
      testApi.endpoints.sessionProbe.initiate(probeId, { forceRefetch: true }),
    );
    await request;
    request.unsubscribe();
  }

  beforeEach(() => {
    jest.clearAllMocks();
    store = createTestStore();
  });

  // Drop subscriptions and queued cache-expiry timers so nothing fires after teardown.
  afterEach(() => {
    store.dispatch(baseApi.util.resetApiState());
  });

  it('clears the session and marks it expired on 401 with a token', async () => {
    mockFetchWithStatus(401);
    store.dispatch(setCredentials({ token: 'stale-token', userName: 'Test User' }));

    await dispatchProbe('solo');

    expect(clearPersistedAuth).toHaveBeenCalledTimes(1);
    expect(store.getState().auth.token).toBeNull();
    expect(store.getState().auth.userName).toBeNull();
    expect(store.getState().auth.sessionExpired).toBe(true);
  });

  it('runs the expiry flow only once for concurrent 401 responses', async () => {
    mockFetchWithStatus(401);
    store.dispatch(setCredentials({ token: 'stale-token', userName: 'Test User' }));

    await Promise.all([dispatchProbe('first'), dispatchProbe('second')]);

    expect(clearPersistedAuth).toHaveBeenCalledTimes(1);
    expect(store.getState().auth.sessionExpired).toBe(true);
  });

  it('ignores 401 responses when no token is present (e.g. failed login)', async () => {
    mockFetchWithStatus(401);

    await dispatchProbe('solo');

    expect(clearPersistedAuth).not.toHaveBeenCalled();
    expect(store.getState().auth.sessionExpired).toBe(false);
  });

  it('does not touch the session on non-401 errors', async () => {
    mockFetchWithStatus(500);
    store.dispatch(setCredentials({ token: 'valid-token', userName: 'Test User' }));

    await dispatchProbe('solo');

    expect(clearPersistedAuth).not.toHaveBeenCalled();
    expect(store.getState().auth.token).toBe('valid-token');
    expect(store.getState().auth.sessionExpired).toBe(false);
  });
});
