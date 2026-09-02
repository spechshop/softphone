import dgram from 'node:dgram';

const sip = dgram.createSocket('udp4');
const rtp = dgram.createSocket('udp4');
await new Promise(resolve => sip.bind(5062, '127.0.0.1', resolve));
await new Promise(resolve => rtp.bind(0, '127.0.0.1', resolve));
const rtpPort = rtp.address().port;
const methods = [];
const sourcePorts = [];
let established = false;
let byeReceived = false;
let dtmfPackets = 0;

const parse = raw => {
  const text = raw.toString();
  const [head] = text.split(/\r?\n\r?\n/, 1);
  const lines = head.split(/\r?\n/);
  const headers = {};
  for (const line of lines.slice(1)) {
    const index = line.indexOf(':');
    if (index < 0) continue;
    const name = line.slice(0, index).trim().toLowerCase();
    (headers[name] ??= []).push(line.slice(index + 1).trim());
  }
  return {line: lines[0], method: lines[0].split(' ')[0].toUpperCase(), headers, text};
};
const response = (request, code, reason, extra = [], body = '') => {
  const to = request.headers.to[0] + (request.headers.to[0].includes(';tag=') ? '' : ';tag=local-provider');
  const headers = [
    ...request.headers.via.map(value => `Via: ${value}`),
    `From: ${request.headers.from[0]}`, `To: ${to}`,
    `Call-ID: ${request.headers['call-id'][0]}`, `CSeq: ${request.headers.cseq[0]}`,
    ...extra,
  ];
  if (body) headers.push('Content-Type: application/sdp');
  headers.push(`Content-Length: ${Buffer.byteLength(body)}`);
  return Buffer.from(`SIP/2.0 ${code} ${reason}\r\n${headers.join('\r\n')}\r\n\r\n${body}`);
};
const sendResponse = (request, peer, code, reason, extra = [], body = '') =>
  sip.send(response(request, code, reason, extra, body), peer.port, peer.address);

sip.on('message', (raw, peer) => {
  const request = parse(raw);
  methods.push(request.method);
  sourcePorts.push(peer.port);
  if (request.method === 'REGISTER') {
    if (!request.headers.authorization && !request.headers['proxy-authorization']) {
      sendResponse(request, peer, 401, 'Unauthorized', [
        'WWW-Authenticate: Digest realm="local.test", nonce="local-register", algorithm=MD5, qop="auth"'
      ]);
    } else {
      sendResponse(request, peer, 200, 'OK', [`Contact: ${request.headers.contact[0]};expires=1800`, 'Expires: 1800']);
    }
  } else if (request.method === 'INVITE') {
    if (!request.headers['proxy-authorization']) {
      sendResponse(request, peer, 407, 'Proxy Authentication Required', [
        'Proxy-Authenticate: Digest realm="local.test", nonce="local-invite", algorithm=MD5, qop="auth"'
      ]);
      return;
    }
    const body = `v=0\r\no=local 1 1 IN IP4 127.0.0.1\r\ns=local\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio ${rtpPort} RTP/AVP 8 101\r\na=rtpmap:8 PCMA/8000\r\na=rtpmap:101 telephone-event/8000\r\na=fmtp:101 0-15\r\n`;
    sendResponse(request, peer, 100, 'Trying');
    sendResponse(request, peer, 180, 'Ringing');
    sendResponse(request, peer, 183, 'Session Progress', [], body);
    sendResponse(request, peer, 200, 'OK', ['Contact: <sip:callee@127.0.0.1:5062>'], body);
    established = true;
  } else if (request.method === 'BYE') {
    byeReceived = true;
    sendResponse(request, peer, 200, 'OK');
  }
});
rtp.on('message', raw => {
  if (raw.length >= 12 && (raw[1] & 0x7f) === 101) dtmfPackets++;
});

const pages = await fetch('http://127.0.0.1:9223/json').then(response => response.json());
const page = pages.find(item => item.type === 'page');
if (!page) throw new Error('Página do Chrome não encontrada');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  ws.addEventListener('open', resolve, {once: true});
  ws.addEventListener('error', reject, {once: true});
});
let id = 0;
const pending = new Map();
ws.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (!message.id || !pending.has(message.id)) return;
  const handlers = pending.get(message.id);
  pending.delete(message.id);
  if (message.error) handlers.reject(new Error(message.error.message)); else handlers.resolve(message.result);
});
const command = (method, params = {}) => new Promise((resolve, reject) => {
  const commandId = ++id;
  pending.set(commandId, {resolve, reject});
  ws.send(JSON.stringify({id: commandId, method, params}));
});
const evaluate = async expression => {
  const result = await command('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
  return result.result.value;
};
const waitFor = async (expression, timeout = 15000) => {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    if (await evaluate(`Boolean(${expression})`)) return;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error(`Timeout esperando ${expression}`);
};

await evaluate(`(new UserManager()).updateUserData('isMicStop', true)`);
await command('Page.reload', {ignoreCache: true});
await waitFor(`document.readyState === 'complete' && typeof window.saveConfig === 'function'`);
await waitFor(`document.getElementById('connection-status')?.textContent === 'Connected'`);
const configured = await evaluate(`(async () => {
  const set = (id, value) => { const el = document.getElementById(id); el.value = value; el.dispatchEvent(new Event('input',{bubbles:true})); };
  set('sipServer','127.0.0.1:5062'); set('sipDomain','local.test'); set('sipUser','frontend'); set('sipPass','local-only-secret');
  await window.saveConfig();
  return document.getElementById('cfgState').className.includes('text-success');
})()`);
if (!configured) throw new Error('Configuração do provider local falhou');

const callInvocation = await evaluate(`(async () => {
  try { window.digits='1000'; await window.startCall(); return {ok:true}; }
  catch (error) { return {ok:false,error:String(error && error.message || error)}; }
})()`);
if (!callInvocation.ok) throw new Error(`startCall falhou: ${callInvocation.error}`);
await waitFor(`document.getElementById('activeCallBar') && document.getElementById('btnHangup').style.display !== 'none'`);
await new Promise(resolve => setTimeout(resolve, 1200));
const ui = await evaluate(`({
  connected: Boolean(document.getElementById('activeCallBar')),
  timer: document.getElementById('callTimer')?.textContent || document.querySelector('#activeCallBar .acb-timer')?.textContent || '',
  mutedBefore: window._acbMuted,
  muteOn: (window._acbToggleMute(), window._acbMuted),
  muteOff: (window._acbToggleMute(), window._acbMuted)
})`);
await evaluate(`window.pushKey('5'); true`);
const dtmfDeadline = Date.now() + 3000;
while (dtmfPackets === 0 && Date.now() < dtmfDeadline) await new Promise(resolve => setTimeout(resolve, 50));
await evaluate(`document.getElementById('btnHangup').click(); true`);
const byeDeadline = Date.now() + 3000;
while (!byeReceived && Date.now() < byeDeadline) await new Promise(resolve => setTimeout(resolve, 50));
await waitFor(`document.getElementById('btnCall').style.display !== 'none'`);

const result = {
  configured, established, uiConnected: ui.connected, timerAdvanced: ui.timer !== '' && ui.timer !== '00:00',
  muteToggled: ui.muteOn === true && ui.muteOff === false, dtmfPackets, byeReceived,
  sipSource4000: sourcePorts.length > 0 && sourcePorts.every(port => port === 4000),
  methods: [...new Set(methods.filter(method => ['REGISTER','INVITE','ACK','BYE'].includes(method)))],
};
console.log(JSON.stringify(result, null, 2));
ws.close(); sip.close(); rtp.close();
if (!configured || !established || !result.uiConnected || !result.timerAdvanced || !result.muteToggled
  || dtmfPackets === 0 || !byeReceived || !result.sipSource4000) process.exit(1);
