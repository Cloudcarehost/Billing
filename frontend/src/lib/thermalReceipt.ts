import type { Hotel, Invoice, Outlet } from '../types/api'

type ReceiptItem = {
  item_name: string
  quantity: string | number
  unit_price?: string | number
  line_total: string | number
  line_subtotal?: string | number
  tax_amount?: string | number
  print_on_bill?: boolean | null
  product?: { category?: { print_on_bill?: boolean | null } | null } | null
}

export function printsOnCustomerBill(item: { print_on_bill?: boolean | null; product?: { category?: { print_on_bill?: boolean | null } | null } | null }) {
  return item.print_on_bill !== false && item.product?.category?.print_on_bill !== false
}

export type ThermalReceiptInput = {
  hotel: Hotel
  outlet?: Pick<Outlet, 'name' | 'address'> | null
  cashier: string
  tableName: string
  serviceLabel?: string
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

export function openThermalReceiptWindow() {
  const popup = window.open('', '_blank', 'width=320,height=720')
  if (!popup) return null
  popup.opener = null
  return popup
}

export function writeThermalReceipt(popup: Window, input: ThermalReceiptInput) {
  popup.document.open()
  popup.document.write(thermalReceiptHtml(input))
  popup.document.close()
}

export function printThermalReceipt(input: ThermalReceiptInput) {
  const popup = openThermalReceiptWindow()
  if (!popup) return false
  writeThermalReceipt(popup, input)
  return true
}

export function thermalReceiptHtml(input: ThermalReceiptInput) {
  const { hotel, outlet, cashier, tableName, invoice, duplicate, serviceLabel = 'Dine In' } = input
  const printable = input.items.filter(printsOnCustomerBill)
  const omitted = printable.length !== input.items.length
  const lines = aggregateReceiptItems(printable)
  const totalQty = lines.reduce((sum, line) => sum + line.qty, 0)
  const billed = invoice.billed_at ? new Date(invoice.billed_at) : new Date()
  const date = billed.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: '2-digit', timeZone: hotel.timezone || 'Asia/Kolkata' })
  const time = billed.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: hotel.timezone || 'Asia/Kolkata' })
  const city = (hotel.address ?? outlet?.address ?? '').split(',').map((part) => part.trim()).filter(Boolean).at(-1) ?? ''
  const place = outlet?.name && outlet.name !== hotel.name ? outlet.name : city
  const printedSubtotal = printable.reduce((sum, item) => sum + Number(item.line_subtotal ?? item.line_total), 0)
  const printedTax = printable.reduce((sum, item) => sum + Number(item.tax_amount ?? 0), 0)
  const printedLinesTotal = printable.reduce((sum, item) => sum + Number(item.line_total), 0)
  const allLinesTotal = input.items.reduce((sum, item) => sum + Number(item.line_total), 0)
  const invoiceDiscount = Number(invoice.discount_amount ?? 0)
  const tax = omitted ? printedTax : Number(invoice.tax_amount ?? 0)
  const discount = omitted && allLinesTotal > 0 ? invoiceDiscount * (printedLinesTotal / allLinesTotal) : invoiceDiscount
  const subtotal = omitted ? printedSubtotal : Number(invoice.subtotal ?? invoice.total_amount)
  const grand = omitted ? printedLinesTotal - discount : Number(invoice.total_amount)
  const itemRows = lines.map((line) => `<div class="line"><span>${escapeHtml(line.name)}</span><span>${qtyLabel(line.qty)}</span><span>${moneyPlain(line.price)}</span><span>${moneyPlain(line.amount)}</span></div>`).join('')

  return `<!doctype html><html><head><title>${escapeHtml(invoice.invoice_number)}</title>
<meta name="viewport" content="width=80mm" />
<style>
@page { size: 80mm auto; margin: 2mm; }
* { box-sizing: border-box; }
html, body { width: 80mm; max-width: 80mm; min-width: 80mm; margin: 0; padding: 0; background: #fff; color: #111; font-family: "Courier New", Courier, ui-monospace, monospace; }
@media print {
  @page { size: 80mm auto; margin: 2mm; }
  html, body { width: 80mm !important; max-width: 80mm !important; min-width: 80mm !important; margin: 0 !important; }
}
.receipt { width: 76mm; margin: 0 auto; font-size: 12px; line-height: 1.28; }
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
  <div class="meta"><span>Date: ${date}</span><span>${escapeHtml(serviceLabel)}: ${escapeHtml(tableName)}</span></div>
  <div class="meta"><span>Time: ${time}</span></div>
  <div class="meta"><span>Cashier: ${escapeHtml(cashier)}</span><span>Bill No: ${escapeHtml(invoice.invoice_number)}</span></div>
  <hr class="rule" />
  <div class="head"><span>Item</span><span>Qty</span><span>Price</span><span>Amount</span></div>
  ${itemRows}
  <hr class="rule" />
  <div class="totals"><span>Total Qty: ${qtyLabel(totalQty)}</span><span>Sub Total ${moneyPlain(subtotal)}</span></div>
  ${tax > 0 ? `<div class="totals"><span></span><span>Tax ${moneyPlain(tax)}</span></div>` : ''}
  ${discount > 0 ? `<div class="totals"><span></span><span>Discount ${moneyPlain(discount)}</span></div>` : ''}
  <p class="grand">Grand Total ₹ ${moneyPlain(grand)}</p>
  <p class="thanks">Thank You!!! Visit Again.</p>
</main>
<script>window.addEventListener('load', function () { window.focus(); window.print(); window.addEventListener('afterprint', function () { window.close() }) })</script>
</body></html>`
}
