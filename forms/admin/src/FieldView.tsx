import { blockViews, useEditorContext, type BlockNode } from '@letterncms/blocks'
import { t, type FieldDef } from '@letterncms/sdk'
import { Fields, Icon, cn } from '@letterncms/ui'

const label = (): FieldDef => ({ handle: 'label', type: 'text', component: 'forms-label', label: t('Label') })

/** Write-lens view of a form field: looks like the field, label editable in place. */
function FieldView({ node, onSettings }: { node: BlockNode; onSettings: (id: string) => void }) {
  const { store, types } = useEditorContext()
  const kind = node.type.slice(5)
  const p = node.props
  const type = types.get(node.type)
  const options = String(p.options ?? '').split('\n').filter(Boolean).map((l) => (l.includes('|') ? l.split('|')[1] : l))
  const box = 'rounded-md border border-border-strong bg-bg px-3 text-sm text-faint'

  if (kind === 'hidden') {
    return (
      <button type="button" onClick={() => onSettings(node.id)} className="flex h-8 items-center gap-2 rounded-md border border-dashed border-border px-3 text-xs text-faint">
        <Icon name="eye-off" size={13} /> {t('Hidden')} <span className="font-mono">{p.name || t('unnamed')}</span> = {p.value || '""'}
      </button>
    )
  }

  return (
    <div className="space-y-1.5 py-1" onClick={() => store.select(node.id)}>
      {kind !== 'checkbox' && kind !== 'consent' && (
        <div className="flex items-center gap-2">
          <Fields bare className="min-w-0 flex-1" fields={[{ ...label(), display: 'heading', placeholder: t('Label') }]} values={p} onChange={(_, v) => store.updateProps(node.id, { label: v })} />
          {p.required && <span className="text-xs text-faint">{t('Required')}</span>}
          <button type="button" onClick={() => onSettings(node.id)} className="rounded-xs px-1 font-mono text-2xs text-faint hover:bg-hover hover:text-text" title={t('Field Settings')}>
            {p.name || t('name?')}
          </button>
        </div>
      )}
      {kind === 'textarea' ? (
        <div className={cn(box, 'h-20 py-2')}>{p.placeholder}</div>
      ) : kind === 'select' ? (
        <div className={cn(box, 'flex h-9 items-center justify-between')}>{p.placeholder || t('Choose…')}<Icon name="chevron-down" size={13} /></div>
      ) : kind === 'radio' || kind === 'checkboxes' ? (
        <div className="space-y-1">
          {options.map((o, i) => (
            <div key={i} className="flex items-center gap-2 text-sm text-muted">
              <span className={cn('size-3.5 border border-border-strong', kind === 'radio' ? 'rounded-full' : 'rounded-[3px]')} /> {o}
            </div>
          ))}
        </div>
      ) : kind === 'checkbox' || kind === 'consent' ? (
        <div className="flex items-start gap-2 text-sm">
          <span className="mt-0.5 size-3.5 shrink-0 rounded-[3px] border border-border-strong" />
          {kind === 'consent' ? (
            <span className="text-muted" dangerouslySetInnerHTML={{ __html: String(p.text ?? '') }} />
          ) : (
            <Fields bare className="min-w-0 flex-1" fields={[label()]} values={p} onChange={(_, v) => store.updateProps(node.id, { label: v })} />
          )}
        </div>
      ) : kind === 'file' ? (
        <div className={cn(box, 'flex h-12 items-center gap-2 border-dashed')}><Icon name="upload" size={14} /> {t('Choose a File')}</div>
      ) : (
        <div className={cn(box, 'flex h-9 items-center gap-2')}>
          <Icon name={type?.icon ?? 'text'} size={13} />
          {p.placeholder}
        </div>
      )}
      {p.help && <p className="m-0 text-xs text-faint">{p.help}</p>}
      {p.show_if?.field && <p className="m-0 text-2xs text-focus">{t('Shown when {field} {operator} {value}', { field: p.show_if.field, operator: p.show_if.op ?? 'equals', value: p.show_if.value })}</p>}
    </div>
  )
}

export function registerFieldViews() {
  blockViews.set('form/*', FieldView as any)
}
