// Run with:  node --test src/services/routes.test.mjs
import test from 'node:test'
import assert from 'node:assert/strict'
import { buildPath, parsePath, DETAIL_SECTIONS } from './routes.js'

const KEYS = ['dashboard', 'quotations', 'orders', 'batches', 'dispatches', 'invoices', 'customers', 'leads', 'company', 'superadmin']

test('the dashboard lives at the root and every other screen at /<section>', () => {
  assert.equal(buildPath('dashboard'), '/')
  assert.equal(buildPath('invoices'), '/invoices')
})

test('a record has its own address on the screens that open one', () => {
  assert.deepEqual(DETAIL_SECTIONS, ['quotations', 'orders', 'batches', 'dispatches', 'invoices', 'customers', 'leads'])
  assert.equal(buildPath('quotations', 17), '/quotations/17')
  assert.equal(buildPath('dispatches', 3), '/dispatches/3')
  assert.equal(buildPath('invoices', 9), '/invoices/9')
  assert.equal(buildPath('customers', 4), '/customers/4')
  assert.equal(buildPath('leads', 12), '/leads/12')
  assert.equal(buildPath('company', 9), '/company', 'a screen without record pages ignores the id')
})

test('every address the app writes reads back to the same screen and record', () => {
  for (const key of KEYS) {
    assert.deepEqual(parsePath(buildPath(key), KEYS), { key, id: null })
  }
  for (const key of DETAIL_SECTIONS) {
    assert.deepEqual(parsePath(buildPath(key, 42), KEYS), { key, id: 42 })
  }
})

test('the root and the dashboard address both mean the dashboard', () => {
  assert.deepEqual(parsePath('/', KEYS), { key: 'dashboard', id: null })
  assert.deepEqual(parsePath('', KEYS), { key: 'dashboard', id: null })
  assert.deepEqual(parsePath('/dashboard', KEYS), { key: 'dashboard', id: null })
})

test('trailing slashes are tolerated', () => {
  assert.deepEqual(parsePath('/orders/', KEYS), { key: 'orders', id: null })
  assert.deepEqual(parsePath('/orders/5/', KEYS), { key: 'orders', id: 5 })
})

test('anything that is not a screen of this app is rejected', () => {
  for (const bad of ['/nope', '/quotations/abc', '/quotations/17/edit', '/quotations/-1', '/quotations/1.5', '/api/health', '//', '/orders/0x10']) {
    if (bad === '//') { assert.deepEqual(parsePath(bad, KEYS), { key: 'dashboard', id: null }); continue }
    assert.equal(parsePath(bad, KEYS), null, bad)
  }
})

test('a record address on a screen without record pages opens that screen', () => {
  assert.deepEqual(parsePath('/company/5', KEYS), { key: 'company', id: null })
})

test('a screen the user cannot see is not a valid address', () => {
  assert.equal(parsePath('/superadmin', KEYS.filter(k => k !== 'superadmin')), null)
})
