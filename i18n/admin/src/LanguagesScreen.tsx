import { invalidate, loadManifest, plural, run, t, useAbility, type RecordTimestamps } from '@letterncms/sdk'
import { Badge, Button, DataView, EmptyState, Fields, Icon, Menu, Page, Skeleton, confirm, toast } from '@letterncms/ui'
import { useState } from 'react'
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
  const [busy, setBusy] = useState(false)
  const [adding, setAdding] = useState('')

  if (data.isLoading) return <Page width="wide" crumbs={[{ label: t('Languages'), icon: 'translate' }]}><Skeleton className="h-40" /></Page>
  if (!data.data) return <Page width="wide" crumbs={[{ label: t('Languages'), icon: 'translate' }]}><EmptyState title={t('Languages are not available')} /></Page>

  const { languages, prefixDefault, canTranslate } = data.data
  const current = languages.map((l) => l.code)
  const fallback = languages.find((l) => l.default)!.code

  const save = async (next: { default?: string; languages?: string[]; prefixDefault?: boolean }, message: string) => {
    setBusy(true)
    try {
      await run('languages/save', { default: next.default ?? fallback, languages: next.languages ?? current, prefixDefault: next.prefixDefault ?? prefixDefault })
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
    setAdding('')
    if (code && !current.includes(code)) void save({ languages: [...current, code] }, t('{language} added', { language: nativeName(code) }))
  }

  const remove = async (l: Language) => {
    if (!(await confirm({ title: t('Remove {language}?', { language: l.name }), description: t('Visitors will no longer see this language. You can add it again later.'), confirm: t('Remove'), danger: true }))) return
    void save({ languages: current.filter((c) => c !== l.code) }, t('{language} removed', { language: l.name }))
  }

  const makeDefault = async (l: Language) => {
    if (!(await confirm({ title: t('Make {language} the default?', { language: l.name }), description: t('Its pages move to the root URL and {current} gets a prefix. Old URLs redirect automatically.', { current: languages.find((x) => x.default)?.name }), confirm: t('Make Default') }))) return
    void save({ default: l.code }, t('{language} is now the default', { language: l.name }))
  }

  const options = Object.fromEntries(COMMON.filter((c) => !current.includes(c)).map((c) => [c, `${localName(c)} — ${nativeName(c)} (${c})`]))

  return (
    <Page
      width="wide"
      crumbs={[{ label: t('Languages'), icon: 'translate' }]}
    >
      <DataView id="languages" label={t('Languages')} items={languages} rowKey={(l) => l.code} searchText={(l) => `${l.name} ${l.code}`}
        columns={[
          { id: 'name', label: t('Name'), value: (l) => l.name, render: (l) => <span className="flex items-center gap-2"><span className="truncate">{l.name}</span>{l.default && <Badge>{t('Default')}</Badge>}</span> },
          { id: 'code', label: t('Code'), width: '90px', value: (l) => l.code, render: (l) => <code>{l.code}</code> },
          { id: 'prefix', label: t('URL Prefix'), width: '140px', value: (l) => l.prefix, render: (l) => l.prefix ? <code>/{l.prefix}/…</code> : t('No URL Prefix') },
          { id: 'direction', label: t('Direction'), width: '120px', render: (l) => l.direction === 'rtl' ? t('Right to Left') : t('Left to Right') },
          { id: 'entries', label: t('Entries'), width: '90px', align: 'end', value: (l) => l.entries, render: (l) => l.entries },
        ]}
        actions={(l) => <Menu
              trigger={<Button variant="ghost" size="sm" icon="more" aria-label={t('Options for {language}', { language: l.name })} disabled={busy} />}
              items={[
                { label: t('Make Default'), icon: 'star', disabled: l.default, onSelect: () => makeDefault(l) },
                { label: l.entries > 0 ? plural(l.entries, 'Remove ({count} entry left)', 'Remove ({count} entries left)') : t('Remove'), icon: 'trash', danger: true, disabled: l.default || l.entries > 0, onSelect: () => remove(l) },
              ]}
            />}
        empty={<EmptyState title={t('Languages are not available')} />}
      />

      <Fields bare className="mt-4 w-80 max-w-full" disabled={busy} fields={[{ handle: 'language', type: 'select', label: t('Add a Language'), placeholder: t('Add a Language…'), required: true, options }]} values={{ language: adding }} onChange={(_, v) => typeof v === 'string' && v !== '' && add(v)} />

      <div className="mt-10 space-y-5">
        <Fields
          disabled={busy}
          fields={[{ handle: 'prefixDefault', type: 'toggle', label: t('Prefix the Default Language Too'), instructions: t('Use /{code}/… for every page instead of the root.', { code: fallback }) }]}
          values={{ prefixDefault }}
          onChange={(_, v) => save({ prefixDefault: v }, v ? t('Default language now uses a prefix') : t('Default language now at the root'))}
        />
        <div className="flex items-start gap-3 text-sm text-muted">
          <Icon name={canTranslate ? 'sparkle' : 'info'} size={16} className="mt-0.5 shrink-0" />
          <p className="m-0">
            {canTranslate
              ? t('AI translation is on: choose Translate in a page’s language menu. Translations start as drafts.')
              : t('To translate pages automatically, set up the assistant with an Anthropic API key. Until then, the language menu copies a page for manual translation.')}
          </p>
        </div>
      </div>
    </Page>
  )
}
