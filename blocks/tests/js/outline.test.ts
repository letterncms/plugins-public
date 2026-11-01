import assert from 'node:assert/strict'
import { test } from 'node:test'
import { allRows, outline, rowId, rowNodes, stops } from '../../admin/src/write/outline.ts'
import type { BlockNode, BlockTypeDef } from '../../admin/src/core/types.ts'

const type = (name: string, children?: boolean | string[]): BlockTypeDef => ({ name, title: name, icon: 'block', category: 'plugin', children })
const types = new Map([
  type('section', true), type('box', true), type('columns', ['column']), type('column', true), type('repeat', true),
  type('paragraph'), type('heading'), type('quote'), type('callout'), type('list'), type('button'), type('link', true),
  type('icon'), type('spacer'), type('outlet'), type('html'), type('input'), type('search'), type('plugin-container', true),
].map((item) => [item.name, item]))
const node = (id: string, name: string, children?: BlockNode[], extra: Partial<BlockNode> = {}): BlockNode => ({ id, type: name, props: {}, ...(children ? { children } : {}), ...extra })
const noCustom = () => false

test('nested layouts retain selectable wrappers, original nodes and exact parent relationships', () => {
  const paragraph = node('words', 'paragraph', undefined, { props: { text: 'Editable words' } })
  const box = node('box', 'box', [paragraph], { style: { base: { display: 'grid' } } })
  const section = node('hero', 'section', [box], { label: 'Hero', attrs: { 'aria-label': 'Introduction' } })
  const original = structuredClone(section)
  const sections = outline([section], types, noCustom)
  assert.equal(sections.length, 1)
  assert.equal(sections[0].label, 'Hero')
  assert.equal(sections[0].container, true)
  assert.deepEqual(allRows(sections).map((row) => [rowId(row), row.kind, row.parent]), [
    ['hero', 'container', null], ['box', 'container', 'hero'], ['words', 'text', 'box'],
  ])
  assert.equal(rowNodes(sections[0].rows[0])[0], section)
  assert.equal(rowNodes(allRows(sections)[1])[0], box)
  assert.deepEqual(section, original, 'Deriving an outline must not rewrite the stored layout.')
})

test('caret traversal crosses nested containers and specialized columns in source order', () => {
  const sections = outline([
    node('intro', 'paragraph'),
    node('layout', 'section', [
      node('columns', 'columns', [
        node('left', 'column', [node('quote', 'quote'), node('nested', 'box', [node('list', 'list', undefined, { props: { items: ['One', 'Two'] } })])]),
        node('right', 'column', [node('heading', 'heading')]),
      ]),
    ]),
    node('outro', 'paragraph'),
  ], types, noCustom)
  assert.deepEqual(stops(sections), [
    { id: 'intro', prop: 'text', parent: null },
    { id: 'quote', prop: 'text', parent: 'left' }, { id: 'quote', prop: 'cite', parent: 'left' },
    { id: 'list', prop: 'items', index: 0, parent: 'nested' }, { id: 'list', prop: 'items', index: 1, parent: 'nested' },
    { id: 'heading', prop: 'text', parent: 'right' }, { id: 'outro', prop: 'text', parent: null },
  ])
  assert.deepEqual(allRows(sections).map(rowId), ['intro', 'layout', 'columns', 'quote', 'nested', 'list', 'heading', 'outro'])
})

test('repeated source children and bound values remain available without misleading literal caret stops', () => {
  const repeat = node('repeat', 'repeat', [
    node('bound-title', 'heading', undefined, { bind: { text: 'item.title' } }),
    node('bound-list', 'list', undefined, { bind: { items: 'item.features' } }),
    node('literal', 'paragraph'),
  ], { props: { source: 'entries', collection: 'posts' } })
  const sections = outline([repeat, ...['outlet', 'html', 'input', 'search'].map((name) => node(name, name))], types, noCustom)
  assert.deepEqual(allRows(sections).map((row) => [rowId(row), row.kind]), [
    ['repeat', 'container'], ['bound-title', 'block'], ['bound-list', 'block'], ['literal', 'text'],
    ['outlet', 'block'], ['html', 'block'], ['input', 'block'], ['search', 'block'],
  ])
  assert.deepEqual(stops(sections), [{ id: 'literal', prop: 'text', parent: 'repeat' }])
})

test('icons, spacers and unknown plugin nodes are retained, including unknown nested content', () => {
  const unknown = node('plugin', 'not-installed', [node('plugin-text', 'paragraph')])
  const sections = outline([node('icon', 'icon'), node('space', 'spacer'), unknown, node('leaf', 'another-plugin')], types, noCustom)
  assert.deepEqual(allRows(sections).map((row) => [rowId(row), row.kind]), [
    ['icon', 'block'], ['space', 'block'], ['plugin', 'container'], ['plugin-text', 'text'], ['leaf', 'block'],
  ])
  assert.equal(rowNodes(allRows(sections)[2])[0], unknown)
})

test('custom child-capable blocks preserve their source children and empty containers remain selectable', () => {
  const sections = outline([
    node('plugin', 'plugin-container', [node('child', 'paragraph')]),
    node('empty', 'box'),
  ], types, (name) => name === 'plugin-container')
  assert.deepEqual(allRows(sections).map((row) => [rowId(row), row.kind]), [['plugin', 'container'], ['child', 'text'], ['empty', 'container']])
  assert.equal(sections.length, 2)
  assert.equal(sections[1].rows[0].kind, 'container')
  assert.deepEqual(stops(sections), [{ id: 'child', prop: 'text', parent: 'plugin' }])
})

test('leaf actions share a row while links containing blocks keep their own selectable wrapper', () => {
  const sections = outline([
    node('one', 'button'), node('two', 'link', undefined, { props: { label: 'Read more' } }),
    node('wrapped', 'link', [node('wrapped-icon', 'icon'), node('wrapped-label', 'paragraph')], { props: { label: 'Card' } }),
    node('three', 'button'),
  ], types, noCustom)
  assert.equal(sections[0].rows[0].kind, 'actions')
  assert.deepEqual(rowNodes(sections[0].rows[0]).map((item) => item.id), ['one', 'two'])
  assert.deepEqual(allRows(sections).map(rowId), ['one', 'wrapped', 'wrapped-icon', 'wrapped-label', 'three'])
  assert.deepEqual(stops(sections), [{ id: 'wrapped-label', prop: 'text', parent: 'wrapped' }])
})

test('visibility propagates through layouts and columns without merging hidden and visible actions', () => {
  const sections = outline([
    node('visible', 'button'), node('hidden-action', 'button', undefined, { hidden: true }),
    node('layout', 'box', [node('hidden-text', 'paragraph')], { hidden: true }),
    node('columns', 'columns', [node('hidden-column', 'column', [node('column-text', 'paragraph')], { hidden: true })]),
  ], types, noCustom)
  assert.equal(sections[0].rows.length, 2)
  assert.deepEqual(allRows(sections).map((row) => [rowId(row), row.hidden]), [
    ['visible', false], ['hidden-action', true], ['layout', true], ['hidden-text', true], ['columns', false], ['column-text', true],
  ])
})

test('an empty list retains one editable caret stop inside its actual container', () => {
  const sections = outline([node('box', 'box', [node('empty-list', 'list', undefined, { props: { items: [] } })])], types, noCustom)
  assert.deepEqual(stops(sections), [{ id: 'empty-list', prop: 'items', index: 0, parent: 'box' }])
})
