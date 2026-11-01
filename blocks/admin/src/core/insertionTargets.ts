/** All coordinates are in the rendered document's viewport; the helper never reads the DOM. */
export interface Rect { left: number; top: number; width: number; height: number }
export interface Point { x: number; y: number }
export type InsertionAxis = 'x' | 'y'
/** Between two siblings, at a container's inner edge (before its first or after its last child), or inside an empty container. */
export type InsertionKind = 'between' | 'edge' | 'inside'
export interface BlockBox {
  id: string
  parentId: string | null
  /** The source sibling index, including siblings that are not currently rendered. */
  index: number
  rect: Rect
  container?: boolean
  /** Optional layout knowledge from the rendered parent (e.g. a one-child flex row). */
  axis?: InsertionAxis
}
export interface InsertionTarget {
  parentId: string | null
  index: number
  axis: InsertionAxis
  /** A two-pixel line along the boundary, as wide as the blocks it separates. */
  rect: Rect
  /** The real gap across the parent, independent of the line's thickness and length. */
  hitRect: Rect
  inside: boolean
  kind: InsertionKind
  depth: number
}

/** A container-edge line hugs its child instead of floating in the container's padding. */
const EDGE_OFFSET = 4
/** Hover zones around a line: the gap itself, at least 12px and at most 32px. */
const ZONE_MIN = 6, ZONE_MAX = 16
export interface InsertionOptions {
  rootRect: Rect
  rootAxis?: InsertionAxis
  /** Applies to boundary proximity; any point inside a real gap remains eligible. */
  maxDistance?: number
  canInsert?: (parentId: string | null, index: number) => boolean
  excludeIds?: ReadonlySet<string>
}

const right = (rect: Rect) => rect.left + rect.width
const bottom = (rect: Rect) => rect.top + rect.height
const center = (rect: Rect, axis: InsertionAxis) => axis === 'x' ? rect.left + rect.width / 2 : rect.top + rect.height / 2
const start = (rect: Rect, axis: InsertionAxis) => axis === 'x' ? rect.left : rect.top
const end = (rect: Rect, axis: InsertionAxis) => axis === 'x' ? right(rect) : bottom(rect)
const valid = (rect: Rect) => [rect.left, rect.top, rect.width, rect.height].every(Number.isFinite) && rect.width > 0 && rect.height >= 0
const contains = (rect: Rect, point: Point) => point.x >= rect.left && point.x <= right(rect) && point.y >= rect.top && point.y <= bottom(rect)

function distance(rect: Rect, point: Point): number {
  return Math.hypot(Math.max(rect.left - point.x, 0, point.x - right(rect)), Math.max(rect.top - point.y, 0, point.y - bottom(rect)))
}

function pairAxis(before: Rect, after: Rect): InsertionAxis {
  const overlap = Math.min(bottom(before), bottom(after)) - Math.max(before.top, after.top)
  return overlap > 0 && Math.abs(center(after, 'x') - center(before, 'x')) > Math.abs(center(after, 'y') - center(before, 'y')) ? 'x' : 'y'
}

function layoutAxis(before: Rect, after: Rect, preferred?: InsertionAxis): InsertionAxis {
  const overlapY = Math.min(bottom(before), bottom(after)) - Math.max(before.top, after.top)
  const overlapX = Math.min(right(before), right(after)) - Math.max(before.left, after.left)
  // A wrapped flex/grid row starts a vertical gap even though its parent flows horizontally.
  if (preferred === 'x' && overlapY > 0) return 'x'
  if (preferred === 'y' && overlapX > 0) return 'y'
  return pairAxis(before, after)
}

function ancestry(blocks: readonly BlockBox[], excluded?: ReadonlySet<string>) {
  const byId = new Map(blocks.map((block) => [block.id, block]))
  const depth = (id: string | null) => {
    const seen = new Set<string>()
    while (id && !seen.has(id)) { seen.add(id); id = byId.get(id)?.parentId ?? null }
    return seen.size
  }
  const isExcluded = (block: BlockBox) => {
    const seen = new Set<string>()
    let id: string | null = block.id
    while (id && !seen.has(id)) {
      if (excluded?.has(id)) return true
      seen.add(id); id = byId.get(id)?.parentId ?? null
    }
    return false
  }
  return { byId, depth, isExcluded }
}

/** Source-ordered slots before, between and after visible siblings, plus empty containers. */
export function insertionTargets(blocks: readonly BlockBox[], options: InsertionOptions): InsertionTarget[] {
  const { byId, depth, isExcluded } = ancestry(blocks, options.excludeIds)
  const groups = new Map<string | null, BlockBox[]>([[null, []]])
  for (const block of blocks) {
    if (!valid(block.rect) || (!block.container && block.rect.height === 0) || isExcluded(block)) continue
    const siblings = groups.get(block.parentId) ?? []
    siblings.push(block); groups.set(block.parentId, siblings)
    if (block.container && !groups.has(block.id)) groups.set(block.id, [])
  }
  const targets: InsertionTarget[] = []
  for (const [parentId, group] of groups) {
    const parent = parentId === null ? null : byId.get(parentId)
    const parentRect = parentId === null ? options.rootRect : parent?.rect
    if (!parentRect || !valid(parentRect)) continue
    const siblings = [...group].sort((a, b) => a.index - b.index)
    const explicitAxis = parentId === null ? options.rootAxis : parent?.axis
    const permitted = (index: number) => options.canInsert?.(parentId, index) ?? true
    const level = depth(parentId)
    if (!siblings.length) {
      if (permitted(0)) targets.push({ parentId, index: 0, axis: explicitAxis ?? 'y', rect: { left: parentRect.left, top: parentRect.top, width: parentRect.width, height: 2 }, hitRect: parentRect, inside: true, kind: 'inside', depth: level })
      continue
    }
    /** `hug` keeps the line beside that end's block; otherwise it sits mid-gap. `line` is the line's cross extent, `cross` the hit area's. */
    const add = (index: number, axis: InsertionAxis, from: number, to: number, cross: Rect, line: Rect, kind: InsertionKind, hug?: 'from' | 'to') => {
      if (!permitted(index)) return
      const direction = Math.sign(to - from), inset = Math.min(Math.abs(to - from) / 2, EDGE_OFFSET)
      const at = hug === 'from' ? from + direction * inset : hug === 'to' ? to - direction * inset : (from + to) / 2
      const low = Math.min(from, to), length = Math.abs(to - from)
      const hitRect: Rect = axis === 'x'
        ? { left: low, top: cross.top, width: length, height: cross.height }
        : { left: cross.left, top: low, width: cross.width, height: length }
      const rect: Rect = axis === 'x'
        ? { left: at - 1, top: line.top, width: 2, height: line.height }
        : { left: line.left, top: at - 1, width: line.width, height: 2 }
      targets.push({ parentId, index, axis, rect, hitRect, inside: false, kind, depth: level })
    }
    const span = (a: Rect, b: Rect): Rect => { const left = Math.min(a.left, b.left), top = Math.min(a.top, b.top); return { left, top, width: Math.max(right(a), right(b)) - left, height: Math.max(bottom(a), bottom(b)) - top } }
    const first = siblings[0], last = siblings.at(-1)!
    const leadingAxis = siblings.length > 1 ? layoutAxis(first.rect, siblings[1].rect, explicitAxis) : explicitAxis ?? 'y'
    const trailingAxis = siblings.length > 1 ? layoutAxis(siblings.at(-2)!.rect, last.rect, explicitAxis) : explicitAxis ?? 'y'
    const leadingForward = siblings.length < 2 || center(siblings[1].rect, leadingAxis) >= center(first.rect, leadingAxis)
    const trailingForward = siblings.length < 2 || center(last.rect, trailingAxis) >= center(siblings.at(-2)!.rect, trailingAxis)
    const firstEdge = leadingForward ? start(first.rect, leadingAxis) : end(first.rect, leadingAxis)
    const lastEdge = trailingForward ? end(last.rect, trailingAxis) : start(last.rect, trailingAxis)
    // Outer padding belongs to its nearest sibling row, not to unrelated wrapped rows.
    add(first.index, leadingAxis, leadingForward ? start(parentRect, leadingAxis) : end(parentRect, leadingAxis), firstEdge, leadingAxis === 'x' ? first.rect : parentRect, first.rect, 'edge', 'to')
    for (let i = 1; i < siblings.length; i++) {
      const before = siblings[i - 1], after = siblings[i]
      const axis = layoutAxis(before.rect, after.rect, explicitAxis)
      const forward = center(after.rect, axis) >= center(before.rect, axis)
      const from = forward ? end(before.rect, axis) : start(before.rect, axis)
      const to = forward ? start(after.rect, axis) : end(after.rect, axis)
      const overlapStart = Math.max(before.rect.top, after.rect.top)
      const overlapEnd = Math.min(bottom(before.rect), bottom(after.rect))
      const cross = axis === 'x' ? { left: parentRect.left, top: overlapStart, width: parentRect.width, height: Math.max(0, overlapEnd - overlapStart) } : parentRect
      // Overlapping boxes have no whitespace gap: leave only an edge-sized hit target.
      const overlaps = forward ? to < from : to > from
      add(after.index, axis, overlaps ? (from + to) / 2 : from, overlaps ? (from + to) / 2 : to, cross, axis === 'x' && cross.height > 0 ? cross : span(before.rect, after.rect), 'between')
    }
    add(last.index + 1, trailingAxis, lastEdge, trailingForward ? end(parentRect, trailingAxis) : start(parentRect, trailingAxis), trailingAxis === 'x' ? last.rect : parentRect, last.rect, 'edge', 'from')
  }
  return targets
}

/**
 * The boundary whose line the pointer rests near (Gutenberg's between-block inserter). The line never
 * follows the pointer: it stays on its boundary, and only a pointer inside its zone selects it. The nearest
 * line wins; near-ties prefer a boundary between siblings over a container edge, then the deeper level.
 */
export function pickHoverTarget(blocks: readonly BlockBox[], point: Point, options: InsertionOptions): InsertionTarget | null {
  if (![point.x, point.y].every(Number.isFinite)) return null
  const candidates = insertionTargets(blocks, options).flatMap((target) => {
    if (target.inside) return []
    const x = target.axis === 'x'
    const along = x ? point.x : point.y, across = x ? point.y : point.x
    const line = (x ? target.rect.left : target.rect.top) + 1
    // The zone is the gap, at most ZONE_MAX either side of the line; a gap thinner than 2 × ZONE_MIN grows into its blocks.
    const gapStart = x ? target.hitRect.left : target.hitRect.top, length = x ? target.hitRect.width : target.hitRect.height
    const grow = Math.max(0, ZONE_MIN - length / 2)
    const low = Math.max(gapStart - grow, line - ZONE_MAX), high = Math.min(gapStart + length + grow, line + ZONE_MAX)
    const crossStart = x ? target.hitRect.top : target.hitRect.left, crossEnd = crossStart + (x ? target.hitRect.height : target.hitRect.width)
    return along >= low && along <= high && across >= crossStart && across <= crossEnd ? [{ target, distance: Math.abs(along - line) }] : []
  })
  if (!candidates.length) return null
  const nearest = Math.min(...candidates.map((candidate) => candidate.distance))
  return candidates
    .filter((candidate) => candidate.distance <= nearest + 2)
    .sort((a, b) => Number(a.target.kind === 'edge') - Number(b.target.kind === 'edge') || b.target.depth - a.target.depth || a.distance - b.distance)[0].target
}

/** Dragging may cross content: choose a fixed source boundary, never a moving mouse caret. */
export function pickDropTarget(blocks: readonly BlockBox[], point: Point, options: InsertionOptions): InsertionTarget | null {
  if (![point.x, point.y].every(Number.isFinite)) return null
  const { isExcluded } = ancestry(blocks, options.excludeIds)
  if (blocks.some((block) => isExcluded(block) && contains(block.rect, point))) return null
  const candidates = insertionTargets(blocks, options)
    .map((target) => ({ target, distance: distance(target.inside ? target.hitRect : target.rect, point) }))
    .filter(({ distance }) => distance <= (options.maxDistance ?? 80))
    .sort((a, b) => a.distance - b.distance || b.target.depth - a.target.depth)
  return candidates[0]?.target ?? null
}
