<?php
$envFile =  '.env';
if (!file_exists($envFile)) {
    if (file_exists('.env.example')) {
        copy('.env.example', $envFile);
    } else {
        file_put_contents($envFile, '');
        throw new Exception("Arquivo .env não encontrado e .env.example também não existe.");
    }
}
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            $value = trim($value, "'\"");
            putenv(trim($key) . '=' . $value);
        }
    }
}

$interface = json_decode(file_get_contents(__DIR__ . '/configInterface.json'), true);
$paths = $interface['autoload'];
$allowObservable = $interface['allowObservable'];
$nameFiles = [];
$cachePages = [];
foreach ($paths as $path) {
    $directory = new DirectoryIterator(__DIR__ . "/{$path}");
    foreach ($directory as $fileInfo) {
        $nameFile = $fileInfo->getFilename();
        if (strlen($nameFile) > 2) {
            $nameFiles[] = (strlen($path) > 1) ? __DIR__ . '/' . $path . "/" . $nameFile : $nameFile;
        }
    }
}
foreach ($nameFiles as $key => $file) is_file($file) && include_once $file;

$listRoutes = \plugins\Request\controller::listPages();
//foreach ($listRoutes as $listRoute) {
//    $e = explode('/', $listRoute);
//    $idKey = explode('.', $e[count($e) - 1])[0];
//  $cachePages[$idKey] = \plugins\Utils\cache\bufferPages::get($idKey, __DIR__);;
//}
