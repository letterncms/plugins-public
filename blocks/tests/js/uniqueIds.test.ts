import assert from 'node:assert/strict'
import { test } from 'node:test'
import { uniqueIds } from '../../admin/src/core/uniqueIds.ts'

let next = 0
const newId = () => `n${next++}`
const node = (id: string, children?: any[]) => ({ id, type: 'box', props: {}, children })

test('leaves a document with unique ids untouched', () => {
  const nodes = [node('a', [node('b')]), node('c')]
  assert.equal(uniqueIds(nodes, newId), nodes)
})

test('gives a second use of an id a new one, nested or not', () => {
  const out = uniqueIds([node('a', [node('a')]), node('b'), node('b')], newId)
  assert.equal(out[0].id, 'a')
  assert.notEqual(out[0].children![0].id, 'a')
  assert.equal(out[1].id, 'b')
  assert.notEqual(out[2].id, 'b')
})
