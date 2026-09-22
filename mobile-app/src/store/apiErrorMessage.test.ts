import { getApiErrorMessage } from './apiErrorMessage';

const t = (key: string, options?: Record<string, unknown>) =>
  options ? `${key}:${JSON.stringify(options)}` : key;

describe('getApiErrorMessage', () => {
  it('shows the reason the terms gate sent instead of the status code', () => {
    const error = {
      status: 403,
      data: {
        message: 'You must accept the latest terms before continuing.',
        code: 'terms_not_accepted',
        terms_current_version: 3,
      },
    };

    expect(getApiErrorMessage(error, t)).toBe(
      'You must accept the latest terms before continuing.',
    );
  });

  it('shows the reason an ApiErrorResource sent', () => {
    const error = {
      status: 403,
      data: {
        status: false,
        message: 'Please verify your email address before opening compartments',
      },
    };

    expect(getApiErrorMessage(error, t)).toBe(
      'Please verify your email address before opening compartments',
    );
  });

  it('falls back to the status when the server sent no message', () => {
    expect(getApiErrorMessage({ status: 500, data: {} }, t)).toBe(
      'common.requestFailedWithStatus:{"status":"500"}',
    );
  });

  it('uses a screen fallback key when given one', () => {
    expect(
      getApiErrorMessage({ status: 503, data: null }, t, {
        fallbackKey: 'compartments.loadFailed',
      }),
    ).toBe('compartments.loadFailed:{"status":"503"}');
  });

  it('prefers a screen override over the server message', () => {
    const error = { status: 422, data: { message: 'The given data was invalid.' } };

    expect(
      getApiErrorMessage(error, t, { overrides: { 422: 'auth.invalidEmailOrPassword' } }),
    ).toBe('auth.invalidEmailOrPassword');
  });

  it('leaves other statuses to the server message when an override does not match', () => {
    const error = { status: 403, data: { message: 'Not allowed.' } };

    expect(
      getApiErrorMessage(error, t, { overrides: { 422: 'auth.invalidEmailOrPassword' } }),
    ).toBe('Not allowed.');
  });

  it('ignores a blank server message', () => {
    expect(getApiErrorMessage({ status: 403, data: { message: '   ' } }, t)).toBe(
      'common.requestFailedWithStatus:{"status":"403"}',
    );
  });

  it('handles a non-object body, such as an HTML error page', () => {
    expect(getApiErrorMessage({ status: 502, data: '<html>bad gateway</html>' }, t)).toBe(
      'common.requestFailedWithStatus:{"status":"502"}',
    );
  });

  it('falls back to a thrown Error message', () => {
    expect(getApiErrorMessage(new Error('Network request failed'), t)).toBe(
      'Network request failed',
    );
  });

  it('falls back to generic text for anything else', () => {
    expect(getApiErrorMessage(undefined, t)).toBe('common.somethingWentWrong');
  });

  it('handles the non-numeric status RTK Query uses for transport failures', () => {
    const error = { status: 'FETCH_ERROR', error: 'TypeError: Network request failed' };

    expect(
      getApiErrorMessage(error, t, { overrides: { 422: 'auth.invalidEmailOrPassword' } }),
    ).toBe('common.requestFailedWithStatus:{"status":"FETCH_ERROR"}');
  });
});
