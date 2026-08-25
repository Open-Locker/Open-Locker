import type { BaseQueryFn, FetchArgs, FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

import { getApiBaseUrl } from '@/src/api/baseUrl';
import { isTermsNotAcceptedError } from './termsGate';
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
