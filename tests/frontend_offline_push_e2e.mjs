const accountId = process.env.TEST_ACCOUNT_ID || '';
if (!accountId) throw new Error('TEST_ACCOUNT_ID ausente');

const pages = await fetch('http://127.0.0.1:9223/json').then(response => response.json());
const page = pages.find(item => item.type === 'page' && item.url !== 'about:blank')
  || pages.find(item => item.type === 'page');
if (!page) throw new Error('Página do Chrome não encontrada');

const socket = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, {once: true});
  socket.addEventListener('error', reject, {once: true});
});
let id = 0;
const pending = new Map();
socket.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (!pending.has(message.id)) return;
  const {resolve, reject} = pending.get(message.id);
  pending.delete(message.id);
  if (message.error) reject(new Error(message.error.message));
  else resolve(message.result);
});
const command = (method, params = {}) => new Promise((resolve, reject) => {
  const commandId = ++id;
  pending.set(commandId, {resolve, reject});
  socket.send(JSON.stringify({id: commandId, method, params}));
});
const evaluate = async expression => {
  const result = await command('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
  return result.result.value;
};
const waitFor = async (expression, timeout = 20000) => {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    if (await evaluate(`Boolean(${expression})`)) return;
    await new Promise(resolve => setTimeout(resolve, 150));
  }
  throw new Error(`Timeout esperando: ${expression}`);
};

await command('Runtime.enable');
await command('Page.enable');
await command('Browser.grantPermissions', {
  origin: 'https://127.0.0.1:8443', permissions: ['notifications'],
});
await command('Page.navigate', {url: 'https://127.0.0.1:8443/'});
await waitFor(`document.readyState === 'complete' && typeof UserManager === 'function'`);
await evaluate(`(() => {
  const fp = ${JSON.stringify(accountId)};
  localStorage.setItem('fp', fp);
  localStorage.setItem('user_data', JSON.stringify({fp, token: '.', currentPage: 'default'}));
  return true;
})()`);
await command('Page.reload', {ignoreCache: true});
await waitFor(`document.readyState === 'complete' && typeof window.enablePushMessages === 'function'`);
await waitFor(`document.getElementById('connection-status')?.textContent === 'Connected'`, 30000);

const push = await evaluate(`(async () => {
  const registration = await navigator.serviceWorker.ready;
  let subscription = await registration.pushManager.getSubscription();
  if (!subscription) {
    await window.enablePushMessages();
    subscription = await registration.pushManager.getSubscription();
  } else {
    await _savePushSubscription(subscription);
  }
  return {permission: Notification.permission, subscribed: Boolean(subscription)};
})()`);
if (push.permission !== 'granted' || !push.subscribed) throw new Error('Subscription Web Push não ficou ativa');

// Keep Chrome alive after closing the only SpechPhone target.
await command('Target.createTarget', {url: 'about:blank'});
console.log(JSON.stringify({pageOpen: true, websocketConnected: true, pushSubscribed: true, accountId}));
await command('Page.close');
socket.close();
