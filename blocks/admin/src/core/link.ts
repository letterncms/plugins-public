import { registry, type FieldDef } from '@letterncms/sdk'

/** A URL field drawn with the link picker (pages, entries, anchors) when it is available. */
export function linkField(def: Partial<FieldDef> & { handle: string; label: string }): FieldDef {
  return registry.fields.get('link') ? ({ ...def, type: 'url', component: 'link' } as FieldDef) : ({ ...def, type: 'url' } as FieldDef)
}
