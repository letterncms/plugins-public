import assert from 'node:assert/strict'
import { test } from 'node:test'
import { insertionTargets, pickDropTarget, pickHoverTarget } from '../admin/src/core/insertionTargets.ts'

const rect = (left, top, width, height) => ({ left, top, width, height })
const box = (id, index, bounds, extra = {}) => ({ id, parentId: null, index, rect: bounds, ...extra })
const rootRect = rect(0, 0, 600, 400)
const blocks = [box('one', 0, rect(20, 20, 560, 60)), box('two', 1, rect(20, 140, 560, 80))]
const pick = (items, point, extra = {}) => pickHoverTarget(items, point, { rootRect, ...extra })

test('dragging over content targets a fixed boundary while hovering text shows no inserter', () => {
  const point = { x: 100, y: 145 }
  assert.equal(pick(blocks, point), null)
  const first = pickDropTarget(blocks, point, { rootRect })
  const moved = pickDropTarget(blocks, { x: 100, y: 152 }, { rootRect })
  assert.equal(first.index, 1)
  assert.deepEqual(first.rect, moved.rect)
})

test('drop targets enforce permitted parents and exclude a moving subtree', () => {
  const nested = [box('parent', 0, rect(0, 0, 600, 300), { container: true }), box('child', 0, rect(20, 20, 560, 60), { parentId: 'parent' }), box('tail', 1, rect(0, 320, 600, 40))]
  assert.equal(pickDropTarget(nested, { x: 100, y: 40 }, { rootRect, excludeIds: new Set(['parent']) }), null)
  assert.equal(pickDropTarget(nested, { x: 100, y: 310 }, { rootRect, canInsert: () => false }), null)
  assert.equal(pickDropTarget(nested, { x: 100, y: 310 }, { rootRect, canInsert: (parent) => parent === null }).parentId, null)
})

test('vertical content exposes before, between and after source slots', () => {
  assert.deepEqual(insertionTargets(blocks, { rootRect }).map(({ parentId, index, kind }) => [parentId, index, kind]), [[null, 0, 'edge'], [null, 1, 'between'], [null, 2, 'edge']])
  assert.equal(pick(blocks, { x: 100, y: 14 }).index, 0)
  assert.equal(pick(blocks, { x: 100, y: 110 }).index, 1)
  assert.equal(pick(blocks, { x: 100, y: 230 }).index, 2)
})

test('lines stay on their boundary: a pointer deep in a wide gap shows nothing', () => {
  const wideGap = [box('one', 0, rect(20, 20, 560, 20)), box('two', 1, rect(20, 300, 560, 20))]
  assert.equal(pick(wideGap, { x: 50, y: 60 }), null)
  const target = pick(wideGap, { x: 50, y: 165 })
  assert.equal(target.index, 1)
  assert.equal(target.rect.top, 169)
  assert.equal(pick(wideGap, { x: 50, y: 180 }).rect.top, 169)
})

test('container edges hug their child instead of floating in the padding', () => {
  const padded = [box('section', 0, rect(0, 0, 600, 400), { container: true }), box('copy', 0, rect(40, 40, 520, 60), { parentId: 'section' })]
  const inner = insertionTargets(padded, { rootRect }).filter((target) => target.parentId === 'section')
  assert.deepEqual(inner.map(({ index, kind, rect }) => [index, kind, rect.top]), [[0, 'edge', 35], [1, 'edge', 103]])
  assert.equal(inner[1].rect.left, 40)
  assert.equal(inner[1].rect.width, 520)
  assert.equal(pick(padded, { x: 300, y: 110 }).index, 1)
  assert.equal(pick(padded, { x: 300, y: 250 }), null)
})

test('between lines span the blocks they separate', () => {
  const between = insertionTargets(blocks, { rootRect })[1]
  assert.deepEqual(between.rect, rect(20, 109, 560, 2))
  assert.deepEqual(between.hitRect, rect(0, 80, 600, 60))
})

test('near-ties prefer the boundary between siblings over a flush container edge', () => {
  const flush = [box('first', 0, rect(0, 0, 600, 200), { container: true }), box('inner', 0, rect(0, 0, 600, 200), { parentId: 'first' }), box('second', 1, rect(0, 200, 600, 200))]
  const target = pick(flush, { x: 300, y: 201 })
  assert.equal(target.parentId, null)
  assert.equal(target.index, 1)
})

test('horizontal layouts use vertical lines in source order', () => {
  const row = [box('one', 0, rect(20, 20, 100, 80)), box('two', 1, rect(160, 20, 100, 80))]
  const target = pick(row, { x: 140, y: 60 })
  assert.equal(target.index, 1)
  assert.equal(target.axis, 'x')
  assert.equal(target.rect.width, 2)
  assert.equal(pick(row, { x: 12, y: 60 }).index, 0)
  assert.equal(pick(row, { x: 270, y: 60 }).index, 2)
})

test('reverse horizontal layouts preserve source indexes rather than sorting by position', () => {
  const row = [box('one', 0, rect(300, 20, 100, 80)), box('two', 1, rect(100, 20, 100, 80))]
  assert.equal(pick(row, { x: 410, y: 60 }).index, 0)
  assert.equal(pick(row, { x: 250, y: 60 }).index, 1)
  assert.equal(pick(row, { x: 90, y: 60 }).index, 2)
})

test('nested sibling gaps resolve to their exact parent, without depending on selection', () => {
  const nested = [box('section', 0, rect(20, 20, 400, 300), { container: true }), box('a', 0, rect(40, 60, 360, 50), { parentId: 'section' }), box('b', 1, rect(40, 180, 360, 50), { parentId: 'section' })]
  const target = pick(nested, { x: 100, y: 145 })
  assert.equal(target.parentId, 'section')
  assert.equal(target.index, 1)
  assert.equal(target.depth, 1)
})

test('empty containers and an empty root have inside targets for their appenders', () => {
  const empty = [box('empty', 0, rect(20, 20, 400, 300), { container: true })]
  const inside = insertionTargets(empty, { rootRect }).find((target) => target.inside)
  assert.equal(inside.parentId, 'empty')
  assert.equal(inside.kind, 'inside')
  assert.deepEqual(inside.hitRect, rect(20, 20, 400, 300))
  assert.equal(pick(empty, { x: 100, y: 100 }), null, 'hovering inside an empty container draws no line')
  assert.deepEqual(insertionTargets([], { rootRect }).map(({ parentId, index, inside }) => [parentId, index, inside]), [[null, 0, true]])
})

test('source indexes remain correct when hidden siblings have no rendered box', () => {
  const sparse = [box('first', 1, rect(20, 20, 560, 60)), box('last', 4, rect(20, 140, 560, 80))]
  assert.deepEqual(insertionTargets(sparse, { rootRect }).map(({ index }) => index), [1, 4, 5])
})

test('hovering directly inside text or media does not hijack editing with insertion', () => {
  assert.equal(pick(blocks, { x: 100, y: 21 }), null)
  assert.equal(pick(blocks, { x: 100, y: 50 }), null)
})

test('flush blocks still get a thin zone across their shared edge', () => {
  const flush = [box('one', 0, rect(0, 0, 600, 100)), box('two', 1, rect(0, 100, 600, 100))]
  assert.equal(pick(flush, { x: 300, y: 95 }).index, 1)
  assert.equal(pick(flush, { x: 300, y: 106 }).index, 1)
  assert.equal(pick(flush, { x: 300, y: 90 }), null)
})

test('type restrictions filter invalid parent slots and never invent an accepted fallback', () => {
  assert.equal(pick(blocks, { x: 100, y: 110 }, { canInsert: () => false }), null)
  assert.equal(pick(blocks, { x: 100, y: 110 }, { canInsert: (_parentId, index) => index === 1 }).index, 1)
})

test('moving a container excludes its entire subtree as a destination', () => {
  const nested = [box('section', 0, rect(20, 20, 400, 300), { container: true }), box('a', 0, rect(40, 60, 360, 50), { parentId: 'section' })]
  assert.equal(pick(nested, { x: 100, y: 150 }, { excludeIds: new Set(['section']) }), null)
  assert.ok(insertionTargets(nested, { rootRect, excludeIds: new Set(['section']) }).every((target) => target.parentId === null))
})

test('pointers outside the parent and invalid measurements are ignored', () => {
  assert.equal(pick(blocks, { x: -10, y: 110 }), null)
  assert.equal(pick(blocks, { x: NaN, y: 110 }), null)
  assert.equal(insertionTargets([box('bad', 0, rect(NaN, 0, 2, 2))], { rootRect }).length, 1)
})

test('one-child containers may declare their rendered horizontal flow', () => {
  const one = [box('row', 0, rect(0, 0, 500, 100), { container: true, axis: 'x' }), box('a', 0, rect(100, 10, 100, 80), { parentId: 'row' })]
  const target = pick(one, { x: 210, y: 40 })
  assert.equal(target.parentId, 'row')
  assert.equal(target.axis, 'x')
  assert.equal(target.index, 1)
})

test('wrapped horizontal groups expose vertical gaps between rows', () => {
  const grid = [box('one', 0, rect(20, 20, 100, 80)), box('two', 1, rect(160, 20, 100, 80)), box('three', 2, rect(20, 140, 100, 80)), box('four', 3, rect(160, 140, 100, 80))]
  const target = pick(grid, { x: 100, y: 120 }, { rootAxis: 'x' })
  assert.equal(target.index, 2)
  assert.equal(target.axis, 'y')
  assert.equal(pick(grid, { x: 140, y: 170 }, { rootAxis: 'x' }).index, 3)
})

test('empty zero-height containers retain an inside anchor without changing rendered layout', () => {
  const empty = [box('empty', 0, rect(20, 100, 400, 0), { container: true })]
  const target = insertionTargets(empty, { rootRect }).find((candidate) => candidate.inside)
  assert.equal(target.parentId, 'empty')
  assert.equal(target.index, 0)
  assert.equal(target.rect.top, 100)
  assert.equal(target.hitRect.height, 0)
  const hidden = [box('hidden', 0, rect(0, 0, 0, 0), { container: true })]
  assert.ok(insertionTargets(hidden, { rootRect }).every((candidate) => candidate.parentId !== 'hidden'))
})
