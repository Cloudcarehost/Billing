import type { OrderItem, Session } from '../../types/api'

export function isOwner(session: Session | null): boolean {
  return Boolean(session?.role.is_owner)
}

export function can(session: Session | null, permission: string): boolean {
  return isOwner(session) || Boolean(session?.permissions.includes('*') || session?.permissions.includes(permission))
}

export function canAny(session: Session | null, permissions: string[]): boolean {
  return permissions.some((permission) => can(session, permission))
}

export function canCancelItem(session: Session | null, item: Pick<OrderItem, 'status' | 'fulfillment_mode'>): boolean {
  if (item.status === 'cancelled') return false
  if (item.status === 'served') return can(session, 'orders.cancel_served')
  if (item.fulfillment_mode === 'kitchen' && (item.status === 'preparing' || item.status === 'ready')) return can(session, 'orders.cancel_prepared')
  return can(session, 'orders.cancel')
}

export function firstAccessiblePath(session: Session | null): string {
  const paths: Array<[string, string]> = [
    ['dashboard.view', '/app'], ['tables.view', '/app/tables'], ['orders.create', '/app/orders'],
    ['kitchen.view', '/app/kitchen'], ['billing.view', '/app/billing'], ['catalog.view', '/app/menu'], ['inventory.view', '/app/inventory'],
    ['reports.view', '/app/reports'], ['finance.view', '/app/money'], ['customers.view', '/app/customers'], ['settings.manage', '/app/settings/hotel'],
    ['users.view', '/app/settings/staff'],
  ]

  return paths.find(([permission]) => can(session, permission))?.[1] ?? '/app/settings/account'
}
