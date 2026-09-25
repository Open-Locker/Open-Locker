import type {
  BaseQueryFn,
  FetchArgs,
  FetchBaseQueryError,
  FetchBaseQueryMeta,
} from '@reduxjs/toolkit/query';
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

import { getApiBaseUrl } from '@/src/api/baseUrl';
import { isTermsNotAcceptedError } from './termsGate';
import { isOrganizationForbiddenError } from './organizationGate';
import { clearActiveOrganization } from '@/src/store/organizationSlice';
import { getCurrentAppLanguage } from '@/src/i18n';
import { markSessionExpired } from '@/src/store/authSlice';
import { clearPersistedAuth } from '@/src/store/authStorage';
import type { RootState } from '@/src/store/store';

function resolveUrl(baseUrl: string, url: string): string {
  if (/^https?:\/\//i.test(url)) {
    return url;
  }
  const normalizedBase = baseUrl.replace(/\/+$/, '');
  const normalizedPath = url.startsWith('/') ? url : `/${url}`;
  return `${normalizedBase}${normalizedPath}`;
}

const rawBaseQuery = fetchBaseQuery({
  baseUrl: '',
  prepareHeaders: (headers, { getState }) => {
    const state = getState() as RootState;
    const token = state.auth.token;
    if (token) {
      headers.set('authorization', `Bearer ${token}`);
    }

    // Which organization the app is acting in. One place, like the token, so
    // no generated endpoint has to know the concept exists. Omitted when the
    // user has a single membership: the server resolves that itself, which is
    // what keeps single-organization installations unaware of any of this.
    // A request that names its own organization keeps it: the locker lists of
    // every organization are fetched up front so switching is instant.
    const activeOrganizationId = state.organization.activeOrganizationId;
    if (activeOrganizationId && !headers.has('x-organization')) {
      headers.set('x-organization', activeOrganizationId);
    }

    headers.set('accept', 'application/json');
    // Tell the backend which language to render server-side strings in
    // (API messages, web pages, queued emails). Tracks the
    // user's settings-screen language switch, falling back to device locale.
    headers.set('accept-language', getCurrentAppLanguage());
    return headers;
  },
});

const dynamicBaseQuery: BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError> = (
  args,
  api,
  extraOptions,
) => {
  const baseUrl = getApiBaseUrl();
  if (typeof args === 'string') {
    return rawBaseQuery(resolveUrl(baseUrl, args), api, extraOptions);
  }

  return rawBaseQuery({ ...args, url: resolveUrl(baseUrl, args.url) }, api, extraOptions);
};

// Single-flight guard: many requests can fail with 401 at once, but the
// session-expiry flow (clear storage, reset state) must only run once.
let sessionExpiryInFlight = false;

const baseQueryWithSessionExpiry: BaseQueryFn<
  string | FetchArgs,
  unknown,
  FetchBaseQueryError
> = async (args, api, extraOptions) => {
  const result = await dynamicBaseQuery(args, api, extraOptions);

  if (result.error?.status === 401) {
    const { token } = (api.getState() as RootState).auth;
    // Only treat 401 as an expired session when we actually sent a token;
    // unauthenticated requests (e.g. a failed login) keep their own errors.
    if (token && !sessionExpiryInFlight) {
      sessionExpiryInFlight = true;
      try {
        await clearPersistedAuth();
        api.dispatch(markSessionExpired());
        // The chosen organization belongs to the session that chose it, so an
        // expiring session forgets it too — otherwise the next person to sign
        // in on this device starts acting in a stranger's choice.
        api.dispatch(clearActiveOrganization());
        api.dispatch(baseApi.util.resetApiState());
      } finally {
        sessionExpiryInFlight = false;
      }
    }
  }

  // A 403 from the terms gate means the user's cached profile is out of date: new
  // terms were activated after it was fetched. Without this the app keeps showing
  // a stale `terms_current_accepted`, so the banner that explains the refusal
  // never appears and every action fails for no visible reason.
  if (result.error?.status === 403 && isTermsNotAcceptedError(result.error.data)) {
    api.dispatch(baseApi.util.invalidateTags(['Auth']));
  }

  // The stored choice names an organization the user cannot act in — revoked
  // membership, or a stale value from another account. Choosing again cannot
  // fix the stored value, so it is cleared before the switcher reopens.
  // Only when the refused request acted in the stored choice: a list fetched
  // up front for another organization says nothing about that choice.
  if (
    result.error?.status === 403 &&
    isOrganizationForbiddenError(result.error.data) &&
    (result.meta as FetchBaseQueryMeta | undefined)?.request.headers.get('x-organization') ===
      (api.getState() as RootState).organization.activeOrganizationId
  ) {
    api.dispatch(clearActiveOrganization());
  }

  return result;
};

export const baseApi = createApi({
  reducerPath: 'openLockerApi',
  baseQuery: baseQueryWithSessionExpiry,
  // The generated API adds the rest through `enhanceEndpoints`. `Auth` is declared
  // here because the base query itself invalidates it, and importing the generated
  // list from here would be circular.
  tagTypes: ['Auth'],
  endpoints: () => ({}),
});
