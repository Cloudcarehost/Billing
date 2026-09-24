/* eslint-disable react-refresh/only-export-components */
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'
import { RESTAURANT_EVENTS } from './events'
import type { RealtimeStatus, RestaurantEvent } from './events'

type Listener = (event: RestaurantEvent) => void
type RealtimeContextValue = {
  status: RealtimeStatus
  subscribe: (listener: Listener) => () => void
}

const RealtimeContext = createContext<RealtimeContextValue | null>(null)

function xsrfToken() {
  const value = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.split('=')[1]
  return value ? decodeURIComponent(value) : ''
}

export function RestaurantRealtimeProvider({ children }: { children: ReactNode }) {
  const { session } = useAuth()
  const [online, setOnline] = useState(navigator.onLine)
  const [connected, setConnected] = useState(false)
  const [everConnected, setEverConnected] = useState(false)
  const [unavailable, setUnavailable] = useState(false)
  const listeners = useRef(new Set<Listener>())
  const hadLiveConnection = useRef(false)
  const hotelId = session?.hotel.id ?? 0
  const outletKey = session?.outlets.map((outlet) => outlet.id).sort((a, b) => a - b).join(',') ?? ''

  const emit = useCallback((event: RestaurantEvent) => {
    listeners.current.forEach((listener) => listener(event))
  }, [])

  useEffect(() => {
    const handleOnline = () => { setOnline(true); emit({ type: 'connection_restored' }) }
    const handleOffline = () => setOnline(false)
    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)
    return () => {
      window.removeEventListener('online', handleOnline)
      window.removeEventListener('offline', handleOffline)
    }
  }, [emit])

  useEffect(() => {
    if (!hotelId || !outletKey || !online) {
      setConnected(false)
      return
    }
    const apiUrl = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'
    const host = import.meta.env.VITE_REVERB_HOST ?? (window.location.hostname === '127.0.0.1' ? '127.0.0.1' : 'localhost')
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080)
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http'
    ;(window as unknown as Window & { Pusher: typeof Pusher }).Pusher = Pusher
    const echo = new Echo({
      broadcaster: 'reverb',
      key: import.meta.env.VITE_REVERB_APP_KEY ?? 'aswad-local-key',
      wsHost: host,
      wsPort: port,
      wssPort: port,
      forceTLS: scheme === 'https',
      enabledTransports: ['ws', 'wss'],
      withCredentials: true,
      Pusher,
      authEndpoint: `${apiUrl}/api/broadcasting/auth`,
      auth: { headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' } },
    } as never)
    const pusher = (echo.connector as unknown as { pusher?: { connection?: { bind: (name: string, listener: () => void) => void } } }).pusher
    const markUnavailable = window.setTimeout(() => setUnavailable(true), 12_000)
    pusher?.connection?.bind('connected', () => {
      window.clearTimeout(markUnavailable)
      setConnected(true)
      setEverConnected(true)
      setUnavailable(false)
      if (hadLiveConnection.current) emit({ type: 'connection_restored' })
      hadLiveConnection.current = true
    })
    pusher?.connection?.bind('disconnected', () => setConnected(false))
    pusher?.connection?.bind('unavailable', () => setUnavailable(true))
    pusher?.connection?.bind('failed', () => setUnavailable(true))
    const receivedEvents = new Set<string>()
    const receive = (event: RestaurantEvent) => {
      if (event.event_id && receivedEvents.has(event.event_id)) return
      if (event.event_id) {
        receivedEvents.add(event.event_id)
        if (receivedEvents.size > 250) receivedEvents.delete(receivedEvents.values().next().value!)
      }
      emit(event)
    }
    const channels = outletKey.split(',').filter(Boolean).map((id) => echo.private(`hotel.${hotelId}.outlet.${id}`))
    RESTAURANT_EVENTS.forEach((name) => { channels.forEach((channel) => channel.listen(`.restaurant.${name}`, receive)) })
    return () => {
      window.clearTimeout(markUnavailable)
      hadLiveConnection.current = false
      echo.disconnect()
      setConnected(false)
    }
  }, [hotelId, outletKey, online, emit])

  const subscribe = useCallback((listener: Listener) => {
    listeners.current.add(listener)
    return () => { listeners.current.delete(listener) }
  }, [])

  const status: RealtimeStatus = !online
    ? 'offline'
    : connected
      ? 'live'
      : unavailable
        ? 'unavailable'
        : everConnected
          ? 'reconnecting'
          : 'connecting'

  const value = useMemo(() => ({ status, subscribe }), [status, subscribe])
  return <RealtimeContext.Provider value={value}>{children}</RealtimeContext.Provider>
}

export function useRealtimeConnection() {
  const context = useContext(RealtimeContext)
  if (!context) throw new Error('useRealtimeConnection must be used inside RestaurantRealtimeProvider.')
  return context
}
