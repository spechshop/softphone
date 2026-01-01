<?php

/**
 * Script de Testes - LIPC Project
 *
 * Este script executa validações no projeto:
 * - Verificação de sintaxe PHP
 * - Análise estática com PHPStan (se disponível)
 * - Validação de arquivos de configuração JSON
 * - Verificação de autoload dos plugins
 */

declare(strict_types=1);

// Cores para output no terminal
const COLOR_RED = "\033[31m";
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_BLUE = "\033[34m";
const COLOR_RESET = "\033[0m";

$projectRoot = __DIR__;
$testsRun = 0;
$testsPassed = 0;
$testsFailed = 0;
$errors = [];
$fixes = [];

function printHeader(string $title): void
{
    echo "\n" . COLOR_BLUE . str_repeat("=", 60) . COLOR_RESET . "\n";
    echo COLOR_BLUE . " $title" . COLOR_RESET . "\n";
    echo COLOR_BLUE . str_repeat("=", 60) . COLOR_RESET . "\n\n";
}

function printSuccess(string $message): void
{
    echo COLOR_GREEN . "  ✓ " . COLOR_RESET . "$message\n";
}

function printError(string $message): void
{
    echo COLOR_RED . "  ✗ " . COLOR_RESET . "$message\n";
}

function printWarning(string $message): void
{
    echo COLOR_YELLOW . "  ⚠ " . COLOR_RESET . "$message\n";
}

function printInfo(string $message): void
{
    echo "  ℹ $message\n";
}

/**
 * Obtém todos os arquivos PHP do projeto (excluindo vendor e node_modules)
 */
function getPhpFiles(string $directory): array
{
    $files = [];
    $excludeDirs = ['vendor', 'node_modules', 'backup', 'stubs', 'libspech'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($file, $key, $iterator) use ($excludeDirs) {
                if ($iterator->hasChildren()) {
                    return !in_array($file->getFilename(), $excludeDirs);
                }
                return $file->isFile() && $file->getExtension() === 'php';
            }
        )
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

// ============================================================
// TESTE 1: Verificação de Sintaxe PHP
// ============================================================
printHeader("1. Verificação de Sintaxe PHP");

$phpFiles = getPhpFiles($projectRoot);
$syntaxErrors = [];

foreach ($phpFiles as $file) {
    $testsRun++;
    $output = shell_exec("php -l " . escapeshellarg($file) . " 2>&1");

    if (strpos($output, 'No syntax errors') !== false) {
        $testsPassed++;
    } else {
        $testsFailed++;
        $syntaxErrors[] = [
            'file' => $file,
            'error' => $output
        ];
        $fixes[] = [
            'type' => 'syntax_error',
            'file' => str_replace($projectRoot . '/', '', $file),
            'description' => 'Erro de sintaxe PHP',
            'commands' => [
                "php -l " . escapeshellarg($file) . " # Verificar erro específico",
                "# Corrija o erro de sintaxe manualmente no arquivo"
            ]
        ];
    }
}

if (empty($syntaxErrors)) {
    printSuccess("Todos os " . count($phpFiles) . " arquivos PHP passaram na verificação de sintaxe");
} else {
    printError("Erros de sintaxe encontrados em " . count($syntaxErrors) . " arquivo(s):");
    foreach ($syntaxErrors as $error) {
        $relPath = str_replace($projectRoot . '/', '', $error['file']);
        printError("  - $relPath: " . $error['error']);
        $errors[] = $error;
    }
}

// ============================================================
// TESTE 2: Validação de Arquivos JSON
// ============================================================
printHeader("2. Validação de Arquivos JSON de Configuração");

$jsonFiles = [

    'plugins/configInterface.json',
];

foreach ($jsonFiles as $jsonFile) {
    $testsRun++;
    $fullPath = $projectRoot . '/' . $jsonFile;

    if (!file_exists($fullPath)) {
        printWarning("Arquivo não encontrado: $jsonFile");
        continue;
    }

    $content = file_get_contents($fullPath);
    $decoded = json_decode($content);

    if (json_last_error() === JSON_ERROR_NONE) {
        $testsPassed++;
        printSuccess("$jsonFile - JSON válido");
    } else {
        $testsFailed++;
        $errorMsg = json_last_error_msg();
        printError("$jsonFile - Erro: $errorMsg");
        $errors[] = ['file' => $jsonFile, 'error' => $errorMsg];
        $fixes[] = [
            'type' => 'json_validation',
            'file' => $jsonFile,
            'description' => 'JSON inválido: ' . $errorMsg,
            'commands' => [
                "cat $jsonFile | python3 -m json.tool # Validar JSON",
                "# Corrija o JSON manualmente ou use um formatador online"
            ]
        ];
    }
}

// ============================================================
// TESTE 3: Verificação de Diretórios de Plugins
// ============================================================
printHeader("3. Verificação de Estrutura de Plugins");

$configPath = $projectRoot . '/plugins/configInterface.json';
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
    $autoloadPaths = $config['autoload'] ?? [];

    foreach ($autoloadPaths as $path) {
        $testsRun++;
        $fullPath = $projectRoot . '/plugins/' . $path;

        if (is_dir($fullPath)) {
            $testsPassed++;
            $fileCount = count(glob($fullPath . '/*.php'));
            printSuccess("plugins/$path - Diretório existe ($fileCount arquivos PHP)");
        } else {
            $testsFailed++;
            printError("plugins/$path - Diretório não encontrado");
            $errors[] = ['file' => "plugins/$path", 'error' => 'Diretório não existe'];
            $fixes[] = [
                'type' => 'missing_directory',
                'file' => "plugins/$path",
                'description' => 'Diretório de plugin não encontrado',
                'commands' => [
                    "mkdir -p plugins/$path",
                    "# Ou remova a entrada do arquivo plugins/configInterface.json"
                ]
            ];
        }
    }
} else {
    printWarning("Arquivo configInterface.json não encontrado");
}

// ============================================================
// TESTE 6: Verificação de Arquivos Principais
// ============================================================
printHeader("6. Verificação de Arquivos Principais");

$mainFiles = [

    'middleware.php' => 'Middleware HTTP',
    'plugins/autoload.php' => 'Autoloader de plugins',
];

foreach ($mainFiles as $file => $description) {
    $testsRun++;
    $fullPath = $projectRoot . '/' . $file;

    if (file_exists($fullPath)) {
        $testsPassed++;
        printSuccess("$file - $description");
    } else {
        $testsFailed++;
        printError("$file - Arquivo não encontrado ($description)");
        $fixes[] = [
            'type' => 'missing_file',
            'file' => $file,
            'description' => "Arquivo principal não encontrado: $description",
            'commands' => [
                "# Verifique se o arquivo $file existe no repositório",
                "git checkout $file # Restaurar do git se foi deletado",
                "# Ou crie o arquivo manualmente se necessário"
            ]
        ];

    }
}

// ============================================================
// RESUMO FINAL
// ============================================================
printHeader("RESUMO DOS TESTES");

echo "  Total de testes executados: $testsRun\n";
echo COLOR_GREEN . "  Testes passados: $testsPassed" . COLOR_RESET . "\n";

if ($testsFailed > 0) {
    echo COLOR_RED . "  Testes falhados: $testsFailed" . COLOR_RESET . "\n";
}

$percentage = $testsRun > 0 ? round(($testsPassed / $testsRun) * 100, 1) : 0;
echo "\n  Taxa de sucesso: $percentage%\n";

// ============================================================
// GERAÇÃO DO ARQUIVO fixs.json
// ============================================================
if (!empty($fixes)) {
    $fixsFile = $projectRoot . '/fixs.json';
    $fixsData = [
        'generated_at' => date('Y-m-d H:i:s'),
        'total_issues' => count($fixes),
        'fixes' => $fixes
    ];

    $jsonOutput = json_encode($fixsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($fixsFile, $jsonOutput);

    echo "\n" . COLOR_YELLOW . "  ℹ Arquivo 'fixs.json' gerado com " . count($fixes) . " possível(is) solução(ões)" . COLOR_RESET . "\n";
}

if ($testsFailed === 0) {
    echo "\n" . COLOR_GREEN . "  ✓ TODOS OS TESTES PASSARAM!" . COLOR_RESET . "\n\n";
    exit(0);
} else {
    echo "\n" . COLOR_RED . "  ✗ ALGUNS TESTES FALHARAM" . COLOR_RESET . "\n\n";
    echo "  Consulte o arquivo 'fixs.json' para possíveis soluções.\n\n";
    exit(1);
}