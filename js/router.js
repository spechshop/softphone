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

const onMessageSocket = (event, socket) => {
    const data = JSON.parse(event.data);
    const user = new UserManager();
    if (Object.keys(data).includes('byToken')) {
        window.waitTokens[data['byToken']] = {data: data.data}
    }


    if (data.type === 'event') {
        if (data.data === 'bye') {
            $('#btnHangup').css('display', 'none');
            $('#btnCall').css('display', '');
            if (typeof window.stopCallTimer === 'function') {
                window.stopCallTimer();
            }
        }
        if (data.data === 'callAccept') {
            $('#btnHangup').css('display', '');
            $('#btnCall').css('display', 'none');


            if (typeof window.startCallTimer === 'function') window.startCallTimer();
            playAudio(window.currentCallId);
        }
    }

    if (data.type === 'setPage') {
        user.updateUserData('currentPage', data.page);
        template.setPage(data.page);
    } else if (data.type === 'setKey') {
        user.updateUserData(data.key, data.value);
    } else if (data.type === 'notify') {
        bootstrap.showToast({
            header: 'Notificação',
            body: data.data.message,
            toastClass: data.data.type,
            colorHeader: 'text-white',
        });
        sendRecByToken({}, 'register');


    } else if (data.type === 'brand') {
        document.getElementById('branded').innerText = data.data;
    } else if (data.type === 'changeCallId') {
        if (window.currentCallId === data.data) return;
        window.currentCallId = data.data;
        playAudio(window.currentCallId)
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
    const wsUrl = `${protocol}//${infoURI().host}:8888?fp=${callId}`;

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

        document.getElementById('btnSpeaker').click();
        document.getElementById('btnSpeaker').click();


        // Se ainda está suspenso após tentar resumir, não reproduz
        if (window.audioContext.state === 'suspended') {
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



