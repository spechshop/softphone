<?php
declare(strict_types=1);

/**
 * Provides a secure and efficient data storage mechanism with operations
 * similar to `Swoole\Table`. Data is encrypted on disk using `libsodium`
 * and accesses are optionally synchronized using `Swoole\Lock`.
 */
class spechphoneVault
{
    private string $path;
    private string $key; // 32 bytes binário
    private array $data = [];

    private bool $dirty = false;
    private ?int $timerId = null;
    private int $debounceMs;
    private int $flushFailures = 0;

    /** @var list<array{type:string,key:string,value?:mixed,delta?:int|float}> */
    private array $pendingOperations = [];

    /** @var \Swoole\Lock|null */
    private $lock = null;

    public function count(): int
    {
        return count($this->data);
    }

    public function __construct(string $path, string $hexKey32, int $debounceMs = 2000)
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('ext-sodium não carregada');
        }

        $this->path = $this->resolvePath($path);
        $this->debounceMs = max(100, $debounceMs);

        $key = sodium_hex2bin($hexKey32);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Chave inválida (32 bytes hex)');
        }
        $this->key = $key;

        if (extension_loaded('swoole')) {
            $this->lock = new \Swoole\Lock(SWOOLE_MUTEX);
        }

        $this->load();
    }

    /* ================= API estilo Swoole\Table ================= */

    /**
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function set(string|null $key, mixed $value): bool
    {
        if (empty($key)) return false;
        $this->withLock(function () use ($key, $value) {
            $value = $this->normalize($value);
            $this->data[$key] = $value;
            $this->pendingOperations[] = ['type' => 'set', 'key' => $key, 'value' => $value];
            $this->markDirty();
        });
        return true;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function del(string $key): bool
    {
        $this->withLock(function () use ($key) {
            unset($this->data[$key]);
            $this->pendingOperations[] = ['type' => 'del', 'key' => $key];
            $this->markDirty();
        });
        return true;
    }

    public function exists(string|null $key = ''): bool
    {
        if (empty($key)) return false;
        return array_key_exists($key, $this->data ?? []);
    }

    public function keys(): array
    {
        return array_keys($this->data);
    }

    public function incr(string $key, int|float $by = 1): int|float
    {
        return $this->atomicNumberOp($key, +$by);
    }

    public function decr(string $key, int|float $by = 1): int|float
    {
        return $this->atomicNumberOp($key, -$by);
    }

    public function flush(): void
    {
        $this->withLock(fn() => $this->saveUnlocked());
    }

    /* ================= Internals ================= */

    private function resolvePath(string $path): string
    {
        $dir = dirname($path);
        $filename = basename($path);

        // Tenta usar/criar o caminho original. Revalida depois do mkdir para
        // tolerar dois workers inicializando o mesmo diretório simultaneamente.
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $path;
        }

        // Fallback: usa o diretório temporário do sistema
        $fallbackDir = sys_get_temp_dir() . '/spechphone';
        if (!is_dir($fallbackDir)) {
            if (!@mkdir($fallbackDir, 0700, true) && !is_dir($fallbackDir)) {
                throw new \RuntimeException(
                    "Não foi possível criar nem o diretório '{$dir}' nem o fallback '{$fallbackDir}'. " .
                    "Verifique as permissões do sistema de arquivos."
                );
            }
        }

        if (!is_writable($fallbackDir)) {
            throw new \RuntimeException("Diretório de fallback sem permissão de escrita: '{$fallbackDir}'");
        }

        @chmod($fallbackDir, 0700);
        return $fallbackDir . DIRECTORY_SEPARATOR . $filename;
    }

    private function atomicNumberOp(string $key, int|float $delta): int|float
    {
        $result = 0;

        $this->withLock(function () use ($key, $delta, &$result) {
            $current = $this->data[$key] ?? 0;

            if (!is_int($current) && !is_float($current)) {
                throw new \RuntimeException("Valor da chave '{$key}' não é numérico");
            }

            $result = $current + $delta;
            $this->data[$key] = $result;
            $this->pendingOperations[] = ['type' => 'delta', 'key' => $key, 'delta' => $delta];
            $this->markDirty();
        });

        return $result;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value) || is_scalar($value) || $value === null) {
            return $value;
        }
        throw new \InvalidArgumentException(
            'EncryptedKV aceita apenas string, int, float, bool, null ou array'
        );
    }

    private function load(): void
    {
        if (!is_file($this->path)) {
            $this->data = [];
            return;
        }

        $blob = file_get_contents($this->path);
        if ($blob === false || $blob === '') {
            throw new \RuntimeException("Falha ao ler {$this->path}");
        }

        $json = $this->decrypt($blob);
        $arr = json_decode($json, true);

        if (!is_array($arr)) {
            throw new \RuntimeException('DB corrompido (JSON inválido)');
        }

        $this->data = $arr;
        $this->dirty = false;
    }

    private function saveUnlocked(): void
    {
        if (!$this->dirty) {
            $this->clearTimer();
            return;
        }

        $operations = $this->pendingOperations;
        $savedData = $this->withFileLock(function () use ($operations): array {
            $data = $this->readDataFromDisk();
            foreach ($operations as $operation) {
                $key = $operation['key'];
                switch ($operation['type']) {
                    case 'set':
                        $data[$key] = $operation['value'];
                        break;
                    case 'del':
                        unset($data[$key]);
                        break;
                    case 'delta':
                        $current = $data[$key] ?? 0;
                        if (!is_int($current) && !is_float($current)) {
                            throw new \RuntimeException("Valor persistido da chave '{$key}' não é numérico");
                        }
                        $data[$key] = $current + $operation['delta'];
                        break;
                }
            }

            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->atomicWrite($this->path, $this->encrypt($json));
            return $data;
        });

        $this->data = $savedData;
        $this->pendingOperations = [];
        $this->dirty = false;
        $this->flushFailures = 0;
        $this->clearTimer();
    }

    private function markDirty(): void
    {
        $this->dirty = true;
        $this->flushFailures = 0;

        if (!extension_loaded('swoole')) return;
        if ($this->timerId !== null) return;

        $this->scheduleFlush($this->debounceMs);
    }

    private function scheduleFlush(int $delayMs): void
    {
        try {
            $timerId = \Swoole\Timer::after($delayMs, function (): void {
                // A one-shot timer is no longer active while its callback executes.
                $this->timerId = null;
                try {
                    $this->flush();
                } catch (\Throwable $error) {
                    $this->flushFailures++;
                    error_log(sprintf(
                        '[spechphoneVault] falha ao persistir %s (tentativa %d/3): %s',
                        $this->path,
                        $this->flushFailures,
                        $error->getMessage()
                    ));

                    // Never let a background persistence failure terminate a Swoole
                    // worker. Keep the mutations queued and retry transient failures.
                    if ($this->dirty && $this->flushFailures < 3) {
                        $retryDelay = min($this->debounceMs * (2 ** $this->flushFailures), 30000);
                        $this->scheduleFlush($retryDelay);
                    }
                }
            });
        } catch (\Throwable $error) {
            error_log("[spechphoneVault] não foi possível agendar a persistência de {$this->path}: {$error->getMessage()}");
            return;
        }

        if ($timerId === false) {
            error_log("[spechphoneVault] não foi possível agendar a persistência de {$this->path}");
            return;
        }
        $this->timerId = $timerId;
    }

    private function clearTimer(): void
    {
        if (!extension_loaded('swoole')) return;
        if ($this->timerId !== null) {
            @\Swoole\Timer::clear($this->timerId);
            $this->timerId = null;
        }
    }

    private function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key);
    }

    private function decrypt(string $blob): string
    {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $nonce = substr($blob, 0, $n);
        $cipher = substr($blob, $n);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Chave errada ou arquivo adulterado');
        }
        return $plain;
    }

    private function readDataFromDisk(): array
    {
        if (!is_file($this->path)) return [];

        $blob = @file_get_contents($this->path);
        if ($blob === false || $blob === '') {
            throw new \RuntimeException("Falha ao ler {$this->path}");
        }

        $data = json_decode($this->decrypt($blob), true);
        if (!is_array($data)) {
            throw new \RuntimeException('DB corrompido (JSON inválido)');
        }
        return $data;
    }

    private function withFileLock(callable $fn): mixed
    {
        $lockPath = $this->path . '.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Não foi possível abrir a trava do vault '{$lockPath}'");
        }

        @chmod($lockPath, 0600);
        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Não foi possível adquirir a trava do vault '{$lockPath}'");
            }
            try {
                return $fn();
            } finally {
                @flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function atomicWrite(string $file, string $bytes): void
    {
        $dir = dirname($file);
        $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(8));

        $written = @file_put_contents($tmp, $bytes, LOCK_EX);
        if ($written === false || $written !== strlen($bytes)) {
            @unlink($tmp);
            throw new \RuntimeException(
                "Não foi possível escrever no arquivo temporário '{$tmp}'. " .
                "Verifique as permissões do diretório '{$dir}'."
            );
        }

        @chmod($tmp, 0600);
        error_clear_last();
        if (!@rename($tmp, $file)) {
            $reason = error_get_last()['message'] ?? 'erro desconhecido';
            @unlink($tmp);
            throw new \RuntimeException(
                "Não foi possível publicar o vault persistido em '{$file}': {$reason}"
            );
        }

        @chmod($file, 0600);
    }

    private function withLock(callable $fn): void
    {
        if ($this->lock instanceof \Swoole\Lock) {
            $this->lock->lock();
            try {
                $fn();
            } finally {
                $this->lock->unlock();
            }
        } else {
            $fn();
        }
    }
}
