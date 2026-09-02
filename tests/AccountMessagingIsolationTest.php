<?php

require __DIR__ . '/../plugins/Utils/helpers/AccountIdentity.php';
require __DIR__ . '/../plugins/Utils/helpers/WebPushHelper.php';
require __DIR__ . '/../plugins/Utils/messages/messageStore.php';
require __DIR__ . '/../plugins/Message/handlers/messageSend.php';

use helpers\utils\AccountIdentity;
use helpers\utils\WebPushHelper;
use plugins\Utils\messages\messageStore;
use handlers\messageSend;

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$accounts = [
    'fp-a' => AccountIdentity::fromData('fp-a', ['sipUser' => 'lotus', 'sipDomain' => 'provedor-a.com', 'sipServer' => 'reg-a.com:5060']),
    'fp-b' => AccountIdentity::fromData('fp-b', ['sipUser' => 'lotus', 'sipDomain' => 'provedor-b.com', 'sipServer' => 'reg-b.com:5060']),
    'fp-single' => AccountIdentity::fromData('fp-single', ['sipUser' => 'single', 'sipDomain' => 'single.test', 'sipServer' => 'single.test']),
];

expect(AccountIdentity::resolve('lotus', 'provedor-a.com', '', $accounts)['accountId'] === 'fp-a', 'MESSAGE/INVITE do domínio A cruzou conta');
expect(AccountIdentity::resolve('lotus', 'provedor-b.com', '', $accounts)['accountId'] === 'fp-b', 'MESSAGE/INVITE do domínio B cruzou conta');
expect(AccountIdentity::resolve('lotus', '', '', $accounts)['status'] === 'ambiguous', 'destino sem domínio duplicado não foi rejeitado');
expect(AccountIdentity::resolve('single', '', '', $accounts)['accountId'] === null, 'identidade incompleta roteou só por sipUser');
expect(AccountIdentity::resolve('single', 'single.test', '', $accounts)['accountId'] === 'fp-single', 'regressão de conta única');
$sameDomainAccounts = [
    'fp-reg-a' => AccountIdentity::fromData('fp-reg-a', ['sipUser' => 'lotus', 'sipDomain' => 'shared.test', 'sipServer' => 'reg-a.test']),
    'fp-reg-b' => AccountIdentity::fromData('fp-reg-b', ['sipUser' => 'lotus', 'sipDomain' => 'shared.test', 'sipServer' => 'reg-b.test']),
];
expect(AccountIdentity::resolve('lotus', 'shared.test', 'reg-a.test', $sameDomainAccounts)['accountId'] === 'fp-reg-a', 'registrar A não desambiguou domínio compartilhado');
expect(AccountIdentity::resolve('lotus', '', 'reg-b.test', $sameDomainAccounts)['accountId'] === 'fp-reg-b', 'registrar B não resolveu domínio ausente');
expect(AccountIdentity::parseSipIdentity('sip:joao@remote.test')['uri'] === 'sip:joao@remote.test', 'URI outbound perdeu domínio');
$sipModel = messageSend::buildSipModel('10.0.0.1', 4000, 'lotus', 'provedor-a.com', 'sip:joao@destino-remoto.com', 'teste', 'call@test');
expect($sipModel['methodForParser'] === 'MESSAGE sip:joao@destino-remoto.com SIP/2.0', 'Request-URI outbound perdeu domínio remoto');
expect($sipModel['headers']['To'][0] === '<sip:joao@destino-remoto.com>', 'To outbound perdeu domínio remoto');
expect(str_starts_with($sipModel['headers']['From'][0], '<sip:lotus@provedor-a.com>'), 'From outbound perdeu conta de origem');

$storeFile = tempnam(sys_get_temp_dir(), 'spech-msg-test-');
messageStore::setFile($storeFile);

$inA = messageStore::saveMessage('fp-a', 'sip:lotus@provedor-a.com', 'sip:joao@provedor-a.com', 'in-a', 'inbound');
$inB = messageStore::saveMessage('fp-b', 'sip:lotus@provedor-b.com', 'sip:joao@provedor-b.com', 'in-b', 'inbound');
$outA = messageStore::saveMessage('fp-a', 'sip:lotus@provedor-a.com', 'sip:joao@provedor-a.com', 'out-a', 'outbound');
$sameUserOtherDomain = messageStore::saveMessage('fp-a', 'sip:lotus@provedor-a.com', 'sip:joao@other.example', 'other-domain', 'inbound');

expect($inA['accountId'] === 'fp-a' && $inA['fromUri'] === 'sip:joao@provedor-a.com', 'contexto inbound A incorreto');
expect($outA['toUri'] === 'sip:joao@provedor-a.com' && $outA['read'] === true, 'contexto outbound incorreto');
expect($inA['conversationId'] !== $inB['conversationId'], 'conversationId colidiu entre contas');
expect($inA['conversationId'] !== $sameUserOtherDomain['conversationId'], 'contatos iguais em domínios distintos colidiram');
expect(count(messageStore::listConversations('fp-a')) === 2, 'lista A não isolou identidades remotas');
expect(count(messageStore::listConversations('fp-b')) === 1, 'lista B vazou conversa A');
expect(count(messageStore::getHistory('fp-a', 'sip:joao@provedor-a.com')['messages']) === 2, 'histórico A incorreto');
expect(count(messageStore::getHistory('fp-b', 'sip:joao@provedor-a.com')['messages']) === 0, 'histórico cruzou accountId');

messageStore::markAsRead('fp-b', 'sip:joao@provedor-a.com', [$inA['id']]);
expect(messageStore::listConversations('fp-a')[0]['unread'] + messageStore::listConversations('fp-a')[1]['unread'] === 2, 'markAsRead cruzado alterou conta A');
messageStore::markAsRead('fp-a', 'sip:joao@provedor-a.com', [$inA['id']]);
$unreadA = array_sum(array_column(messageStore::listConversations('fp-a'), 'unread'));
expect($unreadA === 1, 'unread A não foi marcado isoladamente');

$connections = ['fp-a' => [10, 11, 12], 'fp-b' => [20, 21]];
expect(messageStore::connectionFdsForAccount($connections, 'fp-a') === [10, 11, 12], 'múltiplas abas A incorretas');
expect(messageStore::connectionFdsForAccount($connections, 'fp-b') === [20, 21], 'WebSocket cruzou contas');

// Stress: 20 accounts and hundreds of interleaved messages.
for ($round = 0; $round < 15; $round++) {
    for ($i = 0; $i < 20; $i++) {
        messageStore::saveMessage("stress-fp-{$i}", "sip:local{$i}@provider{$i}.test", "sip:remote@domain{$i}.test", "m-{$round}-{$i}", 'inbound');
    }
}
for ($i = 0; $i < 20; $i++) {
    expect(count(messageStore::getHistory("stress-fp-{$i}", "sip:remote@domain{$i}.test", 1000)['messages']) === 15, "stress vazou/perdeu mensagens da conta {$i}");
}

$sub = static fn(string $endpoint, string $fp = ''): array => ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'public', 'auth' => 'auth'], 'fp' => $fp];
$legacy = [
    'lotus' => ['a' => $sub('https://push/a', 'fp-a'), 'unknown' => $sub('https://push/unknown')],
    'single' => ['single' => $sub('https://push/single')],
];
$migrated = WebPushHelper::migrateLegacyData($legacy, $accounts);
expect(isset($migrated['fp-a']['a']), 'subscription com fp não migrou');
expect(isset($migrated['fp-single']['single']), 'subscription de username único não migrou');
expect(isset($migrated['_legacy_unresolved']['lotus']['unknown']), 'subscription duplicada sem fp foi associada arbitrariamente');
expect(!isset($migrated['fp-b']['a']), 'subscription A vazou para B');
$pruned = WebPushHelper::pruneExpiredData(['fp-a' => ['dead' => $sub('x'), 'live' => $sub('y')], 'fp-b' => ['safe' => $sub('z')]], 'fp-a', ['dead']);
expect(!isset($pruned['fp-a']['dead']) && isset($pruned['fp-a']['live']) && isset($pruned['fp-b']['safe']), 'remoção expirada afetou outra conta');

$legacyStore = [
    'schemaVersion' => 1,
    'conversations' => ['old' => ['id' => 'old', 'participants' => ['single', 'maria'], 'updatedAt' => 1]],
    'messages' => ['old' => [['id' => 'old-1', 'from' => 'maria', 'to' => 'single', 'body' => 'legacy', 'timestamp' => 1, 'read' => false]]],
];
$legacyMigrated = messageStore::migrateLegacyData($legacyStore, $accounts);
expect(count($legacyMigrated['conversations']) === 1 && !isset($legacyMigrated['conversations']['old']), 'histórico único legado não migrou');
$ambiguousStore = $legacyStore;
$ambiguousStore['conversations']['old']['participants'] = ['lotus', 'maria'];
$ambiguousMigrated = messageStore::migrateLegacyData($ambiguousStore, $accounts);
expect(isset($ambiguousMigrated['legacyUnresolved']['conversations']['old']), 'histórico legado ambíguo não foi isolado');

@unlink($storeFile);
@unlink($storeFile . '.lock');
echo "OK: accountId isolou inbound/outbound, WS multiaba, push, histórico, unread, migração e stress.\n";
