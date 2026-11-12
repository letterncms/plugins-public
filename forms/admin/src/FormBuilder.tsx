import { EditorProvider, EditorStore, WriteEditor, useCatalog, type BlockNode } from '@letterncms/blocks'
import { can, invalidate, run, useAbility, type SurfaceComponentProps } from '@letterncms/sdk'
import { NotFoundState, SaveBar, Spinner } from '@letterncms/ui'
import { useMemo, useRef, useState } from 'react'

interface Form {
  handle: string
  title: string
  fields: BlockNode[]
}

const ALLOWED_TOP = ['paragraph', 'heading', 'columns', 'divider', 'callout']

/**
 * A form's fields, edited like a page: the Fields tab of the view `form` (FormsViews). Its
 * save bar is the page's, with the other tabs' changes.
 */
export default function FormBuilder({ props }: SurfaceComponentProps<{ handle: string }>) {
  const form = useAbility<Form>('forms/get', { handle: props.handle }, { refetchOnWindowFocus: false })
  if (form.isLoading) return <div className="grid place-items-center py-10 text-faint"><Spinner /></div>
  if (!form.data) return <NotFoundState icon="form" />
  return <Builder key={form.data.handle} form={form.data} />
}

function Builder({ form }: { form: Form }) {
  const { catalog, types } = useCatalog()
  const saved = useRef(form.fields)
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [version, setVersion] = useState(0)
  const fields = useRef<BlockNode[]>(form.fields)
  // A new store puts the fields back as saved (Discard).
  const store = useMemo(() => new EditorStore(saved.current, (n) => { fields.current = n; setDirty(true) }), [version])
  const manage = can('forms.manage')
  const allowed = useMemo(() => [...[...types.keys()].filter((type) => type.startsWith('form/') && type !== 'forms/form'), ...ALLOWED_TOP], [types])

  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      const stored = await run<Form>('forms/save', { handle: form.handle, fields: fields.current })
      saved.current = stored.fields
      setDirty(false)
      invalidate('forms/', 'form-words/', 'form-submissions/')
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  const discard = () => {
    fields.current = saved.current
    setDirty(false)
    setError(null)
    setVersion((v) => v + 1)
  }

  if (!catalog) return <div className="grid place-items-center py-10 text-faint"><Spinner /></div>
  return (
    // The column of every form-like page; the blocks' handles hang in its margin where the window has one.
    <div className="pl-14 xl:-ml-14">
      <EditorProvider key={version} value={{ store, types, catalog, entryId: null, fieldHandle: 'fields', allowed, getValues: () => ({}), title: form.title }}>
        <WriteEditor />
      </EditorProvider>
      {manage && <SaveBar dirty={dirty} saving={busy} error={error} onSave={() => void save()} onDiscard={discard} />}
    </div>
  )
}
