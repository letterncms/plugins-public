import { can, locale, navigate, t, useAbility } from '@letterncms/sdk'
import { Button, Meter, Widget } from '@letterncms/ui'

interface Coverage {
  total: number
  languages: { code: string; name: string; englishName: string; default: boolean; entries: number; share: number }[]
}

/** Home-screen widget: how much of the content exists in each language. */
export default function WidgetCoverage() {
  const coverage = useAbility<Coverage>('translations/coverage', {}, { staleTime: 60_000 })
  const manage = can('settings.manage')
  const data = coverage.data
  const percent = new Intl.NumberFormat(locale(), { style: 'percent', maximumFractionDigits: 0 })
  const languages = [...(data?.languages ?? [])].sort((a, b) => Number(b.default) - Number(a.default) || b.share - a.share).slice(0, 6)

  return (
    <Widget
      query={coverage}
      action={manage && { label: t('Languages'), to: '/languages' }}
      empty={
        data && data.languages.length < 2
          ? { icon: 'translate', title: t('One Language'), description: t('Add a language to translate your content.'), action: manage && <Button size="sm" icon="plus" onClick={() => navigate('/languages')}>{t('Add a Language')}</Button> }
          : data?.total === 0 && { icon: 'translate', title: t('No content to translate yet') }
      }
    >
      <div className="grid gap-x-8 @md:grid-cols-2">
        {languages.map((l) => (
          <div key={l.code} className="py-2">
            <div className="flex items-baseline gap-2 text-sm">
              <span className="min-w-0 truncate text-text" title={l.englishName}>{l.name}</span>
              {l.default && <span className="text-xs text-faint">{t('Default')}</span>}
              <span className="ml-auto shrink-0 text-xs tabular-nums text-muted">{percent.format(l.share)}</span>
            </div>
            <Meter value={l.share} tone={l.default ? 'muted' : l.share >= 1 ? 'success' : 'ink'} label={t('{language}: {count} of {total} entries', { language: l.englishName, count: l.entries, total: data?.total ?? 0 })} className="mt-1.5" />
          </div>
        ))}
      </div>
    </Widget>
  )
}
