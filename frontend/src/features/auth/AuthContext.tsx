/* eslint-disable react-refresh/only-export-components */
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { api, csrfCookie } from '../../lib/api'
import type { ApiEnvelope, Session } from '../../types/api'

type LoginInput = { email: string; password: string; remember: boolean }
type AuthContextValue = { session: Session | null; loading: boolean; activeOutletId: number | null; setActiveOutletId: (id: number) => void; login: (input: LoginInput) => Promise<void>; logout: () => Promise<void>; refresh: () => Promise<void>; setSession: (session: Session) => void }
const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session | null>(null)
  const [loading, setLoading] = useState(true)
  const [activeOutletId, setActiveOutletState] = useState<number | null>(null)
  const applySession = useCallback((next: Session) => { const key = `active-outlet:${next.hotel.id}:${next.user.id}`; const stored = Number(window.localStorage.getItem(key)); const selected = next.outlets.some((outlet) => outlet.id === stored) ? stored : next.outlets[0]?.id ?? null; setSession(next); setActiveOutletState(selected); if (selected) { api.defaults.headers.common['X-Outlet-Id'] = String(selected); window.localStorage.setItem(key, String(selected)) } else delete api.defaults.headers.common['X-Outlet-Id'] }, [])
  const refresh = useCallback(async () => { try { const response = await api.get<ApiEnvelope<Session>>('/api/v1/me'); applySession(response.data.data) } catch { setSession(null) } finally { setLoading(false) } }, [applySession])
  useEffect(() => { const task = window.setTimeout(() => { void refresh() }, 0); return () => window.clearTimeout(task) }, [refresh])
  useEffect(() => { const expire = () => setSession(null); window.addEventListener('auth:expired', expire); return () => window.removeEventListener('auth:expired', expire) }, [])
  const login = useCallback(async (input: LoginInput) => { await csrfCookie(); const response = await api.post<ApiEnvelope<Session>>('/api/v1/login', input); applySession(response.data.data) }, [applySession])
  const logout = useCallback(async () => { await api.post('/api/v1/logout'); delete api.defaults.headers.common['X-Outlet-Id']; setSession(null); setActiveOutletState(null) }, [])
  const setActiveOutletId = useCallback((id: number) => { if (!session?.outlets.some((outlet) => outlet.id === id)) return; setActiveOutletState(id); api.defaults.headers.common['X-Outlet-Id'] = String(id); window.localStorage.setItem(`active-outlet:${session.hotel.id}:${session.user.id}`, String(id)) }, [session])
  const value = useMemo(() => ({ session, loading, activeOutletId, setActiveOutletId, login, logout, refresh, setSession }), [session, loading, activeOutletId, setActiveOutletId, login, logout, refresh])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() { const context = useContext(AuthContext); if (!context) throw new Error('useAuth must be used inside AuthProvider.'); return context }
