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

const { addDaysLocal, periodPreset, previousPeriod, fyStartYear, fyLabel, PERIOD_PRESETS } = await import('./dates.js')
const at = (s) => new Date(`${s}T10:00:00+05:30`)
const preset = (key, s) => { const r = periodPreset(key, at(s)); return [r.from, r.to] }

test('adding days is a calendar step, across a month end and in the early morning', () => {
  assert.equal(addDaysLocal(30, new Date('2026-11-10T02:00:00+05:30')), '2026-12-10')
  assert.equal(addDaysLocal(-1, new Date('2026-03-01T01:00:00+05:30')), '2026-02-28')
  assert.equal(addDaysLocal(0, new Date('2026-11-10T02:00:00+05:30')), '2026-11-10')
})

test('the financial year starts on 1 April', () => {
  assert.equal(fyStartYear(at('2026-04-01')), 2026)
  assert.equal(fyStartYear(at('2027-03-31')), 2026)
  assert.equal(fyStartYear(at('2026-03-31')), 2025)
  assert.equal(fyLabel(2026), '2026-27')
  assert.equal(fyLabel(2099), '2099-00')
})

test('report period presets in the middle of a financial year', () => {
  const now = '2026-11-10'
  assert.deepEqual(preset('today', now), ['2026-11-10', '2026-11-10'])
  assert.deepEqual(preset('month', now), ['2026-11-01', '2026-11-10'])
  assert.deepEqual(preset('last_month', now), ['2026-10-01', '2026-10-31'])
  assert.deepEqual(preset('quarter', now), ['2026-10-01', '2026-11-10'])
  assert.deepEqual(preset('last_quarter', now), ['2026-07-01', '2026-09-30'])
  assert.deepEqual(preset('fy', now), ['2026-04-01', '2026-11-10'])
  assert.deepEqual(preset('last_fy', now), ['2025-04-01', '2026-03-31'])
})

test('report period presets after the calendar year turns (January to March are the last quarter of the financial year)', () => {
  assert.deepEqual(preset('last_month', '2027-01-05'), ['2026-12-01', '2026-12-31'])
  assert.deepEqual(preset('quarter', '2027-02-15'), ['2027-01-01', '2027-02-15'])
  assert.deepEqual(preset('last_quarter', '2027-02-15'), ['2026-10-01', '2026-12-31'])
  assert.deepEqual(preset('fy', '2027-02-15'), ['2026-04-01', '2027-02-15'])
  assert.deepEqual(preset('last_fy', '2027-02-15'), ['2025-04-01', '2026-03-31'])
})

test('report period presets on the first days of a quarter and of the financial year', () => {
  assert.deepEqual(preset('quarter', '2026-04-02'), ['2026-04-01', '2026-04-02'])
  assert.deepEqual(preset('last_quarter', '2026-04-02'), ['2026-01-01', '2026-03-31'])
  assert.deepEqual(preset('last_month', '2026-04-02'), ['2026-03-01', '2026-03-31'])
  assert.deepEqual(preset('last_quarter', '2026-07-01'), ['2026-04-01', '2026-06-30'])
  assert.deepEqual(preset('fy', '2026-04-01'), ['2026-04-01', '2026-04-01'])
  assert.deepEqual(preset('last_fy', '2026-04-01'), ['2025-04-01', '2026-03-31'])
  assert.deepEqual(preset('last_month', '2026-03-31'), ['2026-02-01', '2026-02-28'])
})

test('every preset in the list resolves and anything else is a custom range', () => {
  for (const p of PERIOD_PRESETS) assert.ok(periodPreset(p.key, at('2026-11-10')), p.key)
  assert.equal(periodPreset('custom', at('2026-11-10')), null)
})

test('the previous period has the same length and ends the day before', () => {
  assert.deepEqual(previousPeriod('2026-11-01', '2026-11-10'), { from: '2026-10-22', to: '2026-10-31' })
  assert.deepEqual(previousPeriod('2026-11-10', '2026-11-10'), { from: '2026-11-09', to: '2026-11-09' })
  assert.deepEqual(previousPeriod('2026-04-01', '2027-03-31'), { from: '2025-04-01', to: '2026-03-31' })
  assert.deepEqual(previousPeriod('2026-03-01', '2026-03-31'), { from: '2026-01-29', to: '2026-02-28' })
})

test('a company whose financial year is the calendar year (start month 1)', () => {
  const p = (key, s) => { const r = periodPreset(key, at(s), 1); return [r.from, r.to] }
  assert.deepEqual(p('fy', '2026-11-10'), ['2026-01-01', '2026-11-10'])
  assert.deepEqual(p('last_fy', '2026-11-10'), ['2025-01-01', '2025-12-31'])
  assert.deepEqual(p('quarter', '2026-11-10'), ['2026-10-01', '2026-11-10'])
  assert.deepEqual(p('last_quarter', '2026-11-10'), ['2026-07-01', '2026-09-30'])
  assert.deepEqual(p('last_quarter', '2026-02-01'), ['2025-10-01', '2025-12-31'])
  assert.equal(fyStartYear(at('2026-11-10'), 1), 2026)
})

test('a company whose financial year starts in July (start month 7)', () => {
  const p = (key, s) => { const r = periodPreset(key, at(s), 7); return [r.from, r.to] }
  assert.deepEqual(p('fy', '2026-11-10'), ['2026-07-01', '2026-11-10'])
  assert.deepEqual(p('fy', '2026-03-10'), ['2025-07-01', '2026-03-10'])
  assert.deepEqual(p('last_fy', '2026-03-10'), ['2024-07-01', '2025-06-30'])
  assert.deepEqual(p('quarter', '2026-11-10'), ['2026-10-01', '2026-11-10'])
  assert.deepEqual(p('quarter', '2026-03-10'), ['2026-01-01', '2026-03-10'])
  assert.deepEqual(p('last_quarter', '2026-08-02'), ['2026-04-01', '2026-06-30'])
  assert.deepEqual(p('last_quarter', '2026-07-02'), ['2026-04-01', '2026-06-30'])
})
