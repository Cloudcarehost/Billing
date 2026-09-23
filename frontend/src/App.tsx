import { lazy, Suspense } from 'react'
import type { ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { AuthProvider, useAuth } from './features/auth/AuthContext'
import { can, firstAccessiblePath, isOwner } from './features/auth/permissions'
import { ForgotPasswordPage, LoginPage, OnboardingPage, ResetPasswordPage } from './pages/AuthPages'
import { PlaceholderPage } from './pages/DashboardPage'
import { OrdersPage, TablesPage } from './pages/RealtimeOperationsPages'
import { ReadyNotificationProvider } from './features/notifications/ReadyNotificationContext'
import { RestaurantRealtimeProvider } from './features/realtime/RestaurantRealtimeProvider'

const MenuPage = lazy(() => import('./pages/MenuPage').then((module) => ({ default: module.MenuPage })))
const KitchenPage = lazy(() => import('./pages/KitchenPage').then((module) => ({ default: module.KitchenPage })))
const BillingPage = lazy(() => import('./pages/BillingPage').then((module) => ({ default: module.BillingPage })))
const InventoryPage = lazy(() => import('./pages/PhaseThreePages').then((module) => ({ default: module.InventoryPage })))
const LiveDashboardPage = lazy(() => import('./pages/PhaseThreePages').then((module) => ({ default: module.LiveDashboardPage })))
const ReportsPage = lazy(() => import('./pages/PhaseThreePages').then((module) => ({ default: module.ReportsPage })))
const MoneyPage = lazy(() => import('./pages/MoneyPage').then((module) => ({ default: module.MoneyPage })))
const AccountSecurityPage = lazy(() => import('./pages/SettingsPages').then((module) => ({ default: module.AccountSecurityPage })))
const HotelSettingsPage = lazy(() => import('./pages/SettingsPages').then((module) => ({ default: module.HotelSettingsPage })))
const OutletsPage = lazy(() => import('./pages/SettingsPages').then((module) => ({ default: module.OutletsPage })))
const RolesPage = lazy(() => import('./pages/SettingsPages').then((module) => ({ default: module.RolesPage })))
const StaffPage = lazy(() => import('./pages/SettingsPages').then((module) => ({ default: module.StaffPage })))
const CustomerTableStatusPage = lazy(() => import('./pages/TableQrPages').then((module) => ({ default: module.CustomerTableStatusPage })))
const TableQrManagementPage = lazy(() => import('./pages/TableQrPages').then((module) => ({ default: module.TableQrManagementPage })))

function RouteFallback() {
  return <div className="app-loading">Loading DineSetu…</div>
}

function LazyRoute({ children }: { children: ReactNode }) {
  return <Suspense fallback={<RouteFallback />}>{children}</Suspense>
}

function PermissionRoute({ permission, children }: { permission: string; children: ReactNode }) {
  const { session } = useAuth()
  return can(session, permission) ? children : <Navigate to={firstAccessiblePath(session)} replace />
}

function OwnerRoute({ children }: { children: ReactNode }) {
  const { session } = useAuth()
  return isOwner(session) ? children : <Navigate to={firstAccessiblePath(session)} replace />
}

function ProtectedApp() {
  const { loading, session } = useAuth()
  if (loading) return <RouteFallback />
  if (!session) return <Navigate to="/login" replace />

  return <AppShell><Routes>
    <Route index element={<LazyRoute><PermissionRoute permission="dashboard.view"><LiveDashboardPage /></PermissionRoute></LazyRoute>} />
    <Route path="tables" element={<PermissionRoute permission="tables.view"><TablesPage /></PermissionRoute>} />
    <Route path="orders" element={<PermissionRoute permission="orders.create"><OrdersPage /></PermissionRoute>} />
    <Route path="kitchen" element={<LazyRoute><PermissionRoute permission="kitchen.view"><KitchenPage /></PermissionRoute></LazyRoute>} />
    <Route path="billing" element={<LazyRoute><PermissionRoute permission="billing.view"><BillingPage /></PermissionRoute></LazyRoute>} />
    <Route path="menu" element={<LazyRoute><PermissionRoute permission="catalog.view"><MenuPage /></PermissionRoute></LazyRoute>} />
    <Route path="inventory" element={<LazyRoute><PermissionRoute permission="inventory.view"><InventoryPage /></PermissionRoute></LazyRoute>} />
    <Route path="reports" element={<LazyRoute><PermissionRoute permission="reports.view"><ReportsPage /></PermissionRoute></LazyRoute>} />
    <Route path="money" element={<LazyRoute><PermissionRoute permission="finance.view"><MoneyPage /></PermissionRoute></LazyRoute>} />
    <Route path="customers" element={<PermissionRoute permission="customers.view"><PlaceholderPage title="Customers" description="Customer management will be added with billing." /></PermissionRoute>} />
    <Route path="settings/hotel" element={<LazyRoute><PermissionRoute permission="settings.manage"><HotelSettingsPage /></PermissionRoute></LazyRoute>} />
    <Route path="settings/outlets" element={<LazyRoute><PermissionRoute permission="settings.manage"><OutletsPage /></PermissionRoute></LazyRoute>} />
    <Route path="settings/staff" element={<LazyRoute><PermissionRoute permission="users.view"><StaffPage /></PermissionRoute></LazyRoute>} />
    <Route path="settings/roles" element={<LazyRoute><OwnerRoute><RolesPage /></OwnerRoute></LazyRoute>} />
    <Route path="settings/table-qr" element={<LazyRoute><OwnerRoute><TableQrManagementPage /></OwnerRoute></LazyRoute>} />
    <Route path="settings/account" element={<LazyRoute><AccountSecurityPage /></LazyRoute>} />
    <Route path="*" element={<Navigate to={firstAccessiblePath(session)} replace />} />
  </Routes></AppShell>
}

export default function App() {
  return <AuthProvider><RestaurantRealtimeProvider><ReadyNotificationProvider><Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/setup" element={<OnboardingPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/table/:token" element={<LazyRoute><CustomerTableStatusPage /></LazyRoute>} />
    <Route path="/app/*" element={<ProtectedApp />} />
    <Route path="*" element={<Navigate to="/app" replace />} />
  </Routes></ReadyNotificationProvider></RestaurantRealtimeProvider></AuthProvider>
}
