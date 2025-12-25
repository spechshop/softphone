let socketGlobal;
let stops = {};
let waitTokens = [];
let ctx;
let sipStatsChart;
let waveSurfer;

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
        console.error('Erro ao conectar ao servidor:', event);
        toast('Erro ao conectar ao servidor', 'Erro', 5000, 'danger');


    }
    socket.onmessage = (event) => onMessageSocket(event, socket);
    socket.onclose = () => {
        socket.closed = true;
        console.error('Socket fechado');

        return setTimeout(autoSocket, 1000);
    }
}


const onOpenSocket = (socket) => {
    socket.closed = false;
    template.displayLoading().then(r => {
        socket.send(JSON.stringify({
            type: 'connect',
            data: (new UserManager()).getUserData()
        }));
        sleep(1000).then(() => {
            sendRecByToken({token: (new UserManager).getValue('token')}, 'stats').then(r => null);
        });
    });

}

const sendRecByToken = async (command, type) => {
    const id = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
    // checar status do socket
    if (socketGlobal.closed) {
        return await sleep(1000);
    }


    socketGlobal.send(JSON.stringify({
        id,
        type,
        data: command
    }));
    let wait = 30000;
    let time = new Date().getTime();
    let end = time + wait;
    while (new Date().getTime() < end) {
        await sleep(100);
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
}

const onMessageSocket = (event, socket) => {
    const data = JSON.parse(event.data);
    const user = new UserManager();
    if (Object.keys(data).includes('byToken')) {
        waitTokens[data['byToken']] = {data: data.data}
    }

    if (data.type === 'setPage') {
        user.updateUserData('currentPage', data.page);
        template.setPage(data.page);
    } else if (data.type === 'setKey') {
        user.updateUserData(data.key, data.value);
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
        return await sendRecByToken({
            'page': page,
            'token': (new UserManager()).getUserData().token,
        }, 'getPage').then(r => {
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





(() => {
    const SEP = /[,;]+/g;

    function init(el) {
        if (!el || el.dataset.tagified) return;
        el.dataset.tagified = '1';

        // UI
        const box = document.createElement('div');
        box.className = 'tag-box';
        const placeholder = document.createElement('span');
        placeholder.className = 'tag-placeholder';
        placeholder.textContent = el.placeholder || 'Add More';
        const inner = document.createElement('input');
        inner.className = 'tag-input-inner';
        inner.type = 'text';

        el.style.display = 'none';
        el.parentNode.insertBefore(box, el);
        box.append(placeholder, inner);

        // estado
        let tags = (el.value || '').split(',').map(s => s.trim()).filter(Boolean);

        // descriptor nativo do 'value'
        const nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        let internalUpdate = false;

        function render() {
            // limpa chips
            box.querySelectorAll('.tag-chip').forEach(n => n.remove());
            // cria chips
            tags.forEach(t => {
                const chip = document.createElement('span');
                chip.className = 'tag-chip';
                // Securely render user-supplied tag value
                const tagText = document.createElement('span');
                tagText.textContent = t;
                const xSpan = document.createElement('span');
                xSpan.className = 'x';
                const xIcon = document.createElement('i');
                xIcon.className = 'fa-sharp fa-regular fa-circle-xmark x';
                xSpan.appendChild(xIcon);
                chip.append(tagText, xSpan);
                box.insertBefore(chip, inner);
            });

            // atualiza o input original SEM disparar o nosso setter
            internalUpdate = true;
            nativeValue.set.call(el, tags.join(','));
            internalUpdate = false;

            // placeholder
            placeholder.style.display = tags.length === 0 && !inner.value ? '' : 'none';
        }

        function syncFromValue() {
            // sincroniza do value externo -> estado interno
            tags = (nativeValue.get.call(el) || '')
                .split(',')
                .map(s => s.trim())
                .filter(Boolean);
            render();
        }

        // intercepta .value SÓ desta instância
        Object.defineProperty(el, 'value', {
            get() {
                return nativeValue.get.call(el);
            },
            set(v) {
                nativeValue.set.call(el, v);
                if (!internalUpdate) syncFromValue(); // evita loop
            }
        });

        // também sincroniza se alguém disparar change/input
        el.addEventListener('change', () => {
            if (!internalUpdate) syncFromValue();
        });
        el.addEventListener('input', () => {
            if (!internalUpdate) syncFromValue();
        });

        // helpers
        const addMany = (text) => {
            text.split(SEP).map(s => s.trim()).filter(Boolean).forEach(p => {
                if (!tags.includes(p)) tags.push(p);
            });
            render();
        };

        // eventos UI
        box.addEventListener('click', e => {
            if (e.target.classList.contains('x')) {
                const chip = e.target.closest('.tag-chip');
                const val = chip.firstChild.textContent;
                tags = tags.filter(t => t !== val);
                render();
            } else inner.focus();
        });

        inner.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
                e.preventDefault();
                if (inner.value.trim()) {
                    addMany(inner.value);
                    inner.value = '';
                }
            } else if (e.key === 'Backspace' && !inner.value && tags.length) {
                tags.pop();
                render();
            }
        });

        inner.addEventListener('input', () => {
            placeholder.style.display = tags.length === 0 && !inner.value ? '' : 'none';
            if (SEP.test(inner.value)) {
                addMany(inner.value);
                inner.value = '';
            }
            SEP.lastIndex = 0;
        });

        inner.addEventListener('paste', e => {
            const text = (e.clipboardData || window.clipboardData).getData('text');
            if (text) {
                e.preventDefault();
                addMany(text);
                inner.value = '';
            }
        });

        inner.addEventListener('blur', () => {
            if (inner.value.trim()) {
                addMany(inner.value);
                inner.value = '';
            }
        });

        render();
    }

    // inicia existentes
    document.querySelectorAll('input.tagify').forEach(init);

    // pega os que nascerem depois
    new MutationObserver(muts => {
        for (const m of muts) {
            m.addedNodes?.forEach(n => {
                if (n.nodeType !== 1) return;
                if (n.matches?.('input.tagify')) init(n);
                n.querySelectorAll?.('input.tagify').forEach(init);
            });
        }
    }).observe(document.documentElement, {childList: true, subtree: true});
})();
