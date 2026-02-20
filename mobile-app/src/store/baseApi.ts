import type { BaseQueryFn, FetchArgs, FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

import { getApiBaseUrl } from '@/src/api/baseUrl';
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

export const baseApi = createApi({
  reducerPath: 'openLockerApi',
  baseQuery: dynamicBaseQuery,
  endpoints: () => ({}),
});
