import { printsOnCustomerBill } from './thermalReceipt'

export type BillableLine = {
  id: number
  item_name: string
  quantity: string | number
  unit_price?: string | number
  line_total: string | number
  print_on_bill?: boolean | null
  status?: string
  product_id?: number | null
  product?: { id?: number } | null
}

export type BillLineGroup<T extends BillableLine = BillableLine> = {
  key: string
  item_name: string
  quantity: number
  unit_price: number
  line_total: number
  print_on_bill: boolean
  product_id: number | null
  items: T[]
  statuses: Array<{ status: string; qty: number }>
}

const statusRank: Record<string, number> = { pending: 0, preparing: 1, ready: 2, served: 3 }

export function qtyLabel(quantity: number) {
  return Number.isInteger(quantity) ? String(quantity) : quantity.toFixed(3).replace(/\.?0+$/, '')
}

export function groupBillLines<T extends BillableLine>(items: T[]): BillLineGroup<T>[] {
  const groups = new Map<string, BillLineGroup<T>>()
  for (const item of items) {
    const qty = Number(item.quantity)
    const amount = Number(item.line_total)
    const price = Number(item.unit_price ?? (qty ? amount / qty : 0))
    const productId = item.product_id ?? item.product?.id ?? null
    const onBill = printsOnCustomerBill(item)
    const key = `${productId ?? item.item_name}|${price}|${onBill ? '1' : '0'}`
    const current = groups.get(key)
    if (current) {
      current.quantity += qty
      current.line_total += amount
      current.items.push(item)
      if (!current.product_id && productId) current.product_id = productId
    } else {
      groups.set(key, {
        key,
        item_name: item.item_name,
        quantity: qty,
        unit_price: price,
        line_total: amount,
        print_on_bill: onBill,
        product_id: productId,
        items: [item],
        statuses: [],
      })
    }
  }
  return [...groups.values()].map((group) => {
    const counts = new Map<string, number>()
    for (const item of group.items) {
      const status = item.status || ''
      if (!status) continue
      counts.set(status, (counts.get(status) ?? 0) + Number(item.quantity))
    }
    group.statuses = [...counts.entries()].map(([status, qty]) => ({ status, qty }))
    group.items.sort((left, right) => (statusRank[left.status ?? ''] ?? 9) - (statusRank[right.status ?? ''] ?? 9) || left.id - right.id)
    return group
  })
}

export function statusSummary(group: BillLineGroup) {
  return group.statuses.map((entry) => `${qtyLabel(entry.qty)} ${entry.status}`).join(' · ')
}

export function takeExcludeUnits<T extends BillableLine>(items: T[], quantity: number): Array<{ item: T; quantity: number }> {
  let remaining = quantity
  const taken: Array<{ item: T; quantity: number }> = []
  for (const item of items) {
    if (remaining <= 0) break
    const available = Number(item.quantity)
    const qty = Math.min(available, remaining)
    if (qty > 0) {
      taken.push({ item, quantity: qty })
      remaining -= qty
    }
  }
  return taken
}
