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
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('clears the session and marks it expired on 401 with a token', async () => {
    mockFetchWithStatus(401);
    const store = createTestStore();
    store.dispatch(setCredentials({ token: 'stale-token', userName: 'Test User' }));

    await store.dispatch(testApi.endpoints.sessionProbe.initiate('solo', { forceRefetch: true }));

    expect(clearPersistedAuth).toHaveBeenCalledTimes(1);
    expect(store.getState().auth.token).toBeNull();
    expect(store.getState().auth.userName).toBeNull();
    expect(store.getState().auth.sessionExpired).toBe(true);
  });

  it('runs the expiry flow only once for concurrent 401 responses', async () => {
    mockFetchWithStatus(401);
    const store = createTestStore();
    store.dispatch(setCredentials({ token: 'stale-token', userName: 'Test User' }));

    const requests = [
      store.dispatch(testApi.endpoints.sessionProbe.initiate('first', { forceRefetch: true })),
      store.dispatch(testApi.endpoints.sessionProbe.initiate('second', { forceRefetch: true })),
    ];
    await Promise.all(requests);
    requests.forEach((request) => request.unsubscribe());

    expect(clearPersistedAuth).toHaveBeenCalledTimes(1);
    expect(store.getState().auth.sessionExpired).toBe(true);
  });

  it('ignores 401 responses when no token is present (e.g. failed login)', async () => {
    mockFetchWithStatus(401);
    const store = createTestStore();

    await store.dispatch(testApi.endpoints.sessionProbe.initiate('solo', { forceRefetch: true }));

    expect(clearPersistedAuth).not.toHaveBeenCalled();
    expect(store.getState().auth.sessionExpired).toBe(false);
  });

  it('does not touch the session on non-401 errors', async () => {
    mockFetchWithStatus(500);
    const store = createTestStore();
    store.dispatch(setCredentials({ token: 'valid-token', userName: 'Test User' }));

    await store.dispatch(testApi.endpoints.sessionProbe.initiate('solo', { forceRefetch: true }));

    expect(clearPersistedAuth).not.toHaveBeenCalled();
    expect(store.getState().auth.token).toBe('valid-token');
    expect(store.getState().auth.sessionExpired).toBe(false);
  });
});
