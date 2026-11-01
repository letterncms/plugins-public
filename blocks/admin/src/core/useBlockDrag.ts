import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import type { InsertionTarget, Point } from './insertionTargets'

export interface BlockDrag {
  ids: string[]
  label: string
  point: Point
  target: InsertionTarget | null
}
interface Options {
  disabled: boolean
  frame: React.RefObject<HTMLIFrameElement | null>
  scale: number
  target: (point: Point, ids: string[]) => InsertionTarget | null
  invalidate: () => void
  commit: (ids: string[], target: InsertionTarget) => void
}

/** One pointer session across the parent document and iframe, with cancel and edge scrolling. */
export function useBlockDrag(options: Options) {
  const latest = useRef(options); latest.current = options
  const [drag, setDrag] = useState<BlockDrag | null>(null)
  const session = useRef<(BlockDrag & { start: Point; active: boolean; pointerId: number; dirty: boolean }) | null>(null)
  const stop = useRef<(commit?: boolean) => void>(() => {})
  const start = (event: ReactPointerEvent, ids: string[], label: string) => {
    if (latest.current.disabled || event.button !== 0 || !ids.length) return
    event.preventDefault()
    stop.current()
    session.current = { ids, label, point: { x: event.clientX, y: event.clientY }, start: { x: event.clientX, y: event.clientY }, target: null, active: false, pointerId: event.pointerId, dirty: true }
    // The overlay takes over hit testing; document listeners also support a lost capture.
    try { event.currentTarget.setPointerCapture(event.pointerId) } catch { /* Synthetic pointers have no native capture. */ }
    const owner = event.currentTarget.ownerDocument
    let animation = 0
    const previousCursor = owner.body.style.cursor, previousSelect = owner.body.style.userSelect
    const finish = (commit = false) => {
      const current = session.current
      session.current = null; cancelAnimationFrame(animation)
      owner.removeEventListener('pointermove', move)
      owner.removeEventListener('pointerup', up)
      owner.removeEventListener('pointercancel', cancel)
      owner.removeEventListener('keydown', key)
      owner.defaultView?.removeEventListener('blur', cancel)
      owner.body.style.cursor = previousCursor; owner.body.style.userSelect = previousSelect
      setDrag(null)
      if (commit && current?.active && current.target && !latest.current.disabled) latest.current.commit(current.ids, current.target)
    }
    stop.current = finish
    const tick = () => {
      const current = session.current, { frame, scale, invalidate, target } = latest.current
      const element = frame.current
      if (!current?.active || !element || latest.current.disabled) { finish(); return }
      const rect = element.getBoundingClientRect()
      const local = { x: (current.point.x - rect.left) / scale, y: (current.point.y - rect.top) / scale }
      const inside = current.point.x >= rect.left && current.point.x <= rect.right && current.point.y >= rect.top && current.point.y <= rect.bottom
      const edge = Math.min(48, rect.height / 4)
      const scroll = inside ? current.point.y < rect.top + edge ? -Math.ceil((rect.top + edge - current.point.y) / 4) : current.point.y > rect.bottom - edge ? Math.ceil((current.point.y - rect.bottom + edge) / 4) : 0 : 0
      if (scroll) { element.contentWindow?.scrollBy(0, scroll / scale); invalidate() }
      if (current.dirty || scroll) {
        current.target = inside ? target(local, current.ids) : null
        current.dirty = false
        setDrag({ ids: current.ids, label: current.label, point: current.point, target: current.target })
      }
      animation = requestAnimationFrame(tick)
    }
    const move = (event: PointerEvent) => {
      const current = session.current
      if (!current || event.pointerId !== current.pointerId) return
      current.point = { x: event.clientX, y: event.clientY }; current.dirty = true
      if (!current.active && Math.hypot(event.clientX - current.start.x, event.clientY - current.start.y) >= 4) {
        current.active = true
        owner.body.style.cursor = 'grabbing'; owner.body.style.userSelect = 'none'
        animation = requestAnimationFrame(tick)
        setDrag({ ids: current.ids, label: current.label, point: current.point, target: null })
      }
      if (current.active) event.preventDefault()
    }
    const up = (event: PointerEvent) => {
      if (event.pointerId !== session.current?.pointerId) return
      // The final point can arrive before the next animation frame.
      const current = session.current, element = latest.current.frame.current
      if (current?.active && element) {
        const rect = element.getBoundingClientRect(), scale = latest.current.scale
        const inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom
        current.target = inside ? latest.current.target({ x: (event.clientX - rect.left) / scale, y: (event.clientY - rect.top) / scale }, current.ids) : null
      }
      finish(true)
    }
    const cancel = () => finish()
    const key = (event: KeyboardEvent) => { if (event.key === 'Escape') { event.preventDefault(); finish() } }
    owner.addEventListener('pointermove', move, { passive: false })
    owner.addEventListener('pointerup', up)
    owner.addEventListener('pointercancel', cancel)
    owner.addEventListener('keydown', key)
    owner.defaultView?.addEventListener('blur', cancel)
  }
  useEffect(() => { if (options.disabled) stop.current() }, [options.disabled])
  useEffect(() => () => stop.current(), [])
  return { drag, start, cancel: () => stop.current() }
}
