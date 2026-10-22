import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const router = fs.readFileSync(new URL('../js/router.js', import.meta.url), 'utf8');
const start = router.indexOf('function urlBase64ToUint8Array');
const end = router.indexOf('// ===== Inbound Call Engine =====');
assert.ok(start >= 0 && end > start, 'Web Push block not found');

function createHarness({subscription = null, responses = []} = {}) {
  const storage = new Map();
  const calls = [];
  const toasts = [];
  const icon = {className: ''};
  const button = {style: {}, title: ''};
  let currentSubscription = subscription;
  const pushManager = {
    async getSubscription() { return currentSubscription; },
    async subscribe() { currentSubscription = makeSubscription(); return currentSubscription; },
  };
  const context = {
    window: {PushManager: function PushManager() {}},
    document: {getElementById: id => id === 'push-notif-btn' ? button : id === 'push-notif-icon' ? icon : null},
    navigator: {serviceWorker: {ready: Promise.resolve({pushManager})}},
    Notification: {permission: 'granted', async requestPermission() { return 'granted'; }},
    PushManager: function PushManager() {},
    UserManager: class { getValue(key) { return key === 'fp' ? 'account-a' : null; } },
    localStorage: {
      getItem(key) { return storage.get(key) ?? null; },
      setItem(key, value) { storage.set(key, String(value)); },
      removeItem(key) { storage.delete(key); },
    },
    async sendRecByToken(data, type) { calls.push({data, type}); return responses.shift() ?? {success: true, publicKey: 'AQ'}; },
    toast(...args) { toasts.push(args); },
    console: {log() {}, warn() {}},
    atob: () => '\x01',
    Uint8Array,
  };
  vm.runInNewContext(router.slice(start, end), context);
  return {context, calls, toasts, storage, icon, button, get subscription() { return currentSubscription; }};
}

function makeSubscription(unsubscribeResult = true) {
  return {endpoint: 'https://push.example/subscription', toJSON() { return {endpoint: this.endpoint, keys: {p256dh: 'public', auth: 'auth'}}; },
    async unsubscribe() { this.unsubscribed = true; return unsubscribeResult; }};
}

const key = 'spechphone.push.pending.account-a';
const existing = makeSubscription();
const retry = createHarness({subscription: existing, responses: [{success: false}, {success: true}]});
retry.storage.set(key, '1');
await retry.context.window.enablePushMessages();
assert.equal(existing.unsubscribed, undefined, 'failed save retry must not unsubscribe the browser subscription');
assert.equal(retry.storage.get(key), '1', 'failed save must remain marked for retry');
await retry.context.window.enablePushMessages();
assert.equal(retry.storage.has(key), false, 'confirmed backend save must clear pending marker');
assert.equal(existing.unsubscribed, undefined, 'successful retry must keep subscription active');

const created = createHarness({responses: [{success: true, publicKey: 'AQ'}, {success: false}]});
await created.context.window.enablePushMessages();
assert.equal(created.storage.get(key), '1', 'backend failure after browser subscription must be recoverable');
assert.ok(created.toasts.some(([, , , severity]) => severity === 'error'), 'failed save must report failure');

const failedRemoval = createHarness({subscription: makeSubscription(), responses: [{success: false}]});
await failedRemoval.context.window.enablePushMessages();
assert.equal(failedRemoval.subscription.unsubscribed, undefined, 'failed backend removal must preserve browser subscription');

const removal = createHarness({subscription: makeSubscription(), responses: [{success: true}]});
await removal.context.window.enablePushMessages();
assert.equal(removal.subscription.unsubscribed, true, 'confirmed removal should unsubscribe browser');

const failedBrowserRemoval = createHarness({subscription: makeSubscription(false), responses: [{success: true}, {success: false}]});
await failedBrowserRemoval.context.window.enablePushMessages();
assert.equal(failedBrowserRemoval.storage.get(key), '1', 'failed browser removal with failed backend restore must be marked pending');
console.log('OK: Web Push retry after save failure, backend-confirmed activation, and safe removal failure.');
