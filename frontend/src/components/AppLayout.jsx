import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { notificationApi } from '../api/endpoints'
import { roleLabel, roleTone } from '../lib/permissions'
import { initials } from '../lib/format'

/**
 * Application shell: sidebar, top bar, content area.
 *
 * Navigation is built from a menu definition filtered by role, so a student
 * never sees a coordinator's menu. This is presentation only — the API
 * enforces every rule independently.
 */

// ---------------------------------------------------------------------
// Menu definition
// ---------------------------------------------------------------------
// Grouped so the sidebar reads as a workflow rather than a flat list.
// `roles` is the complete set allowed to see the item.

const MENU = [
  {
    group: 'Dashboard',
    items: [
      { to: '/student/dashboard', label: 'My Project', roles: ['student'] },
      { to: '/supervisor/dashboard', label: 'My Supervisees', roles: ['supervisor'] },
      { to: '/coordinator/dashboard', label: 'Cohort Overview', roles: ['coordinator', 'admin'] },
      { to: '/examiner/dashboard', label: 'My Assignments', roles: ['examiner'] },
      { to: '/admin/dashboard', label: 'System Overview', roles: ['admin'] },
    ],
  },
  {
    group: 'Projects',
    items: [
      { to: '/projects', label: 'All Projects', roles: ['student', 'supervisor', 'coordinator', 'examiner', 'admin'] },
      { to: '/milestones', label: 'Milestones', roles: ['student', 'supervisor', 'coordinator', 'examiner'] },
      { to: '/assignments', label: 'Supervision', roles: ['coordinator', 'admin'] },
    ],
  },
  {
    group: 'Assessment',
    items: [
      { to: '/evaluations', label: 'My Marking', roles: ['supervisor', 'examiner'] },
      { to: '/grades', label: 'Grades & Release', roles: ['coordinator', 'admin'] },
      { to: '/rubrics', label: 'Rubrics', roles: ['supervisor', 'examiner', 'coordinator', 'admin'] },
    ],
  },
  {
    group: 'Insight',
    items: [
      { to: '/reports', label: 'Reports', roles: ['coordinator', 'admin'] },
      { to: '/archive', label: 'Archive', roles: ['student', 'supervisor', 'coordinator', 'examiner', 'admin'] },
      { to: '/audit', label: 'Audit Log', roles: ['coordinator', 'admin'] },
      { to: '/users', label: 'User Accounts', roles: ['admin'] },
    ],
  },
  {
    group: 'Recognition',
    items: [
      { to: '/leaderboards', label: 'Leaderboards', roles: ['coordinator', 'admin'] },
      { to: '/leaderboard', label: 'Public Page', roles: ['coordinator', 'admin'], external: true },
    ],
  },
  {
    group: 'Account',
    items: [
      { to: '/notifications', label: 'Notifications', roles: ['student', 'supervisor', 'coordinator', 'examiner', 'admin'] },
      { to: '/profile', label: 'My Profile', roles: ['student', 'supervisor', 'coordinator', 'examiner', 'admin'] },
    ],
  },
]

function visibleMenu(role) {
  return MENU.map((section) => ({
    ...section,
    items: section.items.filter((item) => item.roles.includes(role)),
  })).filter((section) => section.items.length > 0)
}

// ---------------------------------------------------------------------

export default function AppLayout() {
  const { user, logout } = useAuth()
  const location = useLocation()

  const [unread, setUnread] = useState(0)
  const [menuOpen, setMenuOpen] = useState(false)

  const sections = visibleMenu(user?.role)

  // --- Unread notification count ---------------------------------------
  // Refetched on navigation rather than polled: the user is the only thing
  // that changes it in practice, and a polling loop would be noise.
  useEffect(() => {
    let cancelled = false

    notificationApi
      .summary()
      .then((data) => {
        if (!cancelled) setUnread(data?.unread_count ?? data?.unread ?? 0)
      })
      .catch(() => {
        // The badge is decoration; a failure must not disturb the page
      })

    return () => {
      cancelled = true
    }
  }, [location.pathname])

  // Close the mobile drawer on navigation
  useEffect(() => {
    setMenuOpen(false)
  }, [location.pathname])

  return (
    <div className="min-h-screen bg-slate-50">
      {/* Skip link — the sidebar is long, and keyboard users should not have
          to tab through every item to reach the content. */}
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50
                   focus:px-3 focus:py-2 focus:bg-white focus:border focus:border-slate-200
                   focus:rounded-lg focus:text-sm"
      >
        Skip to content
      </a>

      {/* ---------------- Sidebar ---------------- */}
      <aside
        className={`fixed inset-y-0 left-0 z-40 w-60 bg-white border-r border-slate-200
          flex flex-col transition-transform lg:translate-x-0
          ${menuOpen ? 'translate-x-0' : '-translate-x-full'}`}
      >
        {/* Brand */}
        <div className="h-14 flex items-center gap-2.5 px-4 border-b border-slate-200 shrink-0">
          <div className="w-7 h-7 rounded-lg bg-brand-600 text-white flex items-center justify-center text-xs font-medium">
            PSM
          </div>
          <div className="min-w-0">
            <p className="text-sm font-medium text-slate-900 leading-tight">PSM System</p>
            <p className="text-[11px] text-slate-500 leading-tight">Final Year Projects</p>
          </div>
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto px-2 py-3">
          {sections.map((section) => (
            <div key={section.group} className="mb-4 last:mb-0">
              <p className="px-3 mb-1 text-[11px] font-medium text-slate-400 uppercase tracking-wide">
                {section.group}
              </p>
              <ul>
                {section.items.map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      className={({ isActive }) =>
                        `block px-3 py-1.5 rounded-lg text-sm mb-0.5 transition-colors ${
                          isActive && !item.external
                            ? 'bg-brand-50 text-brand-700 font-medium'
                            : 'text-slate-600 hover:bg-slate-100'
                        }`
                      }
                    >
                      <span className="flex items-center justify-between gap-2">
                        {item.label}
                        {item.to === '/notifications' && unread > 0 && (
                          <span className="bg-rose-500 text-white text-[10px] font-medium px-1.5 py-0.5 rounded-full">
                            {unread > 99 ? '99+' : unread}
                          </span>
                        )}
                        {item.external && (
                          <span className="text-slate-300 text-xs" aria-hidden="true">
                            ↗
                          </span>
                        )}
                      </span>
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>

        {/* Signed-in user */}
        <div className="border-t border-slate-200 p-3 shrink-0">
          <div className="flex items-center gap-2.5 mb-2">
            <div className="w-8 h-8 rounded-full bg-brand-50 text-brand-700 flex items-center justify-center text-xs font-medium shrink-0">
              {initials(user?.name)}
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-xs font-medium text-slate-900 truncate">{user?.name}</p>
              <span
                className={`inline-block mt-0.5 px-1.5 py-0 rounded border text-[10px] font-medium ${roleTone(
                  user?.role,
                )}`}
              >
                {roleLabel(user?.role)}
              </span>
            </div>
          </div>
          <button
            type="button"
            onClick={logout}
            className="w-full text-left px-3 py-1.5 rounded-lg text-xs text-slate-600 hover:bg-slate-100"
          >
            Sign out
          </button>
        </div>
      </aside>

      {/* Scrim for the mobile drawer */}
      {menuOpen && (
        <button
          type="button"
          aria-label="Close navigation"
          onClick={() => setMenuOpen(false)}
          className="fixed inset-0 z-30 bg-slate-900/20 lg:hidden"
        />
      )}

      {/* ---------------- Main ---------------- */}
      <div className="lg:pl-60">
        {/* Mobile top bar */}
        <div className="lg:hidden h-14 flex items-center gap-3 px-4 bg-white border-b border-slate-200">
          <button
            type="button"
            onClick={() => setMenuOpen(true)}
            aria-label="Open navigation"
            className="p-1.5 rounded-lg hover:bg-slate-100"
          >
            <svg className="w-5 h-5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M4 6h16M4 12h16M4 18h16" strokeLinecap="round" />
            </svg>
          </button>
          <p className="text-sm font-medium text-slate-900">PSM System</p>
        </div>

        <main id="main" className="p-4 sm:p-6 lg:p-8 max-w-[1400px]">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
