import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';

type Translate = (key: string, options?: Record<string, unknown>) => string;

export type ApiErrorMessageOptions = {
  /**
   * Translation keys to prefer for specific status codes, e.g.
   * `{ 422: 'auth.invalidEmailOrPassword' }` on a sign-in form, where the
   * server's validation wording is less useful than the screen's own.
   */
  overrides?: Record<number, string>;
  /**
   * Key used when the server sent no message, in place of the generic
   * "Request failed ({{status}})". Receives `status` for interpolation.
   */
  fallbackKey?: string;
};

/**
 * The message the API sent, if it sent one.
 *
 * Both error shapes the backend produces put it at the top level: the terms and
 * verification gates send `{ message, ... }`, and ApiErrorResource sends
 * `{ status: false, message }`.
 */
function serverMessage(error: FetchBaseQueryError): string | null {
  const data = error.data;
  if (typeof data !== 'object' || data === null) return null;

  const message = (data as { message?: unknown }).message;

  return typeof message === 'string' && message.trim() !== '' ? message.trim() : null;
}

/**
 * User-facing text for a failed request.
 *
 * The backend explains its refusals — "You must accept the latest terms before
 * continuing", "Please verify your email address before opening compartments" —
 * and localises them to the `accept-language` the app sends, so the message is
 * already in the user's language. Showing only the status code left people with
 * a bare "Request failed (403)" and no way to know what to fix.
 *
 * A screen may still override a status where its own wording is better; that
 * wins over the server's.
 */
export function getApiErrorMessage(
  error: unknown,
  t: Translate,
  { overrides = {}, fallbackKey = 'common.requestFailedWithStatus' }: ApiErrorMessageOptions = {},
): string {
  const apiError = error as FetchBaseQueryError | undefined;

  if (apiError && typeof apiError === 'object' && 'status' in apiError) {
    if (typeof apiError.status === 'number') {
      const override = overrides[apiError.status];
      if (override) return t(override);
    }

    return serverMessage(apiError) ?? t(fallbackKey, { status: String(apiError.status) });
  }

  if (error instanceof Error) return error.message;

  return t('common.somethingWentWrong');
}
