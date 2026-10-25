import { test } from 'node:test'
import assert from 'node:assert/strict'

// The screens run in India. Set the zone before any Date is used so the test means the same on every machine.
process.env.TZ = 'Asia/Kolkata'
const { isoLocal, todayLocal, monthStartLocal } = await import('./dates.js')

test('the first of the month is the first of the month, not the last day of the previous one', () => {
  const now = new Date('2026-11-10T09:00:00+05:30')
  assert.equal(monthStartLocal(now), '2026-11-01')
  assert.equal(new Date(2026, 10, 1).toISOString().slice(0, 10), '2026-10-31', 'the old way gave this')
})

test('before 05:30 in the morning today is still today', () => {
  const now = new Date('2026-11-10T02:00:00+05:30')
  assert.equal(todayLocal(now), '2026-11-10')
  assert.equal(now.toISOString().slice(0, 10), '2026-11-09', 'the old way gave this')
})

test('the last minute of a month and the first minute of the next', () => {
  assert.equal(todayLocal(new Date('2026-11-30T23:59:00+05:30')), '2026-11-30')
  assert.equal(todayLocal(new Date('2026-12-01T00:01:00+05:30')), '2026-12-01')
  assert.equal(monthStartLocal(new Date('2026-12-01T00:01:00+05:30')), '2026-12-01')
})

test('single digits are padded and a leap day survives', () => {
  assert.equal(isoLocal(new Date(2028, 1, 29)), '2028-02-29')
  assert.equal(isoLocal(new Date(2026, 0, 5)), '2026-01-05')
})
