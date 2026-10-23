// Run with:  node --test src/composables/
// Uses Node's built-in test runner — no dependency added for it.
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  decideSessionAction as decide, formatCountdown,
  RENEW_WITHIN_SECONDS, WARN_WITHIN_SECONDS, ACTIVE_WITHIN_SECONDS,
} from './sessionPolicy.js'

const HOUR = 3600
const present = 10               // seconds since last input: clearly at the desk
const away = ACTIVE_WITHIN_SECONDS + 60

test('plenty of time left: leave the user alone, active or not', () => {
  assert.equal(decide({ secondsLeft: 10 * HOUR, idleSeconds: present }), 'none')
  assert.equal(decide({ secondsLeft: 10 * HOUR, idleSeconds: away }), 'none')
})

test('an active user is renewed quietly before time runs low — never warned', () => {
  assert.equal(decide({ secondsLeft: RENEW_WITHIN_SECONDS, idleSeconds: present }), 'renew')
  assert.equal(decide({ secondsLeft: 60, idleSeconds: present }), 'renew')
})

test('an idle user is not renewed behind their back, but is warned at the end', () => {
  assert.equal(decide({ secondsLeft: RENEW_WITHIN_SECONDS, idleSeconds: away }), 'none')
  assert.equal(decide({ secondsLeft: WARN_WITHIN_SECONDS + 1, idleSeconds: away }), 'none')
  assert.equal(decide({ secondsLeft: WARN_WITHIN_SECONDS, idleSeconds: away }), 'warn')
  assert.equal(decide({ secondsLeft: 30, idleSeconds: away }), 'warn')
})

test('if a quiet renewal failed (network blip), warn rather than stay silent', () => {
  assert.equal(decide({ secondsLeft: WARN_WITHIN_SECONDS, idleSeconds: present, renewFailed: true }), 'warn')
})

test('a failed renewal does not nag early — only inside the final window', () => {
  assert.equal(decide({ secondsLeft: 20 * 60, idleSeconds: present, renewFailed: true }), 'none')
})

test('out of time is out of time, whatever else is true', () => {
  assert.equal(decide({ secondsLeft: 0, idleSeconds: present }), 'expired')
  assert.equal(decide({ secondsLeft: -500, idleSeconds: away, renewFailed: true }), 'expired')
})

test('a session from before expiry was tracked renews once to learn its expiry', () => {
  assert.equal(decide({ secondsLeft: null, idleSeconds: away }), 'renew')
  assert.equal(decide({ secondsLeft: undefined, idleSeconds: present }), 'renew')
})

test('the boundaries are inclusive at exactly 5 and 30 minutes', () => {
  assert.equal(decide({ secondsLeft: WARN_WITHIN_SECONDS, idleSeconds: away }), 'warn')
  assert.equal(decide({ secondsLeft: WARN_WITHIN_SECONDS + 1, idleSeconds: away }), 'none')
  assert.equal(decide({ secondsLeft: RENEW_WITHIN_SECONDS, idleSeconds: present }), 'renew')
  assert.equal(decide({ secondsLeft: RENEW_WITHIN_SECONDS + 1, idleSeconds: present }), 'none')
})

test('countdown formatting', () => {
  assert.equal(formatCountdown(300), '5:00')
  assert.equal(formatCountdown(65), '1:05')
  assert.equal(formatCountdown(9), '0:09')
  assert.equal(formatCountdown(-4), '0:00')
})
