import fs from 'node:fs';

const env = Object.fromEntries(fs.readFileSync(new URL('../.env', import.meta.url), 'utf8')
  .split(/\r?\n/)
  .filter(line => line && !line.trimStart().startsWith('#') && line.includes('='))
  .map(line => {
    const index = line.indexOf('=');
    return [line.slice(0, index).trim(), line.slice(index + 1).trim().replace(/^['"]|['"]$/g, '')];
  }));

const pages = await fetch('http://127.0.0.1:9223/json').then(response => response.json());
const page = pages.find(item => item.type === 'page');
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
  pending.get(message.id)(message.result);
  pending.delete(message.id);
});
const command = (method, params = {}) => new Promise(resolve => {
  const commandId = ++id;
  pending.set(commandId, resolve);
  socket.send(JSON.stringify({id: commandId, method, params}));
});
const evaluate = async expression => {
  const result = await command('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
  return result.result.value;
};

await command('Runtime.enable');
await evaluate(`(() => {
  window.autoAcceptEnabled = false;
  window.digits = ${JSON.stringify(env.SIP_USERNAME)};
  window.startCall();
  return true;
})()`);

const deadline = Date.now() + 20000;
let inbound = null;
while (Date.now() < deadline) {
  inbound = await evaluate(`window.inboundCallState ? ({
    direction: window.inboundCallState.direction,
    status: window.inboundCallState.status,
    callIdPresent: Boolean(window.inboundCallState.currentCallId)
  }) : null`);
  if (inbound?.direction === 'inbound' && inbound?.status === 'incoming') break;
  await new Promise(resolve => setTimeout(resolve, 200));
}

if (inbound?.direction === 'inbound') {
  await evaluate(`typeof window.rejectIncomingCall === 'function'
    ? window.rejectIncomingCall()
    : (typeof window.hangUpCall === 'function' ? window.hangUpCall() : null)`);
}
console.log(JSON.stringify({inboundReceived: inbound?.direction === 'inbound', state: inbound}, null, 2));
socket.close();
if (inbound?.direction !== 'inbound') process.exit(1);
