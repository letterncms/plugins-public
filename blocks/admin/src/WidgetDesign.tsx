import { can, Link, navigate, plural, t, timeAgo, useAbility, useManifest, type EntrySummary } from '@letterncms/sdk'
import { Button, Icon, Widget, WidgetRow } from '@letterncms/ui'

interface Page {
  items: EntrySummary[]
  total: number
}

const recent = { sort: '-updated_at', perPage: 5 }

/** Home-screen widget: the components and templates changed last, and the way into each. */
export default function WidgetDesign() {
  const manifest = useManifest()
  const components = useAbility<Page>('entries/list', { collection: 'components', ...recent })
  const templates = useAbility<Page>('entries/list', { collection: 'templates', ...recent })
  const collection = (handle: string) => manifest.collections.find((c) => c.handle === handle)
  const items = [...(components.data?.items ?? []), ...(templates.data?.items ?? [])].sort((a, b) => (b.updatedAt ?? '').localeCompare(a.updatedAt ?? '')).slice(0, 5)
  // Either list may be off limits; the widget shows whichever loads.
  const query = { isLoading: components.isLoading || templates.isLoading, isError: components.isError && templates.isError, refetch: () => Promise.all([components.refetch(), templates.refetch()]) }
  const links = [
    { handle: 'components', total: components.data?.total, label: (n: number) => plural(n, '{count} component', '{count} components') },
    { handle: 'templates', total: templates.data?.total, label: (n: number) => plural(n, '{count} template', '{count} templates') },
  ].filter((l) => l.total !== undefined)

  return (
    <Widget
      query={query}
      action={can('settings.manage') && { label: t('Design System'), to: '/design' }}
      empty={
        items.length === 0 && {
          icon: 'symbol',
          title: t('No components or templates yet'),
          action: collection('components')?.can.create && <Button size="sm" icon="symbol" onClick={() => navigate('/content/components/new')}>{t('New Component')}</Button>,
        }
      }
    >
      {items.map((e) => {
        const c = collection(e.collection)
        return (
          <WidgetRow
            key={e.id}
            to={`/content/${e.collection}/${e.id}`}
            icon={c?.icon ?? 'symbol'}
            title={<span className="font-medium">{e.title || t('Untitled')}</span>}
            meta={
              <span className="flex items-center gap-4">
                <span className="hidden text-faint @md:inline">{c?.singular}</span>
                <span className="w-16 text-right">{timeAgo(e.updatedAt)}</span>
              </span>
            }
          />
        )
      })}
      <div className="mt-2 flex items-center gap-4 border-t border-border pt-2.5">
        {links.map((l) => (
          <Link key={l.handle} to={`/content/${l.handle}`} className="flex items-center gap-1.5 text-xs text-muted no-underline transition-colors duration-120 hover:text-text">
            <Icon name={collection(l.handle)?.icon ?? 'symbol'} size={13} className="text-faint" />
            {l.label(l.total ?? 0)}
          </Link>
        ))}
      </div>
    </Widget>
  )
}
