import './styles.css'
import { navigate, registry, t } from '@letterncms/sdk'
import { lazy, useEffect } from 'react'
import { SeoPanel } from './SeoPanel'
import { NewRedirect } from './NewRedirect'

registry.panels.set('seo', { id: 'seo', slot: 'entry.sidebar', fieldHandles: ['seo'], label: 'SEO', icon: 'seo', component: SeoPanel, order: 20, when: (p: any) => !!p.collection?.route })
// Redirects are a tab of the SEO settings; the old address leads there.
registry.panels.set('redirects', { id: 'redirects', slot: 'settings.tabs', label: t('Redirects'), component: lazy(() => import('./Redirects')), order: 10, when: (p: { namespace: string }) => p.namespace === 'seo' })
registry.panels.set('redirects-new', { id: 'redirects-new', slot: 'settings.actions', component: NewRedirect, when: (p: { namespace: string; tab: string }) => p.namespace === 'seo' && p.tab === 'redirects' })
registry.routes.set('/redirects', { path: '/redirects', component: OldRedirects })
registry.widgets.set('seo-health', lazy(() => import('./WidgetHealth')))

function OldRedirects() {
  useEffect(() => navigate('/settings/seo?tab=redirects', { replace: true }), [])
  return null
}
