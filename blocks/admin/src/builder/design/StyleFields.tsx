import type { FieldDef } from '@letterncms/sdk'
import type { StyleApi, ValueStatus } from '../useStyle'
import { PanelFields } from '../controls'

/**
 * A row of the Design tab. Its handle is the CSS property it edits (a group's sub-field
 * handles are), unless `get` and `set` read and write it another way; `keys` are the
 * properties behind its status and reset (on the inline label).
 */
export type StyleField = FieldDef & { get?: () => unknown; set?: (v: any) => void; keys?: string[] }

/** Style rows drawn by `<PanelFields >`, read and written at the variant being edited. */
export function StyleFields({ s, fields, bare }: { s: StyleApi; fields: (StyleField | false | null | undefined)[]; bare?: boolean }) {
  const list = fields.filter(Boolean) as StyleField[]
  const values: Record<string, any> = {}
  const defs = list.map(({ get, set: _set, keys, ...field }) => {
    const sub = field.fields as FieldDef[] | undefined
    const props = keys ?? (sub ? sub.map((f) => f.handle) : [field.handle])
    values[field.handle] = get ? get() : sub ? Object.fromEntries(props.map((k) => [k, s.value(k)])) : s.value(field.handle)
    const statuses = props.map((k) => s.status(k))
    const status = statuses.includes('own') ? 'own' : statuses.includes('inherited') ? 'inherited' : undefined
    return { status, reset: () => s.write(Object.fromEntries(props.map((k) => [k, null]))), ...field } as FieldDef
  })

  const onChange = (handle: string, v: any) => {
    const field = list.find((f) => f.handle === handle)!
    if (field.set) return field.set(v)
    if (!v || typeof v !== 'object') return s.set(handle, v)
    // A record (a group) writes the properties that changed, in one step.
    const patch = changed(values[handle] ?? {}, v)
    if (Object.keys(patch).length) s.write(patch as Record<string, string | null>)
  }

  return <PanelFields bare={bare} fields={defs} values={values} onChange={onChange} />
}

/** A row's status as the inline label shows it. */
export const shownStatus = (status: ValueStatus) => (status === 'none' ? undefined : status)

/** Which keys of a record changed, as a patch. */
export function changed(prev: Record<string, unknown>, next: Record<string, unknown> | null): Record<string, any> {
  return Object.fromEntries(Object.entries(next ?? {}).filter(([k, v]) => v !== prev[k]))
}
