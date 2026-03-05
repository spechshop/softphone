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
            $this->data[$key] = $this->normalize($value);
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
            $this->markDirty();
        });
        return true;
    }

    public function exists(string|null $key = ''): bool
    {
        if (empty($key)) return false;
        return array_key_exists($key, $this->data ?? []);
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

        // Tenta usar o caminho original
        if (is_dir($dir) && is_writable($dir)) {
            return $path;
        }

        // Tenta criar o diretório original
        if (!is_dir($dir) && @mkdir($dir, 0777, true) && is_writable($dir)) {
            return $path;
        }

        // Fallback: usa o diretório temporário do sistema
        $fallbackDir = sys_get_temp_dir() . '/spechphone';
        if (!is_dir($fallbackDir)) {
            if (!@mkdir($fallbackDir, 0777, true)) {
                throw new \RuntimeException(
                    "Não foi possível criar nem o diretório '{$dir}' nem o fallback '{$fallbackDir}'. " .
                    "Verifique as permissões do sistema de arquivos."
                );
            }
        }

        $fallbackPath = \plugins\Request\appController::baseDir() . $filename;


        return $fallbackPath;
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

        $json = json_encode($this->data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException(json_last_error_msg());
        }

        $blob = $this->encrypt($json);
        $this->atomicWrite($this->path, $blob);

        $this->dirty = false;
        $this->clearTimer();
    }

    private function markDirty(): void
    {
        $this->dirty = true;

        if (!extension_loaded('swoole')) return;
        if ($this->timerId !== null) return;

        $this->timerId = \Swoole\Timer::after($this->debounceMs, function () {
            $this->flush();
        });
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

    private function atomicWrite(string $file, string $bytes): void
    {
        $dir = dirname($file);
        $tmp = $file . '.tmp.' . getmypid();

        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException(
                "Não foi possível escrever no arquivo temporário '{$tmp}'. " .
                "Verifique as permissões do diretório '{$dir}'."
            );
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }

        @chmod($file, 0666);
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
