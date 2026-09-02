import fs from 'node:fs';

const env = {};
for (const line of fs.readFileSync(new URL('../.env', import.meta.url), 'utf8').split(/\r?\n/)) {
  if (!line || line.trimStart().startsWith('#') || !line.includes('=')) continue;
  const split = line.indexOf('=');
  env[line.slice(0, split).trim()] = line.slice(split + 1).trim().replace(/^['"]|['"]$/g, '');
}
if (!env.SIP_HOST || !env.SIP_USERNAME || !env.SIP_PASSWORD) throw new Error('Credenciais SIP ausentes no .env');

const pages = await fetch('http://127.0.0.1:9223/json').then(r => r.json());
const page = pages.find(item => item.type === 'page');
if (!page) throw new Error('Página do Chrome não encontrada');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  ws.addEventListener('open', resolve, {once: true});
  ws.addEventListener('error', reject, {once: true});
});

let sequence = 0;
const pending = new Map();
ws.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (!message.id || !pending.has(message.id)) return;
  const {resolve, reject} = pending.get(message.id);
  pending.delete(message.id);
  if (message.error) reject(new Error(message.error.message));
  else resolve(message.result);
});

function command(method, params = {}) {
  const id = ++sequence;
  ws.send(JSON.stringify({id, method, params}));
  return new Promise((resolve, reject) => pending.set(id, {resolve, reject}));
}

async function evaluate(expression) {
  const result = await command('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
  return result.result.value;
}

async function waitFor(expression, timeout = 15000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    if (await evaluate(`Boolean(${expression})`)) return;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error(`Timeout esperando: ${expression}`);
}

await command('Runtime.enable');
await command('Page.enable');
await command('Page.navigate', {url: 'https://127.0.0.1:8443/'});
await waitFor(`document.readyState === 'complete' && typeof window.saveConfig === 'function' && document.getElementById('btnSaveConfig')`);
await waitFor(`document.getElementById('connection-status')?.textContent === 'Connected'`);

const credentials = JSON.stringify({host: env.SIP_HOST, user: env.SIP_USERNAME, password: env.SIP_PASSWORD});
const runSave = passwordExpression => evaluate(`(async () => {
  const credentials = ${credentials};
  const setValue = (id, value) => {
    const element = document.getElementById(id);
    element.value = value;
    element.dispatchEvent(new Event('input', {bubbles: true}));
    element.dispatchEvent(new Event('change', {bubbles: true}));
  };
  setValue('sipServer', credentials.host);
  setValue('sipUser', credentials.user);
  setValue('sipDomain', credentials.host);
  setValue('sipPass', ${passwordExpression});
  setValue('trunkCodec', 'PCMA/8000');
  const first = window.saveConfig();
  const second = window.saveConfig();
  const disabledDuringRequest = document.getElementById('btnSaveConfig').disabled;
  await Promise.all([first, second]);
  return {
    disabledDuringRequest,
    enabledAfterRequest: !document.getElementById('btnSaveConfig').disabled,
    state: document.getElementById('cfgState').textContent,
    stateClass: document.getElementById('cfgState').className,
    passwordType: document.getElementById('sipPass').type
  };
})()`);

const invalid = await runSave(`credentials.password + '-invalid-browser-test'`);
if (!invalid.disabledDuringRequest || !invalid.enabledAfterRequest || !invalid.stateClass.includes('text-danger')) {
  throw new Error('Estado inválido do frontend para credencial incorreta');
}

const valid = await runSave('credentials.password');
if (!valid.disabledDuringRequest || !valid.enabledAfterRequest || !valid.stateClass.includes('text-success')) {
  throw new Error('Estado inválido do frontend para credencial correta');
}

await command('Page.reload', {ignoreCache: true});
await waitFor(`document.readyState === 'complete' && typeof window.saveConfig === 'function' && document.getElementById('btnSaveConfig')`);
await waitFor(`document.getElementById('connection-status')?.textContent === 'Connected'`);
await new Promise(resolve => setTimeout(resolve, 1500));
const reload = await evaluate(`({
  serverPersisted: document.getElementById('sipServer').value === ${JSON.stringify(env.SIP_HOST)},
  userPersisted: document.getElementById('sipUser').value === ${JSON.stringify(env.SIP_USERNAME)},
  passwordStored: Boolean((new UserManager()).getValue('sipPass')),
  registrationState: document.getElementById('cfgState').textContent,
  registrationClass: document.getElementById('cfgState').className
})`);

const result = {
  invalid: {state: invalid.state, loadingLocked: invalid.disabledDuringRequest, buttonRecovered: invalid.enabledAfterRequest},
  valid: {state: valid.state, loadingLocked: valid.disabledDuringRequest, buttonRecovered: valid.enabledAfterRequest},
  passwordFieldType: valid.passwordType,
  reload
};
console.log(JSON.stringify(result, null, 2));
ws.close();
