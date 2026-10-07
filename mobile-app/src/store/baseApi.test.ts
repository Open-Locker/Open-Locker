import { configureStore } from '@reduxjs/toolkit';

import { authReducer, setCredentials } from '@/src/store/authSlice';
import { clearPersistedAuth } from '@/src/store/authStorage';
import { baseApi } from '@/src/store/baseApi';
import { organizationReducer, setActiveOrganization } from '@/src/store/organizationSlice';

jest.mock('@/src/store/authStorage', () => ({
  clearPersistedAuth: jest.fn(() => Promise.resolve()),
}));

const testApi = baseApi.injectEndpoints({
  endpoints: (build) => ({
    sessionProbe: build.query<unknown, string>({
      query: (probeId) => `/session-probe/${probeId}`,
    }),
    organizationProbe: build.query<unknown, string>({
      query: (organizationId) => ({
        url: '/organization-probe',
        headers: { 'x-organization': organizationId },
      }),
    }),
  }),
});

function createTestStore() {
  return configureStore({
    reducer: {
      auth: authReducer,
      // The base query reads the active organization the same way it reads the
      // token, so the slice has to exist for prepareHeaders to run at all.
      organization: organizationReducer,
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

function mockFetchWithBody(status: number, body: unknown): jest.Mock {
  const mock = jest.fn(
    () =>
      new Promise<Response>((resolve) => {
        setTimeout(() => {
          resolve(
            new Response(JSON.stringify(body), {
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

describe('baseQuery organization handling', () => {
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

  afterEach(() => {
    store.dispatch(baseApi.util.resetApiState());
  });

  it('sends no organization header when none is chosen', async () => {
    // The ordinary case. Every build already in the field sends no header, and
    // the server resolves a single membership itself.
    const fetchMock = mockFetchWithStatus(200);

    await dispatchProbe('no-organization');

    const [request] = fetchMock.mock.calls[0] as [Request];
    expect(request.headers.get('x-organization')).toBeNull();
  });

  it('sends the chosen organization on every request', async () => {
    const fetchMock = mockFetchWithStatus(200);
    store.dispatch(setActiveOrganization('org-42'));

    await dispatchProbe('with-organization');

    const [request] = fetchMock.mock.calls[0] as [Request];
    expect(request.headers.get('x-organization')).toBe('org-42');
  });

  async function dispatchOrganizationProbe(organizationId: string) {
    const request = store.dispatch(
      testApi.endpoints.organizationProbe.initiate(organizationId, { forceRefetch: true }),
    );
    await request;
    request.unsubscribe();
  }

  it('keeps an organization the request names itself', async () => {
    // Every organization's lockers are fetched up front, each for its own.
    const fetchMock = mockFetchWithStatus(200);
    store.dispatch(setActiveOrganization('org-42'));

    await dispatchOrganizationProbe('org-7');

    const [request] = fetchMock.mock.calls[0] as [Request];
    expect(request.headers.get('x-organization')).toBe('org-7');
  });

  it('keeps the stored organization when another one is refused', async () => {
    store.dispatch(setActiveOrganization('org-42'));
    mockFetchWithBody(403, { code: 'organization_forbidden' });

    await dispatchOrganizationProbe('org-7');

    expect(store.getState().organization.activeOrganizationId).toBe('org-42');
  });

  it('drops cached data when an organization is refused', async () => {
    // A revoked organization's lockers and tab are cached; they must not stay
    // on screen after the refusal.
    mockFetchWithBody(200, { lockers: ['still here'] });
    await dispatchProbe('cached');
    mockFetchWithBody(403, { code: 'organization_forbidden' });

    await dispatchOrganizationProbe('org-7');

    expect(testApi.endpoints.sessionProbe.select('cached')(store.getState()).data).toBeUndefined();
  });

  it('drops a stored organization the user may not act in', async () => {
    // Choosing again cannot fix a stored value, so it is cleared rather than
    // re-offered against the same bad id.
    store.dispatch(setActiveOrganization('org-gone'));
    mockFetchWithBody(403, { code: 'organization_forbidden' });

    await dispatchProbe('forbidden');

    expect(store.getState().organization.activeOrganizationId).toBeNull();
  });
});
