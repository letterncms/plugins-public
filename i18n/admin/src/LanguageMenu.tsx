import { invalidate, navigate, run, t, useAbility, useManifest, type Entry } from '@letterncms/sdk'
import { Button, Menu, toast } from '@letterncms/ui'
import { useState } from 'react'
import { nativeName } from './names'

interface Versions {
  origin: string
  versions: { id: string; locale: string; name: string; title: string; status: string; original: boolean }[]
  missing: { locale: string; name: string }[]
}

/** Entry toolbar: switch between language versions and start missing ones. */
export function LanguageMenu({ entry, collection, save }: { entry: Entry | null; collection: { handle: string }; save: () => Promise<void> }) {
  const manifest = useManifest()
  const multilingual = manifest.site.locales.length > 1
  const versions = useAbility<Versions>('translations/list', { id: entry?.id ?? '' }, { enabled: multilingual && !!entry })
  const languages = useAbility<{ canTranslate: boolean }>('languages/list', {}, { enabled: multilingual && !!entry, staleTime: 60_000 })
  const [busy, setBusy] = useState(false)

  if (!entry || !multilingual) return null

  const start = async (locale: string, translate: boolean) => {
    setBusy(true)
    const pending = toast.loading(translate ? t('Translating into {language}…', { language: nativeName(locale) }) : t('Creating the {language} version…', { language: nativeName(locale) }))
    try {
      await save().catch(() => {})
      const created = await run<Entry>('translations/create', { id: entry.id, locale, translate })
      invalidate('translations/', 'entries/', 'content/')
      toast.success(translate ? t('Translated as a draft') : t('Copy created as a draft'), { id: pending, description: translate ? t('Review it, then publish.') : t('Translate the text, then publish.') })
      navigate(`/content/${collection.handle}/${created.id}`)
    } catch (e) {
      toast.error(e, { id: pending })
    } finally {
      setBusy(false)
    }
  }

  const missing = versions.data?.missing ?? []
  const canTranslate = languages.data?.canTranslate ?? false

  return (
    <Menu
      trigger={<Button variant="ghost" size="sm" icon="translate" loading={busy} title={t('Languages')}>{entry.locale.toUpperCase()}</Button>}
      items={[
        { label: t('Languages'), heading: true },
        ...(versions.data?.versions ?? []).map((v) => ({
          label: v.status === 'published' || v.status === 'changed' ? nativeName(v.locale) : t('{language} · draft', { language: nativeName(v.locale) }),
          checked: v.id === entry.id,
          onSelect: () => v.id !== entry.id && navigate(`/content/${collection.handle}/${v.id}`),
        })),
        missing.length > 0 && { separator: true, label: '' },
        ...missing.flatMap((m) => [
          canTranslate && { label: t('Translate into {language}', { language: nativeName(m.locale) }), icon: 'sparkle', onSelect: () => start(m.locale, true) },
          { label: canTranslate ? t('Copy for Manual Translation ({code})', { code: m.locale.toUpperCase() }) : t('Add {language} Version', { language: nativeName(m.locale) }), icon: 'copy', onSelect: () => start(m.locale, false) },
        ]),
      ]}
    />
  )
}
