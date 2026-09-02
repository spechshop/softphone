<?php

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/SipRegisterManager.php';
require __DIR__ . '/../plugins/Utils/helpers/SipTransactionManager.php';
require __DIR__ . '/../plugins/Utils/helpers/SipDialog.php';
require __DIR__ . '/../plugins/Utils/helpers/PhoneController.php';
require __DIR__ . '/../plugins/Utils/helpers/AccountIdentity.php';
require __DIR__ . '/../plugins/Utils/helpers/CallState.php';

use helpers\utils\CallState;
use helpers\utils\PhoneController;

CallState::init();
$rows = [
    ['fp' => 'device-a', 'sip_user' => 'shared', 'sip_server' => 'registrar.test', 'sip_domain' => 'one.test'],
    ['fp' => 'device-b', 'sip_user' => 'shared', 'sip_server' => 'registrar.test', 'sip_domain' => 'two.test'],
    ['fp' => 'device-c', 'sip_user' => 'unique', 'sip_server' => 'registrar.test', 'sip_domain' => 'one.test'],
];
foreach ($rows as $row) {
    $key = PhoneController::accountKey([
        'sipUser' => $row['sip_user'], 'sipServer' => $row['sip_server'], 'sipDomain' => $row['sip_domain'],
    ]);
    if (strlen($key) > 48) throw new RuntimeException('account key excede limite seguro da Swoole Table');
    CallState::$sipBindings->set($key, $row + [
        'contact_port' => 4000, 'registered_at' => time(), 'expires_at' => time() + 1800,
    ]);
}
if (CallState::findFpForInbound('shared', 'one.test') !== 'device-a') throw new RuntimeException('domínio one cruzou binding');
if (CallState::findFpForInbound('shared', 'two.test') !== 'device-b') throw new RuntimeException('domínio two cruzou binding');
if (CallState::findFpForInbound('shared', '') !== null) throw new RuntimeException('username sem domínio não pode selecionar conta');
if (CallState::findFpForInbound('unique', '') !== null) throw new RuntimeException('nem username único pode ser chave de roteamento');

$active = ['fp' => 'active-device', 'sip_user' => 'same-aor', 'sip_server' => 'same.test', 'sip_domain' => 'same.test',
    'contact_port' => 4000, 'registered_at' => time(), 'expires_at' => time() + 1800];
CallState::$sipBindings->set('active-binding-a', $active);
if (CallState::findRegisteredFpForInbound('same-aor', 'same.test') !== 'active-device') {
    throw new RuntimeException('binding registrado único não desambiguou vault duplicado');
}
CallState::$sipBindings->set('active-binding-b', array_replace($active, ['fp' => 'other-active-device']));
if (CallState::findRegisteredFpForInbound('same-aor', 'same.test') !== null) {
    throw new RuntimeException('dois bindings registrados não podem selecionar conta arbitrariamente');
}
CallState::$sipBindings->set('expired-binding', array_replace($active, [
    'fp' => 'expired-device', 'sip_user' => 'expired-user', 'expires_at' => time() - 1,
]));
if (CallState::findRegisteredFpForInbound('expired-user', 'same.test') !== null) {
    throw new RuntimeException('binding expirado foi usado como identidade');
}
$incomingBaseline = count(CallState::$incomingCalls);
CallState::$incomingCalls->set('pending-call', [
    'call_id' => 'pending-call', 'fp' => 'device-a', 'status' => 'pending_user',
    'created_at' => time(), 'updated_at' => time(),
]);
if (!CallState::hasActiveCallForFp('device-a')) throw new RuntimeException('pending_user não bloqueou chamada simultânea');
if (CallState::hasActiveCallForFp('device-a', 'pending-call')) throw new RuntimeException('a própria chamada pending_user bloqueou continuação');
if ((CallState::findIncomingCallByFp('device-a')['call_id'] ?? '') !== 'pending-call') throw new RuntimeException('reconnect não encontrou pending_user');
CallState::$incomingCalls->del('pending-call');
if (count(CallState::$incomingCalls) !== $incomingBaseline) throw new RuntimeException('cleanup não retornou incomingCalls ao baseline');
echo "OK: bindings separados por usuário, domínio e registrar; WebSocket não participa da identidade.\n";
