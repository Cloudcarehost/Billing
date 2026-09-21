/* eslint-disable react-refresh/only-export-components */
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { BellRing, Check, Volume2, X } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { can } from '../auth/permissions'
import { useRestaurantRealtime } from '../realtime/useRestaurantRealtime'
import type { RestaurantEvent } from '../realtime/useRestaurantRealtime'
import { api } from '../../lib/api'
import type { ApiEnvelope, DiningTable, Outlet } from '../../types/api'
import { acknowledgeWaiterCall, isDirectBillOutlet } from '../../pages/opsShared'
import { registerWaiterServiceWorker, subscribeWaiterPush } from './waiterAlerts'
import { playFloorSound, soundFor, unlockFloorAlert } from './floorAlerts'

export type ReadyNotification = {
  id: string
  kind: 'ready' | 'waiter_call'
  tableId: number
  sessionId?: number
  tableName: string
  itemName: string
  quantity: string
  receivedAt: string
  read: boolean
  resolved: boolean
}

type ReadyNotificationContextValue = {
  notifications: ReadyNotification[]
  unreadCount: number
  soundEnabled: boolean
  browserPermission: NotificationPermission | 'unsupported'
  pocketReady: boolean
  showAlertSetup: boolean
  canInstall: boolean
  enableAlerts: () => Promise<void>
  installApp: () => Promise<void>
  dismissAlertSetup: () => void
  markAllRead: () => void
  openNotification: (notification: ReadyNotification) => void
  ingestFloorTables: (tables: DiningTable[]) => void
  testSound: () => Promise<void>
}

type InstallPrompt = Event & { prompt: () => Promise<void> }

const ReadyNotificationContext = createContext<ReadyNotificationContextValue | null>(null)

function storageKey(hotelId: number, userId: number) {
  return `aswad-ready-notifications:${hotelId}:${userId}`
}

function readStored(key: string): ReadyNotification[] {
  try {
    const parsed = JSON.parse(window.sessionStorage.getItem(key) ?? '[]')
    if (!Array.isArray(parsed)) return []
    return parsed.slice(0, 20).map((notification) => ({
      ...notification,
      id: String(notification.id),
      kind: notification.kind === 'waiter_call' ? 'waiter_call' : 'ready',
    }))
  } catch {
    return []
  }
}

function readyItemsFrom(table: DiningTable) {
  const session = table.active_session
  if (!session) return []
  const items = [
    ...(session.orders?.flatMap((order) => order.items) ?? []),
    ...(session.preview_items ?? []),
  ].filter((item) => item.status === 'ready' && item.id)
  return [...new Map(items.map((item) => [item.id, item])).values()]
}

export function ReadyNotificationProvider({ children }: { children: ReactNode }) {
  const { session } = useAuth()
  const navigate = useNavigate()
  const [outletIds, setOutletIds] = useState<number[]>([])
  const [notifications, setNotifications] = useState<ReadyNotification[]>([])
  const [soundEnabled, setSoundEnabled] = useState(false)
  const [pocketReady, setPocketReady] = useState(false)
  const [setupDismissed, setSetupDismissed] = useState(false)
  const [installPrompt, setInstallPrompt] = useState<InstallPrompt | null>(null)
  const [browserPermission, setBrowserPermission] = useState<NotificationPermission | 'unsupported'>(() => 'Notification' in window ? window.Notification.permission : 'unsupported')
  const wakeLockRef = useRef<{ release: () => Promise<void> } | null>(null)
  const seenRef = useRef(new Set<string>())
  const key = session ? storageKey(session.hotel.id, session.user.id) : ''
  const waiter = Boolean(session && can(session, 'orders.create'))

  useEffect(() => {
    const task = window.setTimeout(() => {
      if (!session || !waiter) {
        setOutletIds([])
        setNotifications([])
        seenRef.current.clear()
        return
      }
      const stored = readStored(key)
      setNotifications(stored)
      seenRef.current = new Set(stored.map((notification) => notification.id))
      void api.get<ApiEnvelope<Outlet[]>>('/api/v1/outlets')
        .then((response) => setOutletIds(response.data.data.filter((outlet) => outlet.is_active).map((outlet) => outlet.id)))
        .catch(() => setOutletIds([]))
    }, 0)
    return () => window.clearTimeout(task)
  }, [key, session, waiter])

  useEffect(() => {
    if (!key) return
    window.sessionStorage.setItem(key, JSON.stringify(notifications.slice(0, 20)))
  }, [key, notifications])

  const unlockSound = useCallback(async () => {
    const running = await unlockFloorAlert()
    setSoundEnabled(running)
    return running
  }, [])

  useEffect(() => {
    if (!session) return
    const unlock = () => { void unlockSound() }
    window.addEventListener('pointerdown', unlock, { once: true })
    window.addEventListener('keydown', unlock, { once: true })
    return () => {
      window.removeEventListener('pointerdown', unlock)
      window.removeEventListener('keydown', unlock)
    }
  }, [session, unlockSound])

  useEffect(() => {
    if (!waiter) return
    const onInstall = (event: Event) => {
      event.preventDefault()
      setInstallPrompt(event as InstallPrompt)
    }
    window.addEventListener('beforeinstallprompt', onInstall)
    void registerWaiterServiceWorker()
    return () => window.removeEventListener('beforeinstallprompt', onInstall)
  }, [waiter])

  useEffect(() => () => {
    void wakeLockRef.current?.release().catch(() => undefined)
  }, [])

  const playWaiterAlert = useCallback((kind: 'ready' | 'call' = 'ready') => {
    playFloorSound(soundFor(session?.hotel, kind))
    setSoundEnabled(true)
  }, [session])

  const openByTable = useCallback((tableId: number) => {
    navigate(`/app/orders?table=${tableId}`)
  }, [navigate])

  const handleUpdate = useCallback((event: RestaurantEvent) => {
    if (event.type === 'waiter_call_cleared') {
      const callId = `call-${event.table_id}`
      seenRef.current.delete(callId)
      setNotifications((current) => current.map((notification) => notification.id === callId ? { ...notification, read: true, resolved: true } : notification))
      return
    }
    if (event.type === 'waiter_called' && session) {
      const callId = `call-${event.table_id}`
      if (seenRef.current.has(callId)) return
      seenRef.current.add(callId)
      const notification: ReadyNotification = {
        id: callId,
        kind: 'waiter_call',
        tableId: Number(event.table_id),
        sessionId: Number(event.session_id) || undefined,
        tableName: typeof event.data?.table_name === 'string' ? event.data.table_name : 'Table',
        itemName: 'Guest called the waiter',
        quantity: '1',
        receivedAt: typeof event.occurred_at === 'string' ? event.occurred_at : new Date().toISOString(),
        read: false,
        resolved: false,
      }
      setNotifications((current) => [notification, ...current.filter((entry) => entry.id !== callId)].slice(0, 20))
      playWaiterAlert('call')
      if ('Notification' in window && window.Notification.permission === 'granted') {
        const browserNotification = new window.Notification(`${notification.tableName}: waiter needed`, {
          body: 'A guest asked for a waiter at this table.',
          tag: callId,
        })
        browserNotification.onclick = () => { window.focus(); openByTable(notification.tableId); browserNotification.close() }
      }
      return
    }

    const itemId = Number(event.item_id)
    if (!Number.isFinite(itemId)) return
    const readyId = `ready-${itemId}`

    if (event.type === 'item_served' || event.type === 'item_cancelled') {
      setNotifications((current) => current.map((notification) => notification.id === readyId || notification.id === String(itemId) ? { ...notification, read: true, resolved: true } : notification))
      return
    }
    if (event.type !== 'item_ready' || !session || seenRef.current.has(readyId)) return
    if (isDirectBillOutlet(session.outlets, event.outlet_id)) return

    seenRef.current.add(readyId)
    const notification: ReadyNotification = {
      id: readyId,
      kind: 'ready',
      tableId: Number(event.table_id),
      sessionId: Number(event.session_id) || undefined,
      tableName: typeof event.data?.table_name === 'string' ? event.data.table_name : 'Table',
      itemName: typeof event.data?.item_name === 'string' ? event.data.item_name : 'Order item',
      quantity: String(event.data?.quantity ?? '1'),
      receivedAt: typeof event.occurred_at === 'string' ? event.occurred_at : new Date().toISOString(),
      read: false,
      resolved: false,
    }
    setNotifications((current) => [notification, ...current].slice(0, 20))
    playWaiterAlert('ready')

    if ('Notification' in window && window.Notification.permission === 'granted') {
      const browserNotification = new window.Notification(`${notification.tableName}: order ready`, {
        body: `${notification.quantity} × ${notification.itemName} is ready to serve.`,
        tag: `ready-item-${notification.id}`,
      })
      browserNotification.onclick = () => { window.focus(); openByTable(notification.tableId); browserNotification.close() }
    }
  }, [openByTable, playWaiterAlert, session])

  useRestaurantRealtime({ outletIds, onUpdate: handleUpdate })

  useEffect(() => {
    const onMessage = (event: MessageEvent) => {
      if (event.data?.type !== 'aswad-open' || typeof event.data.url !== 'string') return
      navigate(event.data.url.replace(/^https?:\/\/[^/]+/, '') || '/app/orders')
    }
    navigator.serviceWorker?.addEventListener('message', onMessage)
    return () => navigator.serviceWorker?.removeEventListener('message', onMessage)
  }, [navigate])

  const ingestFloorTables = useCallback((tables: DiningTable[]) => {
    if (!waiter || !session) return
    const raised: ReadyNotification[] = []

    for (const table of tables) {
      if (table.waiter_called) {
        const callId = `call-${table.id}`
        if (!seenRef.current.has(callId)) {
          seenRef.current.add(callId)
          raised.push({
            id: callId,
            kind: 'waiter_call',
            tableId: table.id,
            sessionId: table.active_session?.id,
            tableName: table.name,
            itemName: 'Guest called the waiter',
            quantity: '1',
            receivedAt: new Date().toISOString(),
            read: false,
            resolved: false,
          })
        }
      }

      const readyItems = readyItemsFrom(table)
      if (isDirectBillOutlet(session.outlets, table.outlet_id)) continue
      const readyCount = table.active_session?.kitchen_progress?.ready ?? readyItems.length
      if (readyCount < 1) continue

      if (readyItems.length) {
        for (const item of readyItems) {
          const readyId = `ready-${item.id}`
          if (seenRef.current.has(readyId)) continue
          seenRef.current.add(readyId)
          raised.push({
            id: readyId,
            kind: 'ready',
            tableId: table.id,
            sessionId: table.active_session?.id,
            tableName: table.name,
            itemName: item.item_name,
            quantity: String(item.quantity ?? '1'),
            receivedAt: new Date().toISOString(),
            read: false,
            resolved: false,
          })
        }
        continue
      }

      const tableReadyId = `ready-table-${table.id}`
      if (seenRef.current.has(tableReadyId)) continue
      seenRef.current.add(tableReadyId)
      raised.push({
        id: tableReadyId,
        kind: 'ready',
        tableId: table.id,
        sessionId: table.active_session?.id,
        tableName: table.name,
        itemName: readyCount === 1 ? 'Order item' : `${readyCount} items`,
        quantity: String(readyCount),
        receivedAt: new Date().toISOString(),
        read: false,
        resolved: false,
      })
    }

    if (raised.length) {
      setNotifications((current) => [...raised, ...current.filter((entry) => !raised.some((item) => item.id === entry.id))].slice(0, 20))
      const kinds = new Set(raised.map((entry) => entry.kind === 'waiter_call' ? 'call' as const : 'ready' as const))
      kinds.forEach((kind) => playWaiterAlert(kind))
      if ('Notification' in window && window.Notification.permission === 'granted') {
        const first = raised[0]
        new window.Notification(first.kind === 'waiter_call' ? `${first.tableName}: waiter needed` : `${first.tableName}: order ready`, {
          body: first.kind === 'waiter_call' ? 'A guest asked for a waiter at this table.' : `${first.quantity} × ${first.itemName} is ready to serve.`,
          tag: first.id,
        })
      }
    }

    setNotifications((current) => current.map((notification) => {
      const table = tables.find((entry) => entry.id === notification.tableId)
      if (!table) return notification
      if (notification.kind === 'waiter_call' && !table.waiter_called) {
        seenRef.current.delete(notification.id)
        return { ...notification, read: true, resolved: true }
      }
      if (notification.kind === 'ready' && (isDirectBillOutlet(session?.outlets, table.outlet_id) || (table.active_session?.kitchen_progress?.ready ?? readyItemsFrom(table).length) < 1)) {
        seenRef.current.delete(notification.id)
        return { ...notification, read: true, resolved: true }
      }
      return notification
    }))
  }, [playWaiterAlert, session, waiter])

  const testSound = useCallback(async () => {
    await unlockSound()
    playWaiterAlert('ready')
  }, [playWaiterAlert, unlockSound])

  const enableAlerts = useCallback(async () => {
    await unlockSound()
    if ('Notification' in window && window.Notification.permission === 'default') {
      setBrowserPermission(await window.Notification.requestPermission())
    } else if ('Notification' in window) {
      setBrowserPermission(window.Notification.permission)
    }
    const result = await subscribeWaiterPush().catch(() => 'failed' as const)
    setPocketReady(result === 'subscribed' || result === 'unavailable')
    try {
      const lock = await (navigator as Navigator & { wakeLock?: { request: (type: 'screen') => Promise<{ release: () => Promise<void> }> } }).wakeLock?.request('screen')
      if (lock) wakeLockRef.current = lock
    } catch { /* keep-awake is optional */ }
    setSetupDismissed(true)
  }, [unlockSound])

  const installApp = useCallback(async () => {
    if (!installPrompt) return
    await installPrompt.prompt()
    setInstallPrompt(null)
  }, [installPrompt])

  const markAllRead = useCallback(() => {
    setNotifications((current) => current.map((notification) => ({ ...notification, read: true })))
  }, [])
  const openNotification = useCallback((notification: ReadyNotification) => {
    setNotifications((current) => current.map((entry) => entry.id === notification.id ? { ...entry, read: true } : entry))
    if (notification.kind === 'waiter_call') void acknowledgeWaiterCall(notification.tableId).catch(() => undefined)
    openByTable(notification.tableId)
  }, [openByTable])
  const unreadCount = notifications.filter((notification) => !notification.read && !notification.resolved).length
  const showAlertSetup = waiter && !setupDismissed && (!soundEnabled || browserPermission === 'default')
  const value = useMemo(() => ({
    notifications, unreadCount, soundEnabled, browserPermission, pocketReady, showAlertSetup, canInstall: Boolean(installPrompt),
    enableAlerts, installApp, dismissAlertSetup: () => setSetupDismissed(true), markAllRead, openNotification, ingestFloorTables, testSound,
  }), [notifications, unreadCount, soundEnabled, browserPermission, pocketReady, showAlertSetup, installPrompt, enableAlerts, installApp, markAllRead, openNotification, ingestFloorTables, testSound])
  const activeAlerts = notifications.filter((notification) => !notification.read && !notification.resolved).slice(0, 3)

  return <ReadyNotificationContext.Provider value={value}>
    {children}
    <div className="ready-notification-stack" aria-live="polite">{activeAlerts.map((notification) => <article key={notification.id} className={`ready-notification-toast ${notification.kind === 'waiter_call' ? 'waiter-call' : ''}`}>
      <BellRing size={20} />
      <button type="button" onClick={() => openNotification(notification)}><strong>{notification.kind === 'waiter_call' ? `${notification.tableName}: waiter needed` : `${notification.tableName}: ready to serve`}</strong><span>{notification.kind === 'waiter_call' ? 'Guest asked for a waiter.' : `${notification.quantity} × ${notification.itemName}`}</span></button>
      <button type="button" aria-label="Dismiss notification" onClick={() => setNotifications((current) => current.map((entry) => entry.id === notification.id ? { ...entry, read: true } : entry))}><X size={16} /></button>
    </article>)}</div>
  </ReadyNotificationContext.Provider>
}

export function useReadyNotifications() {
  const context = useContext(ReadyNotificationContext)
  if (!context) throw new Error('useReadyNotifications must be used inside ReadyNotificationProvider.')
  return context
}

export function AlertCapability() {
  const { soundEnabled, browserPermission, pocketReady } = useReadyNotifications()
  return <span className="alert-capability">{soundEnabled ? <Check size={12} /> : <Volume2 size={12} />}{soundEnabled ? 'Sound on' : 'Sound needs a tap'}{browserPermission === 'granted' ? ' · Browser alerts on' : ''}{pocketReady ? ' · Pocket alerts on' : ''}</span>
}
