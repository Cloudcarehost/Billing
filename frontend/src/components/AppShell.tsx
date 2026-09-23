import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { Bell, BookOpen, ChefHat, ClipboardList, LayoutDashboard, LogOut, Menu, Moon, Package, QrCode, Search, Settings, Store, Users, UtensilsCrossed, Wallet, Wifi, WifiOff, X } from 'lucide-react'
import { NavLink, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../features/auth/AuthContext'
import { can, canAny, firstAccessiblePath, isOwner } from '../features/auth/permissions'
import { api, errorMessage } from '../lib/api'
import { useRestaurantRealtime } from '../features/realtime/useRestaurantRealtime'
import { AlertCapability, useReadyNotifications } from '../features/notifications/ReadyNotificationContext'
import { FLOOR_SOUND_PREVIEWS, playFloorSound, soundFor } from '../features/notifications/floorAlerts'
import { unwrap, useOperationalRefresh } from '../pages/opsShared'
import type { ApiEnvelope, DiningTable } from '../types/api'

const navigation = [
  ['Dashboard', '/app', LayoutDashboard, 'dashboard.view'], ['Tables', '/app/tables', UtensilsCrossed, 'tables.view'], ['Orders', '/app/orders', ClipboardList, 'orders.create'], ['Kitchen display', '/app/kitchen', ChefHat, 'kitchen.view'], ['Billing', '/app/billing', BookOpen, 'billing.view'], ['Menu', '/app/menu', ChefHat, 'catalog.view'], ['Inventory', '/app/inventory', Package, 'inventory.view'], ['Reports', '/app/reports', LayoutDashboard, 'reports.view'], ['Money', '/app/money', Wallet, 'finance.view'], ['Customers', '/app/customers', Users, 'customers.view'],
] as const

function LiveClock({ timezone, businessDate }: { timezone: string; businessDate?: string | null }) {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 1000)
    return () => window.clearInterval(timer)
  }, [])
  const date = new Intl.DateTimeFormat('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: timezone }).format(now)
  const time = new Intl.DateTimeFormat('en-IN', { hour: 'numeric', minute: '2-digit', timeZone: timezone }).format(now)
  return <time className="today" dateTime={now.toISOString()}>{date}<br /><strong>{time}</strong>{businessDate ? <small>Business {businessDate}</small> : null}</time>
}

export function AppShell({ children }: { children: ReactNode }) {
  const { session, logout, refresh, setSession, activeOutletId, setActiveOutletId } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [menuOpen, setMenuOpen] = useState(() => {
    if (typeof window === 'undefined') return true
    const stored = window.localStorage.getItem('dinesetu-nav-open')
    if (stored === '0') return false
    if (stored === '1') return true
    return window.matchMedia('(min-width: 761px)').matches
  })
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const [online, setOnline] = useState(navigator.onLine)
  const [query, setQuery] = useState('')
  const [endDayMessage, setEndDayMessage] = useState('')
  const [endingDay, setEndingDay] = useState(false)
  const [pendingBills, setPendingBills] = useState(0)
  const menuButtonRef = useRef<HTMLButtonElement>(null)
  const drawerRef = useRef<HTMLElement>(null)
  const notificationRef = useRef<HTMLDivElement>(null)
  const { notifications, unreadCount, enableAlerts, markAllRead, openNotification, showAlertSetup, canInstall, installApp, dismissAlertSetup, pocketReady } = useReadyNotifications()
  const canViewBilling = can(session, 'billing.view')
  const seenPendingBills = useRef<Set<number> | null>(null)
  const loadPendingBills = useCallback(async () => {
    if (!canViewBilling) { setPendingBills(0); seenPendingBills.current = null; return }
    try {
      const tables = unwrap(await api.get<ApiEnvelope<DiningTable[]>>('/api/v1/tables/status', { params: { outlet_id: activeOutletId } }))
      const pending = tables.filter((table) => table.display_status === 'pending_bill')
      setPendingBills(pending.length)
      const ids = new Set(pending.map((table) => table.active_session?.id).filter((id): id is number => Boolean(id)))
      if (seenPendingBills.current) {
        const arrived = [...ids].filter((id) => !seenPendingBills.current!.has(id))
        if (arrived.length) playFloorSound(soundFor(session?.hotel, 'billing'))
      }
      seenPendingBills.current = ids
    } catch {
      /* keep last count */
    }
  }, [activeOutletId, canViewBilling, session?.hotel])
  useEffect(() => { seenPendingBills.current = null }, [activeOutletId])
  const { status } = useRestaurantRealtime({
    outletIds: session.outlets.map((outlet) => outlet.id),
    onUpdate: (event) => {
      if (event.type === 'bill_requested' || event.type === 'table_closed' || event.type === 'invoice_created' || event.type === 'connection_restored') {
        void loadPendingBills()
      }
      if (event.type !== 'outlet_flow_changed' || !event.outlet_id) return
      const flow = event.data?.order_flow
      if (flow !== 'kitchen' && flow !== 'direct_bill') { void refresh(); return }
      setSession({ ...session, outlets: session.outlets.map((outlet) => outlet.id === event.outlet_id ? { ...outlet, order_flow: flow } : outlet) })
    },
  })
  useOperationalRefresh(loadPendingBills, status)
  useEffect(() => { const task = window.setTimeout(() => void loadPendingBills(), 0); return () => window.clearTimeout(task) }, [loadPendingBills])
  useEffect(() => { window.localStorage.setItem('dinesetu-nav-open', menuOpen ? '1' : '0') }, [menuOpen])

  useEffect(() => {
    setNotificationsOpen(false)
    if (window.matchMedia('(max-width: 760px)').matches) setMenuOpen(false)
  }, [location.pathname])
  useEffect(() => {
    if (!notificationsOpen) return
    const onPointer = (event: MouseEvent) => {
      if (!notificationRef.current?.contains(event.target as Node)) setNotificationsOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        setNotificationsOpen(false)
      }
    }
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [notificationsOpen])
  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) }
  }, [])
  useEffect(() => {
    if (!menuOpen) return
    const mobile = window.matchMedia('(max-width: 760px)').matches
    const previousOverflow = document.body.style.overflow
    if (mobile) {
      document.body.style.overflow = 'hidden'
      drawerRef.current?.querySelector<HTMLElement>('a, button')?.focus()
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        setMenuOpen(false)
        return
      }
      if (!mobile || event.key !== 'Tab' || !drawerRef.current) return
      const nodes = Array.from(drawerRef.current.querySelectorAll<HTMLElement>('a[href], button:not([disabled])'))
      if (!nodes.length) return
      const firstNode = nodes[0]
      const lastNode = nodes[nodes.length - 1]
      if (event.shiftKey && document.activeElement === firstNode) {
        event.preventDefault()
        lastNode.focus()
      } else if (!event.shiftKey && document.activeElement === lastNode) {
        event.preventDefault()
        firstNode.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => {
      if (mobile) {
        document.body.style.overflow = previousOverflow
        menuButtonRef.current?.focus()
      }
      document.removeEventListener('keydown', onKey)
    }
  }, [menuOpen])

  if (!session) return null
  const initials = session.user.name.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase()
  const homePath = firstAccessiblePath(session)
  const showSearch = canAny(session, ['tables.view', 'orders.create', 'catalog.view'])
  const endBusinessDay = async () => {
    if (!can(session, 'settings.manage') || endingDay) return
    setEndingDay(true)
    setEndDayMessage('')
    try {
      const response = await api.post('/api/v1/hotel/end-day')
      setEndDayMessage((response.data as { message?: string }).message ?? 'Business day ended.')
      await refresh()
    } catch (err) {
      setEndDayMessage(errorMessage(err))
    } finally {
      setEndingDay(false)
    }
  }
  const submitSearch = (event: FormEvent) => {
    event.preventDefault()
    const term = query.trim()
    if (!term) return
    const encoded = encodeURIComponent(term)
    const looksLikeTable = /^(table\s*)?t?-?\d+$/i.test(term) || /table/i.test(term)
    if (looksLikeTable && can(session, 'tables.view')) navigate(`/app/tables?q=${encoded}`)
    else if (can(session, 'orders.create')) navigate(`/app/orders?q=${encoded}`)
    else if (can(session, 'catalog.view')) navigate(`/app/menu?q=${encoded}`)
    else if (can(session, 'tables.view')) navigate(`/app/tables?q=${encoded}`)
  }
  const closeDrawerIfMobile = () => {
    if (window.matchMedia('(max-width: 760px)').matches) setMenuOpen(false)
  }
  const links = <>
    <nav className="main-nav">{navigation.filter(([, path, , permission]) => {
      if (!can(session, permission)) return false
      if (path === '/app/kitchen' && session.outlets.length > 0 && session.outlets.every((outlet) => outlet.order_flow === 'direct_bill')) return false
      return true
    }).map(([label, path, Icon]) => <NavLink key={path} to={path} end={path === '/app'} className="nav-link" onClick={closeDrawerIfMobile}><Icon size={17} />{label}{path === '/app/billing' && pendingBills > 0 ? <b className="nav-count">{pendingBills > 9 ? '9+' : pendingBills}</b> : null}</NavLink>)}</nav>
    <div className="sidebar-bottom">
      {can(session, 'settings.manage') && <NavLink to="/app/settings/hotel" className="nav-link" onClick={closeDrawerIfMobile}><Settings size={17} />Hotel settings</NavLink>}
      {can(session, 'settings.manage') && <NavLink to="/app/settings/outlets" className="nav-link" onClick={closeDrawerIfMobile}><Store size={17} />Outlets</NavLink>}
      {can(session, 'users.view') && <NavLink to="/app/settings/staff" className="nav-link" onClick={closeDrawerIfMobile}><Users size={17} />Staff access</NavLink>}
      {isOwner(session) && <NavLink to="/app/settings/roles" className="nav-link" onClick={closeDrawerIfMobile}><Settings size={17} />Roles & permissions</NavLink>}
      {isOwner(session) && <NavLink to="/app/settings/table-qr" className="nav-link" onClick={closeDrawerIfMobile}><QrCode size={17} />Table QR codes</NavLink>}
      <NavLink to="/app/settings/account" className="nav-link" onClick={closeDrawerIfMobile}><Users size={17} />My account</NavLink>
      <div className="drawer-user">
        <span className="avatar">{initials}</span>
        <div><strong>{session.user.name}</strong><small>{session.role.name}</small></div>
        <span className={`connection-state ${online ? 'live' : 'offline'}`}>{online ? <Wifi size={14} /> : <WifiOff size={14} />}{online ? 'Online' : 'Offline'}</span>
      </div>
      {can(session, 'settings.manage') && <button className="end-day" type="button" disabled={endingDay} onClick={() => { void endBusinessDay() }}><Moon size={16} />{endingDay ? 'Ending day…' : 'End Day'}</button>}
      {endDayMessage && <p className="end-day-note">{endDayMessage}</p>}
      <button className="end-day" type="button" onClick={() => { void logout().then(() => navigate('/login')) }}><LogOut size={16} />Sign out</button>
    </div>
  </>

  return <div className={`app-shell${menuOpen ? '' : ' nav-collapsed'}`}>
    {menuOpen && <button type="button" className="nav-overlay" aria-label="Close menu" onClick={() => setMenuOpen(false)} />}
    <aside ref={drawerRef} id="app-navigation" className={`sidebar ${menuOpen ? 'open' : ''}`}>
      <div className="drawer-heading">
        <NavLink to={homePath} className="brand" onClick={closeDrawerIfMobile}><span className="brand-mark"><img src="/icon-192.png" alt="" width="32" height="32" /></span><span><strong>DineSetu</strong><small>{session.hotel.name}</small></span></NavLink>
        <button type="button" className="drawer-close" aria-label="Close menu" onClick={() => setMenuOpen(false)}><X size={18} /></button>
      </div>
      {links}
    </aside>
    <main className="main-area">
      <header className="topbar">
        <button ref={menuButtonRef} className="mobile-menu" type="button" aria-label={menuOpen ? 'Close menu' : 'Open menu'} aria-expanded={menuOpen} aria-controls="app-navigation" onClick={() => setMenuOpen((open) => !open)}><Menu size={20} /></button>
        {showSearch && <form className="global-search" onSubmit={submitSearch}><Search size={17} /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search table, order, or menu item…" aria-label="Search tables, orders, or menu" /></form>}
        {session.outlets.length > 0 && <label className="outlet-switcher"><Store size={15} /><select aria-label="Current outlet" value={activeOutletId ?? ''} onChange={(event) => setActiveOutletId(Number(event.target.value))}>{session.outlets.map((outlet) => <option key={outlet.id} value={outlet.id}>{outlet.name}</option>)}</select></label>}
        <div className="topbar-actions"><LiveClock timezone={session.hotel.timezone || 'Asia/Kolkata'} businessDate={session.hotel.current_business_date} /><div className="notification-center" ref={notificationRef}><button type="button" className="icon-button" aria-label={`Notifications${unreadCount ? `, ${unreadCount} unread` : ''}`} aria-expanded={notificationsOpen} aria-controls="notification-panel" onClick={() => setNotificationsOpen((open) => !open)}><Bell size={19} />{unreadCount > 0 && <b>{unreadCount > 9 ? '9+' : unreadCount}</b>}</button>{notificationsOpen && <section id="notification-panel" className="notification-panel" role="dialog" aria-label="Floor notifications"><header><div><strong>Floor alerts</strong><small>Ready food and guest waiter calls</small></div><div className="notification-header-actions">{unreadCount > 0 && <button type="button" onClick={markAllRead}>Mark all read</button>}<button type="button" className="icon-button" aria-label="Close notifications" onClick={() => setNotificationsOpen(false)}><X size={16} /></button></div></header><button type="button" className="enable-alerts" onClick={() => void enableAlerts()}><Bell size={14} />Enable sound, vibrate & pocket alerts</button><AlertCapability /><div className="sound-preview"><p>Tap to hear options. Change the live sounds in Hotel settings.</p><div>{FLOOR_SOUND_PREVIEWS.map((option) => <button type="button" key={option.id} onClick={() => playFloorSound(option.id)}><strong>{option.label}</strong><small>{option.hint}</small></button>)}</div></div><div className="notification-list">{notifications.length ? notifications.map((notification) => <button type="button" key={notification.id} className={!notification.read && !notification.resolved ? 'unread' : ''} onClick={() => { openNotification(notification); setNotificationsOpen(false) }}><span className="notification-icon"><Bell size={14} /></span><span><strong>{notification.tableName}</strong><small>{notification.kind === 'waiter_call' ? (notification.resolved ? 'Waiter call seen' : 'Guest called the waiter') : `${notification.quantity} × ${notification.itemName}${notification.resolved ? ' · Served' : ''}`}</small><time>{new Date(notification.receivedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: session.hotel.timezone || 'Asia/Kolkata' })}</time></span></button>) : <p>No floor alerts yet.</p>}</div></section>}</div><NavLink to="/app/settings/account" className="user-menu" aria-label="Open my account"><span className="avatar">{initials}</span><span><strong>{session.user.name}</strong><small>{session.role.name}</small></span></NavLink><button type="button" className="logout-compact" onClick={() => { void logout().then(() => navigate('/login')) }} aria-label="Sign out"><LogOut size={17} /></button></div>
      </header>
      <div className="page-content">
        {showAlertSetup && <aside className="waiter-alert-banner" role="status">
          <div><strong>Turn on waiter alerts</strong><span>Plays three beeps when food is ready or a guest calls. For a phone in your pocket, allow notifications{canInstall ? ' and install the app.' : '. On iPhone, also Add to Home Screen.'}</span></div>
          <div className="waiter-alert-banner-actions">
            <button type="button" className="button button-primary" onClick={() => void enableAlerts()}>{pocketReady ? 'Alerts ready' : 'Enable alerts'}</button>
            {canInstall && <button type="button" className="button button-secondary" onClick={() => void installApp()}>Install app</button>}
            <button type="button" className="text-action" onClick={dismissAlertSetup}>Not now</button>
          </div>
        </aside>}
        {children}
      </div>
    </main>
  </div>
}
