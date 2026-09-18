import type { Hotel, Invoice, Outlet } from '../types/api'

type ReceiptItem = { item_name: string; quantity: string | number; unit_price?: string | number; line_total: string | number }

export type ThermalReceiptInput = {
  hotel: Hotel
  outlet?: Pick<Outlet, 'name' | 'address'> | null
  cashier: string
  tableName: string
  invoice: Invoice
  items: ReceiptItem[]
  duplicate: boolean
}

const escapeHtml = (value: string) => value.replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[character] ?? character)

const moneyPlain = (amount: string | number | undefined) => Number(amount ?? 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

function qtyLabel(quantity: number) {
  return Number.isInteger(quantity) ? String(quantity) : quantity.toFixed(3).replace(/\.?0+$/, '')
}

export function aggregateReceiptItems(items: ReceiptItem[]) {
  const lines = new Map<string, { name: string; qty: number; price: number; amount: number }>()
  for (const item of items) {
    const qty = Number(item.quantity)
    const amount = Number(item.line_total)
    const price = Number(item.unit_price ?? (qty ? amount / qty : 0))
    const key = `${item.item_name}|${price}`
    const current = lines.get(key)
    if (current) {
      current.qty += qty
      current.amount += amount
    } else {
      lines.set(key, { name: item.item_name, qty, price, amount })
    }
  }
  return [...lines.values()]
}

export function printThermalReceipt(input: ThermalReceiptInput) {
  const html = thermalReceiptHtml(input)
  const existing = document.getElementById('thermal-receipt-frame')
  existing?.remove()
  const frame = document.createElement('iframe')
  frame.id = 'thermal-receipt-frame'
  frame.setAttribute('aria-hidden', 'true')
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;'
  document.body.appendChild(frame)
  const doc = frame.contentDocument
  const printWindow = frame.contentWindow
  if (!doc || !printWindow) {
    frame.remove()
    return false
  }
  doc.open()
  doc.write(html)
  doc.close()
  window.setTimeout(() => {
    printWindow.focus()
    printWindow.print()
    window.setTimeout(() => frame.remove(), 1500)
  }, 200)
  return true
}

export function thermalReceiptHtml(input: ThermalReceiptInput) {
  const { hotel, outlet, cashier, tableName, invoice, duplicate } = input
  const lines = aggregateReceiptItems(input.items)
  const totalQty = lines.reduce((sum, line) => sum + line.qty, 0)
  const billed = invoice.billed_at ? new Date(invoice.billed_at) : new Date()
  const date = billed.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: '2-digit', timeZone: hotel.timezone || 'Asia/Kolkata' })
  const time = billed.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: hotel.timezone || 'Asia/Kolkata' })
  const city = (hotel.address ?? outlet?.address ?? '').split(',').map((part) => part.trim()).filter(Boolean).at(-1) ?? ''
  const place = outlet?.name && outlet.name !== hotel.name ? outlet.name : city
  const tax = Number(invoice.tax_amount ?? 0)
  const discount = Number(invoice.discount_amount ?? 0)
  const itemRows = lines.map((line) => `<div class="line"><span>${escapeHtml(line.name)}</span><span>${qtyLabel(line.qty)}</span><span>${moneyPlain(line.price)}</span><span>${moneyPlain(line.amount)}</span></div>`).join('')

  return `<!doctype html><html><head><title>${escapeHtml(invoice.invoice_number)}</title>
<style>
@page { size: 80mm auto; margin: 3mm; }
* { box-sizing: border-box; }
html, body { width: 80mm; margin: 0; background: #fff; color: #111; font-family: "Courier New", Courier, ui-monospace, monospace; }
.receipt { width: 74mm; margin: 0 auto; font-size: 12px; line-height: 1.28; }
.center { text-align: center; }
.hotel { margin: 4px 0 2px; font-size: 15px; font-weight: 800; }
.muted { font-size: 11px; }
.meta { display: flex; justify-content: space-between; gap: 8px; margin: 2px 0; }
.rule { margin: 7px 0; border: 0; border-top: 1px dashed #111; }
.head, .line { display: grid; grid-template-columns: 1fr 28px 52px 52px; gap: 3px; }
.head { font-weight: 800; }
.line span:nth-child(n+2), .head span:nth-child(n+2) { text-align: right; }
.totals { display: flex; justify-content: space-between; gap: 8px; }
.grand { margin-top: 8px; text-align: center; font-size: 16px; font-weight: 800; }
.thanks { margin-top: 10px; text-align: center; font-weight: 700; }
</style></head><body>
<main class="receipt">
  ${duplicate ? '<p class="center muted">Duplicate</p>' : '<p class="center muted">Original</p>'}
  <p class="center hotel">${escapeHtml(hotel.legal_name || hotel.name)}</p>
  ${hotel.phone ? `<p class="center muted">${escapeHtml(hotel.phone)}</p>` : ''}
  ${place ? `<p class="center muted">${escapeHtml(place)}</p>` : ''}
  ${hotel.gstin ? `<p class="center muted">GSTIN: ${escapeHtml(hotel.gstin)}</p>` : ''}
  <p>Name: ${escapeHtml(invoice.customer_name?.trim() || '-')}</p>
  <div class="meta"><span>Date: ${date}</span><span>Dine In: ${escapeHtml(tableName)}</span></div>
  <div class="meta"><span>Time: ${time}</span></div>
  <div class="meta"><span>Cashier: ${escapeHtml(cashier)}</span><span>Bill No: ${escapeHtml(invoice.invoice_number)}</span></div>
  <hr class="rule" />
  <div class="head"><span>Item</span><span>Qty</span><span>Price</span><span>Amount</span></div>
  ${itemRows}
  <hr class="rule" />
  <div class="totals"><span>Total Qty: ${qtyLabel(totalQty)}</span><span>Sub Total ${moneyPlain(invoice.subtotal ?? invoice.total_amount)}</span></div>
  ${tax > 0 ? `<div class="totals"><span></span><span>Tax ${moneyPlain(tax)}</span></div>` : ''}
  ${discount > 0 ? `<div class="totals"><span></span><span>Discount ${moneyPlain(discount)}</span></div>` : ''}
  <p class="grand">Grand Total ₹ ${moneyPlain(invoice.total_amount)}</p>
  <p class="thanks">Thank You!!! Visit Again.</p>
</main>
</body></html>`
}
