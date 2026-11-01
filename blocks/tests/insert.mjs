// node --experimental-vm-modules --test cms/plugins/blocks/tests/insert.mjs
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync } from 'node:fs'
import { stripTypeScriptTypes } from 'node:module'
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm'

async function insertionEngine() {
  const context = createContext({ structuredClone })
  let sequence = 0
  const types = new SyntheticModule(['newId'], function () { this.setExport('newId', () => `inserted-${++sequence}`) }, { context })
  const source = (path) => new SourceTextModule(stripTypeScriptTypes(readFileSync(new URL(path, import.meta.url), 'utf8'), { mode: 'transform' }), { context })
  const tree = source('../admin/src/core/tree.ts')
  await tree.link(() => types)
  const insert = source('../admin/src/core/insert.ts')
  await insert.link((name) => name === './tree' ? tree : types)
  await insert.evaluate()
  return { ...insert.namespace, tree: tree.namespace }
}

const engine = await insertionEngine()
const type = (name, extra = {}) => ({ name, title: name, icon: 'block', category: 'plugin', ...extra })
const types = new Map([
  type('paragraph', { category: 'text' }), type('heading', { category: 'text' }),
  type('box', { category: 'layout', children: true }), type('repeat', { category: 'data', children: true }),
  type('actions', { category: 'layout', children: ['button', 'link'] }),
  type('button', { category: 'interactive' }), type('link', { category: 'text', children: true }),
  type('columns', { category: 'layout', children: ['column'] }), type('column', { category: 'layout', parent: 'columns', children: true }),
  type('wrong-parent', { children: ['column'] }),
  ...['icon', 'spacer', 'input', 'search', 'html', 'outlet', 'pattern', 'vendor/custom'].map((name) => type(name, { category: 'design' })),
].map((definition) => [definition.name, definition]))
const node = (id, name, children) => ({ id, type: name, props: {}, ...(children ? { children } : {}) })
const normalize = (value) => JSON.parse(JSON.stringify(value))
const location = (...args) => normalize(engine.insertionPoint(...args))
const nestedActions = () => [node('outer', 'box', [node('actions', 'actions', [node('anchor', 'button')]), node('following', 'paragraph')])]

test('catalog categories do not impose a second insertion whitelist', () => {
  for (const definition of types.values()) {
    if (!definition.parent) assert.equal(engine.accepts(null, definition.name, types), true, definition.name)
  }
  assert.equal(engine.accepts(null, 'box', types, ['paragraph']), false, 'The field restriction still applies at the root.')
  assert.equal(engine.accepts(null, 'paragraph', types, ['paragraph']), true)
})

test('one block inserts beside its anchor in the nearest compatible parent', () => {
  const nodes = nestedActions()
  assert.deepEqual(location(nodes, 'anchor', 'button', types), { parentId: 'actions', index: 1 })
  assert.deepEqual(location(nodes, 'anchor', 'heading', types), { parentId: 'outer', index: 1 })
  assert.deepEqual(location(nodes, 'anchor', 'repeat', types), { parentId: 'outer', index: 1 })
})

test('every sibling in an expanded pattern must fit the same insertion parent', () => {
  const nodes = nestedActions()
  assert.deepEqual(location(nodes, 'anchor', ['button', 'link'], types), { parentId: 'actions', index: 1 })
  assert.deepEqual(location(nodes, 'anchor', ['button', 'paragraph'], types), { parentId: 'outer', index: 1 })
  assert.deepEqual(location(nodes, 'anchor', ['paragraph', 'button'], types), { parentId: 'outer', index: 1 }, 'Changing pattern order must not let the first compatible type choose a wrong parent.')
})

test('a heterogeneous pattern is rejected when no common ancestor accepts all sibling types', () => {
  const nodes = [node('columns', 'columns', [node('left', 'column', [node('anchor', 'paragraph')])])]
  assert.equal(location(nodes, 'anchor', ['column', 'paragraph'], types), null)
  assert.equal(location([node('anchor', 'paragraph')], 'anchor', ['paragraph', 'box'], types, ['paragraph']), null)
})

test('field-level allowed types constrain the root while nested parents enforce their own contract', () => {
  const nodes = nestedActions()
  assert.deepEqual(location(nodes, 'anchor', ['paragraph', 'button'], types, ['box']), { parentId: 'outer', index: 1 })
  assert.equal(location(nodes, 'outer', 'paragraph', types, ['box']), null)
  assert.deepEqual(location(nodes, 'outer', 'box', types, ['box']), { parentId: null, index: 1 })
})

test('child-only blocks require both their declared parent and the parent child allowance', () => {
  assert.equal(engine.accepts(null, 'column', types), false)
  assert.equal(engine.accepts(node('box', 'box'), 'column', types), false)
  assert.equal(engine.accepts(node('columns', 'columns'), 'column', types), true)
  assert.equal(engine.accepts(node('other', 'wrong-parent'), 'column', types), false, 'An explicit child list must not bypass the child-only parent restriction.')
  const nodes = [node('columns', 'columns', [node('left', 'column', [node('anchor', 'paragraph')])])]
  assert.deepEqual(location(nodes, 'anchor', 'column', types), { parentId: 'columns', index: 1 })
})

test('missing anchors append only when all proposed sibling types are allowed at the root', () => {
  const nodes = [node('existing', 'paragraph')]
  assert.deepEqual(location(nodes, 'deleted-anchor', ['heading', 'box'], types), { parentId: null, index: 1 })
  assert.equal(location(nodes, 'deleted-anchor', ['paragraph', 'column'], types), null)
  assert.equal(location(nodes, 'deleted-anchor', ['paragraph', 'box'], types, ['paragraph']), null)
})

test('expanded pattern copies preserve sibling order and nested source data at a common parent', () => {
  const pattern = { handle: 'mixed', title: 'Mixed content', synced: false, blocks: [
    { ...node('source-button', 'button'), props: { label: 'Read more' } },
    node('source-box', 'box', [{ ...node('source-text', 'paragraph'), props: { text: 'Nested words' } }]),
  ] }
  const original = structuredClone(pattern)
  const created = engine.choiceToNodes({ kind: 'pattern', pattern }, types)
  const nodes = nestedActions()
  const at = engine.insertionPoint(nodes, 'anchor', Array.from(created, (item) => item.type), types)
  assert.deepEqual(normalize(at), { parentId: 'outer', index: 1 })
  const result = engine.tree.insert(nodes, at.parentId, at.index, created)
  assert.deepEqual(Array.from(result[0].children, (item) => item.type), ['actions', 'button', 'box', 'paragraph'])
  assert.equal(result[0].children[2].children[0].props.text, 'Nested words')
  assert.notEqual(created[0].id, pattern.blocks[0].id)
  assert.notEqual(created[1].children[0].id, pattern.blocks[1].children[0].id)
  assert.deepEqual(pattern, original, 'Insertion must not mutate the reusable source pattern.')
  assert.deepEqual(nodes, nestedActions(), 'Finding an insertion point and inserting keep the original document immutable.')
})
