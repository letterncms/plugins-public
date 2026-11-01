// node --test tests/js — the slash menu's Enter decisions, every combination.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { menuEnter, settleEnter, textDefersEnter } from '../../admin/src/core/slashKeys.ts'

test('the open menu picks the highlighted item whenever there is one', () => {
  for (const loading of [false, true]) {
    assert.equal(menuEnter(3, 0, loading), 'choose')
    assert.equal(menuEnter(3, 2, loading), 'choose')
  }
})

test('with nothing to pick, Enter waits for loading components, else goes to the text', () => {
  assert.equal(menuEnter(0, 0, true), 'wait')
  assert.equal(menuEnter(0, 0, false), 'pass')
  // A highlight left over from a longer list counts as nothing to pick.
  assert.equal(menuEnter(2, 5, false), 'pass')
  assert.equal(menuEnter(2, 5, true), 'wait')
})

test('a remembered Enter picks the first match, waits while loading, or is handed back', () => {
  assert.equal(settleEnter(1, false), 'choose')
  assert.equal(settleEnter(4, true), 'choose')
  assert.equal(settleEnter(0, true), 'wait')
  assert.equal(settleEnter(0, false), 'release')
})

test('the text keeps Enter for the menu only while the menu is not on screen yet', () => {
  assert.equal(textDefersEnter(true, false), true)
  assert.equal(textDefersEnter(true, true), false)
  assert.equal(textDefersEnter(false, false), false)
  assert.equal(textDefersEnter(false, true), false)
})
