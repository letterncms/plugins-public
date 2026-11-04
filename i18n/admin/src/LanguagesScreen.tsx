import { can, invalidate, Link, loadManifest, plural, run, t, useAbility, useManifest, type RecordTimestamps } from '@letterncms/sdk'
import { Badge, Button, DataView, Dialog, EmptyState, Icon, Input, Page, Popover, Skeleton, confirm, toast } from '@letterncms/ui'
import { useRef, useState, type KeyboardEvent } from 'react'
import { COMMON, localName, nativeName } from './names'

interface Language extends RecordTimestamps {
  code: string
  name: string
  default: boolean
  prefix: string
  direction: 'ltr' | 'rtl'
  entries: number
}

interface Data {
  prefixDefault: boolean
  canTranslate: boolean
  languages: Language[]
}

export default function LanguagesScreen() {
  const data = useAbility<Data>('languages/list')
  const manifest = useManifest()
  const [busy, setBusy] = useState(false)
  const [open, setOpen] = useState<string | null>(null)
  const crumbs = [{ label: t('Languages'), icon: 'translate' }]

  if (data.isLoading) return <Page width="narrow" crumbs={crumbs}><Skeleton className="h-40" /></Page>
  if (!data.data) return <Page width="narrow" crumbs={crumbs}><EmptyState title={t('Languages are not available')} /></Page>

  const { languages, prefixDefault, canTranslate } = data.data
  const current = languages.map((l) => l.code)
  const fallback = languages.find((l) => l.default)!
  // Without a translator, the way to one: the assistant's settings, where it has them.
  const assistant = !canTranslate && can('settings.manage') && manifest.settings.some((s) => s.namespace === 'assistant')

  const save = async (next: { default?: string; languages?: string[]; prefixDefault?: boolean }, message: string) => {
    setOpen(null)
    setBusy(true)
    try {
      await run('languages/save', { default: next.default ?? fallback.code, languages: next.languages ?? current, prefixDefault: next.prefixDefault ?? prefixDefault })
      invalidate('languages/', 'entries/', 'translations/')
      await loadManifest() // the admin's language filters read the manifest
      toast.success(message)
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }

  const add = (code: string) => {
    if (!current.includes(code)) void save({ languages: [...current, code] }, t('{language} added', { language: localName(code) }))
  }

  const remove = async (l: Language) => {
    if (!(await confirm({ title: t('Remove {language}?', { language: l.name }), description: t('Visitors will no longer see this language. You can add it again later.'), confirm: t('Remove'), danger: true }))) return
    void save({ languages: current.filter((c) => c !== l.code) }, t('{language} removed', { language: l.name }))
  }

  const makeDefault = async (l: Language) => {
    if (!(await confirm({ title: t('Make {language} the default?', { language: l.name }), description: t('Its pages move to the root URL and {current} gets a prefix. Old URLs redirect automatically.', { current: fallback.name }), confirm: t('Make Default') }))) return
    void save({ default: l.code }, t('{language} is now the default', { language: l.name }))
  }

  // The default language's own setting, so it is where its prefix is shown.
  const prefix = async (on: boolean) => {
    const title = on ? t('Give {language} a URL prefix?', { language: fallback.name }) : t('Remove the URL prefix of {language}?', { language: fallback.name })
    const description = on ? t('Its pages move to /{code}/….', { code: fallback.code }) : t('Its pages move to the root URL.')
    if (!(await confirm({ title, description, confirm: on ? t('Add Prefix') : t('Remove Prefix') }))) return
    void save({ prefixDefault: on }, on ? t('{language} now has a URL prefix', { language: fallback.name }) : t('{language} is now at the root URL', { language: fallback.name }))
  }

  return (
    <Page width="narrow" crumbs={crumbs} actions={<AddLanguage exclude={current} disabled={busy} onAdd={add} />}>
      {/* What fits the page: its name, code, prefix and pages. A row opens what can be done with it. */}
      <DataView id="languages" label={t('Languages')} items={languages} rowKey={(l) => l.code} searchText={(l) => `${l.name} ${l.code}`} onRowClick={(l) => setOpen(l.code)}
        columns={[
          { id: 'name', label: t('Name'), value: (l) => l.name, render: (l) => <span className="flex min-w-0 items-center gap-2"><span className="truncate">{l.name}</span>{l.default && <Badge>{t('Default')}</Badge>}</span> },
          { id: 'code', label: t('Code'), width: '72px', value: (l) => l.code, render: (l) => <code>{l.code}</code> },
          // On a phone the cells make one line under the name, so each says what it is.
          { id: 'prefix', label: t('URL Prefix'), width: '120px', value: (l) => l.prefix, render: (l) => <span className="truncate"><span className="sm:hidden">{t('URL Prefix')} </span>{l.prefix ? <code>/{l.prefix}/…</code> : <span className="text-faint">{t('None')}</span>}</span> },
          { id: 'entries', label: t('Entries'), width: '80px', align: 'end', value: (l) => l.entries, render: (l) => <><span className="sm:hidden">{plural(l.entries, '{count} entry', '{count} entries')}</span><span className="max-sm:hidden tabular-nums">{l.entries}</span></> },
        ]}
        empty={<EmptyState title={t('Languages are not available')} />}
      />
      {(() => {
        const l = languages.find((x) => x.code === open)
        if (!l) return null
        return (
          <Dialog open onOpenChange={(o) => !o && setOpen(null)} size="sm" title={l.name}
            footer={l.default ? (
              <Button variant="primary" disabled={busy} onClick={() => { setOpen(null); void prefix(!prefixDefault) }}>{prefixDefault ? t('Remove URL Prefix') : t('Add URL Prefix')}</Button>
            ) : <>
              <Button variant="ghost" className="mr-auto text-danger! max-sm:mr-0" disabled={busy || l.entries > 0} onClick={() => { setOpen(null); void remove(l) }}>{t('Remove')}</Button>
              <Button variant="primary" disabled={busy} onClick={() => { setOpen(null); void makeDefault(l) }}>{t('Make Default')}</Button>
            </>}>
            <dl className="m-0 grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-2 text-sm">
              <dt className="text-muted">{t('Code')}</dt><dd className="m-0"><code>{l.code}</code></dd>
              <dt className="text-muted">{t('URL Prefix')}</dt><dd className="m-0">{l.prefix ? <code>/{l.prefix}/…</code> : t('None')}</dd>
              <dt className="text-muted">{t('Direction')}</dt><dd className="m-0">{l.direction === 'rtl' ? t('Right to Left') : t('Left to Right')}</dd>
              <dt className="text-muted">{t('Entries')}</dt><dd className="m-0 tabular-nums">{l.entries}</dd>
            </dl>
            {!l.default && l.entries > 0 && <p className="m-0 mt-4 text-xs text-muted">{plural(l.entries, 'It can be removed once its {count} entry is gone.', 'It can be removed once its {count} entries are gone.')}</p>}
          </Dialog>
        )
      })()}

      {canTranslate ? (
        <p className="m-0 mt-4 flex items-center gap-2 text-sm text-muted"><Icon name="sparkle" size={14} className="shrink-0" />{t('Translate a page from its language menu.')}</p>
      ) : assistant && (
        <p className="m-0 mt-4 flex items-center gap-2 text-sm text-muted">
          <Icon name="sparkle" size={14} className="shrink-0" />
          <Link to="/settings/assistant" className="text-text underline underline-offset-2 hover:text-ink">{t('Set Up Automatic Translation')}</Link>
        </p>
      )}
    </Page>
  )
}

/** The New … of this screen: a searchable list of languages, each named once, in the admin's language. */
function AddLanguage({ exclude, disabled, onAdd }: { exclude: string[]; disabled: boolean; onAdd: (code: string) => void }) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [index, setIndex] = useState(0)
  const list = useRef<HTMLDivElement>(null)
  const query = search.trim().toLowerCase()
  const options = COMMON.filter((c) => !exclude.includes(c))
    .map((code) => ({ code, name: localName(code), native: nativeName(code) }))
    .filter((o) => !query || `${o.name} ${o.native} ${o.code}`.toLowerCase().includes(query))
    .sort((a, b) => a.name.localeCompare(b.name))
  // Any xx or xx-YY code works too, for languages not in the list.
  const typed = /^[a-z]{2,3}(-[A-Za-z]{2,4})?$/i.test(search.trim()) && !exclude.includes(search.trim()) && !options.some((o) => o.code.toLowerCase() === query) ? search.trim() : null
  const choices = typed ? [...options, { code: typed, name: localName(typed), native: nativeName(typed) }] : options

  const choose = (code: string) => {
    setOpen(false)
    setSearch('')
    onAdd(code)
  }
  const keys = (e: KeyboardEvent) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      const next = Math.max(0, Math.min(choices.length - 1, index + (e.key === 'ArrowDown' ? 1 : -1)))
      setIndex(next)
      list.current?.children[next]?.scrollIntoView({ block: 'nearest' })
    } else if (e.key === 'Enter' && choices[index]) {
      e.preventDefault()
      choose(choices[index].code)
    }
  }

  return (
    <Popover open={open} onOpenChange={(o) => { setOpen(o); if (!o) setSearch('') }} align="end" className="w-72 max-w-[calc(100vw-24px)]"
      trigger={<Button variant="primary" icon="plus" disabled={disabled}>{t('Add Language')}</Button>}>
      <Input size="sm" icon="search" aria-label={t('Search languages')} placeholder={t('Search languages…')} value={search} onChange={(e) => { setSearch(e.target.value); setIndex(0) }} onKeyDown={keys}
        role="combobox" aria-expanded aria-controls="add-language-options" aria-activedescendant={choices[index] ? `add-language-${choices[index].code}` : undefined} />
      <div ref={list} id="add-language-options" role="listbox" aria-label={t('Languages')} className="mt-1 max-h-72 overflow-y-auto">
        {choices.map((o, i) => (
          <button key={o.code} id={`add-language-${o.code}`} type="button" role="option" aria-selected={i === index} tabIndex={-1} onMouseEnter={() => setIndex(i)} onClick={() => choose(o.code)}
            className="flex min-h-8 w-full items-center gap-2 rounded-xs px-2 py-1 text-left text-sm aria-selected:bg-hover">
            <span className="min-w-0 flex-1 truncate">{o.name}{o.native !== o.name && <span className="text-muted"> · {o.native}</span>}</span>
            <code className="shrink-0 text-xs text-faint">{o.code}</code>
          </button>
        ))}
        {choices.length === 0 && <p className="m-0 px-2 py-3 text-xs text-muted">{t('No languages match your search.')}</p>}
      </div>
    </Popover>
  )
}
