import { LoginResponseError, sessionFromLoginResponse } from '@/src/auth/loginSession';

describe('sessionFromLoginResponse', () => {
  it('uses first and last name from the current login payload', () => {
    expect(
      sessionFromLoginResponse({
        token: '1|token',
        first_name: 'Ada',
        last_name: 'Lovelace',
      }),
    ).toEqual({ token: '1|token', userName: 'Ada Lovelace' });
  });

  it('keeps a legacy payload that only has name', () => {
    expect(
      sessionFromLoginResponse({
        token: '1|token',
        name: 'Ada Lovelace',
      }),
    ).toEqual({ token: '1|token', userName: 'Ada Lovelace' });
  });

  it('allows an empty display name when the payload has neither name field', () => {
    expect(sessionFromLoginResponse({ token: '1|token' })).toEqual({
      token: '1|token',
      userName: '',
    });
  });

  it('rejects a payload whose token is not a string', () => {
    expect(() => sessionFromLoginResponse({ token: undefined, name: 'Ada' })).toThrow(
      LoginResponseError,
    );
    expect(() => sessionFromLoginResponse({ first_name: 'Ada', last_name: 'Lovelace' })).toThrow(
      LoginResponseError,
    );
  });
});
