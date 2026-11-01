import { useEffect, useSyncExternalStore } from 'react'

/**
 * Open slash menu: which paragraph and what was typed after "/". `enter`: Enter was
 * pressed before the menu could draw (fast typing) — it picks the first match once it does.
 */
let state: { id: string; query: string; enter?: boolean } | null = null
const listeners = new Set<() => void>()

export function slashFor(id: string) {
  return state?.id === id ? state : null
}

export function openSlash(next: { id: string; query: string; enter?: boolean } | null) {
  if (JSON.stringify(next) === JSON.stringify(state)) return
  state = next
  listeners.forEach((l) => l())
}

export function useSlash() {
  return useSyncExternalStore(
    (l) => {
      listeners.add(l)
      return () => listeners.delete(l)
    },
    () => state,
  )
}

/**
 * Where the slash menu goes: under the line being typed, or above it when there isn't
 * room below. It never covers the line, and shrinks to the space it has.
 */
export function slashPlacement(caret: { left: number; top: number; bottom: number }, height = 360, width = 300) {
  const gap = 6, margin = 8
  const below = window.innerHeight - caret.bottom - gap - margin
  const above = caret.top - gap - margin
  const down = below >= Math.min(height, 220) || below >= above
  return {
    left: Math.max(margin, Math.min(window.innerWidth - width - margin, caret.left - 4)),
    top: down ? caret.bottom + gap : undefined,
    bottom: down ? undefined : window.innerHeight - caret.top + gap,
    maxHeight: Math.max(120, Math.min(height, down ? below : above)),
  }
}

/** A press anywhere but the menu closes it; the typed "/query" stays as text. */
export function useSlashDismiss(open: boolean, docs: (Document | null | undefined)[]) {
  useEffect(() => {
    if (!open) return
    const onDown = (e: PointerEvent) => !(e.target as Element | null)?.closest?.('[data-cms-slash]') && openSlash(null)
    const targets = docs.filter((d): d is Document => !!d)
    targets.forEach((d) => d.addEventListener('pointerdown', onDown, true))
    return () => targets.forEach((d) => d.removeEventListener('pointerdown', onDown, true))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, ...docs])
}
