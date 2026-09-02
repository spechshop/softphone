import fs from 'node:fs';
import vm from 'node:vm';

const router = fs.readFileSync(new URL('../js/router.js', import.meta.url), 'utf8');
const start = router.indexOf('function _normSip');
const end = router.indexOf('window.handleMessageNew', start);
if (start < 0 || end < 0) throw new Error('helpers de identidade do chat não encontrados');

const context = {};
vm.createContext(context);
vm.runInContext(router.slice(start, end), context);

const keyA = vm.runInContext(`_chatKey('fp-a', 'sip:joao@provedor-a.com')`, context);
const keyB = vm.runInContext(`_chatKey('fp-b', 'sip:joao@provedor-b.com')`, context);
const sameUserOtherDomain = vm.runInContext(`_chatKey('fp-a', 'sip:joao@provedor-b.com')`, context);
if (keyA !== 'fp-a|sip:joao@provedor-a.com') throw new Error('chave interna A incorreta');
if (keyA === keyB || keyA === sameUserOtherDomain) throw new Error('frontend colidiu conta ou domínio remoto');
if (vm.runInContext(`_normSip('sip:joao@provedor-a.com')`, context) !== 'joao') throw new Error('_normSip deixou de servir à exibição');

const page = fs.readFileSync(new URL('../plugins/Request/pages/default.html', import.meta.url), 'utf8');
for (const required of ['data-conv-key', 'window.currentChatKey', 'conv.remoteUri', "msg.direction === 'outbound'", 'messageIds: ids']) {
  if (!page.includes(required)) throw new Error(`fluxo frontend não usa ${required}`);
}
if (!router.includes('message.accountId !== currentAccountId')) throw new Error('messageNew não valida accountId');

console.log('OK: frontend usa accountId|remoteUri e distingue usernames iguais entre contas/domínios.');
