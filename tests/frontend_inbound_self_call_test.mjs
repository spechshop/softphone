import fs from 'node:fs';
import dgram from 'node:dgram';

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

const waitFor = async (expression, timeout = 15000) => {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    if (await evaluate(`Boolean(${expression})`)) return;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error(`Timeout esperando: ${expression}`);
};

// Keep the real call independent of headless-browser microphone permission.
await evaluate(`(new UserManager()).updateUserData('isMicStop', true)`);
await command('Page.reload', {ignoreCache: true});
await waitFor(`document.readyState === 'complete' && typeof window.startCall === 'function'`);
await waitFor(`document.getElementById('connection-status')?.textContent === 'Connected'`);
await command('Runtime.enable');
const outboundStart = await evaluate(`(async () => {
  window.autoAcceptEnabled = false;
  window.digits = ${JSON.stringify(env.SIP_USERNAME)};
  return await window.sendRecByToken({digits: window.digits, codec: 'PCMA/8000'}, 'StartCall');
})()`);
if (!outboundStart?.success) throw new Error(`StartCall não iniciou a autochamada real: ${JSON.stringify(outboundStart)}`);

// The UI permits one call per device, so the self-call's simultaneous inbound
// leg is correctly rejected as busy. Wait for outbound cleanup, then inject a
// fresh inbound INVITE to prove inbound-after-outbound in the browser.
await new Promise(resolve => setTimeout(resolve, 1200));
const caller = dgram.createSocket('udp4');
await new Promise(resolve => caller.bind(0, '127.0.0.1', resolve));
const callerPort = caller.address().port;
const callId = `frontend-after-outbound-${Date.now()}`;
const branch = `z9hG4bK-frontend-${Date.now()}`;
const baseHeaders = [
  `Via: SIP/2.0/UDP 127.0.0.1:${callerPort};branch=${branch};rport`,
  'From: <sip:frontend-test@spechshop.com>;tag=frontend-test',
  `<sip:${env.SIP_USERNAME}@spechshop.com>`,
  `Call-ID: ${callId}`,
  `Contact: <sip:frontend-test@127.0.0.1:${callerPort}>`,
  'Max-Forwards: 70'
];
const sdp = `v=0\r\no=frontend 1 1 IN IP4 127.0.0.1\r\ns=frontend\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio 50000 RTP/AVP 8 101\r\na=rtpmap:8 PCMA/8000\r\na=rtpmap:101 telephone-event/8000\r\n`;
const invite = `INVITE sip:${env.SIP_USERNAME}@127.0.0.1:4000 SIP/2.0\r\n`
  + [baseHeaders[0], baseHeaders[1], `To: ${baseHeaders[2]}`, ...baseHeaders.slice(3),
    'CSeq: 1 INVITE', 'Content-Type: application/sdp', `Content-Length: ${Buffer.byteLength(sdp)}`].join('\r\n')
  + `\r\n\r\n${sdp}`;
caller.send(invite, 4000, '127.0.0.1');

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
  await evaluate(`window.rejectIncomingCall()`);
}
await new Promise(resolve => setTimeout(resolve, 300));
caller.close();
console.log(JSON.stringify({outboundStarted: outboundStart.success, inboundAfterOutbound: inbound?.direction === 'inbound', state: inbound}, null, 2));
socket.close();
if (inbound?.direction !== 'inbound') process.exit(1);
