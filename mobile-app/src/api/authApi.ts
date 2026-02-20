import { getApiBaseUrl } from '@/src/api/baseUrl';
import { requestJson } from '@/src/api/http';
import type { TokenResponse } from '@/src/types/api';

export async function login(email: string, password: string): Promise<TokenResponse> {
  const baseUrl = getApiBaseUrl();
  return requestJson<TokenResponse>(`${baseUrl}/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ email, password }),
  });
}
