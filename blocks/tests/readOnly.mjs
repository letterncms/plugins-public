import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync } from 'node:fs'
import { stripTypeScriptTypes } from 'node:module'
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm'

// node --experimental-vm-modules --test cms/plugins/blocks/tests/readOnly.mjs
async function editor(nodes) {
  const context = createContext({ structuredClone })
  // The field and entry workspace both use this hook lifecycle; effects run after render.
  const slots = []
  let cursor = 0
  let effects = []
  const react = new SyntheticModule(['useRef', 'useState', 'useEffect', 'useSyncExternalStore'], function () {
    this.setExport('useRef', (value) => { const index = cursor++; return slots[index] ??= { current: value } })
    this.setExport('useState', (value) => { const index = cursor++; if (!(index in slots)) slots[index] = typeof value === 'function' ? value() : value; return [slots[index], (next) => { slots[index] = typeof next === 'function' ? next(slots[index]) : next }] })
    this.setExport('useEffect', (callback, deps) => { const index = cursor++; const previous = slots[index]; if (!previous || deps.some((value, at) => !Object.is(value, previous[at]))) effects.push(callback); slots[index] = deps })
    this.setExport('useSyncExternalStore', (_, snapshot) => snapshot())
  }, { context })
  let sequence = 0
  const types = new SyntheticModule(['newId'], function () { this.setExport('newId', () => `new-${++sequence}`) }, { context })
  const source = (path) => new SourceTextModule(stripTypeScriptTypes(readFileSync(new URL(path, import.meta.url), 'utf8'), { mode: 'transform' }), { context })
  const tree = source('../admin/src/core/tree.ts')
  await tree.link(() => types)
  const storeModule = source('../admin/src/core/store.ts')
  await storeModule.link((name) => name === 'react' ? react : tree)
  await storeModule.evaluate()
  const sessionModule = source('../admin/src/core/useBlockSession.ts')
  await sessionModule.link((name) => name === 'react' ? react : storeModule)
  await sessionModule.evaluate()
  const changes = []
  return {
    store: new storeModule.namespace.EditorStore(nodes, (next) => changes.push(next)), changes,
    render(value = nodes, readOnly = false, onChange = (next) => changes.push(next)) {
      cursor = 0; effects = []
      return sessionModule.namespace.useBlockSession(value, onChange, readOnly)
    },
    flushEffects() { const pending = effects; effects = []; pending.forEach((effect) => effect()) },
  }
}

test('sibling move actions keep a selected range intact and undo in one step', async () => {
  const nodes = ['a', 'b', 'c', 'd'].map((id) => ({ id, type: 'paragraph', props: { text: id } }))
  const { store } = await editor(nodes)
  store.select('b', true); store.extendTo('c')
  store.nudgeSelection(1)
  assert.deepEqual(Array.from(store.get().nodes, (node) => node.id), ['a', 'd', 'b', 'c'])
  store.nudgeSelection(1)
  assert.deepEqual(Array.from(store.get().nodes, (node) => node.id), ['a', 'd', 'b', 'c'])
  store.undo()
  assert.deepEqual(Array.from(store.get().nodes, (node) => node.id), ['a', 'b', 'c', 'd'])
  store.nudgeSelection(-1)
  assert.deepEqual(Array.from(store.get().nodes, (node) => node.id), ['b', 'c', 'a', 'd'])
  store.setReadOnly(true)
  store.nudgeSelection(1)
  assert.deepEqual(Array.from(store.get().nodes, (node) => node.id), ['b', 'c', 'a', 'd'])
})

const node = () => ({ id: 'one', type: 'paragraph', props: { text: 'Original' } })

test('read-only state blocks data mutations across inspector, keyboard, drag and history paths', async () => {
  const initial = [node()]
  const { store, changes } = await editor(initial)
  store.setReadOnly(true)
  store.select('one')
  store.updateProps('one', { text: 'Changed' })
  store.updateNode('one', { label: 'Changed' })
  store.insert(null, 0, [{ id: 'two', type: 'paragraph', props: {} }])
  store.duplicate('one')
  store.move('one', null, 0)
  store.remove('one')
  store.select('one')
  store.removeSelection()
  store.undo()
  store.redo()
  assert.equal(store.get().nodes, initial)
  assert.equal(changes.length, 0)
  assert.equal(store.canUndo(), false)
  assert.equal(store.canRedo(), false)
})

test('revoked editing preserves undo history while external refresh remains possible', async () => {
  const initial = [node()]
  const { store, changes } = await editor(initial)
  store.updateProps('one', { text: 'Edited' })
  assert.equal(store.canUndo(), true)
  store.setReadOnly(true)
  store.undo()
  assert.equal(store.get().nodes[0].props.text, 'Edited')
  assert.equal(changes.length, 1)
  store.setReadOnly(false)
  store.undo()
  assert.equal(store.get().nodes[0].props.text, 'Original')
  store.setReadOnly(true)
  const replacement = [{ ...node(), props: { text: 'Server refreshed' } }]
  store.reset(replacement)
  assert.equal(store.get().nodes, replacement)
  assert.equal(changes.length, 2)
})


test('the unified workspace session is read-only before its first effect and remains inspectable', async () => {
  const initial = [{ id: 'layout', type: 'box', props: {}, children: [node()] }]
  const fixture = await editor(initial)
  const store = fixture.render(initial, true)
  store.select('one', true)
  store.setBreakpoint('mobile')
  store.updateProps('one', { text: 'Disallowed first-render write' })
  store.unwrap('layout')
  store.duplicate('layout')
  store.removeSelection()
  assert.equal(store.get().nodes, initial)
  assert.equal(store.get().breakpoint, 'mobile', 'Inspecting responsive styles is allowed without editing.')
  assert.equal(fixture.changes.length, 0)
  fixture.flushEffects()
  assert.equal(store.get().nodes, initial)
})

test('permission changes synchronously protect the same shared block session', async () => {
  const initial = [node()]
  const fixture = await editor(initial)
  const store = fixture.render(initial, false)
  fixture.flushEffects()
  store.updateProps('one', { text: 'Editable content' })
  const edited = store.get().nodes
  const revoked = fixture.render(edited, true)
  assert.equal(revoked, store, 'The entry must not acquire another editor or history when permissions change.')
  revoked.updateNode('one', { style: { base: { color: 'red' } } })
  revoked.undo()
  assert.equal(revoked.get().nodes, edited)
  assert.equal(fixture.changes.length, 1)
  fixture.flushEffects()
  fixture.render(edited, false)
  fixture.flushEffects()
  store.undo()
  assert.equal(store.get().nodes[0].props.text, 'Original')
})

test('save echoes and external replacement keep the shared history while using the current field callback', async () => {
  const initial = [node()]
  const fixture = await editor(initial)
  const firstChanges = [], currentChanges = []
  const store = fixture.render(initial, false, (next) => firstChanges.push(next))
  fixture.flushEffects()
  store.updateProps('one', { text: 'First edit' })
  const emitted = store.get().nodes
  fixture.render(emitted, false, (next) => currentChanges.push(next))
  fixture.flushEffects()
  assert.equal(store.get().nodes, emitted)
  assert.equal(store.canUndo(), true)
  const saved = structuredClone(emitted)
  fixture.render(saved, false, (next) => currentChanges.push(next))
  fixture.flushEffects()
  assert.equal(store.get().nodes, emitted, 'An equivalent server echo keeps the current document tree.')
  assert.equal(store.canUndo(), true, 'Acknowledging a save must not discard the document history.')
  store.updateNode('one', { label: 'Selected block' })
  assert.equal(firstChanges.length, 1)
  assert.equal(currentChanges.length, 1, 'Updates must use the latest host callback.')
})

test('read-only sessions accept external record refresh without emitting an edit', async () => {
  const initial = [node()]
  const fixture = await editor(initial)
  const store = fixture.render(initial, true)
  fixture.flushEffects()
  const refreshed = [{ ...node(), props: { text: 'Latest published content' } }]
  assert.equal(fixture.render(refreshed, true), store)
  fixture.flushEffects()
  assert.equal(store.get().nodes, refreshed)
  store.updateProps('one', { text: 'Still forbidden' })
  assert.equal(store.get().nodes, refreshed)
  assert.equal(fixture.changes.length, 0)
})
