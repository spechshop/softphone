let socketGlobal;
let stops = {};

let ctx;
let sipStatsChart;
let waveSurfer;
window.waitTokens = {}

async function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function convertTimestampToDate(timestamp) {
    if (String(timestamp).length === 10) {
        timestamp *= 1000;
    }
    const date = new Date(timestamp);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0'); // Meses vão de 0-11
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    return `${day}/${month}/${year} ${hours}:${minutes}:${seconds}`;
}

const infoURI = () => {
    const uri = new URL(window.location.href);
    let isSecure = uri.protocol === 'https:';
    let host = uri.hostname;
    let port = uri.port;
    let path = uri.pathname;
    if (port === '') port = isSecure ? '443' : '80';
    return {
        secure: isSecure,
        host: host,
        port: port,
        path: path,
        webSocketURI: () => `${(isSecure ? 'wss' : 'ws')}://${host}:${port}${path}`
    }
}

const autoSocket = () => {
    const socket = new WebSocket(infoURI().webSocketURI());
    socketGlobal = socket;
    socket.onopen = () => onOpenSocket(socket);
    socket.onerror = (event) => {
        document.getElementById('connection-icon').className = 'fa-solid fa-plug-circle-xmark text-danger';
        document.getElementById('connection-status').innerText = 'Connection error';
        console.error('Erro no socket', event);
        socket.close();
    }
    socket.onmessage = async (event) => onMessageSocket(event, socket);
    socket.onclose = () => {
        socket.closed = true;
        console.error('Socket fechado');
        document.getElementById('connection-icon').className = 'fa-solid fa-plug-circle-xmark text-danger';
        document.getElementById('connection-status').innerText = 'Connection timed out';
        return setTimeout(autoSocket, 1000);
    }
}


const onOpenSocket = (socket) => {
    socket.closed = false;
    try {
        if (socket.readyState === WebSocket.OPEN) {
        document.getElementById('connection-icon').className = 'fa-solid fa-plug-circle-check text-success';
            document.getElementById('connection-status').innerText = 'Connected';
        }
    } catch (error) {
        console.error('Erro ao verificar status do socket', error);
        return;
    }

    template.displayLoading().then(r => {
        socket.send(JSON.stringify({
            type: 'connect',
            data: (new UserManager()).getUserData()
        }));
        document.getElementById('deviceId').innerText = (new UserManager()).getValue('fp');
    });

}

window.sendRecByToken = async (params, type) => {
    const id = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
    // checar status do socket
    if (socketGlobal.closed) {
        console.log('socket closed, trying to reconnect');
        return sleep(1000).then(() => sendRecByToken(params, type));
    }


    params['fp'] = user.getValue('fp');
    socketGlobal.send(JSON.stringify({
        id, type, data: params
    }));
    let wait = 30000;
    let time = new Date().getTime();
    let end = time + wait;
    while (new Date().getTime() < end) {
        await sleep(250);
        if (waitTokens[id]) {
            break;
        }
    }
    if (waitTokens[id]) {
        let backup = waitTokens[id];
        delete waitTokens[id];

        return backup.data;
    } else {
        return null;
    }
};

// ===== Call Timer =====
let callTimerInterval = null;
let callStartTime = null;

window.startCallTimer = function () {
    callStartTime = Date.now();
    const timerContainer = document.getElementById('callTimerContainer');
    const timerEl = document.getElementById('callTimer');

    if (timerContainer) {
        timerContainer.style.display = 'block';
    }

    callTimerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - callStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        if (timerEl) {
            timerEl.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }
    }, 1000);
}

window.stopCallTimer = function () {
    if (callTimerInterval) {
        clearInterval(callTimerInterval);
        callTimerInterval = null;
    }
    callStartTime = null;
    const timerContainer = document.getElementById('callTimerContainer');
    const timerEl = document.getElementById('callTimer');

    if (timerContainer) {
        timerContainer.style.display = 'none';
    }
    if (timerEl) {
        timerEl.textContent = '00:00';
    }
}

// ===== Inbound Call Engine =====

window.inboundCallState = {
    currentCallId: null,
    status: 'idle', // idle | incoming | accepting | active | ending | ended
    direction: null,
    from: null,
    to: null,
    codec: null,
    startedAt: null,
    acceptSent: false,
    rejectSent: false,
    hangupSent: false,
};

window.formatSipUri = function (uri) {
    if (!uri) return '';
    const m = uri.match(/sip:([^@>\s]+)/);
    return m ? m[1] : uri;
};

// Ringtone via WebAudio API with visual fallback
let _ringtoneCtx = null;
let _ringtoneGain = null;
let _ringtoneOsc = null;
let _ringtonePulse = null;

window.startRingtone = function () {
    stopRingtone();
    try {
        _ringtoneCtx = new (window.AudioContext || window.webkitAudioContext)();
        _ringtoneOsc = _ringtoneCtx.createOscillator();
        _ringtoneGain = _ringtoneCtx.createGain();
        _ringtoneOsc.type = 'sine';
        _ringtoneOsc.frequency.value = 440;
        _ringtoneGain.gain.value = 0;
        _ringtoneOsc.connect(_ringtoneGain);
        _ringtoneGain.connect(_ringtoneCtx.destination);
        _ringtoneOsc.start();
        let on = false;
        _ringtonePulse = setInterval(() => {
            if (!_ringtoneGain) return;
            on = !on;
            _ringtoneGain.gain.setValueAtTime(on ? 0.25 : 0, _ringtoneCtx.currentTime);
        }, 600);
    } catch (e) {
        console.warn('[CALL] ringtone audio bloqueado, usando visual', e);
    }
    _startRingtoneVisual();
};

window.stopRingtone = function () {
    if (_ringtonePulse) {
        clearInterval(_ringtonePulse);
        _ringtonePulse = null;
    }
    if (_ringtoneOsc) {
        try {
            _ringtoneOsc.stop();
        } catch (_) {
        }
        _ringtoneOsc = null;
    }
    if (_ringtoneCtx) {
        try {
            _ringtoneCtx.close();
        } catch (_) {
        }
        _ringtoneCtx = null;
    }
    _ringtoneGain = null;
    _stopRingtoneVisual();
};

function _startRingtoneVisual() {
    const el = document.getElementById('inboundCallCard');
    if (el) el.classList.add('ringing-pulse');
}

function _stopRingtoneVisual() {
    const el = document.getElementById('inboundCallCard');
    if (el) el.classList.remove('ringing-pulse');
}

// Inbound call timer (separate from outbound timer)
let _inboundTimerInterval = null;

function _startInboundTimer() {
    const start = Date.now();
    _inboundTimerInterval = setInterval(() => {
        const el = document.getElementById('inboundCallTimer');
        if (!el) return;
        const s = Math.floor((Date.now() - start) / 1000);
        el.textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }, 1000);
}

function _stopInboundTimer() {
    if (_inboundTimerInterval) {
        clearInterval(_inboundTimerInterval);
        _inboundTimerInterval = null;
    }
}

window.renderCallWidget = function () {
    if (document.getElementById('inboundCallCard')) return;
    const s = window.inboundCallState;
    const card = document.createElement('div');
    card.id = 'inboundCallCard';
    card.innerHTML = `
<style>
#inboundCallCard{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
width:min(420px,96vw);background:#12121e;border:1px solid rgba(255,255,255,.13);
border-radius:20px;padding:20px 20px 16px;z-index:9999;
box-shadow:0 8px 40px rgba(0,0,0,.7);color:#fff;font-family:inherit;}
#inboundCallBackdrop{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:9998;}
#inboundCallCard.ringing-pulse{animation:ibRingPulse 1.2s ease-in-out infinite;}
@keyframes ibRingPulse{0%,100%{box-shadow:0 8px 40px rgba(0,0,0,.7),0 0 0 0 rgba(34,197,94,.45);}
50%{box-shadow:0 8px 40px rgba(0,0,0,.7),0 0 0 14px rgba(34,197,94,0);}}
#inboundCallCard .ib-avatar{width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.08);
display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
#inboundCallCard .ib-name{font-size:19px;font-weight:700;letter-spacing:.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
#inboundCallCard .ib-sub{font-size:11px;opacity:.5;margin-top:2px;}
#inboundCallCard .ib-status{font-size:13px;opacity:.65;margin-top:10px;}
#inboundCallCard .ib-timer{font-size:13px;font-weight:700;font-family:'Courier New',monospace;color:#22c55e;margin-top:6px;display:none;}
#inboundCallCard .ib-btns{display:flex;gap:10px;margin-top:16px;}
#inboundCallCard .ib-btn{flex:1;padding:13px 8px;border:none;border-radius:12px;font-weight:700;
font-size:14px;cursor:pointer;transition:opacity .15s;}
#inboundCallCard .ib-btn:disabled{opacity:.45;cursor:not-allowed;}
#inboundCallCard .ib-btn-accept{background:#22c55e;color:#fff;}
#inboundCallCard .ib-btn-reject{background:#ef4444;color:#fff;}
#inboundCallCard .ib-btn-hangup{background:#ef4444;color:#fff;display:none;}
</style>
<div style="display:flex;align-items:center;gap:14px;margin-bottom:2px">
  <div class="ib-avatar"><i class="fa-solid fa-phone-volume"></i></div>
  <div style="min-width:0">
    <div class="ib-name" id="ib-from">${formatSipUri(s.from) || 'Desconhecido'}</div>
    <div class="ib-sub" id="ib-codec">${s.codec || ''}</div>
  </div>
</div>
<div class="ib-status" id="ib-status">Chamando...</div>
<div class="ib-timer" id="inboundCallTimer">00:00</div>
<div class="ib-btns">
  <button class="ib-btn ib-btn-accept" id="ib-btn-accept" onclick="acceptIncomingCall()">
    <i class="fa-solid fa-phone me-1"></i>Atender
  </button>
  <button class="ib-btn ib-btn-reject" id="ib-btn-reject" onclick="rejectIncomingCall()">
    <i class="fa-solid fa-phone-slash me-1"></i>Recusar
  </button>
  <button class="ib-btn ib-btn-hangup" id="ib-btn-hangup" onclick="hangupCurrentCall()">
    <i class="fa-solid fa-phone-slash me-1"></i>Desligar
  </button>
</div>`;
    const backdrop = document.createElement('div');
    backdrop.id = 'inboundCallBackdrop';
    document.body.appendChild(backdrop);
    document.body.appendChild(card);
};

window.closeCallWidget = function () {
    const el = document.getElementById('inboundCallCard');
    if (el) el.remove();
    const bd = document.getElementById('inboundCallBackdrop');
    if (bd) bd.remove();
};

window.setCallState = function (status, patch) {
    Object.assign(window.inboundCallState, {status}, patch || {});
    _updateCallWidgetUI();
};

function _updateCallWidgetUI() {
    const s = window.inboundCallState;
    const statusEl = document.getElementById('ib-status');
    const acceptBtn = document.getElementById('ib-btn-accept');
    const rejectBtn = document.getElementById('ib-btn-reject');
    const hangupBtn = document.getElementById('ib-btn-hangup');
    const timerEl = document.getElementById('inboundCallTimer');
    if (!statusEl) return;
    const labels = {
        incoming: 'Chamando...', accepting: 'Atendendo...', active: 'Chamada ativa',
        ending: 'Encerrando...', ended: 'Encerrado', failed: 'Falhou'
    };
    statusEl.textContent = labels[s.status] || s.status;
    if (s.status === 'active') {
        const card = document.getElementById('inboundCallCard');
        if (card) card.style.display = 'none';
        const bd = document.getElementById('inboundCallBackdrop');
        if (bd) bd.style.display = 'none';
        if (acceptBtn) acceptBtn.style.display = 'none';
        if (rejectBtn) rejectBtn.style.display = 'none';
        if (hangupBtn) hangupBtn.style.display = '';
        if (timerEl) timerEl.style.display = '';
    } else if (s.status === 'accepting') {
        if (acceptBtn) {
            acceptBtn.disabled = true;
        }
        if (rejectBtn) {
            rejectBtn.disabled = true;
        }
    } else if (s.status === 'incoming') {
        if (acceptBtn) {
            acceptBtn.style.display = '';
            acceptBtn.disabled = false;
        }
        if (rejectBtn) {
            rejectBtn.style.display = '';
            rejectBtn.disabled = false;
        }
        if (hangupBtn) hangupBtn.style.display = 'none';
        if (timerEl) timerEl.style.display = 'none';
    }
}

window.handleIncomingCall = function (data) {
    const callId = data.callId;
    if (window.inboundCallState.currentCallId === callId) {
        console.log('[CALL] chamada duplicada ignorada', callId);
        return;
    }
    const busy = window.inboundCallState.status !== 'idle' && window.inboundCallState.status !== 'ended';
    if (busy) {
        console.log('[CALL] ocupado, recusando automaticamente', callId);
        sendRecByToken({callId}, 'callReject');
        return;
    }
    console.log('[CALL] incomingCall recebido', callId);
    window.inboundCallState = {
        currentCallId: callId,
        status: 'incoming',
        direction: 'inbound',
        from: data.from,
        to: data.to,
        codec: data.codec,
        startedAt: null,
        acceptSent: false,
        rejectSent: false,
        hangupSent: false,
    };
    renderCallWidget();
    startRingtone();
};

window.handleCallActive = function () {
    if (window.inboundCallState.direction !== 'inbound') return;
    if (!['accepting', 'incoming'].includes(window.inboundCallState.status)) return;
    console.log('[CALL] chamada ativa');
    stopRingtone();
    setCallState('active', {startedAt: Date.now()});
    _startInboundTimer();
    playAudio(window.inboundCallState.currentCallId);
    if (typeof window.startAudioCapture === 'function') {
        window.startAudioCapture();
    }
};

window.handleCallEnded = function () {
    if (window.inboundCallState.status === 'idle') return;
    console.log('[CALL] chamada encerrada');
    stopRingtone();
    _stopInboundTimer();
    if (typeof window.stopAudio === 'function') window.stopAudio();
    setCallState('ended');
    setTimeout(() => {
        closeCallWidget();
        window.inboundCallState.status = 'idle';
        window.inboundCallState.currentCallId = null;
    }, 1200);
};

window.acceptIncomingCall = function () {
    const s = window.inboundCallState;
    if (s.status !== 'incoming') return;
    if (s.acceptSent) return;
    s.acceptSent = true;
    console.log('[CALL] enviando callAccept');
    stopRingtone();
    setCallState('accepting');
    sendRecByToken({callId: s.currentCallId}, 'callAccept');
};

window.rejectIncomingCall = function () {
    const s = window.inboundCallState;
    if (s.status !== 'incoming') return;
    if (s.rejectSent) return;
    s.rejectSent = true;
    console.log('[CALL] enviando callReject');
    stopRingtone();
    setCallState('ending');
    sendRecByToken({callId: s.currentCallId}, 'callReject').then(() => handleCallEnded());
};

window.hangupCurrentCall = function () {
    const s = window.inboundCallState;
    if (!['accepting', 'active'].includes(s.status)) return;
    if (s.hangupSent) return;
    s.hangupSent = true;
    console.log('[CALL] enviando callHangup');
    setCallState('ending');
    sendRecByToken({hangup: true, callId: s.currentCallId}, 'HangUpCall');
};

// ===== Real-time Chat Store =====

window.chatStore = {
    renderedIds: new Set(), // message id → already rendered
    unread: {},             // sipUser → pending unread count (not yet opened)
};

// Normalize SIP URI or plain username to lowercase username for comparison
function _normSip(s) {
    if (!s) return '';
    const m = String(s).match(/sip:([^@>\s]+)/i);
    return (m ? m[1] : String(s)).trim().toLowerCase();
}

window.handleMessageNew = function (message) {
    if (!message) return;
    const key = message.id || [message.from, message.to, message.timestamp, message.body].join('|');
    if (window.chatStore.renderedIds.has(key)) {
        console.log('[MESSAGE] duplicado ignorado', key);
        return;
    }
    window.chatStore.renderedIds.add(key);
    console.log('[MESSAGE] messageNew recebido', message);

    const partner = message.from;
    const partnerNorm = _normSip(partner);
    const chatNorm = _normSip(window.currentChatUser || '');

    console.log('[MESSAGE] partner:', partnerNorm, '| currentChatUser:', chatNorm, '| match:', chatNorm && chatNorm === partnerNorm);

    if (chatNorm && chatNorm === partnerNorm) {
        console.log('[MESSAGE] conversa aberta, renderizando direto');
        if (typeof window.appendMessageToChat === 'function') window.appendMessageToChat(message, true);
        if (typeof window.markAsRead === 'function') window.markAsRead([message.id]);
    } else {
        console.log('[MESSAGE] conversa fechada, incrementando unread');
        window.chatStore.unread[partner] = (window.chatStore.unread[partner] || 0) + 1;
        window._updateConvPreview(partner, message);
        window._updateMsgTabBadge();
    }
};

window._updateConvPreview = function (fromUser, msg) {
    const list = document.getElementById('convList');
    if (!list) return;
    const item = list.querySelector('[data-conv-user="' + CSS.escape(fromUser) + '"]');
    if (item) {
        const preview = item.querySelector('.conv-preview');
        if (preview) preview.textContent = msg.body;
        const wrap = item.querySelector('.conv-badge-wrap');
        if (wrap) {
            let badge = wrap.querySelector('.conv-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'badge bg-danger rounded-pill conv-badge';
                wrap.innerHTML = '';
                wrap.appendChild(badge);
            }
            badge.textContent = (parseInt(badge.textContent) || 0) + 1;
        }
        list.insertBefore(item, list.firstChild);
    } else {
        if (typeof window.loadConversations === 'function') window.loadConversations();
    }
};

window._updateMsgTabBadge = function () {
    const tabBtn = document.querySelector('[data-tab="messages"]');
    if (!tabBtn) return;
    let badge = tabBtn.querySelector('.msg-global-badge');
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'badge bg-danger rounded-pill msg-global-badge';
        badge.style.cssText = 'position:absolute;top:0;right:0;font-size:9px;padding:2px 5px;min-width:15px;line-height:1.2;pointer-events:none;';
        tabBtn.style.position = 'relative';
        tabBtn.appendChild(badge);
    }
    const total = Object.values(window.chatStore.unread).reduce((s, n) => s + n, 0);
    badge.textContent = total > 0 ? String(total) : '';
    badge.style.display = total > 0 ? '' : 'none';
};

// ===== WebSocket Message Handler =====

const onMessageSocket = (event, socket) => {
    const data = JSON.parse(event.data);
    const user = new UserManager();
    if (Object.keys(data).includes('byToken')) {
        window.waitTokens[data['byToken']] = {data: data.data};
    }

    switch (data.type) {
        case 'incomingCall':
            handleIncomingCall(data.data);
            break;

        case 'callActive':
        case 'callAnswered':
            handleCallActive();
            break;

        case 'callEnded':
            handleCallEnded();
            break;

        case 'event':
            if (data.data === 'bye') {
                // Outbound UI cleanup
                $('#btnHangup').css('display', 'none');
                $('#btnCall').css('display', '');
                if (typeof window.stopCallTimer === 'function') window.stopCallTimer();
                // Inbound call teardown
                handleCallEnded();
            }
            if (data.data === 'callAccept') {
                // Outbound call confirmed (inbound path uses callActive/ACK)
                if (window.inboundCallState.direction !== 'inbound') {
                    $('#btnHangup').css('display', '');
                    $('#btnCall').css('display', 'none');
                    if (typeof window.startCallTimer === 'function') window.startCallTimer();
                    playAudio(window.currentCallId);
                }
            }
            if (data.data === 'callActive') {
                handleCallActive();
            }
            break;

        case 'setPage':
            user.updateUserData('currentPage', data.page);
            template.setPage(data.page);
            break;

        case 'setKey':
            user.updateUserData(data.key, data.value);
            break;

        case 'notify':
            bootstrap.showToast({
                header: 'Notificação',
                body: data.data.message,
                toastClass: data.data.type,
                colorHeader: 'text-white',
            });
            sendRecByToken({}, 'register');
            break;

        case 'brand':
            document.getElementById('branded').innerText = data.data;
            break;

        case 'changeCallId':
            if (window.currentCallId === data.data) return;
            window.currentCallId = data.data;
            playAudio(window.currentCallId);
            break;

        case 'messageNew': {
            const _msg = (data.data && data.data.message) ? data.data.message : (data.data || null);
            handleMessageNew(_msg);
            // Also dispatch for any page-level listeners
            window.dispatchEvent(new CustomEvent('spechMessageNew', {detail: _msg}));
            break;
        }
    }
}

// WebSocket Audio Player
window.audioWS = null;
window.audioContext = null;
window.audioQueue = [];
window.nextStartTime = 0;
window.isFirstPacket = true;

// Define sampleRate com getter/setter para detectar mudanças
let _sampleRate = 48000;
Object.defineProperty(window, 'sampleRate', {
    get: function () {
        return _sampleRate;
    },
    set: function (value) {
        if (_sampleRate !== value) {
            _sampleRate = value;
            // Recria o AudioContext com o novo sample rate
            if (window.audioContext) {
                window.audioContext.close();
                window.audioContext = new (window.AudioContext || window.webkitAudioContext)({
                    sampleRate: value,
                    latencyHint: 'interactive',
                });

                // Recria o speakerGainNode
                if (window.speakerGainNode) {
                    const currentGain = window.speakerGainNode.gain.value;
                    window.speakerGainNode = window.audioContext.createGain();
                    window.speakerGainNode.gain.value = currentGain;
                    window.speakerGainNode.connect(window.audioContext.destination);
                }

                // Reset da fila de áudio
                window.audioQueue = [];
                window.nextStartTime = 0;

                console.log('🎵 AudioContext recriado com sample rate:', value);
            }
        }
    },
    configurable: true
});

window.playAudio = (callId) => {
    if (window.currentCallId === callId) {
        console.log('🎧 Audio já está sendo reproduzido');

    }

    // Fecha conexão anterior se existir
    if (window.audioWS) {
        window.audioWS.close();
        window.audioWS = null;
    }

    // Reset
    window.audioQueue = [];
    window.isFirstPacket = true;
    window.nextStartTime = 0;
    window.currentCallId = callId;


    // Inicializa AudioContext DENTRO da interação do usuário (playAudio é chamado após clicar em "Chamar")
    if (!window.audioContext) {
        window.audioContext = new (window.AudioContext || window.webkitAudioContext)({
            sampleRate: window.sampleRate || 48000,
            latencyHint: 'interactive',
        });
        console.log('🎵 AudioContext criado após interação do usuário, estado:', window.audioContext.state);

        // Listener global para resumir AudioContext em qualquer interação
        if (!window._audioContextResumeListenerAdded) {
            const resumeAudioContext = async () => {
                if (window.audioContext && window.audioContext.state === 'suspended') {
                    try {
                        await window.audioContext.resume();
                        console.log('✅ AudioContext resumido via interação do usuário');
                    } catch (e) {
                        console.warn('❌ Erro ao resumir AudioContext:', e);
                    }
                }
            };

            document.addEventListener('click', resumeAudioContext, {once: false});
            document.addEventListener('touchstart', resumeAudioContext, {once: false});
            window._audioContextResumeListenerAdded = true;
        }
    }

    // Resume AudioContext se necessário (após interação do usuário)
    if (window.audioContext.state === 'suspended') {
        window.audioContext.resume()
            .then(() => console.log('✅ AudioContext resumido'))
            .catch(e => console.warn('❌ Erro ao resumir AudioContext:', e));
    }

    if (!window.speakerGainNode) {
        window.speakerGainNode = window.audioContext.createGain();
        window.speakerGainNode.connect(window.audioContext.destination);
        // Tenta pegar o valor inicial do slider se ele existir na página, senão usa o localStorage
        const callVol = document.getElementById('callVol');
        if (callVol) {
            window.speakerGainNode.gain.value = callVol.value / 100;
        } else {
            const savedCallVol = (new UserManager()).getValue('callVol') || 100;
            window.speakerGainNode.gain.value = savedCallVol / 100;
        }
    }

    // Determina protocolo WebSocket
    const protocol = window.location.protocol === "https:" ? "wss:" : "ws:";
    const userFp = (new UserManager()).getValue('fp') || 'anon';
    const wsUrl = `${protocol}//${infoURI().host}:8888?fp=${callId}&ssrc=rx-${userFp}`;

    window.audioWS = new WebSocket(wsUrl);
    window.audioWS.binaryType = "arraybuffer";

    window.audioWS.onopen = () => {
        console.log('🎧 WebSocket Audio conectado:', callId);
    };

    window.audioWS.onmessage = (event) => {
        processAudioData(event.data);
    };

    window.audioWS.onerror = (error) => {
        console.error('❌ Erro WebSocket Audio:', error);
    };

    window.audioWS.onclose = () => {


    };
};
window.isSpeakerMuted = false;

window.stopAudio = async () => {
    console.log('🛑 stopAudio chamado');

    // WS
    if (window.audioWS) {
        try {
            //window.audioWS.close();
        } catch (_) {
        }
        window.audioWS = null;
    }

    // estado
    window.audioQueue = [];
    window.isFirstPacket = true;
    window.nextStartTime = 0;
    window.currentCallId = null;

    // para áudio ativo
    if (window.audioContext && window._activeSources) {
        const now = window.audioContext.currentTime;

        for (const src of window._activeSources) {
            try {
                src.stop(now);
            } catch (_) {
            }
        }
        window._activeSources.length = 0;
    }

    console.log('🔇 Áudio parado com sucesso');
};


window.isSpeakerMuted = false;

function processAudioData(arrayBuffer) {
    // Descarta pacotes se o speaker estiver mutado
    if (window.isSpeakerMuted) {
        return;
    }

    if (window.isFirstPacket) {
        // Inicializa o tempo de reprodução com um pequeno delay (10ms) para evitar glitches
        window.nextStartTime = window.audioContext.currentTime + 0.01;
        window.isFirstPacket = false;
        console.log('🎵 Primeiro pacote de áudio recebido, iniciando reprodução');

        if (arrayBuffer.byteLength === 0) return;
    }

    const pcmData = new Int16Array(arrayBuffer);

    // Atualiza medidor do speaker (chamada recebida)
    if (typeof window.updateCallMeter === 'function') {
        window.updateCallMeter(pcmData);
    }

    // Converte PCM16 para Float32
    const float32Data = new Float32Array(pcmData.length);
    for (let i = 0; i < pcmData.length; i++) {
        float32Data[i] = pcmData[i] / 32768.0;
    }

    // Cria AudioBuffer
    const audioBuffer = window.audioContext.createBuffer(1, float32Data.length, window.audioContext.sampleRate);
    audioBuffer.getChannelData(0).set(float32Data);

    window.audioQueue.push(audioBuffer);

    // Agenda reprodução
    scheduleAudioBuffer();
}


async function scheduleAudioBuffer() {
    if (window.audioQueue.length === 0) return;
    if (window.isSpeakerMuted) return;

    // Resume AudioContext se estiver suspenso (requisito do Chrome)
    if (window.audioContext.state === 'suspended') {
        console.log('🔊 Alto-falante ativo');
        try {
            await window.audioContext.resume();
            console.log('✅ AudioContext resumido com sucesso, estado:', window.audioContext.state);
        } catch (e) {
            console.error('❌ Erro ao resumir AudioContext:', e);
            return;
        }

        // Se ainda está suspenso após tentar resumir, não reproduz
        if (window.audioContext.state === 'suspended') {
            console.warn('⚠️ AudioContext ainda suspenso, aguardando interação do usuário');
            return;
        }
    }

    while (window.audioQueue.length > 0) {
        const buffer = window.audioQueue.shift();
        const source = window.audioContext.createBufferSource();
        source.buffer = buffer;

        if (window.speakerGainNode) {
            source.connect(window.speakerGainNode);
        } else {
            source.connect(window.audioContext.destination);
        }

        // Agenda para tocar no próximo slot disponível
        const scheduleTime = Math.max(window.audioContext.currentTime, window.nextStartTime);
        source.start(scheduleTime);

        // Atualiza próximo tempo disponível
        window.nextStartTime = scheduleTime + buffer.duration;

        // Mantém latência baixa (~500ms)
        if (window.nextStartTime - window.audioContext.currentTime > 0.5) {
            break;
        }
    }
}

class ProcessManager {
    constructor(callback) {
        this.processes = [];
        this.callback = callback;
        this.run();
    }

    run() {
        const originalSetInterval = setInterval;
        const processes = this.processes;
        window.setInterval = function (callback, delay) {
            const intervalID = originalSetInterval(callback, delay);
            processes.push(intervalID);
            return intervalID;
        };
        this.callback();
    }

    kill() {
        this.processes.forEach(id => clearInterval(id));
        this.processes = [];
    }
}

class UserManager {
    constructor(storageKey) {
        this.storageKey = storageKey || 'user_data';
        let fp = false;
        fp = localStorage.fp || false;
        if (!fp) fp = document.getElementById('fp')?.innerText;
        this.updateUserData('fp', fp)
    }

    setUserData(data) {
        if (typeof data === 'object') {
            localStorage.setItem(this.storageKey, JSON.stringify(data));
        } else {
            throw new Error('Data must be an object');
        }
    }

    getUserData() {
        const data = localStorage.getItem(this.storageKey);
        return data ? JSON.parse(data) : null;
    }

    getValue(key) {
        const data = this.getUserData();
        return data && key in data ? data[key] : null;
    }

    updateUserData(key, value) {
        const data = this.getUserData() || {};
        data[key] = value;
        this.setUserData(data);
    }

    removeUserDataField(key) {
        const data = this.getUserData();
        if (data && key in data) {
            delete data[key];
            this.setUserData(data);
        }
    }

    clearUserData() {
        localStorage.removeItem(this.storageKey);
    }

    logout() {
        template.setPage('login').then(() => {
            this.clearUserData();
        });
    }
}

class templateManager {
    async displayLoading() {
        let allToasts = document.querySelectorAll('div[role="alert"]');
        allToasts.forEach(toast => toast.remove());
        while (!document.getElementById('loadingPage')) await sleep(100);
        console.log('loading done');
        const loading = document.getElementById('loadingPage').cloneNode(true);
        loading.removeAttribute('id');
        loading.style.display = 'block';
        const rootElement = document.getElementById('root');
        const rootHeight = rootElement.offsetHeight;
        const center = (rootHeight / 2) - 50;
        loading.style.marginTop = `${center}px`;
        document.getElementById('root').innerHTML = '';
        document.getElementById('root').appendChild(loading);

        // se tiver algum modal aberto fecha depois de 1 segundo
        sleep(1000).then(() => {
            // remove o modal-backdrop
            try {
                document.getElementsByClassName('modal-backdrop')[0].remove();
            } catch (e) {
            }
            // tira a classe modal-open do body
            try {
                document.body.classList.remove('modal-open');
            } catch (e) {
            }
            // fecha o modal
            try {
                document.getElementsByClassName('modal')[0].style.display = 'none';
            } catch (e) {
            }

        });
    }

    async getPage(page) {
        const params = {
            'page': page, 'token': (new UserManager()).getUserData().token,
        }

        return await sendRecByToken(params, 'getPage').then(r => {
            return r;
        })
    }

    async setPage(pageName) {
        await this.displayLoading();
        this.clearStops();
        //  document.getElementById('top-bar').innerHTML = '';

        const scriptData = await this.getPage(pageName);
        const updatedScriptData = this.processScripts(scriptData);


        await this.displayPage(updatedScriptData);
        this.updateUrlAndUserData(pageName);


    }

    clearStops() {
        for (const key in stops) {
            stops[key].kill();
            delete stops[key];
        }
    }

    processScripts(data) {
        const temporaryElement = document.createElement('div');
        temporaryElement.innerHTML = data;
        const scripts = temporaryElement.getElementsByTagName('script');
        Array.from(scripts).forEach(script => {
            const idScript = new Date().getTime();
            const newScriptContent = `stops[${idScript}] = new ProcessManager(() => {${script.innerHTML}});`;
            const newScript = document.createElement('script');
            newScript.innerHTML = newScriptContent;
            try {
                temporaryElement.replaceChild(newScript, script);
            } catch (e) {
            }
        });

        return temporaryElement.innerHTML;
    }

    displayPage(scriptData) {
        $('#root').html(scriptData);
        try {
            document.getElementById('root').style.display = 'block';
        } catch (e) {
            return sleep(100).then(() => this.displayPage(scriptData));
        }
        document.getElementById('loadingPage').style.display = 'none';
    }

    updateUrlAndUserData(pageName) {
        new UserManager().updateUserData('currentPage', pageName);
        const uri = new URL(window.location.href);
        uri.pathname = pageName;
        window.history.pushState({}, '', uri.toString());
    }
}

const template = new templateManager();
const user = new UserManager();
autoSocket();



