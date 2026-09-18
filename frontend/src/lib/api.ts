import axios from 'axios'
import type { ApiEnvelope } from '../types/api'

export const api = axios.create({ baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000', withCredentials: true, withXSRFToken: true, headers: { Accept: 'application/json' } })
api.interceptors.response.use((response) => response, (error: unknown) => {
  if (axios.isAxiosError(error) && error.response?.status === 401 && !error.config?.url?.includes('/api/v1/login')) { window.sessionStorage.setItem('auth-expired', '1'); window.dispatchEvent(new Event('auth:expired')) }
  return Promise.reject(error)
})
export async function csrfCookie() { await api.get('/sanctum/csrf-cookie') }
export function errorMessage(error: unknown): string {
  if (axios.isAxiosError<ApiEnvelope<never> & { errors?: Record<string, string[]> }>(error)) {
    const errors = error.response?.data?.errors
    return errors ? (Object.values(errors).flat().at(0) ?? 'Something went wrong.') : (error.response?.data?.message ?? 'Unable to reach the server. Please try again.')
  }
  return 'Something went wrong. Please try again.'
}
