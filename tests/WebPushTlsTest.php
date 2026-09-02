<?php

require __DIR__ . '/../plugins/Utils/helpers/WebPushHelper.php';

use helpers\utils\WebPushHelper;

$bundle = WebPushHelper::caBundlePath();
if ($bundle === null) throw new RuntimeException('bundle de CAs do sistema não localizado');
if (!is_file($bundle) || !is_readable($bundle)) throw new RuntimeException('bundle de CAs não pode ser lido: ' . $bundle);
$contents = file_get_contents($bundle);
if (!str_contains((string)$contents, 'BEGIN CERTIFICATE')) throw new RuntimeException('arquivo selecionado não contém certificados PEM');

echo "OK: Web Push usa bundle TLS confiável e legível.\n";
