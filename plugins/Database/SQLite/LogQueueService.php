<?php

namespace plugins\Database\SQLite;

use SQLite3;
use Exception;
use Swoole\Coroutine;

class LogQueueService
{
    private static string $queueDir;
    private static string $processingDir;
    private static bool $initialized = false;

    /**
     * Inicializa o serviço de fila de logs
     */
    public static function init(): void
    {
        if (self::$initialized) return;

        $baseDir = __DIR__ . '/../../../';
        self::$queueDir = $baseDir . 'queue/logs/pending/';
        self::$processingDir = $baseDir . 'queue/logs/processing/';

        // Cria diretórios se não existirem
        if (!is_dir(self::$queueDir)) {
            mkdir(self::$queueDir, 0755, true);
        }
        if (!is_dir(self::$processingDir)) {
            mkdir(self::$processingDir, 0755, true);
        }

        self::$initialized = true;
    }

    /**
     * Adiciona um log na fila para processamento assíncrono
     */
    public static function queueLog(string $table, array $data, string $operation = 'insert'): bool
    {
        self::init();

        $logEntry = [
            'timestamp' => microtime(true),
            'table' => $table,
            'operation' => $operation,
            'data' => $data,
            'retry_count' => 0,
            'created_at' => time()
        ];

        $filename = uniqid('log_', true) . '.json';
        $filepath = self::$queueDir . $filename;

        try {
            $written = file_put_contents($filepath, json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return $written !== false;
        } catch (Exception $e) {
            error_log("[LogQueue] Erro ao adicionar log na fila: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Processa logs pendentes em lotes
     */
    public static function processPendingLogs(int $batchSize = 50): int
    {
        self::init();
        $processed = 0;

        $files = glob(self::$queueDir . '*.json');
        if (empty($files)) return 0;

        // Processa apenas o lote especificado
        $filesToProcess = array_slice($files, 0, $batchSize);

        foreach ($filesToProcess as $file) {
            if (self::processLogFile($file)) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Processa um arquivo de log específico
     */
    private static function processLogFile(string $filepath): bool
    {
        try {
            // Move arquivo para diretório de processamento
            $filename = basename($filepath);
            $processingPath = self::$processingDir . $filename;

            if (!rename($filepath, $processingPath)) {
                error_log("[LogQueue] Erro ao mover arquivo para processamento: $filename");
                return false;
            }

            // Lê e processa o log
            $content = file_get_contents($processingPath);
            if (!$content) {
                unlink($processingPath);
                return false;
            }

            $logEntry = json_decode($content, true);
            if (!$logEntry || !isset($logEntry['table'], $logEntry['operation'], $logEntry['data'])) {
                error_log("[LogQueue] Formato inválido no arquivo: $filename");
                unlink($processingPath);
                return false;
            }

            // Conecta ao banco e executa operação
            $database = __DIR__ . '/../../../database/storage.sqlite3';
            $connection = DatabaseService::createConnection($database);

            $success = self::executeLogOperation($connection, $logEntry);

            if ($success) {
                // Remove arquivo processado com sucesso
                unlink($processingPath);
                return true;
            } else {
                // Recoloca na fila com contador de retry
                $logEntry['retry_count'] = ($logEntry['retry_count'] ?? 0) + 1;

                if ($logEntry['retry_count'] > 5) {
                    // Após 5 tentativas, remove o arquivo
                    error_log("[LogQueue] Removendo arquivo após 5 tentativas falhas: $filename");
                    unlink($processingPath);
                    return false;
                }

                // Recoloca na fila
                $retryPath = self::$queueDir . 'retry_' . $filename;
                file_put_contents($retryPath, json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                unlink($processingPath);
                return false;
            }

        } catch (Exception $e) {
            error_log("[LogQueue] Erro ao processar arquivo $filepath: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Executa a operação no banco de dados com retry automático
     */
    private static function executeLogOperation(SQLite3 $connection, array $logEntry): bool
    {
        try {
            $table = $logEntry['table'];
            $operation = $logEntry['operation'];
            $data = $logEntry['data'];

            switch ($operation) {
                case 'insert':
                    $columns = array_keys($data);
                    $placeholders = ':' . implode(', :', $columns);
                    $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES ($placeholders)";
                    $params = $data;
                    break;

                case 'update':
                    if (!isset($data['where'], $data['set'])) {
                        throw new Exception("Operação UPDATE requer 'where' e 'set'");
                    }

                    // Usa método otimizado com retry
                    $whereParams = $data['params'] ?? [];
                    return DatabaseService::updateWithRetry($connection, $table, $data['set'], $data['where'], $whereParams);

                case 'delete':
                    if (!isset($data['where'])) {
                        throw new Exception("Operação DELETE requer 'where'");
                    }
                    $sql = "DELETE FROM $table WHERE " . $data['where'];
                    $params = $data['params'] ?? [];
                    break;

                default:
                    throw new Exception("Operação não suportada: $operation");
            }

            // Para insert e delete, usa método com retry
            $result = DatabaseService::executeWithRetry($connection, $sql, $params);
            return $result !== false;

        } catch (Exception $e) {
            error_log("[LogQueue] Erro na operação de banco: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtém estatísticas da fila
     */
    public static function getQueueStats(): array
    {
        self::init();

        $pendingFiles = glob(self::$queueDir . '*.json');
        $processingFiles = glob(self::$processingDir . '*.json');

        return [
            'pending_count' => count($pendingFiles),
            'processing_count' => count($processingFiles),
            'total_queued' => count($pendingFiles) + count($processingFiles)
        ];
    }

    /**
     * Limpa arquivos antigos da fila (mais de 24 horas)
     */
    public static function cleanupOldFiles(): int
    {
        self::init();
        $cleaned = 0;
        $cutoffTime = time() - (24 * 60 * 60); // 24 horas

        $directories = [self::$queueDir, self::$processingDir];

        foreach ($directories as $dir) {
            $files = glob($dir . '*.json');
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
        }

        return $cleaned;
    }
}
