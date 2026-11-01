import { invalidate, plural, run, t } from '@letterncms/sdk'
import { Button, Dialog, Fields, toast } from '@letterncms/ui'
import { useState } from 'react'
import { createRoot } from 'react-dom/client'
import type { EditorStore } from './store'
import * as tree from './tree'
import { newId, type BlockNode } from './types'

/** Lowercase, dash-separated, valid as a pattern handle (^[a-z0-9][a-z0-9_-]{0,63}$). */
export function patternHandle(title: string, taken: Set<string>): string {
  const base = title.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 56) || 'pattern'
  let handle = base
  for (let i = 2; taken.has(handle); i++) handle = `${base}-${i}`
  return handle
}

/**
 * Save the selected blocks as a pattern. A synced pattern replaces the
 * selection with a reference, so this page updates with the pattern too;
 * `canReference` is false where a pattern block isn't allowed.
 */
export function savePattern(store: EditorStore, canReference: boolean) {
  const ids = store.selection()
  const nodes = ids.map((id) => tree.find(store.get().nodes, id)).filter((n): n is BlockNode => !!n)
  if (nodes.length === 0) return

  const host = document.createElement('div')
  document.body.appendChild(host)
  const root = createRoot(host)

  function SaveDialog() {
    const [open, setOpen] = useState(true)
    const [values, setValues] = useState({ title: '', category: '', description: '', synced: canReference })
    const { title, category, description, synced } = values
    const set = (handle: string, v: unknown) => setValues((current) => ({ ...current, [handle]: v ?? '' }))
    const [busy, setBusy] = useState(false)

    const close = () => {
      setOpen(false)
      setTimeout(() => {
        root.unmount()
        host.remove()
      }, 250)
    }

    const save = async () => {
      if (!title.trim()) return
      setBusy(true)
      try {
        const existing = await run<{ handle: string }[]>('patterns/list')
        const handle = patternHandle(title, new Set(existing.map((p) => p.handle)))
        await run('patterns/save', { handle, title: title.trim(), description: description.trim(), category: category.trim() || undefined, synced, blocks: nodes })
        invalidate('patterns/')
        if (synced) store.replaceSelection([{ id: newId(), type: 'pattern', props: { ref: handle } }])
        toast.success(synced ? t('“{title}” is now a component', { title: title.trim() }) : t('Saved “{title}” as a section', { title: title.trim() }))
        close()
      } catch (e) {
        toast.error(e)
        setBusy(false)
      }
    }

    return (
      <Dialog
        open={open}
        onOpenChange={(o) => !o && close()}
        size="sm"
        title={synced ? t('Make a Component') : t('Save as a Section')}
        description={plural(nodes.length, '{count} block, reusable on every page', '{count} blocks, reusable on every page')}
        footer={
          <>
            <Button variant="ghost" onClick={close}>{t('Cancel')}</Button>
            <Button variant="primary" disabled={!title.trim()} loading={busy} onClick={save}>{synced ? t('Make Component') : t('Save Section')}</Button>
          </>
        }
      >
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            void save()
          }}
        >
          <Fields
            fields={[
              { handle: 'title', type: 'text', label: t('Name'), required: true, autofocus: true, max: 120, placeholder: t('Pricing Card') },
              { handle: 'category', type: 'text', label: t('Category'), max: 60, placeholder: synced ? t('Components') : t('Sections') },
              { handle: 'description', type: 'text', label: t('Description'), max: 500, placeholder: t('When to Use It') },
            ]}
            values={values}
            onChange={set}
          />
          <Fields
            disabled={!canReference}
            fields={[{ handle: 'synced', type: 'toggle', label: canReference ? t('Keep Every Copy in Sync') : t('Saved as a section: components can’t be placed here'), instructions: canReference ? t('Off: a section that is copied when inserted.') : undefined }]}
            values={values}
            onChange={(h, v) => set(h, !!v)}
          />
          <button type="submit" hidden />
        </form>
      </Dialog>
    )
  }

  root.render(<SaveDialog />)
}
