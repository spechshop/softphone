<?php
/**
 * Gera VAPID keys para Web Push.
 * Uso: php tools/generate_vapid.php
 *
 * Adicione as chaves geradas ao .env:
 *   SPECH_PUSH_PUBLIC_KEY='...'
 *   SPECH_PUSH_PRIVATE_KEY='...'
 *   SPECH_PUSH_SUBJECT='mailto:suporte@spechshop.com'
 */
require __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "# Adicione ao .env:\n";
echo "SPECH_PUSH_PUBLIC_KEY='" . $keys['publicKey'] . "'\n";
echo "SPECH_PUSH_PRIVATE_KEY='" . $keys['privateKey'] . "'\n";
echo "SPECH_PUSH_SUBJECT='mailto:suporte@spechshop.com'\n";
