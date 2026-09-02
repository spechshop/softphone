import fs from 'node:fs';
import vm from 'node:vm';

const router = fs.readFileSync(new URL('../js/router.js', import.meta.url), 'utf8');
const page = fs.readFileSync(new URL('../plugins/Request/pages/default.html', import.meta.url), 'utf8');

if (!page.includes('id="btnLogout"') || !page.includes('onclick="logoutSpechPhone()"')) {
  throw new Error('botão Deslogar não está disponível na configuração');
}
if (!router.includes('if (logoutInProgress) return;') || !router.includes("socketGlobal.close(1000, 'logout')")) {
  throw new Error('logout não bloqueia a reconexão automática do WebSocket');
}

const start = router.indexOf('class UserManager');
const end = router.indexOf('class templateManager', start);
if (start < 0 || end < 0) throw new Error('fluxo de logout não encontrado');

const values = new Map([
  ['fp', 'fp-a'],
  ['user_data', JSON.stringify({fp: 'fp-a', sipUser: 'lotus', sipPass: 'secret'})],
  ['spech_call_history', JSON.stringify([{number: '1000'}])],
]);
const localStorage = {
  getItem: key => values.get(key) ?? null,
  setItem: (key, value) => values.set(key, String(value)),
  removeItem: key => values.delete(key),
};
Object.defineProperty(localStorage, 'fp', {
  get: () => values.get('fp'),
  set: value => values.set('fp', String(value)),
});

const events = [];
const subscription = {
  endpoint: 'https://push.example.test/private-endpoint',
  unsubscribe: async () => events.push('push-unsubscribed'),
};
const button = {disabled: false, setAttribute: () => {}, innerHTML: ''};
const socket = {
  readyState: 1,
  close: (code, reason) => events.push(`socket-closed:${code}:${reason}`),
};

const context = {
  localStorage,
  navigator: {
    serviceWorker: {
      ready: Promise.resolve({pushManager: {getSubscription: async () => subscription}}),
    },
  },
  document: {getElementById: id => id === 'btnLogout' ? button : null},
  console,
  WebSocket: {OPEN: 1, CLOSING: 2},
  sleep: async () => {},
  sendRecByToken: async (data, type) => {
    events.push({data: {...data}, type});
    return {success: true};
  },
  window: {
    PushManager: function PushManager() {},
    confirm: () => true,
    stopAudioCapture: () => events.push('capture-stopped'),
    stopAudio: async () => events.push('audio-stopped'),
    audioWS: {close: () => events.push('audio-socket-closed')},
    location: {reload: () => events.push('reloaded')},
  },
};

const runtime = {...context, socket};
vm.createContext(runtime);
vm.runInContext(
  `let socketGlobal = socket; let logoutInProgress = false; ${router.slice(start, end)}`,
  runtime,
);
await runtime.window.logoutSpechPhone();

const remove = events.find(event => typeof event === 'object' && event.type === 'removePushSubscription');
if (remove?.data.fp !== 'fp-a' || remove?.data.endpoint !== subscription.endpoint) {
  throw new Error('logout não removeu a subscription da conta canônica atual');
}
for (const expected of ['push-unsubscribed', 'socket-closed:1000:logout', 'reloaded']) {
  if (!events.includes(expected)) throw new Error(`logout não executou: ${expected}`);
}
for (const removed of ['fp', 'user_data', 'spech_call_history']) {
  if (values.has(removed)) throw new Error(`logout não limpou ${removed}`);
}
if (!button.disabled || !button.innerHTML.includes('Deslogando')) {
  throw new Error('botão não sinaliza logout em andamento');
}

console.log('OK: logout desvincula Push por fp, fecha sockets, limpa a conta local e recarrega.');
