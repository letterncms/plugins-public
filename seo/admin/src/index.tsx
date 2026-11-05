import './styles.css'
import { navigate, registry } from '@letterncms/sdk'
import { lazy, useEffect } from 'react'
import { SeoPanel } from './SeoPanel'

registry.panels.set('seo', { slot: 'entry.sidebar', fieldHandles: ['seo'], label: 'SEO', icon: 'seo', component: SeoPanel, order: 20, when: (p) => !!p.collection?.route })
// Redirects are a tab of the SEO settings (SeoPlugin::redirectsTab); the old address leads there.
registry.routes.set('/redirects', { path: '/redirects', component: OldRedirects })
registry.widgets.set('seo-health', lazy(() => import('./WidgetHealth')))

function OldRedirects() {
  useEffect(() => navigate('/settings/seo?tab=redirects', { replace: true }), [])
  return null
}
