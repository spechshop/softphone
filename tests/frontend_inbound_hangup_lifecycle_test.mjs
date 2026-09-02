import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const router = fs.readFileSync(new URL('../js/router.js', import.meta.url), 'utf8');
const start = router.indexOf('window.inboundCallState =');
const end = router.indexOf('// ===== Real-time Chat Store =====');
assert.ok(start >= 0 && end > start, 'bloco inbound não encontrado em js/router.js');

const elements = new Map();
const documentListeners = new Set();
const intervals = new Set();
let nextInterval = 0;

class FakeElement {
  constructor(tag) {
    this.tag = tag;
    this.style = {};
    this.className = '';
    this.innerHTML = '';
    this.textContent = '';
    this.disabled = false;
    this.offsetWidth = 320;
    this.offsetHeight = 120;
    this._id = '';
    this._timer = null;
    this.classList = {add() {}, remove() {}, toggle() { return false; }};
  }
  set id(value) { this._id = value; }
  get id() { return this._id; }
  remove() { if (this._id) elements.delete(this._id); }
  addEventListener() {}
  querySelector(selector) {
    if (selector === '#activeCallBar-time') {
      this._timer ||= new FakeElement('span');
      return this._timer;
    }
    return null;
  }
  getBoundingClientRect() { return {left: 0, top: 0}; }
  closest() { return null; }
}

const document = {
  body: {appendChild(element) { if (element.id) elements.set(element.id, element); }},
  createElement: tag => new FakeElement(tag),
  getElementById: id => elements.get(id) || null,
  addEventListener(type, listener) { documentListeners.add(`${type}:${String(listener)}`); },
  removeEventListener(type, listener) { documentListeners.delete(`${type}:${String(listener)}`); },
};

let pendingHangup = null;
let audioStops = 0;
let captureStops = 0;
const context = {
  console: {log() {}, warn() {}, error() {}, trace() {}},
  document,
  setInterval(fn) { const id = ++nextInterval; intervals.add(id); return id; },
  clearInterval(id) { intervals.delete(id); },
  setTimeout(fn) { fn(); return 1; },
  clearTimeout() {},
  Date,
  Math,
  Promise,
  sendBrowserNotification() {},
  user: {getValue() { return false; }},
};
context.window = context;
context.window.innerWidth = 1280;
context.window.innerHeight = 720;
context.window.stopAudio = () => { audioStops++; };
context.window.stopAudioCapture = () => { captureStops++; };
context.window.playAudio = () => {};
context.window.startAudioCapture = () => {};
context.sendRecByToken = (params, type) => {
  if (type === 'HangUpCall' && pendingHangup) return pendingHangup.promise;
  return Promise.resolve({success: true});
};

vm.runInNewContext(router.slice(start, end), context, {filename: 'js/router.js'});

const makeDeferred = () => {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return {promise, resolve};
};
const incoming = callId => context.handleIncomingCall({
  callId, from: '<sip:100@example.test>', to: '<sip:200@example.test>', codec: 'PCMA',
});
const assertIdleBaseline = () => {
  assert.deepEqual({...context.inboundCallState}, {
    currentCallId: null, status: 'idle', direction: null, from: null, to: null,
    codec: null, startedAt: null, acceptSent: false, rejectSent: false, hangupSent: false,
  });
  assert.equal(document.getElementById('activeCallBar'), null);
  assert.equal(document.getElementById('inboundCallCard'), null);
  assert.equal(document.getElementById('inboundCallBackdrop'), null);
  assert.equal(intervals.size, 0, 'intervals devem voltar ao baseline');
  assert.equal(documentListeners.size, 0, 'drag listeners devem voltar ao baseline');
};

// Rejeição antes do atendimento.
incoming('reject-1');
await context.rejectIncomingCall();
assertIdleBaseline();

// Hangup local: success primeiro, callEnded duplicado depois.
incoming('success-first');
context.acceptIncomingCall();
context.handleCallActive();
await context.hangupCurrentCall();
assertIdleBaseline();
assert.equal(context.handleCallEnded('success-first'), false);

// callEnded primeiro, resposta do HangUpCall depois.
incoming('event-first');
context.acceptIncomingCall();
context.handleCallActive();
pendingHangup = makeDeferred();
const request = context.hangupCurrentCall();
assert.equal(context.handleCallEnded('event-first'), true);
pendingHangup.resolve({success: true});
await request;
pendingHangup = null;
assertIdleBaseline();

// Também deve encerrar com segurança durante accepting.
incoming('accepting');
context.acceptIncomingCall();
await context.hangupCurrentCall();
assertIdleBaseline();

// Evento atrasado da chamada anterior não pode fechar a próxima chamada.
incoming('new-call');
assert.equal(context.handleCallEnded('accepting'), false);
assert.equal(context.inboundCallState.currentCallId, 'new-call');
context.handleCallEnded('new-call');
assertIdleBaseline();

// Exercita widget, floating bar, timers e listeners por vários ciclos.
for (let cycle = 0; cycle < 50; cycle++) {
  const callId = `cycle-${cycle}`;
  incoming(callId);
  context.acceptIncomingCall();
  context.handleCallActive();
  await context._hangupActiveCall();
  assertIdleBaseline();
}

assert.equal(audioStops, captureStops, 'playback e captura devem ser encerrados pelo mesmo teardown');
assert.ok(audioStops >= 54, 'teardown de áudio não foi exercitado em todos os cenários');

const page = fs.readFileSync(new URL('../plugins/Request/pages/default.html', import.meta.url), 'utf8');
assert.match(page, /window\.stopAudioCapture\s*=\s*function/, 'stopAudioCapture deve estar acessível ao teardown global');

console.log('OK: lifecycle inbound idempotente, ordens invertidas e 50 ciclos sem resíduos.');
