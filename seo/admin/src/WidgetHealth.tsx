import { plural, t, useAbility, useManifest } from '@letterncms/sdk'
import { BarRow, Stat, Widget, WidgetRow, WidgetSkeleton, cn } from '@letterncms/ui'

interface Health {
  pages: number
  score: number | null
  attention: number
  good: number
  issues: { id: string; label: string; pages: number; failing: number }[]
  worst: { id: string; collection: string; locale: string; title: string; score: number; issues: number; top: string | null }[]
}

/** Home-screen widget: how published pages score for search and AI answers, the most common issues and the pages to fix first. */
export default function WidgetHealth() {
  const health = useAbility<Health>('seo/health', { limit: 5 }, { staleTime: 60_000 })
  const h = health.data
  // Versions of a page share its title; the language tells them apart.
  const multilingual = useManifest().site.locales.length > 1

  return (
    <Widget query={health} skeleton={<WidgetSkeleton rows={5} />} empty={h?.pages === 0 && { icon: 'seo', title: t('No published pages yet') }}>
      {h && (
        <>
          <div className="grid grid-cols-2 gap-4 @md:grid-cols-3">
            <Stat label={t('Average Score')} value={<>{h.score}<span className="text-sm font-normal text-faint">/100</span></>} />
            <Stat label={t('Need Attention')} value={<>{h.attention}<span className="text-sm font-normal text-faint"> / {h.pages}</span></>} />
            <Stat label={t('Pages Checked')} value={h.pages} className="hidden @md:block" />
          </div>

          <div className="mt-5 grid gap-x-8 gap-y-5 @lg:grid-cols-2">
            {h.issues.length > 0 && (
              <div className="min-w-0">
                <p className="m-0 mb-1 text-xs text-muted">{t('Common Issues')}</p>
                {h.issues.slice(0, 3).map((issue) => (
                  <BarRow key={issue.id} label={issue.label} value={plural(issue.pages, '{count} page', '{count} pages')} share={issue.pages / h.pages} />
                ))}
              </div>
            )}
            <div className="min-w-0">
              <p className="m-0 mb-0.5 text-xs text-muted">{t('Lowest Scores')}</p>
              {h.worst.length === 0 ? (
                <p className="m-0 py-2 text-sm text-muted">{t('Every page scores {score} or more.', { score: h.good })}</p>
              ) : (
                h.worst.map((page, i) => (
                  <WidgetRow
                    key={page.id}
                    to={`/content/${page.collection}/${page.id}`}
                    icon="file"
                    title={
                      <>
                        {page.title || t('Untitled')}
                        {multilingual && <span className="ml-1.5 text-2xs uppercase text-faint">{page.locale}</span>}
                      </>
                    }
                    meta={<span className={cn('tabular-nums', page.score < 50 ? 'text-danger' : 'text-muted')}>{page.score}</span>}
                    className={i >= 3 ? 'hidden @md:flex' : undefined}
                  />
                ))
              )}
            </div>
          </div>
        </>
      )}
    </Widget>
  )
}
