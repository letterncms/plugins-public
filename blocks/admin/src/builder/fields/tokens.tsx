import { t } from '@letterncms/sdk'
import { Icon, Popover, cn } from '@letterncms/ui'
import { useState, type ReactElement } from 'react'
import { useTokens, type ThemeToken } from '../../core/context'
import { Swatch, display } from '../controls'

/* Design tokens in the inspector: every value that has tokens (color, space, radius, type,
   shadow, duration, ease) offers them in the same list and shows a chosen one as a chip. */

/** The token a value refers to (`token:space.4`, `token:color.primary/60`), if any. */
export function useTokenOf() {
  const { byPath } = useTokens()
  return (value: unknown): { token: ThemeToken; alpha?: string } | null => {
    if (typeof value !== 'string' || !value.startsWith('token:')) return null
    const [path, alpha] = value.slice(6).split('/')
    const token = byPath.get(path)
    return token ? { token, alpha } : null
  }
}

/** A raw value typed as a token path ("space.4" or "$space.4") becomes a token reference. */
export function useTypedToken(group?: string) {
  const { byPath } = useTokens()
  return (typed: string): string | null => {
    const path = typed.trim().replace(/^\$/, '').replace(/^token:/, '')
    if (byPath.has(path)) return `token:${path}`
    if (group && byPath.has(`${group}.${path}`) && /^[a-z0-9-]+$/i.test(path) && !/^-?\d*\.?\d+$/.test(path)) return `token:${group}.${path}`
    return null
  }
}

/** A token's value as one line of text (fluid sizes as min–max). */
export const tokenValue = (token: ThemeToken) => display(token.value)

/** The chip a token value shows as: `space.4`, or a color's name with its swatch. */
export function TokenChip({ token, alpha, className }: { token: ThemeToken; alpha?: string; className?: string }) {
  return (
    <span className={cn('inline-flex min-w-0 items-center gap-1 truncate rounded-xs bg-active px-1.5 leading-5 text-ink', className)} title={`${token.path} · ${tokenValue(token)}`}>
      <span className="truncate">{token.group === 'color' ? token.name : token.path}</span>
      {alpha && <span className="text-muted">/{alpha}</span>}
    </span>
  )
}

/** The list of a group's tokens; picking one calls `onPick('token:path')`, Detach calls `onPick(null)`. */
export function TokenList({ group, value, onPick, detach }: { group: string; value?: string | null; onPick: (v: string) => void; detach?: () => void }) {
  const { byGroup } = useTokens()
  const tokens = byGroup.get(group) ?? []
  const current = typeof value === 'string' && value.startsWith('token:') ? value.slice(6).split('/')[0] : null
  if (!tokens.length) return <p className="m-0 px-2 py-2 text-2xs text-faint">{t('No {group} tokens in the design system.', { group })}</p>
  return (
    <div>
      <p className="cms-label m-0 px-2 pb-1 pt-1.5 !text-2xs">{t('Tokens')}</p>
      <div className="max-h-60 overflow-y-auto">
        {tokens.map((token) => (
          <button
            key={token.path}
            type="button"
            onClick={() => onPick(`token:${token.path}`)}
            className={cn('flex h-7 w-full items-center gap-2 rounded-xs px-2 text-left text-xs hover:bg-hover', current === token.path && 'bg-active')}
          >
            {group === 'color' && <Swatch color={String(token.value)} dark={token.dark ? String(token.dark) : undefined} />}
            {group === 'shadow' && <span className="size-3.5 shrink-0 rounded-[3px] bg-bg" style={{ boxShadow: String(token.value) }} />}
            {group === 'radius' && <span className="size-3.5 shrink-0 border-l-[1.5px] border-t-[1.5px] border-muted" style={{ borderTopLeftRadius: String(token.value) }} />}
            <span className="min-w-0 truncate font-mono text-2xs">{token.path}</span>
            <span className="ml-auto max-w-[45%] shrink-0 truncate font-mono text-2xs text-faint">{tokenValue(token)}</span>
            {current === token.path && <Icon name="b-check" size={12} className="shrink-0 text-focus" />}
          </button>
        ))}
      </div>
      {current && detach && (
        <button type="button" onClick={detach} className="mt-1 flex h-7 w-full items-center gap-2 rounded-xs border-t border-border px-2 text-xs text-muted hover:bg-hover hover:text-text">
          <Icon name="b-link-break" size={12} />
          {t('Detach Token')}
        </button>
      )}
    </div>
  )
}

/**
 * The token button of a value: a small diamond that opens the group's token list.
 * Hidden until its row is hovered unless a token is chosen (`active`).
 */
export function TokenButton({ group, value, onPick, active, className, trigger }: { group: string; value?: string | null; onPick: (v: string | null) => void; active?: boolean; className?: string; trigger?: ReactElement }) {
  const [open, setOpen] = useState(false)
  const { byGroup, byPath } = useTokens()
  if (!(byGroup.get(group) ?? []).length) return null
  const pick = (v: string | null) => {
    onPick(v)
    setOpen(false)
  }
  const detach = () => {
    const token = typeof value === 'string' ? byPath.get(value.slice(6).split('/')[0]) : undefined
    pick(token && typeof token.value === 'string' ? token.value : null)
  }
  return (
    <Popover
      open={open}
      onOpenChange={setOpen}
      align="end"
      className="w-60 !p-1"
      trigger={
        trigger ?? (
          <button
            type="button"
            className={cn('grid size-5 shrink-0 place-items-center rounded-xs text-faint transition-opacity hover:bg-hover hover:text-ink', !active && !open && 'opacity-0 group-hover/value:opacity-100 group-focus-within/value:opacity-100', (active || open) && 'text-ink', className)}
            aria-label={t('Design Tokens')}
            title={t('Design Tokens')}
          >
            <Icon name="b-diamonds-four" size={10} />
          </button>
        )
      }
    >
      <TokenList group={group} value={value} onPick={pick} detach={detach} />
    </Popover>
  )
}
