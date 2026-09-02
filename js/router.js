let socketGlobal;
let logoutInProgress = false;
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
    if (logoutInProgress) return;

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
        if (logoutInProgress) return;
        return setTimeout(() => {
            if (!logoutInProgress) autoSocket();
        }, 1000);
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

    requestBrowserNotificationPermission();
    requestWakeLock();
    // defer so DOM is ready
    setTimeout(_updatePushBtnState, 500);

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

// ===== Browser Notifications & Background Keep-Alive =====

window._wakeLock = null;

window.requestBrowserNotificationPermission = async function () {
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'default') {
        window._notifPermission = Notification.permission;
        return;
    }
    const perm = await Notification.requestPermission();
    window._notifPermission = perm;
};

window.sendBrowserNotification = function (title, body, options) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    if (document.visibilityState === 'visible') return;
    const n = new Notification(title, Object.assign({
        body,
        icon: '/img/pyramid.png',
        badge: '/img/pyramid.png',
        tag: 'spechphone',
    }, options || {}));
    n.onclick = () => {
        window.focus();
        n.close();
    };
};

window.requestWakeLock = async function () {
    if (!('wakeLock' in navigator) || window._wakeLock) return;
    try {
        window._wakeLock = await navigator.wakeLock.request('screen');
        window._wakeLock.addEventListener('release', () => {
            window._wakeLock = null;
        });
        console.log('[WakeLock] ativo');
    } catch (e) {
        console.warn('[WakeLock] não disponível', e);
    }
};

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') window.requestWakeLock();
});

// ===== Web Push ============================================================

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

async function _updatePushBtnState() {
    const btn = document.getElementById('push-notif-btn');
    const icon = document.getElementById('push-notif-icon');
    if (!btn || !icon) return;

    if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
        btn.style.display = 'none';
        return;
    }

    if (Notification.permission === 'denied') {
        icon.className = 'fa-solid fa-bell-slash text-danger';
        btn.title = 'Notificações bloqueadas — libere nas configurações do navegador';
        return;
    }

    try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            icon.className = 'fa-solid fa-bell text-success';
            btn.title = 'Notificações ativas — clique para desativar';
        } else {
            icon.className = 'fa-solid fa-bell text-secondary';
            btn.title = 'Ativar notificações de mensagens';
        }
    } catch (_) {
        icon.className = 'fa-solid fa-bell text-secondary';
        btn.title = 'Ativar notificações de mensagens';
    }
}

async function _getPushSubscription(reg, vapidKey) {
    let sub = await reg.pushManager.getSubscription();
    if (sub) {
        console.log('[PUSH] Subscription reutilizada');
        return sub;
    }
    try {
        sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidKey),
        });
        console.log('[PUSH] Subscription criada');
        return sub;
    } catch (e) {
        console.warn('[PUSH] Falha ao criar subscription', e);
        return null;
    }
}

async function _savePushSubscription(sub) {
    const fp = (new UserManager()).getValue('fp');
    const result = await sendRecByToken({
        fp,
        subscription: sub.toJSON(),
    }, 'savePushSubscription');
    if (result?.success) {
        console.log('[PUSH] Subscription salva no backend');
    } else {
        console.warn('[PUSH] Falha ao salvar subscription', result);
    }
}

window.enablePushMessages = async function () {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        toast('Push não suportado neste navegador.', 'Push', 4000, 'error');
        return;
    }

    const reg = await navigator.serviceWorker.ready;
    const existing = await reg.pushManager.getSubscription();

    // --- DESATIVAR ---
    if (existing) {
        await existing.unsubscribe();
        const fp = (new UserManager()).getValue('fp');
        sendRecByToken({fp, endpoint: existing.endpoint}, 'removePushSubscription');
        await _updatePushBtnState();
        toast('Notificações de mensagens desativadas.', 'Push', 3000, 'info');
        return;
    }

    // --- ATIVAR ---
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') {
        if (perm === 'denied') toast('Notificações bloqueadas. Libere nas configurações do navegador.', 'Aviso', 5000, 'warning');
        await _updatePushBtnState();
        return;
    }

    const keyResp = await sendRecByToken({}, 'getPushPublicKey');
    const vapidKey = keyResp?.publicKey;
    if (!vapidKey) {
        toast('Servidor sem chave VAPID configurada.', 'Push', 4000, 'error');
        return;
    }

    const sub = await _getPushSubscription(reg, vapidKey);
    if (!sub) {
        toast('Falha ao criar subscription push.', 'Push', 4000, 'error');
        return;
    }

    await _savePushSubscription(sub);
    await _updatePushBtnState();
    toast('Notificações de mensagens ativadas!', 'Push', 3000, 'success');
};

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
    sendBrowserNotification(
        'Chamada recebida',
        formatSipUri(data.from) || 'Número desconhecido',
        {requireInteraction: true, tag: 'incoming-call-' + callId}
    );
    setTimeout(() => {
        let aaStatus = user.getValue('autoAcceptEnabled');
        if (aaStatus === true) acceptIncomingCall();
    }, 500);


};




window.handleCallActive = function () {
    console.trace('Invocado por:')
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
    renderActiveCallBar(formatSipUri(window.inboundCallState.from) || window.inboundCallState.from);
};

window.handleCallEnded = function (callId = null) {
    const s = window.inboundCallState;
    if (s.status === 'idle') return false;
    // A delayed duplicate from an older call must never tear down a newer one.
    if (callId && s.currentCallId && callId !== s.currentCallId) return false;
    console.log('[CALL] chamada encerrada');
    stopRingtone();
    _stopInboundTimer();
    if (typeof window.stopAudio === 'function') window.stopAudio();
    if (typeof window.stopAudioCapture === 'function') window.stopAudioCapture();
    setCallState('ended');
    closeActiveCallBar();
    closeCallWidget();
    Object.assign(s, {
        status: 'idle',
        currentCallId: null,
        direction: null,
        from: null,
        to: null,
        codec: null,
        startedAt: null,
        acceptSent: false,
        rejectSent: false,
        hangupSent: false,
    });
    return true;
};

window.acceptIncomingCall = async function () {
    const s = window.inboundCallState;
    if (s.status !== 'incoming') return;
    if (s.acceptSent) return;
    s.acceptSent = true;
    console.log('[CALL] enviando callAccept');
    stopRingtone();
    setCallState('accepting');
    let media = {
        opus: typeof UserManager !== 'undefined' ? (new UserManager()).getValue('opus') : null,
        sourceSampleRate: 8000,
        sourceChannels: 1
    };
    try {
        if (typeof window.prepareOpusForCall === 'function') media = await window.prepareOpusForCall();
    } catch (error) {
        console.warn('[CALL] falha ao preparar captura; atendendo com mono seguro', error);
    }
    return sendRecByToken({callId: s.currentCallId, ...media}, 'callAccept');
};

window.rejectIncomingCall = function () {
    const s = window.inboundCallState;
    if (s.status !== 'incoming') return;
    if (s.rejectSent) return;
    s.rejectSent = true;
    console.log('[CALL] enviando callReject');
    stopRingtone();
    setCallState('ending');
    return sendRecByToken({callId: s.currentCallId}, 'callReject').then(() => handleCallEnded());
};

window.hangupCurrentCall = function () {
    const s = window.inboundCallState;
    if (!['accepting', 'active'].includes(s.status)) return;
    if (s.hangupSent) return;
    const callId = s.currentCallId;
    s.hangupSent = true;
    console.log('[CALL] enviando callHangup');
    setCallState('ending');
    return sendRecByToken({hangup: true, callId}, 'HangUpCall').then((result) => {
        if (result?.success) handleCallEnded(callId);
        return result;
    }).catch((error) => {
        console.error('[CALL] erro ao encerrar chamada', error);
        throw error;
    });
};

// ===== Active Call Floating Bar =====

let _activeBarTimerId = null;
let _activeBarStartAt = null;
let _activeBarDragListeners = null;

window._hangupActiveCall = function () {
    const s = window.inboundCallState;
    if (s && ['accepting', 'active'].includes(s.status)) {
        return window.hangupCurrentCall();
    } else if (typeof window.hangUpCall === 'function') {
        return window.hangUpCall();
    }
};

window._acbMuted = false;

window._acbToggleMute = function () {
    window._acbMuted = !window._acbMuted;
    if (window._acbMuted) {
        if (typeof window.stopAudioCapture === 'function') window.stopAudioCapture();
    } else {
        if (typeof window.startAudioCapture === 'function') window.startAudioCapture();
    }
    const icon = document.getElementById('acb-mute-icon');
    if (icon) icon.className = window._acbMuted ? 'fa-solid fa-microphone-slash' : 'fa-solid fa-microphone';
    const btn = document.getElementById('acb-btn-mute');
    if (btn) btn.classList.toggle('acb-btn-active', window._acbMuted);
};

window._acbToggleSpeaker = function () {
    window.isSpeakerMuted = !window.isSpeakerMuted;
    if (window.speakerGainNode) {
        window.speakerGainNode.gain.value = window.isSpeakerMuted ? 0 : 1;
    }
    const icon = document.getElementById('acb-speaker-icon');
    if (icon) icon.className = window.isSpeakerMuted ? 'fa-solid fa-volume-slash' : 'fa-solid fa-volume-high';
    const btn = document.getElementById('acb-btn-speaker');
    if (btn) btn.classList.toggle('acb-btn-active', window.isSpeakerMuted);
};

window._acbToggleMinimize = function () {
    const bar = document.getElementById('activeCallBar');
    if (!bar || window.innerWidth > 768) return;
    const isMin = bar.classList.toggle('acb-minimized');
    const btn = document.getElementById('acb-btn-keypad');
    if (btn) btn.classList.toggle('acb-btn-keypad-active', isMin);
};

function _acbAvatarColor(name) {
    const palette = ['#8e44ad', '#2980b9', '#16a085', '#d35400', '#c0392b', '#2c3e50', '#1a6b4a', '#6c3483'];
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
    return palette[h % palette.length];
}

window.renderActiveCallBar = function (partner) {
    window.closeActiveCallBar();
    _activeBarStartAt = Date.now();
    partner = partner || 'Chamada ativa';

    const initials = partner.trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase() || '?';
    const avatarBg = _acbAvatarColor(partner);

    const bar = document.createElement('div');
    bar.id = 'activeCallBar';
    bar.className = 'bg-gradient-night';
    bar.innerHTML = `
        <div class="acb-header">
            <div class="acb-grip">
                <span></span><span></span><span></span>
                <span></span><span></span><span></span>
            </div>
            <span class="acb-status-badge">Em chamada</span>
            <span></span>
        </div>
        <div class="acb-body">
            <div class="acb-avatar" style="background:${avatarBg}">${initials}</div>
            <div class="acb-info">
                <div id="activeCallBar-name">${partner}</div>
                <div class="acb-sub">
                    <span id="activeCallBar-dot"></span>
                    <span id="activeCallBar-time">00:00</span>
                </div>
            </div>
            <button class="acb-hangup-round" onclick="event.stopPropagation();window._hangupActiveCall()" title="Desligar">
                <i class="fa-solid fa-phone-slash"></i>
            </button>
        </div>
        <div class="acb-mob-actions">
            <button id="acb-btn-mute" class="acb-btn-action" onclick="window._acbToggleMute()" title="Mutar microfone">
                <i id="acb-mute-icon" class="fa-solid fa-microphone"></i>
                <span>Mudo</span>
            </button>
            <button id="acb-btn-speaker" class="acb-btn-action" onclick="window._acbToggleSpeaker()" title="Alto-falante">
                <i id="acb-speaker-icon" class="fa-solid fa-volume-high"></i>
                <span>Volume</span>
            </button>
            <button id="acb-btn-keypad" class="acb-btn-action" onclick="window._acbToggleMinimize()" title="Mostrar teclado DTMF">
                <i class="fa-solid fa-grip"></i>
                <span>Teclado</span>
            </button>
        </div>
        <div class="acb-footer">
            <button class="acb-hangup-full" onclick="window._hangupActiveCall()">
                <i class="fa-solid fa-phone-slash"></i>
                <span class="acb-hangup-label"> Desligar</span>
            </button>
        </div>
    `;

    document.body.appendChild(bar);

    // Toque na barra minimizada re-expande (mobile)
    bar.addEventListener('click', (e) => {
        if (e.target.closest('button')) return;
        if (bar.classList.contains('acb-minimized')) {
            bar.classList.remove('acb-minimized');
            const btn = document.getElementById('acb-btn-keypad');
            if (btn) btn.classList.remove('acb-btn-keypad-active');
        }
    });

    // ── Drag (desktop only) ──
    let _drag = {on: false, sx: 0, sy: 0, ox: 0, oy: 0};
    const onMove = (e) => {
        if (!_drag.on) return;
        const cx = e.touches ? e.touches[0].clientX : e.clientX;
        const cy = e.touches ? e.touches[0].clientY : e.clientY;
        const nx = Math.max(0, Math.min(window.innerWidth - bar.offsetWidth, _drag.ox + cx - _drag.sx));
        const ny = Math.max(0, Math.min(window.innerHeight - bar.offsetHeight, _drag.oy + cy - _drag.sy));
        bar.style.left = nx + 'px';
        bar.style.top = ny + 'px';
        bar.style.bottom = 'auto';
    };
    const onUp = () => {
        _drag.on = false;
        bar.style.cursor = 'grab';
    };
    bar.addEventListener('mousedown', (e) => {
        if (e.target.closest('button')) return;
        if (window.innerWidth <= 768) return;
        const rect = bar.getBoundingClientRect();
        _drag = {on: true, sx: e.clientX, sy: e.clientY, ox: rect.left, oy: rect.top};
        bar.style.cursor = 'grabbing';
        e.preventDefault();
    });
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
    _activeBarDragListeners = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    };

    // ── Timer ──
    const timerEl = bar.querySelector('#activeCallBar-time');
    _activeBarTimerId = setInterval(() => {
        const e = Math.floor((Date.now() - _activeBarStartAt) / 1000);
        timerEl.textContent = `${String(Math.floor(e / 60)).padStart(2, '0')}:${String(e % 60).padStart(2, '0')}`;
    }, 1000);
};

window.closeActiveCallBar = function () {
    const bar = document.getElementById('activeCallBar');
    if (bar) bar.remove();
    if (_activeBarTimerId) {
        clearInterval(_activeBarTimerId);
        _activeBarTimerId = null;
    }
    if (_activeBarDragListeners) {
        _activeBarDragListeners();
        _activeBarDragListeners = null;
    }
    _activeBarStartAt = null;
    window._acbMuted = false;
};

// ===== Real-time Chat Store =====

window.chatStore = {
    renderedIds: new Set(), // accountId|messageId → already rendered
    unread: {},             // accountId|remoteUri → pending unread count
};

// Display helper only. Internal matching must use _chatKey(accountId, remoteUri).
function _normSip(s) {
    if (!s) return '';
    const m = String(s).match(/sip:([^@>\s]+)/i);
    return (m ? m[1] : String(s)).trim().toLowerCase();
}

function _sipIdentity(s) {
    const value = String(s || '').trim().replace(/[<>]/g, '');
    const match = value.match(/sip:([^@;\s]+)@([^;\s]+)/i) || value.match(/^([^@;\s]+)@([^;\s]+)$/);
    return match ? `sip:${match[1].toLowerCase()}@${match[2].toLowerCase()}` : value.toLowerCase();
}

function _chatKey(accountId, remoteUri) {
    return `${accountId || ''}|${_sipIdentity(remoteUri)}`;
}

window.handleMessageNew = function (message) {
    if (!message) return;
    const currentAccountId = (new UserManager()).getValue('fp') || '';
    if (!message.accountId || (currentAccountId && message.accountId !== currentAccountId)) {
        console.warn('[MESSAGE] evento descartado por accountId divergente');
        return;
    }
    const key = `${message.accountId}|${message.id || [message.fromUri, message.toUri, message.timestamp, message.body].join('|')}`;
    if (window.chatStore.renderedIds.has(key)) {
        console.log('[MESSAGE] duplicado ignorado', key);
        return;
    }
    window.chatStore.renderedIds.add(key);
    console.log('[MESSAGE] messageNew recebido', message);

    const partner = message.remoteUri;
    const conversationKey = _chatKey(message.accountId, partner);
    const currentKey = window.currentChatKey || '';

    console.log('[MESSAGE] conversationKey:', conversationKey, '| currentChatKey:', currentKey);

    if (currentKey && currentKey === conversationKey) {
        console.log('[MESSAGE] conversa aberta, renderizando direto');
        if (typeof window.appendMessageToChat === 'function') window.appendMessageToChat(message, true);
        if (message.direction === 'inbound' && typeof window.markAsRead === 'function') window.markAsRead([message.id]);
    } else if (message.direction === 'outbound') {
        // Sync previews in other tabs without turning sent messages into unread.
        if (typeof window.loadConversations === 'function') window.loadConversations();
    } else {
        console.log('[MESSAGE] conversa fechada, incrementando unread');
        window.chatStore.unread[conversationKey] = (window.chatStore.unread[conversationKey] || 0) + 1;
        window._updateConvPreview(conversationKey, message);
        window._updateMsgTabBadge();
        sendBrowserNotification(
            'Nova mensagem de ' + String(partner || '').replace(/^sip:/i, ''),
            message.body || '',
            {tag: 'msg-' + conversationKey}
        );
    }
};

window._updateConvPreview = function (conversationKey, msg) {
    const list = document.getElementById('convList');
    if (!list) return;
    const item = list.querySelector('[data-conv-key="' + CSS.escape(conversationKey) + '"]');
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
        case 'registrationState': {
            window.latestRegistrationState = data.data;
            const state = document.getElementById('cfgState');
            if (state) {
                state.className = data.data.success ? 'mini mt-3 text-success' : 'mini mt-3 text-danger';
                state.textContent = data.data.message || (data.data.success
                    ? 'Registro SIP confirmado.'
                    : 'Registro SIP não confirmado.');
            }
            break;
        }

        case 'incomingCall':
            handleIncomingCall(data.data);
            break;

        case 'callActive':
        case 'callAnswered':
            handleCallActive();
            break;

        case 'callEnded':
            handleCallEnded(data.data?.callId || null);
            break;

        case 'opusNegotiated':
            if (typeof window.setEffectiveOpusConfig === 'function') {
                window.setEffectiveOpusConfig(data.data || {});
            }
            break;

        case 'event':
            if (data.data === 'bye') {
                // Outbound UI cleanup
                $('#btnHangup').css('display', 'none');
                $('#btnCall').css('display', '');
                if (typeof window.stopCallTimer === 'function') window.stopCallTimer();
                closeActiveCallBar();
                // Inbound call teardown
                handleCallEnded();
            }
            if (data.data === 'callAccept') {
                // Outbound call confirmed (inbound path uses callActive/ACK).
                // O servidor pode reenviar 'callAccept' no reconnect a partir de
                // estado residual (coroutinesProcess) — só tratamos como outbound
                // atendido se houver de fato uma chamada outbound em curso,
                // identificada pela visibilidade do botão "Desligar".
                if (window.inboundCallState.direction === 'inbound') break;
                const _btnHangup = document.getElementById('btnHangup');
                const _outboundInProgress = _btnHangup && _btnHangup.style.display !== 'none';
                if (!_outboundInProgress) {
                    console.log('[CALL] callAccept ignorado: sem chamada outbound ativa (state replay)');
                    break;
                }
                $('#btnHangup').css('display', '');
                $('#btnCall').css('display', 'none');
                if (typeof window.startCallTimer === 'function') window.startCallTimer();
                playAudio(window.currentCallId);
                const _outPartner = (new UserManager()).getValue('lastDigits') || 'Chamada';
                renderActiveCallBar(_outPartner);
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
            if (data.key === 'opus' && typeof window.refreshOpusControls === 'function') {
                window.refreshOpusControls(data.value);
            }
            if (data.key === 'audio' && typeof window.refreshAudioControls === 'function') {
                window.refreshAudioControls(data.value);
            }
            if (data.key === 'trunkCodec' && typeof window.refreshOpusVisibility === 'function') {
                const select = document.getElementById('trunkCodec');
                if (select) select.value = data.value;
                window.refreshOpusVisibility();
            }
            break;

        case 'notify':
            bootstrap.showToast({
                header: 'Notificação',
                body: data.data.message,
                toastClass: data.data.type,
                colorHeader: 'text-white',
            });
            break;

        case 'brand':
            document.getElementById('branded').innerText = data.data;
            break;

        case 'changeCallId':
            if (window.currentCallId === data.data) return;
            window.currentCallId = data.data;
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
    if (!callId) {
        console.log('❌ Chamada inválida, não é possível reproduzir áudio');
        return;
    }
    if (window.currentCallId === callId) {
        if (window.audioWS) {
            console.log('🎧 Audio já está sendo reproduzido');
            return;
        }
    }

    // Fecha conexão anterior se existir
    if (window.audioWS) {
        window.audioWS.close();
        window.audioWS = null;
        stopAudio();
    }


    // Reset
    window.audioQueue = [];
    window.isFirstPacket = true;
    window.nextStartTime = 0;
    window.currentCallId = callId;
    console.log('✅ ✅ ✅ ✅ 📞 Chamada iniciada com ID:', callId);


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
    const sampleRate = window.sampleRate || 8000;
    const playbackChannels = typeof window.getEffectiveOpusConfig === 'function'
        && String((new UserManager()).getValue('trunkCodec') || '').toUpperCase().startsWith('OPUS/')
        ? window.getEffectiveOpusConfig().channels : 1;
    window.playbackChannels = playbackChannels;
    const wsUrl = `${protocol}//${infoURI().host}:8889?fp=${callId}&ssrc=rx-${userFp}&sampleRate=${sampleRate}&channels=${playbackChannels}`;

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
            window.audioWS.close();
        } catch (_) {
        }
        window.audioWS = null;
    }

    // estado
    window.audioQueue = [];
    window.isFirstPacket = true;
    window.nextStartTime = 0;


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
        //return;
    }

    if (window.isFirstPacket) {
        // Inicializa o tempo de reprodução com um pequeno delay (10ms) para evitar glitches
        window.nextStartTime = window.audioContext.currentTime + 0.01;
        window.isFirstPacket = false;
        console.log('🎵 Primeiro pacote de áudio recebido, iniciando reprodução');

        if (arrayBuffer.byteLength === 0) return;
    }

    const pcmData = new Int16Array(arrayBuffer);
    const channels = window.playbackChannels === 2 ? 2 : 1;

    // Atualiza medidor do speaker (chamada recebida)
    if (typeof window.updateCallMeter === 'function') {
        window.updateCallMeter(pcmData);
    }

    // Converte PCM16 para Float32
    const frames = Math.floor(pcmData.length / channels);
    const audioBuffer = window.audioContext.createBuffer(channels, frames, window.audioContext.sampleRate);
    for (let channel = 0; channel < channels; channel++) {
        const plane = audioBuffer.getChannelData(channel);
        for (let frame = 0; frame < frames; frame++) {
            plane[frame] = pcmData[frame * channels + channel] / 32768.0;
        }
    }

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

    // Válvula de escape: se já estamos muito à frente do currentTime, descarta
    // a fila e ressincroniza. Impede que jitter/suspend acumule latência permanente.
    const lookahead = window.nextStartTime - window.audioContext.currentTime;
    if (lookahead > 0.3) {
        console.warn(`⏩ Latência ${(lookahead * 1000).toFixed(0)}ms — ressincronizando`);
        if (window._activeSources) {
            for (const s of window._activeSources) {
                try { s.stop(); } catch (_) {}
            }
            window._activeSources.length = 0;
        }
        window.audioQueue = [];
        window.nextStartTime = window.audioContext.currentTime + 0.02;
        return;
    }

    window._activeSources = window._activeSources || [];

    while (window.audioQueue.length > 0) {
        const buffer = window.audioQueue.shift();
        const source = window.audioContext.createBufferSource();
        source.buffer = buffer;

        if (window.speakerGainNode) {
            source.connect(window.speakerGainNode);
        } else {
            source.connect(window.audioContext.destination);
        }

        window._activeSources.push(source);
        source.onended = () => {
            const i = window._activeSources.indexOf(source);
            if (i !== -1) window._activeSources.splice(i, 1);
        };

        // Agenda para tocar no próximo slot disponível
        const scheduleTime = Math.max(window.audioContext.currentTime, window.nextStartTime);
        source.start(scheduleTime);

        // Atualiza próximo tempo disponível
        window.nextStartTime = scheduleTime + buffer.duration;

        // Mantém latência baixa (~250ms)
        if (window.nextStartTime - window.audioContext.currentTime > 0.25) {
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

    async logout() {
        if (logoutInProgress) return;
        logoutInProgress = true;

        const fp = this.getValue('fp') || localStorage.getItem('fp');
        let subscription = null;

        try {
            if ('serviceWorker' in navigator && 'PushManager' in window) {
                const registration = await Promise.race([
                    navigator.serviceWorker.ready,
                    sleep(2000).then(() => null),
                ]);
                subscription = registration
                    ? await registration.pushManager.getSubscription()
                    : null;

                // Desvincula no backend enquanto o WebSocket ainda autentica este fp.
                if (subscription && fp && socketGlobal?.readyState === WebSocket.OPEN) {
                    const removeRequest = sendRecByToken({
                        fp,
                        endpoint: subscription.endpoint,
                    }, 'removePushSubscription').catch((error) => {
                        console.warn('[LOGOUT] Falha ao desvincular Push no backend', error);
                    });
                    await Promise.race([removeRequest, sleep(3000)]);
                }
            }
        } catch (error) {
            console.warn('[LOGOUT] Não foi possível consultar a assinatura Push', error);
        }

        try {
            if (subscription) await subscription.unsubscribe();
        } catch (error) {
            console.warn('[LOGOUT] Não foi possível remover a assinatura Push do navegador', error);
        }

        try {
            if (typeof window.stopAudioCapture === 'function') window.stopAudioCapture();
            if (typeof window.stopAudio === 'function') await window.stopAudio();
        } catch (error) {
            console.warn('[LOGOUT] Falha ao encerrar áudio local', error);
        }

        if (window.audioWS) {
            try {
                window.audioWS.close();
            } catch (_) {
            }
            window.audioWS = null;
        }

        if (socketGlobal && socketGlobal.readyState < WebSocket.CLOSING) {
            try {
                socketGlobal.close(1000, 'logout');
            } catch (_) {
            }
        }

        this.clearUserData();
        localStorage.removeItem('fp');
        localStorage.removeItem('spech_call_history');
        window.location.reload();
    }
}

window.logoutSpechPhone = async function () {
    if (logoutInProgress) return;
    if (!window.confirm('Deseja deslogar deste dispositivo? As notificações desta conta serão desativadas.')) return;

    const button = document.getElementById('btnLogout');
    if (button) {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Deslogando...';
    }

    await (new UserManager()).logout();
};

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
