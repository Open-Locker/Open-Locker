import { getApiBaseUrl } from '@/src/api/baseUrl';
import { requestJson } from '@/src/api/http';
import type { CompartmentDto } from '@/src/types/api';

export async function fetchCompartments(token: string): Promise<CompartmentDto[]> {
  const baseUrl = getApiBaseUrl();
  return requestJson<CompartmentDto[]>(`${baseUrl}/compartments`, {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${token}`,
    },
  });
}

