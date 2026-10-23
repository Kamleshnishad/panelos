// Run with:  node --test src/services/errorText.test.mjs
import test from 'node:test'
import assert from 'node:assert/strict'
import { errorText } from './errorText.js'

const resp = (status, data) => ({ response: { status, data } })

test('validation errors show just the messages', () => {
  assert.equal(errorText(resp(422, { errors: { phone: ['Bad phone.'], email: ['Bad email.'] }, message: 'x' })),
    'Bad phone. • Bad email.')
})

test('a server-error envelope shows its message and the support reference, not the whole body', () => {
  const out = errorText(resp(500, { success: false, message: 'Something went wrong on our end.', error_code: 'SERVER_ERROR', reference: 'ab12cd34', meta: { v: 1 } }))
  assert.equal(out, 'Something went wrong on our end. (ref ab12cd34)')
  assert.ok(!out.includes('SERVER_ERROR') && !out.includes('[object'))
})

test('an EMPTY 500 — what a crashed server returns — says what happened', () => {
  const out = errorText(resp(500, ''))
  assert.match(out, /server hit a problem/)
  assert.match(out, /500/)
})

test('no response at all means the network, not the data', () => {
  assert.match(errorText({}), /Could not reach the server/)
})

test('a client error with no body falls back to the caller text', () => {
  assert.equal(errorText(resp(404, ''), 'Not found.'), 'Not found.')
})

test('non-string error values are ignored rather than printed', () => {
  assert.equal(errorText(resp(422, { errors: { x: [{ a: 1 }] }, message: 'Fallback msg' })), 'Fallback msg')
})
