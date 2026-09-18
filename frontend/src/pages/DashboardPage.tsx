import { ArrowUpRight, Building2, ClipboardList, LayoutGrid, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../features/auth/AuthContext'
import { can, isOwner } from '../features/auth/permissions'

type SetupCardData = { number: string; title: string; text: string; to: string; icon: ReactNode }

export function DashboardPage() {
  const { session } = useAuth()
  const cards = [
    can(session, 'settings.manage') && { number: '01', title: 'Hotel profile', text: 'Confirm currency, timezone, contact and tax details.', to: '/app/settings/hotel', icon: <Building2 /> },
    can(session, 'settings.manage') && { number: '02', title: 'Outlets', text: 'Add the restaurant, bar or reception counters that bill separately.', to: '/app/settings/outlets', icon: <LayoutGrid /> },
    can(session, 'users.view') && { number: '03', title: 'Staff access', text: 'Add managers, cashiers, waiters and kitchen staff with the correct role.', to: '/app/settings/staff', icon: <Users /> },
    isOwner(session) && { number: '04', title: 'Roles & permissions', text: 'Review what each team member is able to see and do.', to: '/app/settings/roles', icon: <ClipboardList /> },
  ].filter(Boolean) as SetupCardData[]

  return <>
    <section className="page-heading"><div><p className="eyebrow">RESTAURANT MANAGEMENT</p><h1>Welcome back, {session?.user.name.split(' ')[0]}</h1><p>Set up your hotel team and outlets, then we'll move to menu and table operations.</p></div>{can(session, 'settings.manage') && <Link className="button button-primary" to="/app/settings/hotel"><Building2 size={17} />Hotel settings</Link>}</section>
    {cards.length > 0 && <section className="setup-grid">{cards.map((card) => <SetupCard key={card.number} {...card} />)}</section>}
    <section className="info-panel"><div className="info-icon"><ArrowUpRight size={23} /></div><div><h2>Next: menu and table operations</h2><p>Your access-management foundation is ready. We will now connect products, dining tables and the live order workflow.</p></div></section>
  </>
}

function SetupCard({ number, title, text, to, icon }: SetupCardData) {
  return <Link to={to} className="setup-card"><span className="setup-number">{number}</span><span className="setup-icon">{icon}</span><h2>{title}</h2><p>{text}</p><span className="card-link">Configure <ArrowUpRight size={15} /></span></Link>
}

export function PlaceholderPage({ title, description }: { title: string; description: string }) {
  return <section className="placeholder"><span className="placeholder-icon"><ClipboardList size={27} /></span><p className="eyebrow">COMING NEXT</p><h1>{title}</h1><p>{description}</p></section>
}
