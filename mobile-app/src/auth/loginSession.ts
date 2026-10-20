import { formatUserName } from '@/src/utils/userName';

/**
 * Fields the sign-in screen may receive.
 *
 * Current responses send `first_name` and `last_name`. Builds from before that
 * split only read `name`, so both shapes are accepted here.
 */
export type LoginSessionResponse = {
  token?: unknown;
  first_name?: string | null;
  last_name?: string | null;
  name?: string | null;
};

export class LoginResponseError extends Error {
  constructor() {
    super('Login response did not include a token string.');
    this.name = 'LoginResponseError';
  }
}

/**
 * Values that can be written to SecureStore.
 *
 * SecureStore throws if either value is not a string. A missing legacy `name`
 * used to reach that call as `undefined` and surfaced the storage error on the
 * sign-in form.
 */
export function sessionFromLoginResponse(response: LoginSessionResponse | null): {
  token: string;
  userName: string;
} {
  if (response == null || typeof response.token !== 'string' || response.token.trim() === '') {
    throw new LoginResponseError();
  }

  const formatted = formatUserName(
    typeof response.first_name === 'string' ? response.first_name : null,
    typeof response.last_name === 'string' ? response.last_name : null,
  );
  const legacyName = typeof response.name === 'string' ? response.name.trim() : '';

  return {
    token: response.token,
    userName: formatted || legacyName,
  };
}
