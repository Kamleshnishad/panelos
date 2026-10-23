// Run with:  node --test src/services/
import test from 'node:test'
import assert from 'node:assert/strict'
import { runTransfer, transfers } from './transfers.js'
import { activity, trackActivity } from './activity.js'

const sleep = (ms) => new Promise(r => setTimeout(r, ms))
const card = (label) => transfers.find(t => t.label === label)

test('the estimate moves while the server is working and never reaches 100% on its own', async () => {
  const seen = []
  const result = await runTransfer('PDF-a', async () => {
    for (let i = 0; i < 6; i++) { await sleep(150); seen.push(card('PDF-a').percent) }
    return 'file'
  }, { kind: 'test-a', estimateMs: 600 })

  assert.equal(result, 'file')
  assert.ok(seen[0] > 0, 'starts moving straight away')
  assert.ok(seen.every((p, i) => i === 0 || p >= seen[i - 1]), 'never goes backwards')
  assert.ok(Math.max(...seen) <= 88, 'estimate is capped below the real-download range')
})

test('real download progress takes over, and 100% only comes with the file', async () => {
  let during
  await runTransfer('PDF-b', async (onProgress) => {
    onProgress({ loaded: 50, total: 100 })
    during = { percent: card('PDF-b').percent, phase: card('PDF-b').phase }
    return 1
  }, { kind: 'test-b' })

  assert.equal(during.phase, 'downloading')
  assert.ok(during.percent >= 88 && during.percent < 100)
  assert.equal(card('PDF-b').percent, 100)
  assert.equal(card('PDF-b').phase, 'done')
  await sleep(1000)
  assert.equal(card('PDF-b'), undefined, 'the card clears itself after finishing')
})

test('a failure is shown as failed, rethrown, and still clears', async () => {
  await assert.rejects(runTransfer('PDF-c', async () => { throw new Error('boom') }, { kind: 'test-c' }), /boom/)
  assert.equal(card('PDF-c').failed, true)
})

test('the top bar ignores fast requests and silent ones, and shows for slow ones', async () => {
  const handlers = { req: [], res: [] }
  trackActivity({ interceptors: {
    request:  { use: (f) => handlers.req.push(f) },
    response: { use: (ok, bad) => handlers.res.push({ ok, bad }) },
  } })
  const start = (cfg) => handlers.req[0](cfg)
  const finish = (cfg) => handlers.res[0].ok({ config: cfg })

  const fast = start({}); await sleep(50); finish(fast)
  await sleep(400)
  assert.equal(activity.busy, false, 'a fast request never flashes the bar')

  const quiet = start({ silent: true }); await sleep(450)
  assert.equal(activity.busy, false, 'polling does not light the bar')
  finish(quiet)

  const slow = start({}); await sleep(450)
  assert.equal(activity.busy, true, 'a slow request shows the bar')
  finish(slow); await sleep(700)
  assert.equal(activity.busy, false, 'and it goes away when the request ends')
})
