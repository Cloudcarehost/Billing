import { CirclePlus, CookingPot, PackagePlus, Pencil, Pin, Search, Trash2, UtensilsCrossed } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import type { FormEvent, InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ConfirmDialog } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { can } from '../features/auth/permissions'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, Category, KitchenStation, Outlet, Paginated, Product } from '../types/api'
import { money } from './opsShared'

function Heading({ eyebrow, title, text, action }: { eyebrow: string; title: string; text: string; action?: ReactNode }) { return <section className="page-heading"><div><p className="eyebrow">{eyebrow}</p><h1>{title}</h1><p>{text}</p></div>{action}</section> }

type DeleteTarget = { kind: 'category' | 'station'; id: number; name: string } | null

export function MenuPage() {
  const { session } = useAuth(); const manage = can(session, 'catalog.manage')
  const [categories, setCategories] = useState<Category[]>([]); const [stations, setStations] = useState<KitchenStation[]>([]); const [products, setProducts] = useState<Product[]>([]); const [outlets, setOutlets] = useState<Outlet[]>([])
  const [error, setError] = useState(''); const [success, setSuccess] = useState(''); const [panel, setPanel] = useState<'product' | 'category' | 'station' | null>(null)
  const [editing, setEditing] = useState<Product | null>(null); const [editingCategory, setEditingCategory] = useState<Category | null>(null); const [editingStation, setEditingStation] = useState<KitchenStation | null>(null)
  const [deleting, setDeleting] = useState<DeleteTarget>(null)
  const [params] = useSearchParams(); const [query, setQuery] = useState(params.get('q') ?? '')
  const load = useCallback(async () => { try { const [categoryData, stationData, productData] = await Promise.all([api.get<ApiEnvelope<Category[]>>('/api/v1/categories'), api.get<ApiEnvelope<KitchenStation[]>>('/api/v1/kitchen-stations'), api.get<Paginated<Product>>('/api/v1/products?per_page=100')]); setCategories(categoryData.data.data); setStations(stationData.data.data); setProducts(productData.data.data); if (can(session, 'settings.manage')) { const outletData = await api.get<ApiEnvelope<Outlet[]>>('/api/v1/outlets'); setOutlets(outletData.data.data) } } catch (err) { setError(errorMessage(err)) } }, [session])
  useEffect(() => { const task = window.setTimeout(() => { void load() }, 0); return () => window.clearTimeout(task) }, [load])
  function openPanel(next: 'product' | 'category' | 'station') {
    setError(''); setSuccess('')
    setPanel(next)
    if (next !== 'product') setEditing(null)
    if (next !== 'category') setEditingCategory(null)
    if (next !== 'station') setEditingStation(null)
  }
  async function saveProduct(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); const fulfillmentMode = String(values.get('fulfillment_mode') || 'direct') as 'kitchen' | 'direct'; const data = { name: String(values.get('name')), short_description: String(values.get('short_description') || '') || null, serving_size: String(values.get('serving_size') || '') || null, category_id: values.get('category_id') ? Number(values.get('category_id')) : null, fulfillment_mode: fulfillmentMode, kitchen_station_id: fulfillmentMode === 'kitchen' && values.get('kitchen_station_id') ? Number(values.get('kitchen_station_id')) : null, selling_price: Number(values.get('selling_price')), cost_price: Number(values.get('cost_price') || 0), tax_rate: Number(values.get('tax_rate') || 0), sku: String(values.get('sku') || '') || null, barcode: String(values.get('barcode') || '') || null, hsn_code: String(values.get('hsn_code') || '') || null, track_inventory: values.has('track_inventory'), is_active: values.has('is_active'), is_quick: values.has('is_quick') }; setError(''); try { if (editing) await api.put(`/api/v1/products/${editing.id}`, data); else await api.post('/api/v1/products', data); form.reset(); setPanel(null); setEditing(null); setSuccess(editing ? 'Product updated.' : 'Product added.'); await load() } catch (err) { setError(errorMessage(err)) } }
  async function saveCategory(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    const values = new FormData(form)
    const data = { name: String(values.get('name')), is_active: values.has('is_active') }
    setError('')
    try {
      if (editingCategory) await api.put(`/api/v1/categories/${editingCategory.id}`, data)
      else await api.post('/api/v1/categories', data)
      form.reset()
      setEditingCategory(null)
      setSuccess(editingCategory ? 'Category updated.' : 'Category added.')
      await load()
    } catch (err) { setError(errorMessage(err)) }
  }
  async function saveStation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    const values = new FormData(form)
    const data = { name: String(values.get('name')), code: String(values.get('code')), outlet_id: Number(values.get('outlet_id')), is_active: values.has('is_active') }
    setError('')
    try {
      if (editingStation) await api.put(`/api/v1/kitchen-stations/${editingStation.id}`, data)
      else await api.post('/api/v1/kitchen-stations', data)
      form.reset()
      setEditingStation(null)
      setSuccess(editingStation ? 'Kitchen station updated.' : 'Kitchen station added.')
      await load()
    } catch (err) { setError(errorMessage(err)) }
  }
  async function confirmDelete() {
    if (!deleting) return
    setError('')
    try {
      if (deleting.kind === 'category') await api.delete(`/api/v1/categories/${deleting.id}`)
      else await api.delete(`/api/v1/kitchen-stations/${deleting.id}`)
      if (deleting.kind === 'category' && editingCategory?.id === deleting.id) setEditingCategory(null)
      if (deleting.kind === 'station' && editingStation?.id === deleting.id) setEditingStation(null)
      setSuccess(deleting.kind === 'category' ? 'Category deleted. Products stay on the menu as Uncategorized.' : 'Kitchen station deleted.')
      setDeleting(null)
      await load()
    } catch (err) { setError(errorMessage(err)); setDeleting(null) }
  }
  const filtered = products.filter((product) => `${product.name} ${product.sku ?? ''} ${product.barcode ?? ''}`.toLowerCase().includes(query.toLowerCase()))
  return <>
    <Heading eyebrow="CATALOG" title="Menu & products" text="Keep prices, tax and kitchen routing accurate. Inventory tracking is optional and never blocks orders." action={manage ? <div className="heading-actions"><button className="button button-secondary" type="button" onClick={() => openPanel('category')}><CirclePlus size={16} />Category</button>{outlets.length > 0 && <button className="button button-secondary" type="button" onClick={() => openPanel('station')}><CookingPot size={16} />Station</button>}<button className="button button-primary" type="button" onClick={() => { setEditing(null); openPanel('product') }}><PackagePlus size={16} />Add product</button></div> : undefined} />
    {panel === 'product' && <form className="settings-card compact-form" onSubmit={saveProduct}>
      <div className="role-form-heading"><div><h2>{editing ? `Edit ${editing.name}` : 'Add product'}</h2><p>Prices and tax are saved securely by the server.</p></div><button type="button" className="button button-secondary" onClick={() => { setPanel(null); setEditing(null) }}>Cancel</button></div>
      <div className="form-grid">
        <Field label="Product name" name="name" defaultValue={editing?.name} required />
        <Field label="Serving size / quantity" name="serving_size" placeholder="e.g. 20 ml, 100 ml, 500 g" defaultValue={editing?.serving_size ?? ''} />
        <Field label="Short description" name="short_description" placeholder="Shown to staff when ordering" defaultValue={editing?.short_description ?? ''} />
        <Field label="Selling price" name="selling_price" type="number" min="0" step="0.01" defaultValue={editing?.selling_price} required />
        <Field label="Cost price" name="cost_price" type="number" min="0" step="0.01" defaultValue={editing?.cost_price ?? '0'} />
        <Field label="Tax rate (%)" name="tax_rate" type="number" min="0" max="100" step="0.01" defaultValue={editing?.tax_rate ?? '0'} />
        <Field label="SKU" name="sku" defaultValue={editing?.sku ?? ''} />
        <Field label="Barcode" name="barcode" defaultValue={editing?.barcode ?? ''} />
        <Field label="HSN / SAC code" name="hsn_code" defaultValue={editing?.hsn_code ?? ''} />
        <Select label="Category" name="category_id" defaultValue={editing?.category_id ?? ''}><option value="">No category</option>{categories.filter((item) => item.is_active || item.id === editing?.category_id).map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</Select>
        <Select label="Service route" name="fulfillment_mode" defaultValue={editing?.fulfillment_mode ?? 'direct'}><option value="direct">Direct service — waiter collects it</option><option value="kitchen">Kitchen preparation</option></Select>
        <Select label="Kitchen station (required for kitchen items)" name="kitchen_station_id" defaultValue={editing?.kitchen_station_id ?? ''}><option value="">Select kitchen station</option>{stations.filter((item) => item.is_active || item.id === editing?.kitchen_station_id).map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</Select>
      </div>
      <div className="check-group"><label className="check-row"><input type="checkbox" name="track_inventory" defaultChecked={editing?.track_inventory ?? false} />Track inventory (optional — never blocks taking or serving orders)</label><label className="check-row"><input type="checkbox" name="is_active" defaultChecked={editing?.is_active ?? true} />Available to sell</label><label className="check-row"><input type="checkbox" name="is_quick" defaultChecked={editing?.is_quick ?? false} />Pin on order screen</label></div>
      <button className="button button-primary">{editing ? 'Save product' : 'Add product'}</button>
    </form>}
    {panel === 'category' && <section className="catalog-manage">
      <form key={editingCategory?.id ?? 'new-category'} className="settings-card compact-form" onSubmit={saveCategory}>
        <div className="role-form-heading"><div><h2>{editingCategory ? `Edit ${editingCategory.name}` : 'Add category'}</h2><p>Rename, hide or add groups used on the order screen.</p></div><button type="button" className="button button-secondary" onClick={() => { setPanel(null); setEditingCategory(null) }}>Close</button></div>
        <div className="form-grid">
          <Field label="Category name" name="name" required defaultValue={editingCategory?.name ?? ''} />
          <label className="check-row field-wide"><input type="checkbox" name="is_active" value="1" defaultChecked={editingCategory?.is_active ?? true} />Active category</label>
        </div>
        <div className="form-action-row">
          <button className="button button-primary" type="submit">{editingCategory ? 'Save category' : 'Add category'}</button>
          {editingCategory && <button className="button button-secondary" type="button" onClick={() => setEditingCategory(null)}>Cancel edit</button>}
        </div>
      </form>
      <section className="data-card">
        <div className="data-card-heading"><UtensilsCrossed size={19} /><h2>Categories</h2><span>{categories.length} total</span></div>
        <div className="data-list">{categories.map((category) => <div className="data-row" key={category.id}><div><strong>{category.name}</strong><small>{products.filter((product) => product.category_id === category.id).length} products</small></div><span className={`status-badge ${category.is_active ? 'status-green' : 'status-red'}`}>{category.is_active ? 'Active' : 'Inactive'}</span><span className="row-actions"><button className="row-action" type="button" title={`Edit ${category.name}`} onClick={() => { setEditingCategory(category); setError(''); setSuccess('') }}><Pencil size={16} /></button><button className="row-action danger" type="button" title={`Delete ${category.name}`} onClick={() => setDeleting({ kind: 'category', id: category.id, name: category.name })}><Trash2 size={16} /></button></span></div>)}{!categories.length && <p className="empty-row">No categories yet.</p>}</div>
      </section>
    </section>}
    {panel === 'station' && <section className="catalog-manage">
      <form key={editingStation?.id ?? 'new-station'} className="settings-card compact-form" onSubmit={saveStation}>
        <div className="role-form-heading"><div><h2>{editingStation ? `Edit ${editingStation.name}` : 'Add kitchen station'}</h2><p>Stations route kitchen tickets. Products keep selling if you delete one.</p></div><button type="button" className="button button-secondary" onClick={() => { setPanel(null); setEditingStation(null) }}>Close</button></div>
        <div className="form-grid">
          <Field label="Station name" name="name" required defaultValue={editingStation?.name ?? ''} />
          <Field label="Station code" name="code" required defaultValue={editingStation?.code ?? ''} />
          <Select label="Outlet" name="outlet_id" required defaultValue={editingStation?.outlet_id ?? ''}><option value="" disabled>Select outlet</option>{outlets.filter((outlet) => outlet.is_active || outlet.id === editingStation?.outlet_id).map((outlet) => <option key={outlet.id} value={outlet.id}>{outlet.name}</option>)}</Select>
          <label className="check-row field-wide"><input type="checkbox" name="is_active" value="1" defaultChecked={editingStation?.is_active ?? true} />Active station</label>
        </div>
        <div className="form-action-row">
          <button className="button button-primary" type="submit">{editingStation ? 'Save station' : 'Add station'}</button>
          {editingStation && <button className="button button-secondary" type="button" onClick={() => setEditingStation(null)}>Cancel edit</button>}
        </div>
      </form>
      <section className="data-card">
        <div className="data-card-heading"><CookingPot size={19} /><h2>Kitchen stations</h2><span>{stations.length} total</span></div>
        <div className="data-list">{stations.map((station) => <div className="data-row" key={station.id}><div><strong>{station.name}</strong><small>{station.code} · {station.outlet?.name ?? 'Outlet'}</small></div><span className={`status-badge ${station.is_active ? 'status-green' : 'status-red'}`}>{station.is_active ? 'Active' : 'Inactive'}</span><span className="row-actions"><button className="row-action" type="button" title={`Edit ${station.name}`} onClick={() => { setEditingStation(station); setError(''); setSuccess('') }}><Pencil size={16} /></button><button className="row-action danger" type="button" title={`Delete ${station.name}`} onClick={() => setDeleting({ kind: 'station', id: station.id, name: station.name })}><Trash2 size={16} /></button></span></div>)}{!stations.length && <p className="empty-row">No kitchen stations yet.</p>}</div>
      </section>
    </section>}
    {error && <p className="form-error form-notice">{error}</p>}{success && <p className="form-success form-notice">{success}</p>}
    <section className="catalog-summary">
      <div><strong>{products.length}</strong><span>Products</span></div>
      {manage ? <button type="button" onClick={() => openPanel('category')}><strong>{categories.length}</strong><span>Categories — edit or delete</span></button> : <div><strong>{categories.length}</strong><span>Categories</span></div>}
      {manage && outlets.length > 0 ? <button type="button" onClick={() => openPanel('station')}><strong>{stations.length}</strong><span>Kitchen stations — edit or delete</span></button> : <div><strong>{stations.length}</strong><span>Kitchen stations</span></div>}
    </section>
    <label className="catalog-search"><Search size={17} /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search by name, SKU or barcode" /></label>
    <section className="product-grid">{filtered.map((product) => <article className="product-card" key={product.id}><div className="product-card-top"><span className="product-icon"><UtensilsCrossed size={20} /></span><div className="product-card-flags">{product.is_quick ? <span className="quick-pin-badge" title="Pinned on order screen"><Pin size={12} />Pinned</span> : null}<span className={product.is_active ? 'status-badge status-green' : 'status-badge status-red'}>{product.is_active ? 'Active' : 'Inactive'}</span></div></div><h2>{product.name}{product.serving_size && <small> · {product.serving_size}</small>}</h2>{product.short_description && <p>{product.short_description}</p>}<p>{product.category?.name ?? 'Uncategorized'} · {product.fulfillment_mode === 'kitchen' ? `Kitchen · ${product.kitchen_station?.name ?? 'Station required'}` : 'Direct service'}</p><strong className="product-price">{money(product.selling_price, session?.hotel.currency_code)}</strong><small>Tax {product.tax_rate}% · {product.track_inventory ? 'Inventory tracked' : 'No inventory tracking'}</small>{manage && <button className="text-action" onClick={() => { setEditing(product); openPanel('product') }}><Pencil size={14} />Edit product</button>}</article>)}{!filtered.length && <p className="empty-row">No products found.</p>}</section>
    <ConfirmDialog
      open={Boolean(deleting)}
      title={deleting?.kind === 'category' ? `Delete ${deleting.name}?` : deleting ? `Delete ${deleting.name}?` : 'Delete'}
      text={deleting?.kind === 'category' ? 'Products in this category stay on the menu as Uncategorized. This does not change prices or stop orders.' : 'Kitchen tickets keep their item names. Products on this station will need a new station if they go through the kitchen.'}
      confirmLabel="Delete"
      onClose={() => setDeleting(null)}
      onConfirm={() => void confirmDelete()}
    />
  </>
}

function Field({ label, ...props }: { label: string } & InputHTMLAttributes<HTMLInputElement>) { return <label className="field"><span>{label}</span><input {...props} /></label> }
function Select({ label, children, ...props }: { label: string; children: ReactNode } & SelectHTMLAttributes<HTMLSelectElement>) { return <label className="field"><span>{label}</span><select {...props}>{children}</select></label> }
