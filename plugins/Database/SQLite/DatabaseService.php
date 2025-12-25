<?php



namespace plugins\Database\SQLite;


use SQLite3;
use SQLite3Result;
use Swoole\Coroutine;
use Swoole\Runtime;

Runtime::enableCoroutine();

class DatabaseService
{
    public static function createConnection($database = null, $retry = 0): SQLite3
    {
        if ($retry > 2) {
            throw new \Exception('Erro ao conectar ao banco de dados');
        }

        if (is_null($database)) {
            $database = __DIR__ . '/database/storage.sqlite3';
        }

        if (str_contains($database, '/plugins/')) {
            $database = explode('/plugins/', __DIR__)[0] . '/database/storage.sqlite3';
        }

        try {
            $sqlite3 = new SQLite3($database);
          //  echo "SQLite conectado: $database (tentativa " . ($retry + 1) . ")\n";
        } catch (\Exception $e) {
            echo "ERRO SQLite: Arquivo $database - " . $e->getMessage() . "\n";
            error_log("SQLite Connection Error (retry $retry): $database - " . $e->getMessage());
            Coroutine::sleep(1);
            return self::createConnection($database, $retry + 1);
        }

        try {
            // Configurações anti-lock e performance
            $sqlite3->busyTimeout(30000); // 30 segundos de timeout
            $sqlite3->exec('PRAGMA journal_mode = WAL;'); // Write-Ahead Logging
            $sqlite3->exec('PRAGMA synchronous = NORMAL;'); // Menos rigoroso que FULL
            $sqlite3->exec('PRAGMA cache_size = -64000;'); // 64MB de cache
            $sqlite3->exec('PRAGMA temp_store = MEMORY;'); // Armazena temporários na RAM
            $sqlite3->exec('PRAGMA mmap_size = 134217728;'); // 128MB mmap
            $sqlite3->exec('PRAGMA wal_autocheckpoint = 1000;'); // Checkpoint a cada 1000 páginas
            $sqlite3->exec('PRAGMA optimize;'); // Otimizações automáticas
            //echo "SQLite configurado com WAL mode e otimizações anti-lock\n";
        } catch (\Exception $e) {
            echo "ERRO ao configurar SQLite: " . $e->getMessage() . "\n";
            error_log("SQLite Configuration Error: " . $e->getMessage());
        }

        return $sqlite3;
    }

    public static function begin(SQLite3 $db): void
    {
        $db->exec('BEGIN IMMEDIATE TRANSACTION');
    }

    public static function commit(SQLite3 $db): void
    {
        $db->exec('COMMIT');
    }

    public static function rollback(SQLite3 $db): void
    {
        $db->exec('ROLLBACK');
    }

    public static function transactional(SQLite3 $db, callable $callback): bool
    {
        self::begin($db);
        try {
            $callback($db);
            self::commit($db);
            return true;
        } catch (\Throwable $e) {
            self::rollback($db);
            echo "Erro em transação: {$e->getMessage()}\n";
            return false;
        }
    }

    public static function findIdWithKeyValue(string $table, string $key, string $value, SQLite3 $db): ?int
    {
        $stmt = $db->prepare("SELECT id FROM $table WHERE $key = :value");
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row['id'] ?? null;
    }

    public static function queryPrepared(SQLite3 $db, string $sql, array $params = []): SQLite3Result|false
    {
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(is_int($k) ? $k + 1 : ":$k", $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        return $stmt->execute();
    }

    /**
     * Executa query com retry automático em caso de database locked
     */
    public static function executeWithRetry(SQLite3 $db, string $sql, array $params = [], int $maxRetries = 5): SQLite3Result|false
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            try {
                $stmt = $db->prepare($sql);

                foreach ($params as $k => $v) {
                    $paramName = is_int($k) ? $k + 1 : ":$k";
                    $paramType = is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT;
                    $stmt->bindValue($paramName, $v, $paramType);
                }

                $result = $stmt->execute();
                $stmt->close();

                if ($result !== false) {
                    return $result;
                }

                $lastError = $db->lastErrorMsg();

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
            }

            // Se não é erro de lock, não tenta novamente
            if (!str_contains($lastError ?? '', 'database is locked')) {
                break;
            }

            $attempt++;
            $backoffMs = min(1000 * pow(2, $attempt), 5000); // Backoff exponencial até 5s

            echo "[SQLite] Tentativa $attempt falhou (database locked), aguardando {$backoffMs}ms...\n";
            usleep($backoffMs * 1000); // Converte para microsegundos
        }

        echo "[SQLite] ERRO após $maxRetries tentativas: " . ($lastError ?? 'desconhecido') . "\n";
        return false;
    }

    /**
     * Executa UPDATE com retry automático
     */
    public static function updateWithRetry(SQLite3 $db, string $table, array $data, string $where, array $whereParams = []): bool
    {
        $setParts = [];
        $allParams = [];

        // Prepara SET clause
        foreach ($data as $col => $val) {
            $setParts[] = "$col = :set_$col";
            $allParams["set_$col"] = $val;
        }

        // Adiciona parâmetros do WHERE
        foreach ($whereParams as $key => $val) {
            $allParams[$key] = $val;
        }

        $sql = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE $where";

        $result = self::executeWithRetry($db, $sql, $allParams);
        return $result !== false;
    }
}
