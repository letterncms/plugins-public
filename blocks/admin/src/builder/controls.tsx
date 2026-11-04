import { registry, t, useManifestIfLoaded, type FieldContext, type FieldDef } from '@letterncms/sdk'
import { Icon, Tooltip, cn, fieldControlId, fieldVisible } from '@letterncms/ui'
import { useState, useSyncExternalStore, type ReactNode } from 'react'

/* The inspector's frame, shared by every tab: sections with a title row, then rows of a
   label column and a control. One gutter (16px), one row (28px controls, 4px apart), one
   label column (88px), so every panel lines up with the next. */

/** The label column every row shares. */
export const labelColumn = 'w-[88px]'

const COLLAPSED = 'cms.inspector.collapsed'

function collapsed(): Record<string, boolean> {
  try {
    const saved: unknown = JSON.parse(localStorage.getItem(COLLAPSED) ?? '{}')
    return saved && typeof saved === 'object' && !Array.isArray(saved) ? Object.fromEntries(Object.entries(saved).filter(([, value]) => typeof value === 'boolean')) : {}
  } catch {
    return {}
  }
}

/**
 * A titled group of rows. Every title works the same way: it folds and unfolds the
 * section (a folded one shows its caret), and `actions` sit at the right. Whether it
 * is open is remembered per title; `open` is the default. `empty` (nothing set) dims
 * the title; an empty section with an add action says so when opened, and adding opens it.
 */
export function Section({ title, children, open: initial = true, actions, empty }: { title: string; children: ReactNode; open?: boolean; actions?: ReactNode; empty?: boolean }) {
  const [open, setOpenState] = useState(() => collapsed()[title] === undefined ? initial : !collapsed()[title])
  const setOpen = (next: boolean) => {
    setOpenState(next)
    try {
      localStorage.setItem(COLLAPSED, JSON.stringify({ ...collapsed(), [title]: !next }))
    } catch {
      // Private mode: remember for this session only.
    }
  }
  return (
    <section data-section={title} className="min-w-0 border-b border-border last:border-b-0">
      <header className="group/section flex h-10 items-center gap-1 pl-4 pr-3">
        <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className={cn('flex h-full min-w-0 flex-1 items-center gap-1 text-left text-xs font-semibold', empty ? 'text-muted' : 'text-ink')}>
          <span className="min-w-0 truncate">{title}</span>
          {/* Open sections show their caret on hover; a folded one keeps it, so it reads as one. */}
          <Icon name="b-caret-down" size={10} className={cn('shrink-0 text-faint transition-[rotate,opacity] duration-120', open ? 'opacity-0 group-hover/section:opacity-100 group-focus-within/section:opacity-100' : '-rotate-90')} />
        </button>
        {/* Adding something shows it: the section opens. */}
        {actions && <span className="contents" onClickCapture={() => !open && setOpen(true)}>{actions}</span>}
      </header>
      {open && <div className="space-y-1 px-4 pb-3">{empty && actions ? <p className="m-0 flex h-7 items-center text-xs text-faint">{t('None')}</p> : children}</div>}
    </section>
  )
}

/** A panel's content that isn't a section (a tab's own fields at the top): the same gutter and rhythm. */
export function Body({ children, className }: { children: ReactNode; className?: string }) {
  return <div data-section="content" className={cn('space-y-1 border-b border-border px-4 py-3 last:border-b-0', className)}>{children}</div>
}

/** A small heading inside a section, with an add button. */
export function SubHeading({ title, action }: { title: string; action?: ReactNode }) {
  return (
    <div className="flex h-7 items-center justify-between pt-1">
      <span className="text-xs text-muted">{title}</span>
      {action}
    </div>
  )
}

/**
 * The label of a row. A value set here reads darker (click it to reset); one that comes
 * from a larger screen, another state or the component carries a small dot. Nothing else
 * changes color: the panel stays one quiet column.
 */
export function RowLabel({ field, children, htmlFor, className }: { field: FieldDef; children?: ReactNode; htmlFor?: string; className?: string }) {
  const status = field.status as 'own' | 'inherited' | undefined
  const reset = field.reset as (() => void) | undefined
  const label = children ?? field.label
  const base = cn('flex h-7 min-w-0 shrink-0 items-center gap-1 text-left text-xs', labelColumn, status === 'own' ? 'text-ink' : 'text-muted', className)
  const text = <span className="min-w-0 truncate">{label}</span>
  if (status === 'own' && reset) {
    return (
      <Tooltip content={t('Reset {label}', { label: String(field.label ?? '') })}>
        <button type="button" onClick={reset} className={cn(base, 'decoration-faint decoration-dotted underline-offset-[3px] hover:underline')}>{text}</button>
      </Tooltip>
    )
  }
  if (status === 'inherited') {
    return (
      <label htmlFor={htmlFor} className={base} title={t('Inherited from a larger screen, another state or the component')}>
        {text}
        <span className="size-1 shrink-0 rounded-full bg-warning" aria-hidden />
      </label>
    )
  }
  return <label htmlFor={htmlFor} className={base} title={field.instructions as string | undefined}>{text}</label>
}

/** About how many characters of a label fit its column. */
const LABEL_FITS = 15

/** Field types that need the panel's full width: the label goes above them. */
export const WIDE = ['richtext', 'markdown', 'code', 'json', 'textarea', 'media', 'repeater', 'tags', 'entries', 'group', 'properties', 'fields']

/**
 * Whether a field's label goes above its control rather than beside it: controls that
 * need the panel's width (or say so: `stack`), and choices with words (icon groups and two
 * short words stay beside their label). One rule for every tab.
 */
export function stacks(field: FieldDef): boolean {
  if (field.stack === true || (WIDE.includes(field.type) && !field.component)) return true
  // A label longer than its column (often in translation) gets the row above instead of an ellipsis.
  if (field.type !== 'toggle' && String(field.label ?? '').length > LABEL_FITS) return true
  if (field.type !== 'select' || field.component || field.display !== 'segmented') return false
  const options = Object.values(field.options ?? {})
  const words = options.map((o) => (typeof o === 'string' ? o : o && typeof o === 'object' && 'label' in o ? String(o.label ?? '') : '')).filter(Boolean)
  return words.length > 3 || words.join(' ').length > 20
}

/** Every control in the inspector has one height. */
const panelSize = (size: FieldDef['size']): FieldDef['size'] => (size === 'xs' || size === undefined ? 'sm' : size)

/**
 * Rows of the inspector: the label column and the control, drawn the same way in every
 * tab. Like `<Fields layout="inline">`, plus the inspector's own label and control height.
 * `bare` draws the controls alone (a row a field lays out itself).
 */
export function PanelFields({ fields, values, onChange, disabled, context = {}, className, bare }: { fields: FieldDef[]; values: Record<string, any>; onChange: (handle: string, value: any) => void; disabled?: boolean; context?: FieldContext; className?: string; bare?: boolean }) {
  useSyncExternalStore(registry.fields.subscribe, registry.fields.version)
  const manifest = useManifestIfLoaded()
  return (
    <div className={cn('min-w-0', className ?? 'space-y-1')}>
      {fields.filter((field) => fieldVisible(field, values)).map((field) => {
        const type = manifest?.fieldTypes.find((ft) => ft.key === field.type)
        const Component = registry.fields.get(field.component ?? type?.component ?? field.type) ?? registry.fields.get('json')
        if (!Component) return null
        // Choices stay on one line, shrinking to the panel (`fit`).
        const def = { ...field, size: panelSize(field.size), fit: true, id: fieldControlId(field, context.path) } as FieldDef
        const stacked = !bare && stacks(field)
        const control = <Component field={def} value={values[field.handle] ?? field.default ?? null} onChange={(v: unknown) => onChange(field.handle, v)} disabled={disabled} context={{ ...context, values }} />
        return (
          <div data-field={field.handle} key={field.handle} className={cn('flex min-w-0', stacked ? 'flex-col items-stretch' : 'min-h-7 items-center gap-2', field.className as string | undefined)}>
            {!bare && field.type !== 'toggle' && <RowLabel field={def} htmlFor={def.id as string} className={stacked ? '!w-auto' : undefined} />}
            <div className={cn('flex min-w-0 items-center', !stacked && 'flex-1')}>{control}</div>
          </div>
        )
      })}
    </div>
  )
}

/** The look of an inspector input, for the controls blocks fields draw themselves. */
export const inputClass = 'cms-input h-7 w-full min-w-0 rounded-sm px-2 text-sm placeholder:text-ghost'

export function display(v: unknown): string {
  if (Array.isArray(v)) return String(v[0])
  if (v && typeof v === 'object' && 'min' in (v as any)) return `${(v as any).min}–${(v as any).max}`
  return String(v)
}

/** A color chip on a checkerboard (so transparency shows). `dark` shows the dark-mode value in its corner. */
export function Swatch({ color, dark, className }: { color: string; dark?: string | null; className?: string }) {
  return (
    <span className={cn('relative inline-block size-4 shrink-0 overflow-hidden rounded-[4px] align-middle [background:repeating-conic-gradient(#ddd_0_25%,#fff_0_50%)_0_0/6px_6px]', className)}>
      <span className="absolute inset-0" style={{ background: color }} />
      {dark && <span className="absolute inset-0 [clip-path:polygon(100%_50%,100%_100%,50%_100%)]" style={{ background: dark }} />}
      <span className="absolute inset-0 rounded-[inherit] shadow-[inset_0_0_0_1px_rgb(0_0_0/0.12)]" />
    </span>
  )
}

/** The small square button of a row or a section title (+, −, link…). */
export const iconButtonClass = 'grid size-6 shrink-0 place-items-center rounded-sm text-muted transition-colors duration-120 hover:bg-hover hover:text-ink'

export function AddButton({ onClick, label = t('Add') }: { onClick: () => void; label?: string }) {
  return (
    <Tooltip content={label}>
      <button type="button" onClick={onClick} className={iconButtonClass} aria-label={label}>
        <Icon name="b-plus" size={14} />
      </button>
    </Tooltip>
  )
}

export function RemoveButton({ onClick, label = t('Remove') }: { onClick: () => void; label?: string }) {
  return (
    <Tooltip content={label}>
      <button type="button" onClick={onClick} className={cn(iconButtonClass, 'text-faint hover:text-danger')} aria-label={label}>
        <Icon name="b-minus" size={14} />
      </button>
    </Tooltip>
  )
}
