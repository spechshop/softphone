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
echo "OK: bindings separados por usuário, domínio e registrar na porta compartilhada 4000.\n";
